<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Contracts\SeatAccess;
use Goldnead\StatamicOffers\Events\CouponRedeemed;
use Goldnead\StatamicOffers\Events\OfferSoldOut;
use Goldnead\StatamicOffers\Events\SeatAccepted;
use Goldnead\StatamicOffers\Events\SeatInvited;
use Goldnead\StatamicOffers\Events\SeatPoolClosed;
use Goldnead\StatamicOffers\Events\SeatPoolOpened;
use Goldnead\StatamicOffers\Events\SeatRevoked;
use Goldnead\StatamicOffers\Events\ShortLinkSwitched;
use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Models\SeatPool;
use Goldnead\StatamicOffers\Support\SeatPools;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Discount;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Goldnead\StatamicPayments\Support\Refunds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

// FakeSeatAccess steht dort; allein gestartet laedt PHPUnit die Datei sonst nicht.
require_once __DIR__.'/SeatsTest.php';

/**
 * Die Momente eines Angebots als Ereignisse: Plaetze, Kontingent, Gutschein,
 * Link-Weiche. Bis 1.12 feuerte dieses Addon keins, und ausser dem Addon
 * selbst konnte niemand hoeren, wann ein Platz angenommen oder ein Angebot
 * ausverkauft war (automations, Webhook-Manager).
 *
 * Jedes genau einmal je Moment, auch wenn der Anbieter doppelt liefert oder
 * jemand zweimal klickt.
 */
class OfferEventsTest extends TestCase
{
    protected FakeSeatAccess $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->access = new FakeSeatAccess;
        $this->app->instance(SeatAccess::class, $this->access);

