<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\OfferUsage;
use Goldnead\StatamicOffers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * „Was haengt hier dran."
 *
 * **Warum das ein Feature ist und keine Spielerei.** Jede Verdrahtung in
 * dieser Suite war unsichtbar. Deshalb fiel einen Monat lang niemandem auf,
 * dass nach einem Kauf keine Bestaetigung rausging: man haette es nur gesehen,
 * wenn man in vier Tabellen nachgesehen haette.
 *
 * In dieser Testumgebung sind `statamic-funnels` und `statamic-automations`
 * **nicht** installiert. Das ist der Fall, den es zu ueberleben gilt: eine
 * Installation, die nur Angebote hat, darf nicht mit einem Fehler antworten —
 * sondern mit „nichts verdrahtet".
 */
class OfferUsageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Der Speicher gilt fuer eine Anfrage; ein Testlauf ist ein Prozess mit
        // vielen darin. Ohne das saehe der zweite Test die Verdrahtung des
        // ersten.
        OfferUsage::forget();
    }

    #[Test]
    public function ohne_die_nachbar_addons_ist_nichts_verdrahtet(): void
    {
        $usage = OfferUsage::forHandle('stimmnotfallplan');

        // Nicht null, nicht eine Ausnahme: zwei leere Listen. Das Kaestchen im
        // Formular kann damit „nichts verdrahtet" schreiben, und genau dieser
        // Satz ist der Unterschied zu einem fehlenden Kaestchen, das sich wie
        // „noch nicht gebaut" liest.
        $this->assertSame(['funnels' => [], 'automations' => []], $usage);
    }

    #[Test]
    public function ein_unbekanntes_angebot_bekommt_dieselbe_leere_antwort(): void
    {
        $this->assertSame(
            ['funnels' => [], 'automations' => []],
            OfferUsage::forHandle('gibt-es-nicht'),
        );
    }

    #[Test]
    public function die_liste_traegt_die_verdrahtung_je_zeile(): void
    {
        Offer::create([
            'handle' => 'stimmnotfallplan',
            'name' => 'Der Stimmnotfallplan',
            'product' => 'noten-paket',
            'amount_cent' => 1400,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ]);

        $zeile = $this->actingAs(tap(User::make()->email('studio@example.com')->makeSuper())->save())
            ->getJson('/cp/utilities/offers')
            ->assertOk()
            ->json('data.0');

        // Der Schluessel muss da sein, auch leer. Fehlte er, liefe die Ansicht
        // in ein `undefined` und zeigte gar nichts — wieder eine Verdrahtung,
        // ueber die niemand etwas erfaehrt.
        $this->assertArrayHasKey('usage', $zeile);
        $this->assertSame(['funnels' => [], 'automations' => []], $zeile['usage']);
    }
}
