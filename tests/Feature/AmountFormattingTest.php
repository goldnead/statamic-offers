<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A price has two readers, and they want opposite things.
 *
 * Anything that parses wants a dot and no grouping, always, whatever language
 * the site speaks. A person wants their own language: in German the dot is not
 * a decimal separator at all — it groups thousands — so "1.249" printed for a
 * price of one thousand two hundred forty-nine reads as one thousand two
 * hundred forty-nine, and "249.00" reads as a machine talking.
 *
 * The two used to be the same method, and the machine won.
 */
class AmountFormattingTest extends TestCase
{
    #[Test]
    public function the_parseable_amount_keeps_its_dot_in_every_language(): void
    {
        $offer = new Offer(['amount_cent' => 124950, 'compare_at_cent' => 199900]);

        foreach (['de', 'en', 'fr'] as $sprache) {
            $this->app->setLocale($sprache);

            $this->assertSame('1249.50', $offer->amount(), "amount() drifted under {$sprache}");
            $this->assertSame('1999.00', $offer->compareAt(), "compareAt() drifted under {$sprache}");
        }
    }

    #[Test]
    public function the_readable_amount_follows_the_language(): void
    {
        if (! class_exists(\NumberFormatter::class)) {
            $this->markTestSkipped('ext-intl is what knows how a language writes a number.');
        }

        $offer = new Offer(['amount_cent' => 124950]);

        $this->app->setLocale('de');
        $this->assertSame('1.249,50', $offer->amountLocal());

        $this->app->setLocale('en');
        $this->assertSame('1,249.50', $offer->amountLocal());
    }

    /**
     * Ohne `ext-intl` bleibt der Preis trotzdem in seiner Sprache.
     *
     * **Der Zweig, den kein Test erreichte, war der Zweig, der lief.** Auf
     * diesem Rechner ist `intl` geladen, im Container von adriangoldner.com
     * nicht — und dort stand am 07.09.2026 in der Kasse „520.00 €". Der
     * Rueckfall gab einfach `number_format(..., '.', '')` zurueck, also genau
     * das, wovor der Kommentar ueber dieser Klasse warnt.
     *
     * Deshalb wird hier die Rueckfall-Methode direkt gerufen: welcher Zweig
     * laeuft, entscheidet die Umgebung, und ein Test darf sich davon nicht
     * aussuchen lassen, was er prueft.
     */
    #[Test]
    public function without_intl_the_price_still_follows_the_language(): void
    {
        $this->assertSame('1.249,50', Offer::localiseWithoutIntl(124950, 'de'));
        $this->assertSame('1.249,50', Offer::localiseWithoutIntl(124950, 'de_AT'));
        $this->assertSame('1.249,50', Offer::localiseWithoutIntl(124950, 'de-DE'));
        $this->assertSame('1,249.50', Offer::localiseWithoutIntl(124950, 'en'));

        // Eine Sprache, ueber die dieses Paket nichts weiss, bekommt keine
        // geratene Schreibweise, sondern die neutrale.
        $this->assertSame('1,249.50', Offer::localiseWithoutIntl(124950, 'ja'));

        // Und die Nachkommastellen bleiben, auch wenn sie null sind.
        $this->assertSame('520,00', Offer::localiseWithoutIntl(52000, 'de'));
    }

    #[Test]
    public function two_decimals_survive_a_round_number(): void
    {
        // 249 is not 249,0 and not 249. A price has cents even when they are zero.
        $offer = new Offer(['amount_cent' => 24900]);

        $this->app->setLocale('de');

        $this->assertSame('249,00', $offer->amountLocal());
        $this->assertSame('249.00', $offer->amount());
    }

    #[Test]
    public function nothing_stays_nothing(): void
    {
        // A free offer and an offer whose product carries the price are both
        // "no own amount", and neither may print a zero.
        $offer = new Offer(['amount_cent' => null, 'compare_at_cent' => null]);

        $this->assertNull($offer->compareAt());
        $this->assertNull($offer->compareAtLocal());
    }
}
