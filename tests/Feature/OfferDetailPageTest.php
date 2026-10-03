<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * An offer has its own page, which is also its form.
 *
 * It used to open in a stack beside the listing. An offer carries prices,
 * bumps, withdrawal terms, checkout fields, countries, seats and a short link,
 * and that is more than a side panel holds.
 */
class OfferDetailPageTest extends TestCase
{
    protected function user()
    {
        return User::all()->first() ?? tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    protected function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'name' => 'Frühlings-Upsell',
            'handle' => 'fruehling-upsell',
            'product' => 'noten-paket',
            'amount_cent' => 1200,
            'headline' => 'Nur heute',
            'slot' => Offer::SLOT_BUMP,
            'active' => true,
        ], $overrides));
    }

    #[Test]
    public function the_detail_page_carries_the_form_values(): void
    {
        $offer = $this->offer();

        $response = $this->actingAs($this->user())
            ->get(cp_route('utilities.offers.show', ['offer' => $offer->id]))
            ->assertOk();

        $page = $response->viewData('page');

        $this->assertSame('statamic-offers::Offers/Show', $page['component']);
        $this->assertSame('Nur heute', $page['props']['offer']['edit_values']['headline']);
        $this->assertSame(cp_route('utilities.offers.update', ['offer' => $offer->id]), $page['props']['updateUrl']);
        $this->assertSame(cp_route('utilities.offers.destroy', ['offer' => $offer->id]), $page['props']['deleteUrl']);
    }

    #[Test]
    public function the_create_page_has_no_record_and_posts_to_store(): void
    {
        $page = $this->actingAs($this->user())->get(cp_route('utilities.offers.create'))
            ->assertOk()
            ->viewData('page');

        $this->assertSame('statamic-offers::Offers/Show', $page['component']);
        $this->assertNull($page['props']['offer']);
        $this->assertNull($page['props']['updateUrl']);
        $this->assertSame(cp_route('utilities.offers.store'), $page['props']['storeUrl']);
    }

    #[Test]
    public function the_create_page_does_not_end_on_create(): void
    {
        // Statamic marks the parent nav item ("Hilfsmittel") for any URL that
        // ends on /create or /edit, so the page would not light up "Angebote".
        $this->assertStringEndsNotWith('/create', cp_route('utilities.offers.create'));
        $this->assertStringEndsNotWith('/edit', cp_route('utilities.offers.create'));
    }

    #[Test]
    public function a_row_in_the_list_leads_to_the_page_and_carries_no_form_payload(): void
    {
        $offer = $this->offer();

        $row = collect($this->actingAs($this->user())->getJson('/cp/utilities/offers')->json('data'))
            ->firstWhere('handle', 'fruehling-upsell');

        $this->assertSame(cp_route('utilities.offers.show', ['offer' => $offer->id]), $row['show_url']);
        $this->assertArrayNotHasKey('edit_values', $row);
        $this->assertArrayNotHasKey('seat_pools', $row);
    }

    #[Test]
    public function saving_a_new_offer_leads_to_its_page_and_deleting_leads_back_to_the_list(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)->post(cp_route('utilities.offers.store'), [
            'name' => 'Neu', 'handle' => 'neu', 'product' => 'noten-paket',
            'slot' => Offer::SLOT_STANDALONE, 'active' => true, 'amount_cent' => 500,
        ]);

        $offer = Offer::query()->where('handle', 'neu')->firstOrFail();
        $response->assertRedirect(cp_route('utilities.offers.show', ['offer' => $offer->id]));

        $this->actingAs($user)->delete(cp_route('utilities.offers.destroy', ['offer' => $offer->id]))
            ->assertRedirect(cp_route('utilities.offers'));

        $this->assertSame(0, Offer::count());
    }

    #[Test]
    public function the_core_date_field_round_trips_through_the_display_timezone(): void
    {
        config(['statamic.system.display_timezone' => 'Europe/Berlin']);
        $user = $this->user();

        // Der `date`-Feldtyp schickt einen Zeitpunkt (ISO, UTC).
        $this->actingAs($user)->post(cp_route('utilities.offers.store'), [
            'name' => 'Datum', 'handle' => 'datum', 'product' => 'noten-paket',
            'slot' => Offer::SLOT_STANDALONE, 'active' => true, 'amount_cent' => 500,
            'available_from' => '2026-10-05T12:30:00.000Z',
            'access_starts_at' => '2026-10-06T00:00:00.000Z',
        ])->assertSessionHasNoErrors();

        $offer = Offer::query()->where('handle', 'datum')->firstOrFail();

        $this->assertSame('2026-10-05 12:30:00', $offer->available_from->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-06', $offer->access_starts_at->format('Y-m-d'));

        // Und zurueck: derselbe Zeitpunkt, den die Seite dem Feldtyp gibt.
        $page = $this->actingAs($user)->get(cp_route('utilities.offers.show', ['offer' => $offer->id]))
            ->viewData('page')['props']['dates'];

        $this->assertSame('2026-10-05T12:30:00.000Z', $page['values']['available_from']);
        $this->assertSame('Europe/Berlin', $page['meta']['available_from']['timezone']);
    }

    #[Test]
    public function an_unknown_offer_gets_the_control_panel_404_page(): void
    {
        $page = $this->actingAs($this->user())
            ->get(cp_route('utilities.offers.show', ['offer' => 99999]))
            ->assertNotFound()
            ->viewData('page');

        // Die 404-Seite des Control Panels, nicht die nackte Zeile des Handlers.
        $this->assertSame('errors/404', $page['component']);
    }

    #[Test]
    public function the_pages_need_the_permission(): void
    {
        $offer = $this->offer();
        $role = tap(Role::make('nur-cp')->addPermission('access cp'))->save();
        $user = tap(User::make()->email('ohne@example.com')->assignRole($role))->save();

        $this->actingAs($user)->getJson(cp_route('utilities.offers.show', ['offer' => $offer->id]))->assertForbidden();
        $this->actingAs($user)->getJson(cp_route('utilities.offers.create'))->assertForbidden();
    }

    #[Test]
    public function an_unknown_offer_is_a_404(): void
    {
        $this->actingAs($this->user())->get(cp_route('utilities.offers.show', ['offer' => 99999]))->assertNotFound();
    }

    #[Test]
    public function there_is_no_second_route_for_editing(): void
    {
        $this->assertFalse(Route::has('statamic.cp.utilities.offers.edit'));
        $this->assertNull(collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => str_contains($route->uri(), 'utilities/offers') && str_ends_with($route->uri(), '/edit')));
    }

    #[Test]
    public function the_slot_is_explained_in_both_languages(): void
    {
        foreach (['de', 'en'] as $locale) {
            $this->assertNotSame('statamic-offers::messages.field_slot_help', trans('statamic-offers::messages.field_slot_help', [], $locale));
            $help = trans('statamic-offers::messages.field_slot_help', [], $locale);

            // All three places named, not only the selected one.
            $this->assertStringContainsString(trans('statamic-offers::messages.slot_bump', [], $locale).':', $help);
            $this->assertStringContainsString(trans('statamic-offers::messages.slot_post_purchase', [], $locale).':', $help);
            $this->assertStringContainsString(trans('statamic-offers::messages.slot_standalone', [], $locale).':', $help);
        }
    }
}
