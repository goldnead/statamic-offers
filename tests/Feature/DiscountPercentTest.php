<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\Basket;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Checkout;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * A percentage off the catalogue price.
 *
 * The price stays in one place — the catalogue — and the struck-through price
 * follows it. Every test here is about the arithmetic being the same wherever
 * the price is read: model, catalogue resolver, basket, checkout.
 */
class DiscountPercentTest extends TestCase
{
    protected function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'rabatt',
            'name' => 'Rabatt',
            'product' => 'noten-paket', // 2900
            'slot' => Offer::SLOT_POST_PURCHASE,
            'active' => true,
        ], $overrides));
    }

    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    #[Test]
    public function the_effective_price_is_the_catalogue_price_minus_the_share(): void
    {
        $offer = $this->offer(['discount_percent' => 20]);

        $this->assertSame(2320, $offer->effectiveAmountCent());
        $this->assertSame(2320, $offer->amountCent());
        $this->assertSame(2900, $offer->effectiveCompareAtCent());
        $this->assertSame('29.00', $offer->compareAt());
    }

    #[Test]
    public function rounding_is_to_the_nearest_cent(): void
    {
        // A percentage of a price that is a whole number of euros is always a
        // whole number of cents, so the fixture prices cannot show rounding.
        // A price of 9.99 can.
        config()->set('statamic-payments.products.krumm', ['name' => 'Krumm', 'amount_cent' => 999]);

        $offer = $this->offer(['product' => 'krumm', 'discount_percent' => 15]);

        // 999 × 0.85 = 849.15 → 849
        $this->assertSame(849, $offer->effectiveAmountCent());

        $offer->update(['discount_percent' => 35]);
        // 999 × 0.65 = 649.35 → 649
        $this->assertSame(649, $offer->fresh()->effectiveAmountCent());

        $offer->update(['discount_percent' => 45]);
        // 999 × 0.55 = 549.45 → 549
        $this->assertSame(549, $offer->fresh()->effectiveAmountCent());

        $offer->update(['discount_percent' => 25]);
        // 999 × 0.75 = 749.25 → 749
        $this->assertSame(749, $offer->fresh()->effectiveAmountCent());
    }

    #[Test]
    public function a_bundle_is_discounted_on_the_sum_of_its_parts(): void
    {
        $offer = $this->offer(['products' => ['begleit-cd'], 'discount_percent' => 10]);

        // (2900 + 1200) × 0.9 = 3690
        $this->assertSame(3690, $offer->effectiveAmountCent());
        $this->assertSame(4100, $offer->effectiveCompareAtCent());
    }

    #[Test]
    public function an_own_price_wins_and_a_hand_set_compare_at_wins(): void
    {
        $offer = $this->offer(['amount_cent' => 1000, 'compare_at_cent' => 5000, 'discount_percent' => 20]);

        $this->assertSame(1000, $offer->effectiveAmountCent());
        $this->assertSame(5000, $offer->effectiveCompareAtCent());
    }

    #[Test]
    public function without_a_percentage_nothing_changes(): void
    {
        $offer = $this->offer();

        $this->assertSame(2900, $offer->effectiveAmountCent());
        $this->assertNull($offer->effectiveCompareAtCent());
    }

    #[Test]
    public function the_catalogue_the_basket_and_the_checkout_all_charge_the_discounted_price(): void
    {
        $this->offer(['discount_percent' => 20]);

        $this->assertSame(2320, app(Catalogue::class)->find('offer:rabatt')['amount_cent']);
        $this->assertSame(2320, Basket::make(Offer::query()->where('handle', 'rabatt')->firstOrFail())->grossCent());
        $this->assertSame(2320, app(Checkout::class)->start('offer:rabatt')->payment->amount_cent);
    }

    #[Test]
    public function the_control_panel_refuses_a_price_and_a_percentage_together(): void
    {
        $this->actingAs($this->user())->postJson('/cp/utilities/offers', [
            'name' => 'Rabatt',
            'handle' => 'rabatt',
            'product' => 'noten-paket',
            'slot' => Offer::SLOT_POST_PURCHASE,
            'amount_cent' => 1000,
            'discount_percent' => 20,
        ])->assertJsonValidationErrors(['amount_cent', 'discount_percent']);

        $this->assertSame(0, Offer::count());
    }

    #[Test]
    public function the_control_panel_bounds_the_percentage(): void
    {
        foreach ([0, 100] as $percent) {
            $this->actingAs($this->user())->postJson('/cp/utilities/offers', [
                'name' => 'Rabatt',
                'handle' => 'rabatt',
                'product' => 'noten-paket',
                'slot' => Offer::SLOT_POST_PURCHASE,
                'discount_percent' => $percent,
            ])->assertJsonValidationErrors(['discount_percent']);
        }
    }

    #[Test]
    public function the_listing_shows_the_derived_compare_at_price(): void
    {
        $this->offer(['discount_percent' => 20]);

        $row = collect($this->actingAs($this->user())->getJson('/cp/utilities/offers')->json('data'))
            ->firstWhere('handle', 'rabatt');

        $this->assertSame(20, $row['discount_percent']);
        $this->assertNotNull($row['compare_at']);
        $this->assertNull($row['edit_values']['compare_at_cent']);
    }
}
