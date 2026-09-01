<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * How many, and until when.
 *
 * The limit counts *paid* lines, so the tests buy through the real checkout
 * and mark the payment paid the way a provider would.
 */
class AvailabilityTest extends TestCase
{
    protected function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'knapp',
            'name' => 'Knapp',
            'product' => 'noten-paket',
            'amount_cent' => 1000,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ], $overrides));
    }

    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    protected function buyAndPay(string $handle): void
    {
        $payment = app(Checkout::class)->start($handle, ['email' => 'k@example.com'])->payment;
        $this->gateway->markPaid($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function no_limit_means_null_and_always_sellable(): void
    {
        $offer = $this->offer();

        $this->assertNull($offer->remainingQuantity());
        $this->assertTrue($offer->isSellable());
    }

    #[Test]
    public function paid_purchases_count_down_and_the_last_one_closes_the_offer(): void
    {
        $offer = $this->offer(['quantity_limit' => 2]);

        $this->assertSame(2, $offer->remainingQuantity());

        $this->buyAndPay('offer:knapp');
        $this->assertSame(1, $offer->remainingQuantity());
        $this->assertTrue($offer->isSellable());

        $this->buyAndPay('offer:knapp');

        // remaining == 0 is the edge: not "below zero", exactly zero.
        $this->assertSame(0, $offer->remainingQuantity());
        $this->assertFalse($offer->isSellable());
        $this->assertNull(app(Catalogue::class)->find('offer:knapp'));
        $this->assertNull(app(Checkout::class)->start('offer:knapp'));
    }

    #[Test]
    public function an_unpaid_purchase_does_not_count(): void
    {
        $offer = $this->offer(['quantity_limit' => 1]);

        // Started, never paid.
        app(Checkout::class)->start('offer:knapp', ['email' => 'k@example.com']);

        $this->assertSame(1, $offer->remainingQuantity());
        $this->assertTrue($offer->isSellable());
    }

    #[Test]
    public function a_limit_lowered_under_what_was_sold_reads_as_sold_out(): void
    {
        $offer = $this->offer(['quantity_limit' => 5]);
        $this->buyAndPay('offer:knapp');
        $this->buyAndPay('offer:knapp');

        $offer->update(['quantity_limit' => 1]);

        $this->assertSame(0, $offer->fresh()->remainingQuantity());
    }

    #[Test]
    public function outside_the_time_window_the_offer_is_not_sellable(): void
    {
        Carbon::setTestNow('2027-05-10 12:00:00');

        $future = $this->offer(['available_from' => '2027-05-11 00:00:00']);
        $past = $this->offer(['handle' => 'vorbei', 'available_until' => '2027-05-09 23:59:59']);
        $inside = $this->offer(['handle' => 'drin', 'available_from' => '2027-05-01', 'available_until' => '2027-05-31 23:59:59']);

        $this->assertFalse($future->isSellable());
        $this->assertFalse($past->isSellable());
        $this->assertTrue($inside->isSellable());

        $this->assertNull(app(Catalogue::class)->find('offer:knapp'));
        $this->assertNotNull(app(Catalogue::class)->find('offer:drin'));
    }

    #[Test]
    public function the_listing_names_the_state(): void
    {
        Carbon::setTestNow('2027-05-10 12:00:00');

        $this->offer(['quantity_limit' => 3]);
        $this->offer(['handle' => 'vorbei', 'available_until' => '2027-05-09 23:59:59']);
        $this->offer(['handle' => 'frei']);

        $rows = collect($this->actingAs($this->user())->getJson('/cp/utilities/offers')->json('data'))
            ->keyBy('handle');

        $this->assertSame('limited', $rows['knapp']['availability']['state']);
        $this->assertSame(3, $rows['knapp']['availability']['remaining']);
        $this->assertSame('ended', $rows['vorbei']['availability']['state']);
        $this->assertSame('unlimited', $rows['frei']['availability']['state']);
    }

    #[Test]
    public function the_control_panel_stores_the_window_and_refuses_an_end_before_the_start(): void
    {
        $payload = [
            'name' => 'Knapp',
            'handle' => 'knapp',
            'product' => 'noten-paket',
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ];

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/offers', $payload + [
                'quantity_limit' => 50,
                'available_from' => '2027-06-01T18:00',
                'available_until' => '2027-06-01T12:00',
            ])
            ->assertJsonValidationErrors(['available_until']);

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/offers', $payload + [
                'quantity_limit' => 50,
                'available_from' => '2027-06-01T18:00',
                'available_until' => '2027-06-08T18:00',
            ])
            ->assertRedirect();

        $offer = Offer::query()->where('handle', 'knapp')->firstOrFail();
        $this->assertSame(50, $offer->quantity_limit);
        $this->assertSame('2027-06-01 18:00', $offer->available_from->format('Y-m-d H:i'));
        $this->assertSame('2027-06-08 18:00', $offer->available_until->format('Y-m-d H:i'));
    }
}
