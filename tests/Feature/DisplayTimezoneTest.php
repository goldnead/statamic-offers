<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * Datum und Uhrzeit im Formular sind Anzeige-Zeit, gespeichert wird in der
 * Zeitzone der Anwendung.
 *
 * Statamic trennt die beiden: `app.timezone` ist die der Datenbank (meist UTC),
 * `statamic.system.display_timezone` die, in der Menschen Zeiten lesen und
 * tippen. Wer „18:00" als Umschaltzeit eintraegt, meint 18:00 auf seiner Uhr,
 * nicht in UTC; bei Europa/Berlin im Oktober sind das zwei Stunden Unterschied,
 * und der Flyer-Link schaltet um 20:00 statt um 18:00.
 */
class DisplayTimezoneTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('statamic.system.display_timezone', 'Europe/Berlin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function times_are_typed_and_shown_in_the_display_timezone_and_stored_in_utc(): void
    {
        $user = tap(User::make()->email('studio@example.com')->makeSuper())->save();

        $this->actingAs($user)->post(cp_route('utilities.offers.store'), [
            'name' => 'Herbst', 'handle' => 'herbst', 'product' => 'noten-paket',
            'slot' => Offer::SLOT_STANDALONE, 'active' => true, 'amount_cent' => 1000,
            'available_from' => '2026-10-01T09:00',
            'available_until' => '2026-10-31T23:59',
            'link_slug' => 'herbst', 'link_target' => '/herbst', 'link_fallback' => '/warteliste',
            'link_switch_at' => '2026-10-15T18:00',
        ])->assertSessionHasNoErrors();

        $offer = Offer::query()->where('handle', 'herbst')->firstOrFail();

        // Berlin ist im Oktober (bis zum 25.) UTC+2, danach UTC+1.
        $this->assertSame('2026-10-15 16:00:00', $offer->link_switch_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 07:00:00', $offer->available_from->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-31 22:59:00', $offer->available_until->utc()->format('Y-m-d H:i:s'));

        // Zurueck ins Formular in derselben Anzeige-Zeit, die getippt wurde.
        $werte = collect($this->getJson(cp_route('utilities.offers'))->json('data'))->firstWhere('handle', 'herbst')['edit_values'];

        $this->assertSame('2026-10-15T18:00', $werte['link_switch_at']);
        $this->assertSame('2026-10-01T09:00', $werte['available_from']);
        $this->assertSame('2026-10-31T23:59', $werte['available_until']);
    }

    #[Test]
    public function the_switch_happens_at_the_typed_local_time(): void
    {
        $user = tap(User::make()->email('studio@example.com')->makeSuper())->save();

        $this->actingAs($user)->post(cp_route('utilities.offers.store'), [
            'name' => 'Herbst', 'handle' => 'herbst', 'product' => 'noten-paket',
            'slot' => Offer::SLOT_STANDALONE, 'active' => true, 'amount_cent' => 1000,
            'link_slug' => 'herbst', 'link_target' => '/herbst', 'link_fallback' => '/warteliste',
            'link_switch_at' => '2026-10-15T18:00',
        ])->assertSessionHasNoErrors();

        // 17:30 in Berlin: noch vor dem Umschalten.
        Carbon::setTestNow(Carbon::parse('2026-10-15 15:30:00', 'UTC'));
        $this->get('/go/herbst')->assertRedirect('/herbst');

        // 18:01 in Berlin: danach.
        Carbon::setTestNow(Carbon::parse('2026-10-15 16:01:00', 'UTC'));
        $this->get('/go/herbst')->assertRedirect('/warteliste');
    }

    #[Test]
    public function the_screen_names_the_display_timezone(): void
    {
        $user = tap(User::make()->email('studio@example.com')->makeSuper())->save();

        $html = html_entity_decode((string) $this->actingAs($user)->get(cp_route('utilities.offers'))->assertOk()->getContent());

        // Die Eigenschaft der Seite, nicht irgendein Vorkommen im HTML.
        $this->assertMatchesRegularExpression('#"timezone":"Europe\\\\/Berlin"#', $html);
        $this->assertDoesNotMatchRegularExpression('#"timezone":"UTC"#', $html);
    }
}
