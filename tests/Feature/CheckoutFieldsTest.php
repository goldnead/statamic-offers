<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Offers;
use Goldnead\StatamicOffers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * The field library and the offer's picks from it.
 */
class CheckoutFieldsTest extends TestCase
{
    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    /**
     * @return array<string, mixed>
     */
    protected function valid(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Kurs',
            'handle' => 'kurs',
            'product' => 'noten-paket',
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ], $overrides);
    }

    #[Test]
    public function the_library_is_read_from_the_config_and_normalised(): void
    {
        config()->set('statamic-offers.checkout_fields', [
            'name' => ['label' => 'Name', 'type' => 'text', 'required' => true],
            'newsletter' => ['label' => 'Newsletter', 'type' => 'checkbox'],
            'size' => ['label' => 'Größe', 'type' => 'select', 'options' => ['s' => 'S', 'm' => 'M']],
            'odd' => ['label' => 'Odd', 'type' => 'range'],
            'broken' => 'not an array',
        ]);

        $library = Offers::fieldLibrary();

        $this->assertSame(['name', 'newsletter', 'size', 'odd'], array_keys($library));
        $this->assertTrue($library['name']['required']);
        $this->assertFalse($library['newsletter']['required']);
        $this->assertSame('checkbox', $library['newsletter']['type']);
        $this->assertSame(['s' => 'S', 'm' => 'M'], $library['size']['options']);
        // An unknown type is read as text rather than rendered as nothing.
        $this->assertSame('text', $library['odd']['type']);
        $this->assertNull($library['odd']['options']);
    }

    #[Test]
    public function the_default_library_carries_the_invoice_address(): void
    {
        $keys = Offers::fieldKeys();

        foreach (['name', 'street', 'postal_code', 'city', 'country'] as $key) {
            $this->assertContains($key, $keys);
        }

        // Two letters, as the funnel's checkout validates it.
        $this->assertContains('size:2', Offers::fieldLibrary()['country']['rules']);
    }

    #[Test]
    public function an_offer_only_reports_keys_the_library_still_knows(): void
    {
        $offer = Offer::create($this->valid(['checkout_fields' => ['name', 'street', 'vanished']]));

        $this->assertSame(['name', 'street'], $offer->checkoutFields());
    }

    #[Test]
    public function an_offer_without_picks_reports_an_empty_list(): void
    {
        $this->assertSame([], Offer::create($this->valid())->checkoutFields());
    }

    #[Test]
    public function the_control_panel_refuses_an_unknown_key(): void
    {
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/offers', $this->valid(['checkout_fields' => ['name', 'shoe_size']]))
            ->assertJsonValidationErrors(['checkout_fields.1']);

        $this->assertSame(0, Offer::count());
    }

    #[Test]
    public function the_control_panel_stores_picks_in_library_order_and_empty_as_null(): void
    {
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/offers', $this->valid(['checkout_fields' => ['city', 'name', 'city']]))
            ->assertRedirect();

        $offer = Offer::query()->where('handle', 'kurs')->firstOrFail();
        $this->assertSame(['name', 'city'], $offer->checkout_fields);

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/offers/'.$offer->id, $this->valid(['checkout_fields' => []]))
            ->assertRedirect();

        $this->assertNull($offer->fresh()->checkout_fields);
    }
}
