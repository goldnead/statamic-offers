<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\OfferSales;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Fulfilment;
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
        // Started, never paid: not revenue.
        app(Checkout::class)->start('offer:upsell', ['email' => 'k@example.com']);

        OfferSales::forget();

        $this->assertSame(3600, OfferSales::revenueCent($offer));
        $this->assertSame(3, OfferSales::soldForListing($offer));
        $this->assertSame(3, OfferSales::sold($offer));
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
