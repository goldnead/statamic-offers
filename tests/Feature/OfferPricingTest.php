<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Checkout;
use PHPUnit\Framework\Attributes\Test;

/**
 * What an offer costs, and where that number is allowed to come from.
 *
 * The payment addon is built on one rule: an amount never comes from a request.
 * An offer's own price is the first thing that ever needed to bend it, so every
 * test here is about bending it in the right direction — server-side, in a
 * table, decided by the site owner.
 */
class OfferPricingTest extends TestCase
{
    protected function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'fruehling-upsell',
            'name' => 'Frühlings-Upsell',
            'product' => 'noten-paket',
            'amount_cent' => 1200,
            'slot' => Offer::SLOT_POST_PURCHASE,
            'active' => true,
        ], $overrides));
    }

    #[Test]
    public function an_offer_with_its_own_price_overrides_the_catalogue(): void
    {
        $this->offer();

        // The product is €29. This offer sells the same thing for €12, which is
        // what an upsell is, and the only reason this addon exists.
        $this->assertSame(1200, app(Catalogue::class)->find('offer:fruehling-upsell')['amount_cent']);
        $this->assertSame(2900, app(Catalogue::class)->find('noten-paket')['amount_cent']);
    }

    #[Test]
    public function an_offer_without_a_price_uses_the_catalogue(): void
    {
        $this->offer(['amount_cent' => null]);

        $this->assertSame(2900, app(Catalogue::class)->find('offer:fruehling-upsell')['amount_cent']);
    }

    #[Test]
    public function it_can_actually_be_bought(): void
    {
        $this->offer();

        $payment = app(Checkout::class)->start('offer:fruehling-upsell')->payment;

        $this->assertSame(1200, $payment->amount_cent);
        $this->assertSame('offer:fruehling-upsell', $payment->product);
        $this->assertSame(1, $payment->items()->count());
    }

    #[Test]
    public function an_inactive_offer_cannot_be_bought(): void
    {
        $this->offer(['active' => false]);

        // Not "shows an error later": it is not a priced thing at all, so every
        // guard the payment addon already has refuses it for free.
        $this->assertNull(app(Catalogue::class)->find('offer:fruehling-upsell'));
        $this->assertNull(app(Checkout::class)->start('offer:fruehling-upsell'));
    }

    #[Test]
    public function an_offer_pointing_at_a_product_nobody_configured_cannot_be_bought(): void
    {
        $this->offer(['product' => 'gibt-es-nicht', 'amount_cent' => null]);

        // With no price of its own and no product behind it, there is no amount
        // — and an order for an unknown amount is the one thing that must never
        // reach a provider.
        $this->assertNull(app(Catalogue::class)->find('offer:fruehling-upsell'));
    }

    #[Test]
    public function an_offer_with_its_own_price_but_no_product_is_still_refused(): void
    {
        $this->offer(['product' => 'gibt-es-nicht', 'amount_cent' => 1200]);

        // Tempting to allow — there *is* a price. But the product is what gets
        // delivered, and charging for something the catalogue has never heard
        // of takes money for nothing.
        $this->assertNull(app(Catalogue::class)->find('offer:fruehling-upsell'));
    }

    #[Test]
    public function the_compare_at_price_is_never_charged(): void
    {
        $this->offer(['compare_at_cent' => 9900]);

        $payment = app(Checkout::class)->start('offer:fruehling-upsell')->payment;

        // A struck-through price that could be charged would be the same
        // mistake as a price in a request, with better styling.
        $this->assertSame(1200, $payment->amount_cent);
    }

    #[Test]
    public function a_handle_without_the_prefix_is_a_product_and_not_an_offer(): void
    {
        $this->offer(['handle' => 'noten-paket', 'amount_cent' => 100]);

        // An offer named after a product must not be able to reprice it. The
        // prefix is what keeps the two apart.
        $this->assertSame(2900, app(Catalogue::class)->find('noten-paket')['amount_cent']);
        $this->assertSame(100, app(Catalogue::class)->find('offer:noten-paket')['amount_cent']);
    }
}
