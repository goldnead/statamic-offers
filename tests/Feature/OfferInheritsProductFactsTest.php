<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Support\Catalogue;
use PHPUnit\Framework\Attributes\Test;

/**
 * An offer is a product, presented — so it answers every question the product
 * answers, not only the two about price and name.
 *
 * This is the test that was missing, and its absence broke three shipped addons
 * at once. The resolver used to return `name`, `amount_cent`, `currency` and
 * `offer`, and nothing else. Everything an offer purchase needed afterwards
 * lives on the product:
 *
 *   `digital` and the tax class — without them no invoice could be written for
 *   an offer purchase at all, so the chain funnel → offer → payment → invoice
 *   broke at its last link; and
 *   `grants` — without it nobody who bought through an offer received the
 *   access they had paid for.
 *
 * Each of the three addons had a green suite. None of them crossed the boundary
 * between two, which is the only place this could be seen.
 */
class OfferInheritsProductFactsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'noten-paket' => [
                'name' => 'Notenpaket',
                'amount_cent' => 2900,
                'digital' => false,
                'eigener_schluessel' => 'wert',
                'grants' => 'noten-zugang',
            ],
        ]);
    }

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
    public function an_offer_carries_what_the_product_declares(): void
    {
        $this->offer();

        $resolved = app(Catalogue::class)->find('offer:fruehling-upsell');

        // Not guessed, inherited. Inventing these for an offer would put a
        // wrong rate on an immutable tax document.
        $this->assertArrayHasKey('digital', $resolved, 'ohne das schreibt statamic-invoices gar keine Rechnung');
        $this->assertFalse($resolved['digital']);

        // Anything else the product declares travels too. `tax_class` used to
        // stand here as the example, which read as if the tax class flowed
        // this way — it does not: `statamic-invoices` looks it up per product
        // handle, which is why the resolver also returns `product`. An
        // invented key makes the same point without the wrong implication.
        $this->assertSame('wert', $resolved['eigener_schluessel']);
    }

    #[Test]
    public function an_offer_carries_what_the_product_grants(): void
    {
        $this->offer();

        // Without this the customer pays and receives nothing — the payment
        // settles, the entitlement never appears, and neither side errors.
        $this->assertSame('noten-zugang', app(Catalogue::class)->find('offer:fruehling-upsell')['grants']);
    }

    #[Test]
    public function the_offers_own_values_still_win(): void
    {
        $this->offer();

        $resolved = app(Catalogue::class)->find('offer:fruehling-upsell');

        // The direction of the merge, pinned. Inheriting must not overwrite the
        // two things an offer exists to change: `+` keeps its LEFT operand, so
        // getting the order wrong here would sell the upsell at full price
        // under the product's name.
        $this->assertSame(1200, $resolved['amount_cent'], 'der Angebotspreis, nicht der Produktpreis');
        $this->assertSame('Frühlings-Upsell', $resolved['name']);
        $this->assertSame('fruehling-upsell', $resolved['offer']);
        $this->assertSame('offer:fruehling-upsell', $resolved['handle'], 'die Zeile ist das Angebot, nicht das Produkt');
    }

    #[Test]
    public function an_offer_without_its_own_price_takes_the_products(): void
    {
        $this->offer(['amount_cent' => null]);

        $this->assertSame(2900, app(Catalogue::class)->find('offer:fruehling-upsell')['amount_cent']);
    }

    /**
     * What an offer deliberately does NOT inherit.
     *
     * Found by review: merging the whole product array pulled the subscription
     * keys along, and that decides something about money nobody asked. An
     * offer whose product is a subscription would have started a recurring
     * charge at the *offer* price — the discount, every month, forever — and
     * `Subscriptions::start('offer:x')`, a guaranteed no-op until now, would
     * quietly have become a real subscription.
     *
     * "An upsell at €12 for a €29 product" says nothing about what month two
     * costs. Until somebody decides that, an offer stays a one-off.
     */
    #[Test]
    public function an_offer_does_not_inherit_the_subscription_terms(): void
    {
        config()->set('statamic-payments.products.mitgliedschaft', [
            'name' => 'Mitgliedschaft',
            'amount_cent' => 2900,
            'digital' => true,
            'interval' => '1 month',
            'times' => 12,
            'trial_days' => 14,
            'trial_amount_cent' => 100,
        ]);

        $this->offer(['product' => 'mitgliedschaft']);

        $resolved = app(Catalogue::class)->find('offer:fruehling-upsell');

        foreach (['interval', 'times', 'trial_days', 'trial_amount_cent'] as $schluessel) {
            $this->assertArrayNotHasKey($schluessel, $resolved, "[{$schluessel}] entscheidet ueber wiederkehrendes Geld");
        }

        // What it does inherit is unaffected.
        $this->assertTrue($resolved['digital']);
    }

    #[Test]
    public function an_offer_for_a_product_that_says_nothing_says_nothing_either(): void
    {
        config()->set('statamic-payments.products', [
            'noten-paket' => ['name' => 'Notenpaket', 'amount_cent' => 2900],
        ]);

        $this->offer();

        $resolved = app(Catalogue::class)->find('offer:fruehling-upsell');

        // Inheriting nothing is the right answer when the product knows
        // nothing. An offer must not invent a `digital` its product never
        // declared — the addon downstream refuses to write an invoice for
        // exactly that reason, and it should keep refusing.
        $this->assertArrayNotHasKey('digital', $resolved);
        $this->assertArrayNotHasKey('grants', $resolved);
    }
}
