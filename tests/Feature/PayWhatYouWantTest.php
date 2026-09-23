<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Offers;
use Goldnead\StatamicOffers\Support\AmountNotAccepted;
use Goldnead\StatamicOffers\Support\Basket;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Fulfilment;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Antlers;

/**
 * O1: Zahl, was du willst.
 *
 * Die einzige Preisart, bei der der Betrag aus dem Browser kommt. Deshalb ist
 * jeder Test hier eine Grenze: unter dem Mindestpreis, ueber der Obergrenze,
 * ein Betrag an einem Angebot, das keinen frei waehlbaren Preis hat.
 */
class PayWhatYouWantTest extends TestCase
{
    protected function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'workshop',
            'name' => 'Workshop auf Spendenbasis',
            'product' => 'noten-paket',
            'slot' => Offer::SLOT_STANDALONE,
            'price_mode' => Offer::PRICE_PWYW,
            'pwyw_min_cent' => 1000,
            'pwyw_suggested_cent' => 2500,
            'active' => true,
        ], $overrides));
    }

    #[Test]
    public function a_chosen_amount_above_the_minimum_is_what_the_catalogue_charges(): void
    {
        $this->offer();

        $this->assertSame(3000, app(Catalogue::class)->find('offer:workshop:=3000')['amount_cent']);
    }

    #[Test]
    public function an_amount_below_the_minimum_does_not_resolve(): void
    {
        $this->offer();

        $this->assertNull(app(Catalogue::class)->find('offer:workshop:=999'));
        $this->assertNull(app(Checkout::class)->start('offer:workshop:=500'));
    }

    #[Test]
    public function an_amount_above_the_ceiling_does_not_resolve(): void
    {
        $this->offer(['pwyw_max_cent' => 10000]);

        $this->assertNotNull(app(Catalogue::class)->find('offer:workshop:=10000'));
        $this->assertNull(app(Catalogue::class)->find('offer:workshop:=10001'));
    }

    #[Test]
    public function without_an_own_ceiling_the_config_caps_it(): void
    {
        config(['statamic-offers.pay_what_you_want.max_cent' => 50000]);
        $this->offer();

        // Ein vertippter Betrag mit drei Nullen zu viel ist keine Spende.
        $this->assertNull(app(Catalogue::class)->find('offer:workshop:=500000'));
    }

    #[Test]
    public function a_fixed_price_offer_refuses_an_amount_from_outside(): void
    {
        $this->offer(['price_mode' => Offer::PRICE_FIXED, 'amount_cent' => 4900]);

        // Sonst liesse sich jedes Angebot fuer einen Cent kaufen, indem man
        // `:=1` an den Handle haengt.
        $this->assertNull(app(Catalogue::class)->find('offer:workshop:=1'));
        $this->assertSame(4900, app(Catalogue::class)->find('offer:workshop')['amount_cent']);
    }

    #[Test]
    public function malformed_amounts_do_not_resolve(): void
    {
        $this->offer();

        foreach (['offer:workshop:=', 'offer:workshop:=-100', 'offer:workshop:=10.50', 'offer:workshop:=01500'] as $handle) {
            $this->assertNull(app(Catalogue::class)->find($handle), $handle);
        }
    }

    #[Test]
    public function the_basket_builds_the_handle_and_checks_the_minimum(): void
    {
        $offer = $this->offer();

        $basket = Basket::make($offer, amountCent: 4200);

        $this->assertSame(['offer:workshop:=4200'], $basket->handles());
        $this->assertSame(4200, $basket->grossCent());

        $this->expectException(InvalidArgumentException::class);
        Basket::make($offer, amountCent: 999);
    }

    #[Test]
    public function an_amount_out_of_bounds_says_so_to_the_buyer(): void
    {
        $offer = $this->offer(['pwyw_max_cent' => 20000]);

        try {
            Basket::make($offer, amountCent: 30000);
            $this->fail('Kein Fehler fuer einen Betrag ueber dem Hoechstbetrag.');
        } catch (AmountNotAccepted $e) {
            // Eine eigene Ausnahme, damit die Kasse sie von einem kaputten
            // Formular unterscheidet, und ein Satz, der die Grenzen nennt.
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
            $this->assertStringContainsString('10', $e->buyerMessage());
            $this->assertStringContainsString('200', $e->buyerMessage());
        }
    }

    #[Test]
    public function a_coupon_cannot_push_a_chosen_amount_below_the_minimum(): void
    {
        $offer = $this->offer();
        Coupon::create(['code' => 'HALB', 'percent' => 50, 'active' => true]);

        // 15,00 gewaehlt, 50 % waeren 7,50 und damit unter dem Mindestpreis
        // von 10,00. Der Boden gilt auch nach dem Rabatt: abgezogen wird
        // hoechstens, was ueber dem Mindestpreis liegt.
        $basket = Basket::make($offer, [], 'HALB', amountCent: 1500);

        $this->assertSame(500, $basket->discount()->amountCent);
        $this->assertSame(1000, $basket->netCent());
    }

    #[Test]
    public function without_a_chosen_amount_the_basket_takes_the_suggestion(): void
    {
        $offer = $this->offer();

        $this->assertSame(['offer:workshop:=2500'], Basket::make($offer)->handles());
    }

    #[Test]
    public function the_payment_carries_the_chosen_amount_and_counts_as_a_sale(): void
    {
        $offer = $this->offer(['quantity_limit' => 5]);

        $payment = app(Checkout::class)->start(Basket::make($offer, amountCent: 3300)->handles(), ['email' => 'k@example.com'])->payment;
        $this->gateway->markPaid($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);

        $this->assertSame(3300, $payment->fresh()->amount_cent);
        // Frueher zaehlte nur der nackte Handle. Ein Kauf mit Betrag ist
        // derselbe Verkauf und muss gegen das Kontingent und als angenommen
        // zaehlen.
        $this->assertSame(4, $offer->fresh()->remainingQuantity());
        $this->assertSame(1, $offer->fresh()->accepted_count);
    }

    #[Test]
    public function thanks_are_picked_by_the_highest_tier_reached(): void
    {
        $offer = $this->offer(['pwyw_thanks' => [
            ['from_cent' => 0, 'text' => 'Danke.'],
            ['from_cent' => 5000, 'text' => 'Danke, du traegst den Workshop mit.'],
            ['from_cent' => 2500, 'text' => 'Danke fuer den vollen Beitrag.'],
        ]]);

        $this->assertSame('Danke.', $offer->thankYouFor(1000));
        $this->assertSame('Danke fuer den vollen Beitrag.', $offer->thankYouFor(2500));
        $this->assertSame('Danke, du traegst den Workshop mit.', $offer->thankYouFor(9000));
        $this->assertSame('Danke fuer den vollen Beitrag.', Offers::thankYouFor('offer:workshop:=3000'));

        $this->assertSame(
            'Danke, du traegst den Workshop mit.',
            trim((string) Antlers::parse('{{ offers:thanks handle="workshop" amount_cent="6000" }}', [], true)),
        );
    }

    #[Test]
    public function the_tag_hands_the_template_the_bounds(): void
    {
        $this->offer(['pwyw_max_cent' => 20000]);

        $html = (string) Antlers::parse(
            '{{ offers:show handle="workshop" }}{{ pwyw_min_cent }}|{{ pwyw_suggested_cent }}|{{ pwyw_max_cent }}|{{ amount_cent }}{{ /offers:show }}',
            [],
            true,
        );

        $this->assertSame('1000|2500|20000|1000', trim($html));
    }
}