        Mail::fake();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products.workshop', [
            'name' => 'Workshop',
            'amount_cent' => 4900,
            'grants' => 'workshop-zugang',
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'stimmgruppe',
            'name' => 'Workshop für die Stimmgruppe',
            'product' => 'workshop',
            'amount_cent' => 39000,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ], $overrides));
    }

    protected function buy(string $handle = 'offer:stimmgruppe', ?Discount $discount = null): Payment
    {
        $payment = app(Checkout::class)->start($handle, ['email' => 'leitung@chor.example', 'name' => 'Anna Leitung'], null, $discount)->payment;
        $this->gateway->markPaid($payment->provider_id);

        // Der Anbieter liefert mehrfach, mit Absicht.
        app(Fulfilment::class)->handle($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);

        return $payment->fresh();
    }

    #[Test]
    public function a_seat_purchase_opens_its_pool_once_and_says_so(): void
    {
        Event::fake([SeatPoolOpened::class]);
        $this->offer(['seats' => 5]);

        $this->buy();

        Event::assertDispatchedTimes(SeatPoolOpened::class, 1);
        Event::assertDispatched(SeatPoolOpened::class, fn (SeatPoolOpened $e) => $e->pool->is(SeatPool::query()->sole()));
    }

    #[Test]
    public function inviting_accepting_and_taking_back_are_one_event_each(): void
    {
        $this->offer(['seats' => 5]);
        $this->buy();
        $pool = SeatPool::query()->sole();
        $seats = app(SeatPools::class);

        Event::fake([SeatInvited::class, SeatAccepted::class, SeatRevoked::class]);

        $seat = $seats->invite($pool, 'Sopran@Chor.example', 'Sara Sopran');
        Event::assertDispatched(SeatInvited::class, fn (SeatInvited $e) => $e->seat->is($seat) && $e->pool->is($pool));

        $seats->accept($seat);
        $seats->accept($seat);
        Event::assertDispatchedTimes(SeatAccepted::class, 1);

        $this->assertTrue($seats->revoke($seat));
        $this->assertFalse($seats->revoke($seat));
        Event::assertDispatchedTimes(SeatRevoked::class, 1);
        Event::assertDispatched(SeatRevoked::class, fn (SeatRevoked $e) => $e->previousStatus === 'claimed' && $e->reason === null);
    }

    #[Test]
    public function a_seat_whose_access_could_not_be_revoked_is_not_announced_as_taken_back(): void
    {
        $this->offer(['seats' => 5]);
        $this->buy();
        $seats = app(SeatPools::class);
        $seat = $seats->invite(SeatPool::query()->sole(), 'sopran@chor.example');
        $seats->accept($seat);

        Event::fake([SeatRevoked::class]);
        $this->access->failRevoke = true;

        $this->assertFalse($seats->revoke($seat));
        Event::assertNotDispatched(SeatRevoked::class);
    }

    #[Test]
    public function a_full_refund_closes_the_pool_once_after_taking_every_seat_back(): void
    {
        $this->offer(['seats' => 5]);
        $payment = $this->buy();
        $seats = app(SeatPools::class);
        $seats->accept($seats->invite(SeatPool::query()->sole(), 'sopran@chor.example'));
        $seats->invite(SeatPool::query()->sole(), 'alt@chor.example');

        $order = [];
        Event::listen(SeatRevoked::class, function (SeatRevoked $e) use (&$order) {
            $order[] = 'revoked:'.$e->previousStatus;
        });
        Event::listen(SeatPoolClosed::class, function (SeatPoolClosed $e) use (&$order) {
            $order[] = 'closed';
        });

        app(Refunds::class)->record($payment->fresh(), $payment->amount_cent, 're_1');
        $seats->closeForPayment($payment, 'noch einmal');

        $this->assertSame(['revoked:claimed', 'revoked:invited', 'closed'], $order);
    }

    #[Test]
    public function the_purchase_that_takes_the_last_unit_sells_the_offer_out_once(): void
    {
        Event::fake([OfferSoldOut::class]);
        $offer = $this->offer(['quantity_limit' => 2]);

        $this->buy();
        Event::assertNotDispatched(OfferSoldOut::class);

        $this->buy();
        Event::assertDispatchedTimes(OfferSoldOut::class, 1);
        Event::assertDispatched(OfferSoldOut::class, fn (OfferSoldOut $e) => $e->offer->is($offer) && $e->sold === 2);
        $this->assertNotNull($offer->fresh()->sold_out_at);
    }

    #[Test]
    public function a_raised_limit_can_sell_out_again(): void
    {
        Event::fake([OfferSoldOut::class]);
        $offer = $this->offer(['quantity_limit' => 1]);
        $this->buy();

        $offer->update(['quantity_limit' => 3]);
        $this->buy();
        Event::assertDispatchedTimes(OfferSoldOut::class, 1);
        $this->assertNull($offer->fresh()->sold_out_at);

        $this->buy();
        Event::assertDispatchedTimes(OfferSoldOut::class, 2);
    }

    #[Test]
    public function a_paid_payment_with_a_coupon_redeems_it_and_one_without_does_not(): void
    {
        Event::fake([CouponRedeemed::class]);
        $this->offer();
        $coupon = Coupon::create(['code' => 'CHOR20', 'percent' => 20, 'active' => true]);

        $this->buy();
        Event::assertNotDispatched(CouponRedeemed::class);

        $payment = $this->buy('offer:stimmgruppe', new Discount('chor20', 7800));
        Event::assertDispatchedTimes(CouponRedeemed::class, 1);
        Event::assertDispatched(CouponRedeemed::class, fn (CouponRedeemed $e) => $e->coupon->is($coupon) && $e->payment->is($payment));
    }

    #[Test]
    public function the_short_link_announces_its_switch_at_the_first_visit_after_the_date(): void
    {
        Event::fake([ShortLinkSwitched::class]);
        $offer = $this->offer([
            'link_slug' => 'herbst',
            'link_target' => '/workshop',
            'link_fallback' => '/warteliste',
            'link_switch_at' => Carbon::now()->subMinute(),
        ]);

        $this->get('/go/herbst')->assertRedirect('/warteliste');
        $this->get('/go/herbst')->assertRedirect('/warteliste');

        Event::assertDispatchedTimes(ShortLinkSwitched::class, 1);
        Event::assertDispatched(ShortLinkSwitched::class, fn (ShortLinkSwitched $e) => $e->offer->is($offer) && $e->reason === 'date');

        // Stichtag verschoben: die Weiche fuehrt wieder zum Angebot, und der
        // naechste Wechsel ist wieder einer.
        $offer->update(['link_switch_at' => Carbon::now()->addDay()]);
        $this->get('/go/herbst')->assertRedirect('/workshop');
        $offer->update(['link_switch_at' => Carbon::now()->subMinute()]);
        $this->get('/go/herbst')->assertRedirect('/warteliste');

        Event::assertDispatchedTimes(ShortLinkSwitched::class, 2);
    }

    #[Test]
    public function the_purchase_that_sells_out_switches_the_link_without_waiting_for_a_visit(): void
    {
        Event::fake([ShortLinkSwitched::class]);
        $this->offer([
            'quantity_limit' => 1,
            'link_slug' => 'herbst',
            'link_target' => '/workshop',
            'link_fallback' => '/warteliste',
        ]);

        $this->buy();
        $this->get('/go/herbst')->assertRedirect('/warteliste');

        Event::assertDispatchedTimes(ShortLinkSwitched::class, 1);
        Event::assertDispatched(ShortLinkSwitched::class, fn (ShortLinkSwitched $e) => $e->reason === 'sold_out');
    }

    #[Test]
    public function a_link_without_a_second_target_never_switches(): void
    {
        Event::fake([ShortLinkSwitched::class]);
        $this->offer(['quantity_limit' => 1, 'link_slug' => 'herbst', 'link_target' => '/workshop']);

        $this->buy();
        $this->get('/go/herbst')->assertRedirect('/workshop');

        Event::assertNotDispatched(ShortLinkSwitched::class);
    }

    #[Test]
    public function every_event_carries_the_brand_of_what_it_is_about(): void
    {
        $offer = $this->offer(['seats' => 5]);
        $offer->forceFill(['brand_id' => 7])->save();

        $pool = new SeatPool(['brand_id' => 7]);
        $this->assertSame(7, (new SeatPoolOpened($pool))->brandId);
        $this->assertSame(7, (new OfferSoldOut($offer, 1))->brandId);
        $this->assertNull((new SeatPoolClosed(new SeatPool(['brand_id' => 0]), 'x'))->brandId);
    }
}
