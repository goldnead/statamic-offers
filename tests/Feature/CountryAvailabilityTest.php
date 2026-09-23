<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Offers;
use Goldnead\StatamicOffers\Support\Basket;
use Goldnead\StatamicOffers\Support\OfferNotAvailable;
use Goldnead\StatamicOffers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * O3: Verfuegbarkeit nach Land.
 *
 * Durchgesetzt am Korb, der Stelle, an der ein Kauf entsteht. Eine Vorlage, die
 * das Angebot nur ausblendet, ist keine Sperre: das Formular laesst sich von
 * Hand abschicken.
 */
class CountryAvailabilityTest extends TestCase
{
    protected function offer(string $mode, array $countries = [], string $handle = 'kurs'): Offer
    {
        return Offer::create([
            'handle' => $handle,
            'name' => 'Kurs',
            'product' => 'noten-paket',
            'amount_cent' => 4900,
            'slot' => Offer::SLOT_STANDALONE,
            'country_mode' => $mode,
            'countries' => $countries,
            'active' => true,
        ]);
    }

    #[Test]
    public function worldwide_is_the_default_and_needs_no_country(): void
    {
        $offer = Offer::create([
            'handle' => 'kurs', 'name' => 'Kurs', 'product' => 'noten-paket',
            'amount_cent' => 4900, 'slot' => Offer::SLOT_STANDALONE, 'active' => true,
        ]);

        $this->assertTrue($offer->fresh()->isAvailableIn(null));
        $this->assertSame(['offer:kurs'], Basket::make($offer)->handles());
    }

    #[Test]
    public function a_list_admits_only_its_countries(): void
    {
        $offer = $this->offer(Offer::COUNTRIES_ONLY, ['DE', 'AT', 'CH']);

        $this->assertTrue($offer->isAvailableIn('de'));
        $this->assertTrue($offer->isAvailableIn('CH'));
        $this->assertFalse($offer->isAvailableIn('FR'));
    }

    #[Test]
    public function everywhere_except_refuses_only_its_countries(): void
    {
        $offer = $this->offer(Offer::COUNTRIES_EXCEPT, ['US']);

        $this->assertTrue($offer->isAvailableIn('DE'));
        $this->assertFalse($offer->isAvailableIn('us'));
    }

    #[Test]
    public function an_unknown_country_is_refused_where_a_rule_exists(): void
    {
        // Ohne Land laesst sich die Regel nicht pruefen. Durchwinken hiesse, sie
        // gilt nur fuer Kaeufer, die ihr Land freiwillig nennen.
        $this->assertFalse($this->offer(Offer::COUNTRIES_EXCEPT, ['US'])->isAvailableIn(null));
        $this->assertFalse($this->offer(Offer::COUNTRIES_ONLY, ['DE'], 'zweites')->isAvailableIn(''));
    }

    #[Test]
    public function the_basket_refuses_a_buyer_from_elsewhere(): void
    {
        $offer = $this->offer(Offer::COUNTRIES_ONLY, ['DE']);

        $this->assertSame(['offer:kurs'], Basket::make($offer, country: 'DE')->handles());

        $this->expectException(OfferNotAvailable::class);
        Basket::make($offer, country: 'FR');
    }

    #[Test]
    public function the_basket_refuses_without_a_country_when_a_rule_exists(): void
    {
        $offer = $this->offer(Offer::COUNTRIES_ONLY, ['DE']);

        $this->expectException(OfferNotAvailable::class);
        Basket::make($offer);
    }

    #[Test]
    public function a_bump_restricted_elsewhere_drops_out_of_the_basket(): void
    {
        Offer::create([
            'handle' => 'cd', 'name' => 'CD', 'product' => 'begleit-cd', 'amount_cent' => 900,
            'slot' => Offer::SLOT_BUMP, 'country_mode' => Offer::COUNTRIES_ONLY, 'countries' => ['AT'],
            'active' => true,
        ]);
        $main = Offer::create([
            'handle' => 'kurs', 'name' => 'Kurs', 'product' => 'noten-paket', 'amount_cent' => 4900,
            'slot' => Offer::SLOT_STANDALONE, 'bumps' => ['cd'], 'active' => true,
        ]);

        $this->assertSame(['offer:kurs'], Basket::make($main, ['cd'], country: 'DE')->handles());
        $this->assertSame(['offer:kurs', 'offer:cd'], Basket::make($main, ['cd'], country: 'AT')->handles());
    }

    #[Test]
    public function siblings_can_ask_by_handle(): void
    {
        $this->offer(Offer::COUNTRIES_EXCEPT, ['US']);

        $this->assertTrue(Offers::availableIn('offer:kurs', 'DE'));
        $this->assertTrue(Offers::availableIn('offer:kurs:=100', 'DE'));
        $this->assertFalse(Offers::availableIn('offer:kurs', 'US'));
        // Was kein Angebot ist, regelt dieses Paket nicht.
        $this->assertTrue(Offers::availableIn('noten-paket', 'US'));
    }
}
