<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * When access begins and how long it lasts.
 */
class AccessWindowTest extends TestCase
{
    protected function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'kurs',
            'name' => 'Kurs',
            'product' => 'noten-paket',
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ], $overrides));
    }

    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    #[Test]
    public function no_window_means_null(): void
    {
        $this->assertNull($this->offer()->accessWindow());
    }

    #[Test]
    public function a_start_alone_and_a_duration_alone_are_both_windows(): void
    {
        $this->assertSame(
            ['starts_at' => '2027-01-15', 'days' => null],
            $this->offer(['access_starts_at' => '2027-01-15'])->accessWindow(),
        );

        $this->assertSame(
            ['starts_at' => null, 'days' => 90],
            $this->offer(['handle' => 'zweiter', 'access_days' => 90])->accessWindow(),
        );
    }

    #[Test]
    public function both_together(): void
    {
        $this->assertSame(
            ['starts_at' => '2027-01-15', 'days' => 30],
            $this->offer(['access_starts_at' => '2027-01-15', 'access_days' => 30])->accessWindow(),
        );
    }

    #[Test]
    public function the_control_panel_stores_and_clears_the_window(): void
    {
        $payload = [
            'name' => 'Kurs',
            'handle' => 'kurs',
            'product' => 'noten-paket',
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ];

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/offers', $payload + ['access_starts_at' => '2027-03-01', 'access_days' => 365])
            ->assertRedirect();

        $offer = Offer::query()->where('handle', 'kurs')->firstOrFail();
        $this->assertSame(['starts_at' => '2027-03-01', 'days' => 365], $offer->accessWindow());

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/offers/'.$offer->id, $payload)
            ->assertRedirect();

        $this->assertNull($offer->fresh()->accessWindow());
    }

    #[Test]
    public function the_control_panel_refuses_zero_days(): void
    {
        $this->actingAs($this->user())->postJson('/cp/utilities/offers', [
            'name' => 'Kurs',
            'handle' => 'kurs',
            'product' => 'noten-paket',
            'slot' => Offer::SLOT_STANDALONE,
            'access_days' => 0,
        ])->assertJsonValidationErrors(['access_days']);
    }
}
