<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The screen that writes.
 *
 * Offers are the only thing in this family a site owner *makes* rather than
 * receives, so this is the one screen where a wrong value ends up charged.
 * Every test here tries to put one in.
 */
class WritingTest extends TestCase
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
            'name' => 'Frühlings-Upsell',
            'handle' => 'fruehling-upsell',
            'product' => 'noten-paket',
            'amount_cent' => 1200,
            'slot' => Offer::SLOT_POST_PURCHASE,
            'active' => true,
        ], $overrides);
    }

    #[Test]
    public function a_user_without_the_permission_cannot_write(): void
    {
        $offer = Offer::create($this->valid());
        $user = $this->userWithoutPermission();

        // The screen decides what customers are charged. Reading it is one
        // thing; writing to it is editing a shop.
        $this->actingAs($user)->postJson('/cp/utilities/offers', $this->valid(['handle' => 'neu']))->assertForbidden();
        $this->actingAs($user)->patchJson('/cp/utilities/offers/'.$offer->id, $this->valid(['amount_cent' => 1]))->assertForbidden();
        $this->actingAs($user)->deleteJson('/cp/utilities/offers/'.$offer->id)->assertForbidden();

        $this->assertSame(1200, $offer->fresh()->amount_cent);
        $this->assertSame(1, Offer::count());
    }

    #[Test]
    public function a_price_that_is_not_a_whole_number_of_minor_units_is_refused(): void
    {
        // "12,00" read as 12 cents is the shape of the mistake: it saves, it
        // looks right in the listing, and it sells a €12 thing for twelve cents.
        foreach (['12,00', '12.00', 0, -100, 'kostenlos'] as $bad) {
            $this->actingAs($this->user())
                ->post('/cp/utilities/offers', $this->valid(['amount_cent' => $bad]))
                ->assertSessionHasErrors('amount_cent');
        }

        $this->assertSame(0, Offer::count());
    }

    #[Test]
    public function an_offer_cannot_point_at_another_offer(): void
    {
        // The pair would ask each other what they cost until the process ran
        // out of memory, and the listing you would delete one from asks every
        // row whether it is sellable.
        $this->actingAs($this->user())
            ->post('/cp/utilities/offers', $this->valid(['product' => 'offer:fruehling-upsell']))
            ->assertSessionHasErrors('product');

        $this->assertSame(0, Offer::count());
    }

    #[Test]
    public function a_product_the_catalogue_does_not_have_is_refused(): void
    {
        $this->actingAs($this->user())
            ->post('/cp/utilities/offers', $this->valid(['product' => 'gibt-es-nicht']))
            ->assertSessionHasErrors('product');
    }

    #[Test]
    public function a_handle_has_to_be_a_handle_and_has_to_be_free(): void
    {
        Offer::create($this->valid());

        foreach (['Offer:Foo', 'mit leerzeichen', 'Groß', '-vorne', ''] as $bad) {
            $this->actingAs($this->user())
                ->post('/cp/utilities/offers', $this->valid(['handle' => $bad]))
                ->assertSessionHasErrors('handle');
        }

        // And not twice: two offers on one handle is one offer nobody can buy.
        $this->actingAs($this->user())
            ->post('/cp/utilities/offers', $this->valid())
            ->assertSessionHasErrors('handle');

        $this->assertSame(1, Offer::count());
    }

    #[Test]
    public function a_slot_it_does_not_know_is_refused(): void
    {
        // A slot is a promise about context; an unknown one would show a
        // post-purchase offer at checkout and charge twice for one journey.
        $this->actingAs($this->user())
            ->post('/cp/utilities/offers', $this->valid(['slot' => 'irgendwo']))
            ->assertSessionHasErrors('slot');
    }

    #[Test]
    public function a_valid_offer_saves(): void
    {
        $this->actingAs($this->user())
            ->post('/cp/utilities/offers', $this->valid())
            ->assertRedirect();

        $offer = Offer::first();
        $this->assertSame(1200, $offer->amount_cent);
        $this->assertTrue($offer->active);
    }

    #[Test]
    public function deleting_takes_the_counters_with_it(): void
    {
        $offer = Offer::create($this->valid(['shown_count' => 40, 'accepted_count' => 4]));

        $this->actingAs($this->user())->delete('/cp/utilities/offers/'.$offer->id);

        // An offer nobody can see any more must not keep contributing to a
        // conversion report.
        $this->assertSame(0, Offer::count());
    }
}
