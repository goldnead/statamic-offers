<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The coupon screen.
 *
 * Every row on this screen is permission for a stranger to pay less, so the
 * tests here are the ways somebody could give themselves one: writing without
 * the permission, posting a discount the form does not offer, or taking a code
 * somebody else already has.
 */
class CouponScreenTest extends TestCase
{
    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    protected function userWithoutPermission()
    {
        $role = tap(Role::make('nur-cp')->addPermission('access cp'))->save();

        return tap(User::make()->email('ohne@example.com')->assignRole($role))->save();
    }

    /**
     * @return array<string, mixed>
     */
    protected function valid(array $overrides = []): array
    {
        return array_merge([
            'code' => 'FRUEHLING',
            'name' => 'Frühlingsaktion',
            'percent' => 20,
            'active' => true,
        ], $overrides);
    }

    protected function coupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'BESTAND',
            'percent' => 10,
            'active' => true,
        ], $overrides));
    }

    #[Test]
    public function a_user_without_the_permission_cannot_write(): void
    {
        $coupon = $this->coupon();
        $user = $this->userWithoutPermission();

        // Three writing routes, three locks. A hidden button is not one.
        $this->actingAs($user)->postJson('/cp/utilities/coupons', $this->valid())->assertForbidden();
        $this->actingAs($user)->patchJson('/cp/utilities/coupons/'.$coupon->id, $this->valid(['percent' => 90]))->assertForbidden();
        $this->actingAs($user)->deleteJson('/cp/utilities/coupons/'.$coupon->id)->assertForbidden();

        $this->assertSame(10, $coupon->fresh()->percent);
        $this->assertSame(1, Coupon::count());
    }

    #[Test]
    public function a_user_without_the_permission_cannot_run_actions(): void
    {
        $coupon = $this->coupon(['active' => false]);
        $user = $this->userWithoutPermission();

        // The bulk endpoints write too. They are the ones most easily left
        // behind a checkbox that the browser simply never renders.
        $this->actingAs($user)->postJson('/cp/utilities/coupons/actions', [
            'action' => 'statamic_offers_activate_coupon',
            'selections' => [$coupon->id],
        ])->assertForbidden();

        $this->actingAs($user)->postJson('/cp/utilities/coupons/actions/list', [
            'selections' => [$coupon->id],
        ])->assertForbidden();

        $this->assertFalse($coupon->fresh()->active);
    }

    #[Test]
    public function a_user_without_the_permission_cannot_read_the_listing(): void
    {
        $this->coupon();

        $this->actingAs($this->userWithoutPermission())
            ->getJson('/cp/utilities/coupons')
            ->assertForbidden();
    }

    #[Test]
    public function a_coupon_can_be_created_changed_and_deleted(): void
    {
        $this->actingAs($this->user())
            ->post('/cp/utilities/coupons', $this->valid())
            ->assertSessionHasNoErrors();

        $coupon = Coupon::firstWhere('code', 'FRUEHLING');
        $this->assertSame(20, $coupon->percent);
        $this->assertNull($coupon->amount_cent);

        $this->actingAs($this->user())
            ->patch('/cp/utilities/coupons/'.$coupon->id, $this->valid(['percent' => null, 'amount_cent' => 500, 'currency' => 'eur']))
            ->assertSessionHasNoErrors();

        $coupon->refresh();
        $this->assertNull($coupon->percent);
        $this->assertSame(500, $coupon->amount_cent);
        // Upper-cased on the way in, because `Coupon::apply()` compares
        // currencies as strings and "eur" would silently refuse to apply.
        $this->assertSame('EUR', $coupon->currency);

        $this->actingAs($this->user())->delete('/cp/utilities/coupons/'.$coupon->id);
        $this->assertSame(0, Coupon::count());
    }

    #[Test]
    public function exactly_one_kind_of_discount_is_required(): void
    {
        // Neither is a coupon that does nothing; both is a coupon whose worth
        // depends on which branch of `apply()` runs first.
        foreach ([[], ['percent' => 20, 'amount_cent' => 500]] as $bad) {
            $this->actingAs($this->user())
                ->post('/cp/utilities/coupons', array_merge($this->valid(['percent' => null]), $bad))
                ->assertSessionHasErrors(['percent', 'amount_cent']);
        }

        $this->assertSame(0, Coupon::count());
    }

    #[Test]
    public function a_percentage_outside_one_to_a_hundred_is_refused(): void
    {
        foreach ([0, -5, 101, '20,5', 'zwanzig'] as $bad) {
            $this->actingAs($this->user())
                ->post('/cp/utilities/coupons', $this->valid(['percent' => $bad]))
                ->assertSessionHasErrors('percent');
        }

        $this->assertSame(0, Coupon::count());
    }

    #[Test]
    public function a_code_cannot_be_taken_twice_whatever_the_capitalisation(): void
    {
        $this->coupon(['code' => 'FRUEHLING']);

        // `findByCode()` matches case-insensitively, so a second row spelled
        // differently would make which coupon applies a matter of row order.
        $this->actingAs($this->user())
            ->post('/cp/utilities/coupons', $this->valid(['code' => 'fruehling']))
            ->assertSessionHasErrors('code');

        $this->assertSame(1, Coupon::count());
    }

    #[Test]
    public function a_coupon_keeps_its_own_code_when_it_is_edited(): void
    {
        $coupon = $this->coupon(['code' => 'FRUEHLING']);

        $this->actingAs($this->user())
            ->patch('/cp/utilities/coupons/'.$coupon->id, $this->valid(['code' => 'FRUEHLING', 'percent' => 30]))
            ->assertSessionHasNoErrors();

        $this->assertSame(30, $coupon->fresh()->percent);
    }

    #[Test]
    public function an_end_before_the_start_is_refused(): void
    {
        $this->actingAs($this->user())
            ->post('/cp/utilities/coupons', $this->valid(['starts_at' => '2026-09-10', 'ends_at' => '2026-09-01']))
            ->assertSessionHasErrors('ends_at');

        $this->assertSame(0, Coupon::count());
    }

    #[Test]
    public function an_end_date_without_a_start_date_is_allowed(): void
    {
        // `after:starts_at` reads its argument as a literal date once
        // `starts_at` is empty, and would then refuse every end date on a
        // coupon that simply has no start.
        $this->actingAs($this->user())
            ->post('/cp/utilities/coupons', $this->valid(['ends_at' => '2026-09-30']))
            ->assertSessionHasNoErrors();

        $coupon = Coupon::firstWhere('code', 'FRUEHLING');

        // And the last day counts in full, rather than dying at midnight.
        $this->assertSame('2026-09-30 23:59:59', $coupon->ends_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function a_coupon_can_only_be_limited_to_offers_that_exist(): void
    {
        Offer::create(['handle' => 'haupt', 'name' => 'Haupt', 'product' => 'noten-paket', 'slot' => Offer::SLOT_STANDALONE, 'active' => true]);

        $this->actingAs($this->user())
            ->post('/cp/utilities/coupons', $this->valid(['offers' => ['haupt', 'gibt-es-nicht']]))
            ->assertSessionHasErrors('offers.1');

        $this->assertSame(0, Coupon::count());

        $this->actingAs($this->user())
            ->post('/cp/utilities/coupons', $this->valid(['offers' => ['haupt', 'haupt']]))
            ->assertSessionHasNoErrors();

        // Stored once. Twice would show the same restriction twice on the
        // screen and read as two different rules.
        $this->assertSame(['haupt'], Coupon::firstWhere('code', 'FRUEHLING')->offers);
    }

    #[Test]
    public function the_used_count_cannot_be_set_from_the_form(): void
    {
        $coupon = $this->coupon(['max_uses' => 5]);

        $this->actingAs($this->user())
            ->patch('/cp/utilities/coupons/'.$coupon->id, $this->valid(['code' => 'BESTAND', 'used_count' => 0, 'max_uses' => 5]));

        // A code that is used up must not be reset by re-saving the row: the
        // count is the record of what already happened.
        $this->assertSame(0, $coupon->fresh()->used_count);

        $coupon->claim();
        $this->assertSame(1, $coupon->fresh()->used_count);

        $this->actingAs($this->user())
            ->patch('/cp/utilities/coupons/'.$coupon->id, $this->valid(['code' => 'BESTAND', 'used_count' => 0, 'max_uses' => 5]));

        $this->assertSame(1, $coupon->fresh()->used_count);
    }
}
