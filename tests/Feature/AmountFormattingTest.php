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
