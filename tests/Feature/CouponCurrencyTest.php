<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Offers;
use Goldnead\StatamicOffers\Support\CouponBatch;
use Goldnead\StatamicOffers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Statamic\Facades\User;

/**
 * Ein Festbetrag hat eine Waehrung.
 *
 * Bis 1.21.1 durfte ein Gutschein „10,00 weniger" ohne Waehrung gespeichert
 * werden, und `Coupon::apply()` ueberging dann die Pruefung: 10 Euro Rabatt
 * auf einen Preis in Franken oder Pfund. Neue Festbetraege brauchen jetzt die
 * Waehrung; bestehende ohne gelten in der Waehrung des Shops, damit verteilte
 * Codes nicht ueber Nacht still wirkungslos werden.
 */
class CouponCurrencyTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.currency', 'EUR');
    }

    protected function legacy(): Coupon
    {
        // Wie ein Bestandsgutschein: Festbetrag, Waehrung leer.
        return Coupon::create(['code' => 'ALT10', 'amount_cent' => 1000, 'currency' => null, 'active' => true])->fresh();
    }

    #[Test]
    public function a_fixed_amount_without_currency_counts_in_the_shop_currency_only(): void
    {
        $coupon = $this->legacy();

        $this->assertSame(4000, $coupon->apply(5000, 'EUR'));
        $this->assertSame(4000, $coupon->apply(5000, 'eur'));
        $this->assertSame(5000, $coupon->apply(5000, 'CHF'));
        $this->assertSame(5000, $coupon->apply(5000, 'GBP'));
    }

    #[Test]
    public function a_fixed_amount_is_not_taken_off_a_price_of_unknown_currency(): void
    {
        $this->assertSame(5000, $this->legacy()->apply(5000));

        $mitWaehrung = Coupon::create(['code' => 'EUR10', 'amount_cent' => 1000, 'currency' => 'EUR', 'active' => true]);
        $this->assertSame(5000, $mitWaehrung->apply(5000));

        // Ein Prozentsatz braucht keine Waehrung.
        $prozent = Coupon::create(['code' => 'PROZ', 'percent' => 10, 'active' => true]);
        $this->assertSame(4500, $prozent->apply(5000));
    }

    #[Test]
    public function the_frozen_terms_name_the_currency_and_follow_up_payments_respect_it(): void
    {
        $terms = array_merge($this->legacy()->terms(), ['duration' => Coupon::DURATION_FOREVER]);

        $this->assertSame('EUR', $terms['currency']);
        $this->assertSame(1000, Offers::recurringDiscountCent($terms, 3, 5000, 'EUR'));
        $this->assertSame(0, Offers::recurringDiscountCent($terms, 3, 5000, 'CHF'));

        // Schon eingefroren ohne Waehrung (Abo von vor dieser Fassung).
        $alt = array_merge($terms, ['currency' => null]);
        $this->assertSame(1000, Offers::recurringDiscountCent($alt, 3, 5000, 'EUR'));
        $this->assertSame(0, Offers::recurringDiscountCent($alt, 3, 5000, 'CHF'));
    }

    #[Test]
    public function the_control_panel_wants_a_currency_for_a_fixed_amount(): void
    {
        $user = tap(User::make()->email('studio@example.com')->makeSuper())->save();

        $this->actingAs($user)
            ->postJson('/cp/utilities/coupons', ['code' => 'FEST', 'amount_cent' => 500, 'active' => true])
            ->assertJsonValidationErrors(['currency']);

        $this->actingAs($user)
            ->postJson('/cp/utilities/coupons/generate', ['count' => 2, 'amount_cent' => 500])
            ->assertJsonValidationErrors(['currency']);

        $this->assertSame(0, Coupon::count());

        // Ein Prozentsatz kommt ohne aus.
        $this->actingAs($user)
            ->postJson('/cp/utilities/coupons', ['code' => 'PROZ', 'percent' => 10, 'active' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Coupon::count());
    }

    #[Test]
    public function a_batch_with_a_fixed_amount_and_no_currency_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        (new CouponBatch)->generate(['count' => 2, 'amount_cent' => 500]);
    }
}
