<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\Basket;
use Goldnead\StatamicOffers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * What somebody agreed to buy on an offer page.
 *
 * Every test here is a way somebody could try to buy something they were not
 * offered, or pay less than they were asked. The browser supplies two things — a
 * list of ticked handles and a typed string — and neither may decide a price.
 */
class BasketTest extends TestCase
{
    protected function offer(string $handle, string $product, string $slot = Offer::SLOT_STANDALONE, ?int $cent = null, array $bumps = []): Offer
    {
        return Offer::create([
            'handle' => $handle,
            'name' => ucfirst($handle),
            'product' => $product,
            'amount_cent' => $cent,
            'slot' => $slot,
            'bumps' => $bumps,
            'active' => true,
        ]);
    }

    #[Test]
    public function only_bumps_the_offer_lists_can_be_ticked(): void
    {
        $this->offer('cd', 'begleit-cd', Offer::SLOT_BUMP, 500);
        $this->offer('teuer', 'kurs', Offer::SLOT_BUMP, 9900);
        $main = $this->offer('haupt', 'kurs', bumps: ['cd']);

        // The form claims both. The offer lists one. Believing the form would
        // let anybody add an unrelated product to somebody's basket by editing
        // the page.
        $basket = Basket::make($main, ['cd', 'teuer']);

        $this->assertSame(['offer:haupt', 'offer:cd'], $basket->handles());
    }

    #[Test]
    public function a_bump_that_is_no_longer_sellable_quietly_drops_out(): void
    {
        $bump = $this->offer('cd', 'begleit-cd', Offer::SLOT_BUMP, 500);
        $main = $this->offer('haupt', 'kurs', bumps: ['cd']);

        $bump->update(['active' => false]);

        // Dropped rather than refusing the whole checkout: somebody switching a
        // bump off should stop it being offered, not stop the sale.
        $this->assertSame(['offer:haupt'], Basket::make($main->fresh(), ['cd'])->handles());
    }

    #[Test]
    public function an_offer_cannot_bump_itself(): void
    {
        $main = $this->offer('haupt', 'kurs', Offer::SLOT_BUMP, bumps: ['haupt']);

        $this->assertSame(['offer:haupt'], Basket::make($main, ['haupt'])->handles());
    }

    #[Test]
    public function bumps_are_shown_in_the_order_the_offer_lists_them(): void
    {
        $this->offer('a', 'begleit-cd', Offer::SLOT_BUMP, 500);
        $this->offer('b', 'begleit-cd', Offer::SLOT_BUMP, 700);
        $main = $this->offer('haupt', 'kurs', bumps: ['b', 'a']);

        // The form posts them in DOM order; the editorial order is the one
        // somebody chose in the Control Panel.
        $this->assertSame(['offer:haupt', 'offer:b', 'offer:a'], Basket::make($main, ['a', 'b'])->handles());
    }

    #[Test]
    public function a_percentage_comes_off_the_whole_basket(): void
    {
        $this->offer('cd', 'begleit-cd', Offer::SLOT_BUMP, 500);
        $main = $this->offer('haupt', 'kurs', cent: 9500, bumps: ['cd']);

        Coupon::create(['code' => 'FRUEHLING', 'percent' => 10, 'active' => true]);

        $basket = Basket::make($main, ['cd'], 'fruehling');

        $this->assertSame(10000, $basket->grossCent());
        $this->assertSame(9000, $basket->netCent());
    }

    #[Test]
    public function a_code_that_is_not_for_this_offer_does_nothing(): void
    {
        $main = $this->offer('haupt', 'kurs', cent: 10000);
        $this->offer('anderes', 'kurs', cent: 10000);

        Coupon::create(['code' => 'NUR-ANDERES', 'percent' => 50, 'offers' => ['anderes'], 'active' => true]);

        $basket = Basket::make($main, [], 'NUR-ANDERES');

        $this->assertNull($basket->coupon());
        $this->assertSame(10000, $basket->netCent());
    }

    #[Test]
    public function an_expired_or_exhausted_code_does_nothing(): void
    {
        $main = $this->offer('haupt', 'kurs', cent: 10000);

        Coupon::create(['code' => 'ABGELAUFEN', 'percent' => 50, 'ends_at' => now()->subDay(), 'active' => true]);
        Coupon::create(['code' => 'AUFGEBRAUCHT', 'percent' => 50, 'max_uses' => 1, 'used_count' => 1, 'active' => true]);
        Coupon::create(['code' => 'NOCH-NICHT', 'percent' => 50, 'starts_at' => now()->addDay(), 'active' => true]);

        foreach (['ABGELAUFEN', 'AUFGEBRAUCHT', 'NOCH-NICHT'] as $code) {
            $this->assertSame(10000, Basket::make($main, [], $code)->netCent(), $code.' should not apply');
        }
    }

    #[Test]
    public function a_fixed_discount_cannot_make_the_basket_negative(): void
    {
        $main = $this->offer('haupt', 'kurs', cent: 1000);

        Coupon::create(['code' => 'ZUVIEL', 'amount_cent' => 5000, 'active' => true]);

        $this->assertSame(0, Basket::make($main, [], 'ZUVIEL')->netCent());
    }

    #[Test]
    public function a_percentage_over_a_hundred_is_treated_as_a_hundred(): void
    {
        $main = $this->offer('haupt', 'kurs', cent: 1000);

        // A typo in the Control Panel, not an instruction to pay the buyer.
        Coupon::create(['code' => 'TIPPFEHLER', 'percent' => 500, 'active' => true]);

        $this->assertSame(0, Basket::make($main, [], 'TIPPFEHLER')->netCent());
    }

    #[Test]
    public function the_use_is_claimed_when_the_basket_becomes_a_payment(): void
    {
        $main = $this->offer('haupt', 'kurs', cent: 10000);

        $coupon = Coupon::create(['code' => 'EINMAL', 'percent' => 10, 'max_uses' => 1, 'active' => true]);

        // Merely looking at the price does not use the code up. Somebody who
        // types it and closes the tab has not spent it.
        Basket::make($main, [], 'EINMAL')->netCent();
        $this->assertSame(0, $coupon->fresh()->used_count);

        $discount = Basket::make($main, [], 'EINMAL')->discount();
        $this->assertNotNull($discount);
        $this->assertSame(1000, $discount->amountCent);
        $this->assertSame(1, $coupon->fresh()->used_count);

        // And the last one really is the last one.
        $this->assertNull(Basket::make($main, [], 'EINMAL')->discount());
    }

    #[Test]
    public function a_code_typed_in_any_case_still_works(): void
    {
        $main = $this->offer('haupt', 'kurs', cent: 10000);

        Coupon::create(['code' => 'FrühLing', 'percent' => 10, 'active' => true]);

        $this->assertSame(9000, Basket::make($main, [], '  frühling  ')->netCent());
    }
}
