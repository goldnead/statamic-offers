<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\BrandContext\Facades\BrandSettings;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\Settings;
use Goldnead\StatamicOffers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die Einstellungen dieses Addons, und ob ein geänderter Wert dort ankommt, wo
 * er gelesen wird.
 *
 * Die Grenze, die diese Tests überschreiten müssen, ist nicht die zum
 * Formular — die gehört `statamic-brand-context` und ist dort geprüft. Sie ist
 * die zwischen einer gespeicherten Überschreibung und dem, was ein Käufer
 * liest: `Offer::withdrawalTerms()`. Eine Zusicherung auf die gespeicherte
 * Zeile allein würde auch dann grün bleiben, wenn dieses Addon seine Werte aus
 * einer ganz anderen Config-Wurzel liest.
 */
class SettingsTest extends TestCase
{
    protected function offer(): Offer
    {
        return Offer::create([
            'handle' => 'kurs',
            'name' => 'Kurs',
            'product' => 'noten-paket',
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ]);
    }

    #[Test]
    public function it_registers_with_the_shared_settings_registry(): void
    {
        // Das Einzige, was keine andere Zusicherung hier fangen würde: ein
        // erfüllter Vertrag, der nie angemeldet wird, ist ein Bildschirm ohne
        // Angebots-Abschnitt — und alles Übrige liefe trotzdem durch.
        $registry = app(SettingsRegistry::class);

        $this->assertTrue($registry->has('offers'));
        $this->assertSame(Settings::class, $registry->provider('offers'));
        // Der Namensraum heißt `offers`, die Config-Wurzel `statamic-offers`.
        // Wären sie hier vertauscht, landeten die Überschreibungen auf einer
        // Wurzel, die niemand liest.
        $this->assertSame('statamic-offers', $registry->configPath('offers'));
        $this->assertSame('manage offers settings', $registry->permission('offers'));
    }

    #[Test]
    public function a_saved_seller_name_reaches_the_text_the_buyer_reads(): void
    {
        $offer = $this->offer();

        // Vorher: der Platzhalter fällt auf den Anwendungsnamen zurück.
        $this->assertStringNotContainsString('Notenwerkstatt', $offer->withdrawalTerms()['text']);

        BrandSettings::for('offers')->save([
            'seller.name' => 'Notenwerkstatt Goldner',
            'seller.contact' => 'widerruf@example.com',
        ]);

        $terms = $offer->withdrawalTerms();

        $this->assertStringContainsString('Notenwerkstatt Goldner', $terms['text']);
        $this->assertStringContainsString('widerruf@example.com', $terms['text']);
    }

    #[Test]
    public function a_saved_withdrawal_notice_replaces_the_packaged_draft(): void
    {
        $offer = $this->offer();
        $vorher = $offer->withdrawalTerms();

        // Der Fall, für den der mehrzeilige Typ existiert: ein anwaltlich
        // geprüfter Ersatz für den mitgelieferten Entwurf, länger als die 255
        // Zeichen, die ein einzeiliges Feld zuließe.
        $geprueft = "Widerrufsrecht\n\n"
            .str_repeat('Sie haben das Recht, diesen Vertrag binnen {days} Tagen zu widerrufen. ', 12);

        BrandSettings::for('offers')->save([
            'withdrawal.days' => 30,
            'withdrawal.text' => $geprueft,
        ]);

        $terms = $offer->withdrawalTerms();

        $this->assertGreaterThan(255, strlen($terms['text']));
        $this->assertStringContainsString('binnen 30 Tagen', $terms['text']);
        $this->assertSame(30, $terms['days']);

        // Die Fassung, die eine Zahlung einfriert, hängt am Wortlaut. Bliebe
        // sie gleich, wäre später nicht mehr zu beantworten, welchen Text ein
        // Käufer gesehen hat.
        $this->assertNotSame($vorher['version'], $terms['version']);
    }

    #[Test]
    public function an_offer_with_its_own_wording_keeps_it(): void
    {
        // Die Einstellung ist die Vorgabe, nicht das Gesetz. Ein Angebot, das
        // selbst etwas sagt, darf davon nicht überschrieben werden — sonst
        // änderte ein Griff auf dem Einstellungsbildschirm den Text unter
        // jedem einzelnen Angebot.
        $offer = $this->offer();
        $offer->withdrawal_text = 'Für dieses Angebot gilt etwas anderes.';
        $offer->save();

        BrandSettings::for('offers')->save([
            'withdrawal.text' => 'Die neue Vorgabe der Seite.',
        ]);

        $this->assertSame('Für dieses Angebot gilt etwas anderes.', $offer->withdrawalTerms()['text']);
    }

    #[Test]
    public function counting_stops_when_it_is_switched_off(): void
    {
        BrandSettings::for('offers')->save(['count_impressions' => false]);

        $this->assertFalse(config('statamic-offers.count_impressions'));
    }
}
