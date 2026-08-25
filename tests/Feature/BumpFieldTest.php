<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The bump picker on the offer form.
 *
 * The field is a select, so the browser only ever offers what it was given —
 * but a select is a text field with a nice hat on, and what actually arrives is
 * a list of strings somebody may have written by hand. Everything below is a
 * way of writing one that the form itself would never produce.
 */
class BumpFieldTest extends TestCase
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

    protected function offer(string $handle, string $slot = Offer::SLOT_BUMP): Offer
    {
        return Offer::create([
            'handle' => $handle,
            'name' => ucfirst($handle),
            'product' => 'begleit-cd',
            'amount_cent' => 500,
            'slot' => $slot,
            'active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function valid(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Hauptangebot',
            'handle' => 'haupt',
            'product' => 'noten-paket',
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ], $overrides);
    }

    #[Test]
    public function bumps_are_kept_in_the_order_they_were_picked(): void
    {
        $this->offer('cd');
        $this->offer('poster');

        $this->actingAs($this->user())
            ->post('/cp/utilities/offers', $this->valid(['bumps' => ['poster', 'cd']]))
            ->assertSessionHasNoErrors();

        // The order is the editorial decision, so it is stored, not sorted.
        $this->assertSame(['poster', 'cd'], Offer::firstWhere('handle', 'haupt')->bumps);
    }

    #[Test]
    public function an_offer_that_does_not_exist_cannot_be_a_bump(): void
    {
        $this->actingAs($this->user())
            ->post('/cp/utilities/offers', $this->valid(['bumps' => ['gibt-es-nicht']]))
            ->assertSessionHasErrors('bumps.0');

        $this->assertSame(0, Offer::count());
    }

    #[Test]
    public function an_offer_that_is_not_placed_at_checkout_cannot_be_a_bump(): void
    {
        $this->offer('spaeter', Offer::SLOT_POST_PURCHASE);

        // It exists, it is sellable, and it would still never render: bumps are
        // drawn at checkout and this one is not placed there.
        $this->actingAs($this->user())
            ->post('/cp/utilities/offers', $this->valid(['bumps' => ['spaeter']]))
            ->assertSessionHasErrors('bumps.0');

        $this->assertSame(1, Offer::count());
    }

    #[Test]
    public function an_offer_cannot_carry_itself(): void
    {
        // A pair that asks each other what they cost is how the listing you
        // would delete it from dies as well.
        $this->actingAs($this->user())
            ->post('/cp/utilities/offers', $this->valid(['handle' => 'selbst', 'slot' => Offer::SLOT_BUMP, 'bumps' => ['selbst']]))
            ->assertSessionHasErrors('bumps.0');

        $this->assertSame(0, Offer::count());
    }

    #[Test]
    public function a_handle_listed_twice_is_stored_once(): void
    {
        $this->offer('cd');

        $this->actingAs($this->user())
            ->post('/cp/utilities/offers', $this->valid(['bumps' => ['cd', 'cd']]))
            ->assertSessionHasNoErrors();

        // Twice would draw the same checkbox twice and charge for it twice.
        $this->assertSame(['cd'], Offer::firstWhere('handle', 'haupt')->bumps);
    }

    #[Test]
    public function an_offer_saved_without_the_field_simply_has_no_bumps(): void
    {
        // Every client that is not this addon's own form leaves the key out.
        $this->actingAs($this->user())
            ->post('/cp/utilities/offers', $this->valid())
            ->assertSessionHasNoErrors();

        $this->assertSame([], Offer::firstWhere('handle', 'haupt')->bumps);
    }

    #[Test]
    public function bumps_can_be_taken_away_again(): void
    {
        $this->offer('cd');
        $offer = Offer::create($this->valid(['bumps' => ['cd']]));

        $this->actingAs($this->user())
            ->patch('/cp/utilities/offers/'.$offer->id, $this->valid(['bumps' => []]))
            ->assertSessionHasNoErrors();

        $this->assertSame([], $offer->fresh()->bumps);
    }

    #[Test]
    public function a_user_without_the_permission_cannot_add_a_bump(): void
    {
        $this->offer('cd');
        $offer = Offer::create($this->valid());

        $this->actingAs($this->userWithoutPermission())
            ->patchJson('/cp/utilities/offers/'.$offer->id, $this->valid(['bumps' => ['cd']]))
            ->assertForbidden();

        $this->assertSame([], (array) $offer->fresh()->bumps);
    }

    #[Test]
    public function the_listing_counts_the_bumps_and_leaves_the_cell_empty_at_zero(): void
    {
        $this->offer('cd');
        Offer::create($this->valid(['bumps' => ['cd']]));
        Offer::create($this->valid(['handle' => 'ohne', 'name' => 'Ohne']));

        $rows = collect($this->actingAs($this->user())->getJson('/cp/utilities/offers')->json('data'))
            ->keyBy('handle');

        $this->assertSame(1, $rows['haupt']['bumps_count']);
        // Null, not 0. A column full of zeroes reads as a broken feature.
        $this->assertNull($rows['ohne']['bumps_count']);
    }

    #[Test]
    public function the_bumps_column_is_part_of_the_listing(): void
    {
        Offer::create($this->valid());

        $fields = array_column(
            $this->actingAs($this->user())->getJson('/cp/utilities/offers')->json('meta.columns'),
            'field'
        );

        $this->assertContains('bumps', $fields);
    }
}
