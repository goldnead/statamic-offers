<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;
use Goldnead\StatamicPayments\Support\Catalogue;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ein Angebot, das mehr als eine Sache verkauft.
 *
 * Ein Buendel ist **eine** Zeile zu **einem** Preis, die mehrere Dinge
 * uebergibt — und nicht drei Zeilen. Drei Zeilen waeren die naheliegende
 * Bauart und die falsche: sobald ein Buendelpreis unter der Summe der Teile
 * liegt, muesste der Nachlass auf die Zeilen verteilt werden, und dafuer gibt
 * es keine ehrliche Regel, wenn die Teile verschiedene Steuerfakten tragen.
 *
 * Drei Dinge koennen hier still schiefgehen, und fuer jedes steht unten ein
 * Fall: der Preis faellt auf ein Teil zurueck (zu billig verkauft), die
 * Freischaltung gibt nur das erste Stueck her (bezahlt, nichts bekommen), und
 * ein Teil faellt aus dem Katalog (weniger geliefert als verkauft).
 */
class BundleOfferTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'noten-paket' => [
                'name' => 'Notenpaket',
                'amount_cent' => 2900,
                'digital' => true,
                'grants' => 'noten-zugang',
            ],
            'playback-paket' => [
                'name' => 'Playback-Paket',
                'amount_cent' => 1900,
                'digital' => true,
                'grants' => 'playback-zugang',
            ],
            'mitschnitt' => [
                'name' => 'Workshop-Mitschnitt',
                'amount_cent' => 2100,
                'digital' => true,
                'grants' => ['mitschnitt-zugang', 'noten-zugang'],
            ],
            // Eine Live-Leistung: nicht elektronisch erbracht, anderer
            // Leistungsort, anderer Pflichthinweis.
            'einzelstunde' => [
                'name' => 'Einzelstunde',
                'amount_cent' => 12500,
                'digital' => false,
                'grants' => 'stunde',
            ],
        ]);
    }

    protected function bundle(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'fruehlings-buendel',
            'name' => 'Frühlings-Bündel',
            'product' => 'noten-paket',
            'products' => ['playback-paket', 'mitschnitt'],
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ], $overrides));
    }

    #[Test]
    public function a_bundle_without_a_price_of_its_own_costs_the_sum_of_its_parts(): void
    {
        $bundle = $this->bundle();

        // 2900 + 1900 + 2100. Und nicht 2900: das waere der Preis des
        // Leitprodukts, und drei Dinge zum Preis von einem waere ein Fehler,
        // den erst die Buchhaltung findet.
        $this->assertSame(6900, $bundle->amountCent());
        $this->assertSame(6900, app(Catalogue::class)->find('offer:fruehlings-buendel')['amount_cent']);
    }

    #[Test]
    public function a_bundle_price_of_its_own_wins(): void
    {
        $bundle = $this->bundle(['amount_cent' => 4900]);

        $this->assertSame(4900, $bundle->amountCent());
        $this->assertSame(4900, app(Catalogue::class)->find('offer:fruehlings-buendel')['amount_cent']);
    }

    #[Test]
    public function a_bundle_hands_over_everything_its_parts_grant(): void
    {
        $this->bundle(['amount_cent' => 4900]);

        $resolved = app(Catalogue::class)->find('offer:fruehlings-buendel');

        // Vereinigung, und `noten-zugang` nur einmal, obwohl zwei Teile ihn
        // vergeben.
        $this->assertEqualsCanonicalizing(
            ['noten-zugang', 'playback-zugang', 'mitschnitt-zugang'],
            $resolved['grants'],
        );
    }

    #[Test]
    public function an_offer_over_one_thing_is_untouched(): void
    {
        Offer::create([
            'handle' => 'einzeln',
            'name' => 'Notenpaket',
            'product' => 'noten-paket',
            'amount_cent' => 1200,
            'slot' => Offer::SLOT_POST_PURCHASE,
            'active' => true,
        ]);

        $resolved = app(Catalogue::class)->find('offer:einzeln');

        // Unveraendert die Zeichenkette aus dem Produkt, keine Liste: eine
        // Fassung, die hier ploetzlich `['noten-zugang']` zurueckgaebe, haette
        // jede aeltere Installation von `statamic-payments` daneben stumm um
        // ihre Freischaltung gebracht.
        $this->assertSame('noten-zugang', $resolved['grants']);
        $this->assertSame(1200, $resolved['amount_cent']);

        // Kein `products` an einem Angebot ueber eine Sache: der Schluessel
        // sagt „hier sind mehrere Teile", und ein Schluessel mit einem Element
        // darin waere eine Aussage, die niemand gemacht hat.
        $this->assertArrayNotHasKey('products', $resolved);
    }

    #[Test]
    public function a_bundle_whose_parts_disagree_about_delivery_is_not_sellable(): void
    {
        $this->bundle([
            'products' => ['playback-paket', 'einzelstunde'],
            'amount_cent' => 12900,
        ]);

        // Nichts, und zwar laut vor dem Geld: `Checkout::start()` verweigert
        // einen Handle, den der Katalog nicht kennt, und bricht den ganzen
        // Vorgang ab. Die Alternative waere, einen der beiden Pflichthinweise
        // zu raten und auf ein Dokument zu schreiben, das sich nicht
        // korrigieren laesst.
        $this->assertNull(app(Catalogue::class)->find('offer:fruehlings-buendel'));
    }

    #[Test]
    public function a_bundle_with_a_part_the_catalogue_does_not_know_is_not_sellable(): void
    {
        $bundle = $this->bundle(['products' => ['playback-paket', 'gibt-es-nicht']]);

        $this->assertFalse($bundle->isSellable());
        $this->assertNull($bundle->amountCent());
        $this->assertNull(app(Catalogue::class)->find('offer:fruehlings-buendel'));
    }

    #[Test]
    public function the_lead_product_stays_the_one_the_invoice_line_is_filed_under(): void
    {
        $this->bundle(['amount_cent' => 4900]);

        $resolved = app(Catalogue::class)->find('offer:fruehlings-buendel');

        // Die Steuerklasse einer Zeile haengt am Produkt-Handle. Ein Buendel
        // hat drei Teile und braucht trotzdem genau einen — das Leitprodukt.
        $this->assertSame('noten-paket', $resolved['product']);
        $this->assertSame('offer:fruehlings-buendel', $resolved['handle']);
        $this->assertTrue($resolved['digital']);

        // Und daneben, benannt, was tatsaechlich geliefert wird. Wer nur
        // `product` liest, liefert ein Drittel des Buendels aus.
        $this->assertSame(
            ['noten-paket', 'playback-paket', 'mitschnitt'],
            $resolved['products'],
        );
    }

    #[Test]
    public function the_lead_product_is_never_counted_twice(): void
    {
        $bundle = $this->bundle(['products' => ['noten-paket', 'playback-paket']]);

        // 2900 + 1900, nicht 2900 + 2900 + 1900.
        $this->assertSame(4800, $bundle->amountCent());
        $this->assertSame(['noten-paket', 'playback-paket'], $bundle->productHandles());
    }

    #[Test]
    public function a_bundle_that_grants_several_things_needs_a_sibling_that_understands_lists(): void
    {
        config(['statamic-payments.entitlements.enabled' => true]);

        $this->bundle(['amount_cent' => 4900]);

        // Absichtlich gegen die *installierte* Fassung und nicht gegen eine
        // Zahl in der composer.json: dieselbe Frage, die der Resolver stellt.
        // So sagt der Test dasselbe, egal welches Geschwister danebenliegt —
        // und er faellt um, sobald die beiden Antworten auseinandergehen.
        $kannListen = method_exists(EntitlementsBridge::class, 'slugsFor');

        $resolved = app(Catalogue::class)->find('offer:fruehlings-buendel');

        if ($kannListen) {
            $this->assertNotNull($resolved);
            $this->assertCount(3, $resolved['grants']);

            return;
        }

        // Aeltere Bruecke: `grants` als Liste faellt dort an `is_string()`
        // heraus und vergibt **gar nichts**. Dann lieber nicht verkaufbar —
        // sichtbar unbequem statt still um die Lieferung gebracht.
        $this->assertNull($resolved);
    }

    #[Test]
    public function a_bundle_without_entitlements_turned_on_is_never_blocked_by_that_check(): void
    {
        config(['statamic-payments.entitlements.enabled' => false]);

        $this->bundle(['amount_cent' => 4900]);

        // Ist die Zugangsbruecke aus, sagt `grants` nichts ueber diese
        // Installation. Ein Buendel aus drei Dingen ohne Zugangsverwaltung ist
        // voellig in Ordnung und darf nicht an einer Pruefung haengen, die eine
        // Frage stellt, die hier niemand gestellt hat.
        $this->assertNotNull(app(Catalogue::class)->find('offer:fruehlings-buendel'));
    }
}
