<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Offers;
use Goldnead\StatamicOffers\Support\Basket;
use Goldnead\StatamicOffers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * O6: wie lange und worauf ein Gutschein wirkt.
 *
 * Bis hierher wirkte jeder Gutschein auf die erste Zahlung und auf den ganzen
 * Korb. Das bleibt die Vorgabe; alles andere muss jemand waehlen.
 */
class CouponDurationTest extends TestCase
{
    protected function abo(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'abo',
            'name' => 'Abo',
            'product' => 'noten-paket',
            'amount_cent' => 2000,
            'interval' => '1 month',
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ], $overrides));
    }

    #[Test]
    public function an_existing_coupon_still_means_the_first_payment_only(): void
    {
        $coupon = Coupon::create(['code' => 'ALT', 'percent' => 10, 'active' => true])->fresh();

        $this->assertSame(Coupon::DURATION_ONCE, $coupon->duration);
        $this->assertSame(Coupon::APPLIES_ORDER, $coupon->applies_to);
        $this->assertTrue($coupon->appliesToPayment(1));
        $this->assertFalse($coupon->appliesToPayment(2));
    }

    #[Test]
    public function repeating_covers_the_first_n_payments(): void
    {
        $coupon = Coupon::create(['code' => 'DREI', 'percent' => 10, 'active' => true,
            'duration' => Coupon::DURATION_REPEATING, 'duration_cycles' => 3]);

        $this->assertTrue($coupon->appliesToPayment(1));
        $this->assertTrue($coupon->appliesToPayment(3));
        $this->assertFalse($coupon->appliesToPayment(4));
    }

    #[Test]
    public function forever_covers_every_payment(): void
    {
        $coupon = Coupon::create(['code' => 'IMMER', 'percent' => 10, 'active' => true,
            'duration' => Coupon::DURATION_FOREVER]);

        $this->assertTrue($coupon->appliesToPayment(48));
    }

    #[Test]
    public function the_basket_hands_the_terms_on_for_the_following_payments(): void
    {
        Coupon::create(['code' => 'DREI', 'percent' => 25, 'active' => true,
            'duration' => Coupon::DURATION_REPEATING, 'duration_cycles' => 3]);

        $basket = Basket::make($this->abo(), [], 'drei');

        $terms = $basket->couponTerms();

        $this->assertSame([
            'code' => 'DREI',
            'percent' => 25,
            'amount_cent' => null,
            'currency' => null,
            'duration' => Coupon::DURATION_REPEATING,
            'cycles' => 3,
        ], $terms);
        $this->assertSame(['coupon' => $terms], $basket->paymentMeta());

        // Zahlung 1 traegt der Korb selbst; ab Zahlung 2 rechnet payments mit
        // derselben Regel. Zahlung 4 ist wieder voll.
        $this->assertSame(500, Offers::recurringDiscountCent($terms, 2, 2000));
        $this->assertSame(500, Offers::recurringDiscountCent($terms, 3, 2000));
        $this->assertSame(0, Offers::recurringDiscountCent($terms, 4, 2000));
    }

    #[Test]
    public function a_fixed_amount_never_takes_more_than_the_payment(): void
    {
        $terms = ['code' => 'X', 'percent' => null, 'amount_cent' => 5000, 'currency' => null,
            'duration' => Coupon::DURATION_FOREVER, 'cycles' => null];

        $this->assertSame(2000, Offers::recurringDiscountCent($terms, 7, 2000));
    }

    #[Test]
    public function a_one_off_purchase_hands_on_nothing(): void
    {
        Coupon::create(['code' => 'IMMER', 'percent' => 10, 'active' => true, 'duration' => Coupon::DURATION_FOREVER]);

        $einmalig = $this->abo(['interval' => null]);

        $this->assertNull(Basket::make($einmalig, [], 'IMMER')->couponTerms());
        $this->assertSame([], Basket::make($einmalig, [], 'IMMER')->paymentMeta());
    }

    #[Test]
    public function a_coupon_can_be_limited_to_the_main_offer_or_to_the_bumps(): void
    {
        Offer::create(['handle' => 'cd', 'name' => 'CD', 'product' => 'begleit-cd', 'amount_cent' => 1000,
            'slot' => Offer::SLOT_BUMP, 'active' => true]);
        $main = Offer::create(['handle' => 'kurs', 'name' => 'Kurs', 'product' => 'noten-paket', 'amount_cent' => 9000,
            'slot' => Offer::SLOT_STANDALONE, 'bumps' => ['cd'], 'active' => true]);

        Coupon::create(['code' => 'KORB', 'percent' => 10, 'active' => true]);
        Coupon::create(['code' => 'HAUPT', 'percent' => 10, 'active' => true, 'applies_to' => Coupon::APPLIES_MAIN]);
        Coupon::create(['code' => 'BUMP', 'percent' => 50, 'active' => true, 'applies_to' => Coupon::APPLIES_BUMPS]);

        $this->assertSame(1000, Basket::make($main, ['cd'], 'KORB')->discount()->amountCent);
        $this->assertSame(900, Basket::make($main, ['cd'], 'HAUPT')->discount()->amountCent);
        $this->assertSame(500, Basket::make($main, ['cd'], 'BUMP')->discount()->amountCent);
        $this->assertSame(9500, Basket::make($main, ['cd'], 'BUMP')->netCent());

        // Ein Bump-Gutschein ohne Bump im Korb nimmt nichts und verbraucht sich
        // nicht.
        $vorher = Coupon::findByCode('BUMP')->used_count;
        $this->assertNull(Basket::make($main, [], 'BUMP')->discount());
        $this->assertSame($vorher, Coupon::findByCode('BUMP')->used_count);
    }

    #[Test]
    public function funnel_wide_is_readable_by_the_funnel(): void
    {
        $coupon = Coupon::create(['code' => 'LAUF', 'percent' => 10, 'active' => true, 'funnel_wide' => true]);

        $this->assertTrue($coupon->coversFollowUps());
        $this->assertFalse(Coupon::create(['code' => 'NUR', 'percent' => 10, 'active' => true])->coversFollowUps());
    }

    #[Test]
    public function the_form_needs_a_count_for_repeating(): void
    {
        $user = tap(User::make()->email('studio@example.com')->makeSuper())->save();

        $this->actingAs($user)
            ->post(cp_route('utilities.coupons.store'), [
                'code' => 'DREI', 'percent' => 10, 'active' => true,
                'duration' => Coupon::DURATION_REPEATING,
            ])
            ->assertSessionHasErrors('duration_cycles');

        $this->post(cp_route('utilities.coupons.store'), [
            'code' => 'DREI', 'percent' => 10, 'active' => true,
            'duration' => Coupon::DURATION_REPEATING, 'duration_cycles' => 3,
            'applies_to' => Coupon::APPLIES_MAIN, 'funnel_wide' => true,
        ])->assertSessionHasNoErrors();

        $coupon = Coupon::findByCode('DREI');
        $this->assertSame(3, $coupon->duration_cycles);
        $this->assertSame(Coupon::APPLIES_MAIN, $coupon->applies_to);
        $this->assertTrue($coupon->funnel_wide);

        // Ohne Wiederholung wird die Zahl geleert, damit sie nicht spaeter
        // wirkt, wenn jemand die Dauer umstellt.
        $this->patch(cp_route('utilities.coupons.update', $coupon->id), [
            'code' => 'DREI', 'percent' => 10, 'active' => true,
            'duration' => Coupon::DURATION_FOREVER, 'duration_cycles' => 3,
        ])->assertSessionHasNoErrors();

        $this->assertNull($coupon->fresh()->duration_cycles);
    }
}
