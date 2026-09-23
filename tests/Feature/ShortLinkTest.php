<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * O5: Link-Weiche.
 *
 * Ein Link auf dem Flyer, der nicht veraltet: bis zum Stichtag oder bis zum
 * letzten Platz fuehrt er zum Angebot, danach zur Warteliste. Gedruckt wird
 * einmal, umgeschaltet wird von selbst.
 */
class ShortLinkTest extends TestCase
{
    protected function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'fruehbucher',
            'name' => 'Frühbucher',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
            'link_slug' => 'herbst',
            'link_target' => '/workshop/fruehbucher',
            'link_fallback' => '/workshop/warteliste',
        ], $overrides));
    }

    #[Test]
    public function before_the_deadline_the_link_leads_to_the_offer(): void
    {
        $offer = $this->offer(['link_switch_at' => Carbon::now()->addDay()]);

        $this->get('/go/herbst')->assertRedirect('/workshop/fruehbucher');

        $this->assertSame(1, $offer->fresh()->link_hits_target);
        $this->assertSame(0, $offer->fresh()->link_hits_fallback);
    }

    #[Test]
    public function after_the_deadline_it_leads_to_the_fallback(): void
    {
        $offer = $this->offer(['link_switch_at' => Carbon::now()->subMinute()]);

        $this->get('/go/herbst')->assertRedirect('/workshop/warteliste');
        $this->get('/go/herbst')->assertRedirect('/workshop/warteliste');

        $this->assertSame(0, $offer->fresh()->link_hits_target);
        $this->assertSame(2, $offer->fresh()->link_hits_fallback);
    }

    #[Test]
    public function without_an_own_deadline_the_end_of_the_sale_counts(): void
    {
        $this->offer(['available_until' => Carbon::now()->subHour()]);

        $this->get('/go/herbst')->assertRedirect('/workshop/warteliste');
    }

    #[Test]
    public function a_sold_out_contingent_switches_the_link(): void
    {
        $this->offer(['quantity_limit' => 1]);

        $this->get('/go/herbst')->assertRedirect('/workshop/fruehbucher');

        $payment = app(Checkout::class)->start('offer:fruehbucher', ['email' => 'k@example.com'])->payment;
        $this->gateway->markPaid($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);

        $this->get('/go/herbst')->assertRedirect('/workshop/warteliste');
    }

    #[Test]
    public function the_sold_out_switch_can_be_turned_off(): void
    {
        $this->offer(['quantity_limit' => 1, 'link_switch_on_sold_out' => false]);

        $payment = app(Checkout::class)->start('offer:fruehbucher', ['email' => 'k@example.com'])->payment;
        $this->gateway->markPaid($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);

        $this->get('/go/herbst')->assertRedirect('/workshop/fruehbucher');
    }

    #[Test]
    public function the_query_travels_along_so_a_coupon_link_works_through_it(): void
    {
        $this->offer(['link_target' => 'https://chor.example/kasse?utm_source=flyer']);

        $this->get('/go/herbst?coupon=CHOR20')
            ->assertRedirect('https://chor.example/kasse?utm_source=flyer&coupon=CHOR20');
    }

    #[Test]
    public function without_a_fallback_the_link_keeps_leading_to_the_offer_page(): void
    {
        $this->offer(['link_fallback' => null, 'link_switch_at' => Carbon::now()->subDay()]);

        // Eine Weiche ohne zweites Ziel ist ein gewoehnlicher Kurzlink. Ein 404
        // auf einem gedruckten Flyer waere schlimmer als eine Seite, die
        // „ausverkauft" sagt.
        $this->get('/go/herbst')->assertRedirect('/workshop/fruehbucher');
    }

    #[Test]
    public function an_unknown_slug_is_not_found(): void
    {
        $this->offer();

        $this->get('/go/gibtsnicht')->assertNotFound();
    }

    #[Test]
    public function the_offer_screen_hands_out_its_qr_code(): void
    {
        $offer = $this->offer();
        $user = tap(User::make()->email('studio@example.com')->makeSuper())->save();

        $this->actingAs($user)
            ->get(cp_route('utilities.offers.qr', ['offer' => $offer->id, 'format' => 'png']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    #[Test]
    public function the_slug_is_checked_in_the_form(): void
    {
        $this->offer();
        $user = tap(User::make()->email('studio@example.com')->makeSuper())->save();

        $this->actingAs($user)
            ->post(cp_route('utilities.offers.store'), [
                'name' => 'Zweites', 'handle' => 'zweites', 'product' => 'noten-paket',
                'slot' => Offer::SLOT_STANDALONE, 'active' => true,
                'link_slug' => 'herbst', 'link_target' => '/x',
            ])
            ->assertSessionHasErrors('link_slug');

        $this->post(cp_route('utilities.offers.store'), [
            'name' => 'Drittes', 'handle' => 'drittes', 'product' => 'noten-paket',
            'slot' => Offer::SLOT_STANDALONE, 'active' => true,
            'link_slug' => 'fruehling', 'link_target' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('link_target');
    }
}
