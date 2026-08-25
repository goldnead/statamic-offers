<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Fulfilment;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Antlers;

/**
 * What was shown, and what was accepted.
 *
 * The whole value of the two numbers is that the second one means *paid*. A
 * conversion rate built on clicks flatters itself every time a card is
 * declined, and a number nobody can trust is worse than no number.
 */
class CountingTest extends TestCase
{
    protected function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'fruehling-upsell',
            'name' => 'Frühlings-Upsell',
            'product' => 'noten-paket',
            'amount_cent' => 1200,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ], $overrides));
    }

    #[Test]
    public function a_started_purchase_counts_nothing(): void
    {
        $offer = $this->offer();

        app(Checkout::class)->start('offer:fruehling-upsell', ['email' => 'k@example.com']);

        // Interest is not a sale. Somebody who reaches the provider and closes
        // the tab has accepted nothing.
        $this->assertSame(0, $offer->fresh()->accepted_count);
    }

    #[Test]
    public function a_paid_purchase_counts_once(): void
    {
        $offer = $this->offer();

        $payment = app(Checkout::class)->start('offer:fruehling-upsell', ['email' => 'k@example.com'])->payment;
        $this->gateway->markPaid($payment->provider_id);

        // Three deliveries, because a provider redelivers by design.
        app(Fulfilment::class)->handle($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);

        $this->assertSame(1, $offer->fresh()->accepted_count);
    }

    #[Test]
    public function an_offer_bought_as_an_order_bump_counts_too(): void
    {
        $offer = $this->offer();

        // The buyer came for the course and ticked the offer on the way. It was
        // accepted just as much as the thing they came for.
        $payment = app(Checkout::class)->start(['noten-paket', 'offer:fruehling-upsell'])->payment;
        $this->gateway->markPaid($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);

        $this->assertSame(1, $offer->fresh()->accepted_count);
        $this->assertSame(2900 + 1200, $payment->fresh()->amount_cent);
    }

    #[Test]
    public function a_payment_that_has_nothing_to_do_with_an_offer_counts_nothing(): void
    {
        $offer = $this->offer();

        $payment = app(Checkout::class)->start('noten-paket')->payment;
        $this->gateway->markPaid($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);

        $this->assertSame(0, $offer->fresh()->accepted_count);
    }

    #[Test]
    public function the_tag_counts_a_view_and_can_be_told_not_to(): void
    {
        $offer = $this->offer();

        $this->parse('{{ offers:show handle="fruehling-upsell" }}{{ amount }}{{ /offers:show }}');
        $this->assertSame(1, $offer->fresh()->shown_count);

        // Off on a heavily cached page, where the number was meaningless anyway.
        config(['statamic-offers.count_impressions' => false]);
        $this->parse('{{ offers:show handle="fruehling-upsell" }}{{ amount }}{{ /offers:show }}');
        $this->assertSame(1, $offer->fresh()->shown_count);
    }

    #[Test]
    public function the_tag_yields_nothing_for_an_offer_that_cannot_be_bought(): void
    {
        $this->offer(['active' => false]);

        $out = $this->parse('{{ offers:show handle="fruehling-upsell" }}{{ if no_results }}nichts{{ else }}{{ amount }}{{ /if }}{{ /offers:show }}');

        // A page that offered something unbuyable would send somebody to a
        // checkout that refuses them.
        $this->assertSame('nichts', trim($out));
    }

    protected function parse(string $template): string
    {
        return (string) Antlers::parse($template, [], true);
    }
}
