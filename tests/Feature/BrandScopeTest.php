<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\BrandContext\Models\Brand;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Support\Catalogue;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * Ein Control Panel, mehrere Angebotslisten.
 *
 * Wer als Chorwerkstatt arbeitet, sah bisher die Angebote von Halbmond und
 * Lindhorst in derselben Tabelle — dreizehn Zeilen aus fuenf Marken, waehrend
 * die Produktliste derselben Demo sauber trennt.
 *
 * **Die Verengung liegt ausdruecklich an den CP-Wegen und nicht als globaler
 * Scope am Modell**, und der wichtigste Test hier ist der, der das belegt: ein
 * Webhook hat keine Marke, und `Brands::only()` schliesst bei unbeantwortbarer
 * Frage zu. Laege der Scope global, faende die Erfuellung einer bezahlten
 * Bestellung ihr Angebot nicht mehr — Geld geflossen, nichts ausgeliefert,
 * keine Meldung.
 */
class BrandScopeTest extends TestCase
{
    /**
     * Der **echte** Manager von statamic-brand-context, eingestellt.
     *
     * Kein Double. Ein Stellvertreter, der nur die drei Fragen von {@see Brands}
     * beantwortet, kann weniger als der Anbieter: brand-context haengt sich
     * auch in die Warteschlange und verlangt dort einen echten `BrandManager`,
     * und ein Double faellt an dieser Typangabe um. Ein Test, der an einer
     * Stelle vorbeimisst, an der der Anbieter mehr tut, belegt weniger als er
     * behauptet.
     */
    protected function marke(bool $multi = true, ?int $current = 1): void
    {
        config(['brand-context.multi_brand' => $multi]);

        $manager = app('brand-context');

        if ($current === null) {
            $manager->forget();

            return;
        }

        $marke = Brand::query()->find($current) ?? Brand::create([
            'id' => $current,
            'handle' => 'marke-'.$current,
            'name' => 'Marke '.$current,
        ]);

        $manager->setCurrent($marke);
    }

    protected function angebot(string $handle, int $brand, string $slot = Offer::SLOT_STANDALONE): Offer
    {
        return Offer::create([
            'handle' => $handle,
            'name' => ucfirst($handle),
            'product' => 'noten-paket',
            'amount_cent' => 1000,
            'slot' => $slot,
            'active' => true,
            'brand_id' => $brand,
        ]);
    }

    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    #[Test]
    public function die_liste_zeigt_nur_die_angebote_der_eigenen_marke(): void
    {
        $this->marke(current: 1);

        $this->angebot('cw-kurs', 1);
        $this->angebot('hm-vinyl', 2);
        $this->angebot('lh-karte', 3);

        $handles = collect($this->actingAs($this->user())->getJson('/cp/utilities/offers')->json('data'))
            ->pluck('handle')
            ->all();

        $this->assertSame(['cw-kurs'], $handles);
    }

    #[Test]
    public function ein_neues_angebot_bekommt_die_marke_des_anlegenden(): void
    {
        $this->marke(current: 7);

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/offers', [
                'name' => 'Frisch',
                'handle' => 'frisch',
                'product' => 'noten-paket',
                'amount_cent' => 1000,
                'slot' => Offer::SLOT_STANDALONE,
                'active' => true,
            ])
            ->assertRedirect();

        $this->assertSame(7, Offer::query()->where('handle', 'frisch')->firstOrFail()->brand_id);
    }

    #[Test]
    public function ein_fremdes_angebot_laesst_sich_weder_aendern_noch_loeschen(): void
    {
        $this->marke(current: 1);

        $fremd = $this->angebot('hm-vinyl', 2);

        // Die Liste zeigt es nicht mehr; ohne diese Wache nimmt die Route es
        // trotzdem an, und eine Nummer zu raten ist keine Kunst.
        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/offers/'.$fremd->id, [
                'name' => 'Uebernommen',
                'handle' => 'hm-vinyl',
                'product' => 'noten-paket',
                'amount_cent' => 1,
                'slot' => Offer::SLOT_STANDALONE,
                'active' => true,
            ])
            ->assertNotFound();

        $this->actingAs($this->user())
            ->deleteJson('/cp/utilities/offers/'.$fremd->id)
            ->assertNotFound();

        $this->assertSame('Hm-vinyl', $fremd->fresh()->name);
    }

    #[Test]
    public function das_bump_auswahlfeld_zeigt_nur_die_eigene_marke(): void
    {
        $this->marke(current: 1);

        $this->angebot('cw-cd', 1, Offer::SLOT_BUMP);
        $this->angebot('hm-shirt', 2, Offer::SLOT_BUMP);

        $seite = $this->actingAs($this->user())->get('/cp/utilities/offers');

        $seite->assertOk()
            ->assertSee('cw-cd')
            // Ein Auswahlfeld mit zu vielen Zeilen sieht aus wie ein
            // Auswahlfeld; deshalb steht es hier als Zusicherung.
            ->assertDontSee('hm-shirt');
    }

    #[Test]
    public function der_katalog_findet_ein_angebot_auch_ohne_aktuelle_marke(): void
    {
        // **Der Webhook-Fall, und der Grund, warum hier kein globaler Scope
        // liegt.** Der Anbieter meldet eine Zahlung, es gibt keine Sitzung und
        // keine Marke. Ein Modell mit globalem Scope antwortete hier `null`,
        // die Erfuellung faende ihr Angebot nicht, und niemand erfuehre davon.
        $this->angebot('cw-kurs', 1);

        $this->marke(current: null);

        $eintrag = app(Catalogue::class)->find('offer:cw-kurs');

        $this->assertIsArray($eintrag);
        $this->assertSame(1000, $eintrag['amount_cent']);
    }

    #[Test]
    public function ohne_mandanten_bleibt_alles_wie_vorher(): void
    {
        // Der Normalfall: ein Betrieb, eine Marke, jede Zeile auf Null. Die
        // Verengung darf dort gar nicht erst greifen.
        $this->marke(multi: false, current: null);

        $this->angebot('kurs', 0);
        $this->angebot('cd', 0);

        $handles = collect($this->actingAs($this->user())->getJson('/cp/utilities/offers')->json('data'))
            ->pluck('handle')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['cd', 'kurs'], $handles);
    }
}
