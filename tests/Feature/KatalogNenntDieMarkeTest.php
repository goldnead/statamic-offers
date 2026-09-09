<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Support\Catalogue;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

/**
 * Wem das Angebot gehoert, steht im Katalogeintrag.
 *
 * `statamic-payments` 1.24.1 stempelt eine Folgezahlung mit der `brand_id` des
 * Katalogeintrags statt mit der Marke der Vorgaengerzahlung. Der Schluessel ist
 * die **einzige** Naht: payments kennt `Offer` nicht und darf es nicht kennen,
 * weil offers an payments haengt und nicht umgekehrt. Ohne die Zeilen hier ist
 * der Fix drueben stiller toter Code, und ein Upsell aus einem Funnel mit
 * fremdem Angebot wird weiter unter der falschen Marke verkauft — mit
 * Rechnungsserie und Absender daran.
 *
 * Fuer ein **Buendel** gilt dieselbe Strenge wie bei `digital`: widersprechen
 * sich die Teile ueber die Marke, gibt es das Buendel nicht. Ein Buendel ist
 * eine Zeile zu einem Preis, und eine Zeile kann nur einer Marke gehoeren —
 * eine davon zu waehlen hiesse raten, wessen Umsatz das ist und wessen
 * Rechnungsnummer darauf steht.
 */
class KatalogNenntDieMarkeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'noten-paket' => [
                'name' => 'Notenpaket',
                'amount_cent' => 2900,
                'digital' => true,
                'brand_id' => 2,
            ],
            'playback-paket' => [
                'name' => 'Playback-Paket',
                'amount_cent' => 1900,
                'digital' => true,
                'brand_id' => 2,
            ],
            // Dasselbe Regal, andere Marke.
            'fremdes-paket' => [
                'name' => 'Fremdes Paket',
                'amount_cent' => 2100,
                'digital' => true,
                'brand_id' => 3,
            ],
            // Sagt nichts ueber seine Marke. Das ist kein Widerspruch,
            // sondern Schweigen, und Schweigen darf nichts blockieren.
            'stilles-paket' => [
                'name' => 'Stilles Paket',
                'amount_cent' => 1500,
                'digital' => true,
            ],
            // Noch eine dritte Marke, fuer das Buendel mit drei Antworten.
            'drittes-paket' => [
                'name' => 'Drittes Paket',
                'amount_cent' => 1100,
                'digital' => true,
                'brand_id' => 4,
            ],
            // Eine Marke als Ziffernfolge im Text. Kein Kunstfall: eine
            // Eloquent-Spalte ohne Cast liefert genau die, und der Katalog ist
            // offen fuer den Resolver eines fremden Pakets.
            'text-paket' => [
                'name' => 'Paket mit Text-Marke',
                'amount_cent' => 1300,
                'digital' => true,
                'brand_id' => '3',
            ],
            // Und etwas, das gar keine Marke ist.
            'unsinn-paket' => [
                'name' => 'Paket mit Unsinn',
                'amount_cent' => 1400,
                'digital' => true,
                'brand_id' => ['nicht', 'zu', 'gebrauchen'],
            ],
        ]);
    }

    protected function angebot(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'fruehling',
            'name' => 'Frühlings-Angebot',
            'product' => 'noten-paket',
            'brand_id' => 2,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ], $overrides));
    }

    /**
     * Der Grundfall, und er belegt fuer sich allein wenig.
     *
     * Angebot und Produkt tragen hier dieselbe Marke, der Eintrag saehe also
     * auch ohne den Fix so aus — die Marke des Produkts kam bis 1.11.1 durch
     * den `+`-Merge mit. Was **wessen** Marke es ist, zeigt erst der Fall
     * darunter. Dieser steht trotzdem hier: er sichert die Form ab, in der der
     * Schluessel ankommt, und faellt um, wenn er ganz verschwindet.
     */
    #[Test]
    public function ein_einzelangebot_traegt_seine_marke_im_katalogeintrag(): void
    {
        $this->angebot();

        $eintrag = app(Catalogue::class)->find('offer:fruehling');

        // Streng auf `int`, nicht auf lose Gleichheit: `brandFor()` drueben
        // nimmt eine Ziffernfolge im Text zwar an, aber ein Array wuerde es
        // verwerfen — und was hier herausgeht, soll die eindeutige Form haben.
        $this->assertSame(2, $eintrag['brand_id']);
    }

    #[Test]
    public function das_angebot_nennt_seine_eigene_marke_und_nicht_die_des_produkts(): void
    {
        // Das Angebot gehoert Marke 3, das Produkt darunter Marke 2. Verkauft
        // wird das Angebot, also gilt dessen Marke — dieselbe Regel wie beim
        // Namen und beim Preis, wo das Angebot ebenfalls gewinnt.
        $this->angebot(['brand_id' => 3]);

        $this->assertSame(3, app(Catalogue::class)->find('offer:fruehling')['brand_id']);
    }

    #[Test]
    public function ein_angebot_ohne_marke_nennt_null_und_nicht_die_des_produkts(): void
    {
        // Auf einem Betrieb ohne Mandanten ist das jede Zeile. `brandFor()`
        // liest die Null als „nennt keine Marke" und erbt — laut, mit
        // `info`. Die Marke des Produkts hier durchzureichen waere eine
        // Aussage, die das Angebot nie gemacht hat.
        $this->angebot(['brand_id' => 0]);

        $this->assertSame(0, app(Catalogue::class)->find('offer:fruehling')['brand_id']);
    }

    #[Test]
    public function ein_buendel_mit_einheitlicher_marke_traegt_die_des_angebots(): void
    {
        // Teile einig auf Marke 2, das Angebot selbst gehoert Marke 3. Kein
        // Widerspruch im Sinne der Regel — die prueft die Teile gegeneinander,
        // nicht gegen das Angebot —, und verkauft wird das Angebot. Waere hier
        // 2 zu lesen, reichte das Buendel die Marke seiner Teile durch, und
        // der Umsatz landete bei der falschen Marke.
        $this->angebot([
            'brand_id' => 3,
            'products' => ['playback-paket'],
            'amount_cent' => 3900,
        ]);

        $eintrag = app(Catalogue::class)->find('offer:fruehling');

        $this->assertNotNull($eintrag);
        $this->assertSame(3, $eintrag['brand_id']);
    }

    #[Test]
    public function ein_buendel_dessen_teile_sich_ueber_die_marke_widersprechen_ist_nicht_verkaufbar(): void
    {
        Log::spy();

        $this->angebot([
            'products' => ['fremdes-paket'],
            'amount_cent' => 3900,
        ]);

        // `null` heisst hier: `Checkout::start()` verweigert den ganzen
        // Vorgang — laut, und bevor Geld fliesst.
        $this->assertNull(app(Catalogue::class)->find('offer:fruehling'));

        // Die Meldung muss den Betreiber handeln lassen koennen, also stehen
        // die Handles **und** die Marken darin. „Ein Buendel ist nicht
        // verkaufbar" ohne Namen zwingt zum Suchen.
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $meldung, array $kontext): bool {
                return str_contains($meldung, 'brand')
                    && $kontext['offer'] === 'fruehling'
                    && $kontext['products'] === ['noten-paket', 'fremdes-paket']
                    && $kontext['brands'] === ['noten-paket' => 2, 'fremdes-paket' => 3];
            })
            ->once();
    }

    #[Test]
    public function ein_teil_das_nichts_ueber_seine_marke_sagt_widerspricht_niemandem(): void
    {
        Log::spy();

        $this->angebot([
            'products' => ['stilles-paket'],
            'amount_cent' => 3900,
        ]);

        $eintrag = app(Catalogue::class)->find('offer:fruehling');

        $this->assertNotNull($eintrag);
        $this->assertSame(2, $eintrag['brand_id']);

        // Breit gefasst und nicht auf die Markenmeldung eingeengt: eine auf
        // den Text verengte Erwartung fing in der Nachbarfamilie eine
        // eingebaute Warnung nicht.
        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function eine_marke_als_ziffernfolge_im_text_zaehlt_und_kann_widersprechen(): void
    {
        Log::spy();

        // `'3'` gegen `2`. Eine Eloquent-Spalte ohne Cast liefert genau so
        // eine Ziffernfolge, und sie als „sagt nichts" zu behandeln hiesse,
        // einen echten Widerspruch durchzulassen.
        $this->angebot([
            'products' => ['text-paket'],
            'amount_cent' => 3900,
        ]);

        $this->assertNull(app(Catalogue::class)->find('offer:fruehling'));

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $meldung, array $kontext): bool {
                // Als `int` in der Meldung, nicht als `'3'`: was hier steht,
                // soll ein Betreiber gegen seine Markenliste halten koennen.
                return str_contains($meldung, 'brand')
                    && $kontext['brands'] === ['noten-paket' => 2, 'text-paket' => 3];
            })
            ->once();
    }

    #[Test]
    public function ein_teil_dessen_marke_gar_keine_ist_blockiert_nichts_und_sagt_es_trotzdem(): void
    {
        Log::spy();

        // Behandelt wie Schweigen — raten waere schlimmer, und ein blosser
        // `(int)`-Cast machte aus dem Array die Marke 1, also eine echte, die
        // es zufaellig gibt. Aber der Grund steht laut da, statt als „nennt
        // keine Marke" verkleidet zu werden.
        $this->angebot([
            'products' => ['unsinn-paket'],
            'amount_cent' => 3900,
        ]);

        $eintrag = app(Catalogue::class)->find('offer:fruehling');

        $this->assertNotNull($eintrag);
        $this->assertSame(2, $eintrag['brand_id']);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $meldung, array $kontext): bool {
                return str_contains($meldung, 'not a usable brand id')
                    && $kontext['product'] === 'unsinn-paket'
                    && $kontext['brand_id_type'] === 'array'
                    // Der Wert selbst nur bei einem Skalar: was hier steht,
                    // kommt aus fremdem Code.
                    //
                    // `array_key_exists` und nicht `??`: der Schluessel traegt
                    // `null`, und `null ?? 'da'` waere `'da'`.
                    && array_key_exists('brand_id', $kontext)
                    && $kontext['brand_id'] === null;
            })
            ->once();
    }

    #[Test]
    public function ein_buendel_mit_drei_marken_nennt_alle_drei_in_einer_meldung(): void
    {
        Log::spy();

        $this->angebot([
            'products' => ['fremdes-paket', 'drittes-paket'],
            'amount_cent' => 5900,
        ]);

        $this->assertNull(app(Catalogue::class)->find('offer:fruehling'));

        // **Der Grund, warum erst nach der Schleife verglichen wird.** Bricht
        // die Pruefung beim ersten Paar ab, nennt die einzige Meldung zu diesem
        // Vorgang nur zwei der drei Marken. Wer das gemeldete Paar korrigiert,
        // liefe danach erneut in denselben Fehler, ohne dass das je dastand.
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $meldung, array $kontext): bool {
                return str_contains($meldung, 'brand')
                    && $kontext['brands'] === [
                        'noten-paket' => 2,
                        'fremdes-paket' => 3,
                        'drittes-paket' => 4,
                    ];
            })
            ->once();
    }

    #[Test]
    public function die_marke_am_modell_ist_eine_zahl_auch_wenn_sie_als_text_ankommt(): void
    {
        // Die Garantie sitzt am Modell und nicht an einer Leseposition. Ein
        // Treiber, der Zeichenketten liefert, faellt sonst nirgends auf — und
        // jede kuenftige Stelle, die `brand_id` liest, muesste es selbst
        // wissen.
        $angebot = $this->angebot(['brand_id' => '2']);

        $this->assertSame(2, $angebot->fresh()->brand_id);
    }
}
