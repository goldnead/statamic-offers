<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\OfferSales;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Discount;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Goldnead\StatamicPayments\Support\Refunds;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * Revenue per offer, read from the payment lines.
 */
class RevenueColumnTest extends TestCase
{
    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    protected function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'upsell',
            'name' => 'Upsell',
            'product' => 'noten-paket',
            'amount_cent' => 1200,
            'slot' => Offer::SLOT_POST_PURCHASE,
            'active' => true,
        ], $overrides));
    }

    protected function buyAndPay(string|array $handles): Payment
    {
        $payment = app(Checkout::class)->start($handles, ['email' => 'k@example.com'])->payment;
        $this->gateway->markPaid($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);

        return $payment->fresh();
    }

    #[Test]
    public function revenue_is_the_sum_of_paid_lines_for_the_offer_and_nothing_else(): void
    {
        $offer = $this->offer();
        $this->offer(['handle' => 'anderes', 'name' => 'Anderes', 'amount_cent' => 5000]);

        $this->buyAndPay('offer:upsell');
        $this->buyAndPay('offer:upsell');
        // Sold alongside a product: the product's line is not the offer's.
        $this->buyAndPay(['noten-paket', 'offer:upsell']);
        // The other offer's money stays with the other offer.
        $this->buyAndPay('offer:anderes');
        // Started, not paid: not revenue, not sold — but held against the
        // limit for the next hour, which is what `sold()` answers.
        app(Checkout::class)->start('offer:upsell', ['email' => 'k@example.com']);

        OfferSales::forget();

        $this->assertSame(3600, OfferSales::revenueCent($offer));
        $this->assertSame(3, OfferSales::soldForListing($offer));
        $this->assertSame(4, OfferSales::committedForListing($offer));
        $this->assertSame(4, OfferSales::sold($offer));
    }

    #[Test]
    public function revenue_is_net_of_the_lines_coupon_share_and_its_refund_share(): void
    {
        $offer = $this->offer();

        // Offer (1200) and product (2900) in one payment, 410 off the 4100.
        // The payment addon apportions the discount by line: 120 on the
        // offer, 290 on the product. Offer net: 1080; payment total: 3690.
        $payment = app(Checkout::class)->start(
            ['offer:upsell', 'noten-paket'],
            ['email' => 'k@example.com'],
            null,
            new Discount('ZEHN', 410, 'Zehn Prozent'),
        )->payment;
        $this->gateway->markPaid($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);

        $this->assertSame(3690, $payment->fresh()->amount_cent);
        $this->assertSame(120, (int) $payment->items()->where('product', 'offer:upsell')->value('discount_cent'));

        OfferSales::forget();
        $this->assertSame(1080, OfferSales::revenueCent($offer));

        // Then 1000 of the 3690 is refunded. The offer's share is its part of
        // the total: 1000 × 1080 / 3690 = 292.68 → 293. Net: 787.
        $this->assertTrue(app(Refunds::class)->record($payment->fresh(), 1000));

        OfferSales::forget();
        $this->assertSame(787, OfferSales::revenueCent($offer));

        // A full refund leaves nothing, and never less than nothing.
        app(Refunds::class)->record($payment->fresh(), 5000);

        OfferSales::forget();
        $this->assertSame(0, OfferSales::revenueCent($offer));
    }

    #[Test]
    public function without_the_payment_tables_nothing_is_counted_and_the_column_is_gone(): void
    {
        $offer = $this->offer(['quantity_limit' => 5]);

        // Renamed rather than dropped: the test harness rolls the payment
        // migrations back on teardown and needs the tables to exist for that.
        Schema::rename('payment_items', 'payment_items_weg');
        Schema::rename('payments', 'payments_weg');
        OfferSales::forget();

        try {
            Log::shouldReceive('notice')
                ->once()
                ->withArgs(fn (string $message) => str_contains($message, 'payment tables are missing'));

            // No limit can be enforced, so none is claimed.
            $this->assertNull($offer->remainingQuantity());
            $this->assertTrue($offer->isSellable());

            $response = $this->actingAs($this->user())->getJson('/cp/utilities/offers');

            $columns = collect($response->json('meta.columns'))->pluck('field')->all();
            $this->assertNotContains('revenue', $columns);
            $this->assertContains('availability', $columns);

            $row = collect($response->json('data'))->firstWhere('handle', 'upsell');
            $this->assertNull($row['revenue']);
            $this->assertNull($row['sold']);
            $this->assertSame('unlimited', $row['availability']['state']);
        } finally {
            Schema::rename('payments_weg', 'payments');
            Schema::rename('payment_items_weg', 'payment_items');
            OfferSales::forget();
        }
    }

    #[Test]
    public function the_listing_carries_a_revenue_column_with_the_sum(): void
    {
        $this->offer();
        $this->buyAndPay('offer:upsell');

        OfferSales::forget();

        $response = $this->actingAs($this->user())->getJson('/cp/utilities/offers');

        $this->assertContains('revenue', collect($response->json('meta.columns'))->pluck('field')->all());

        $row = collect($response->json('data'))->firstWhere('handle', 'upsell');
        $this->assertSame(1, $row['sold']);
        $this->assertStringContainsString('12', $row['revenue']);
    }

    #[Test]
    public function the_listing_can_be_filtered_to_the_upsells(): void
    {
        $this->offer();
        $this->offer(['handle' => 'bump', 'name' => 'Bump', 'slot' => Offer::SLOT_BUMP]);

        $filters = base64_encode(json_encode(['statamic_offers_slot' => ['value' => Offer::SLOT_POST_PURCHASE]]));

        $handles = collect($this->actingAs($this->user())->getJson('/cp/utilities/offers?filters='.$filters)->json('data'))
            ->pluck('handle')->all();

        $this->assertSame(['upsell'], $handles);
    }
}
