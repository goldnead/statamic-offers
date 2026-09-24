<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\BrandContext\Models\Brand;
use Goldnead\StatamicOffers\Contracts\SeatAccess;
use Goldnead\StatamicOffers\Events\CouponRedeemed;
use Goldnead\StatamicOffers\Events\OfferSoldOut;
use Goldnead\StatamicOffers\Events\SeatAccepted;
use Goldnead\StatamicOffers\Integrations\WebhookManager\OffersTrigger;
use Goldnead\StatamicOffers\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Models\SeatPool;
use Goldnead\StatamicOffers\Support\SeatPools;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Discount;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Goldnead\WebhookManager\Domain\OutboundWebhook\Models\OutboundWebhook;
use Goldnead\WebhookManager\Events\TriggerDetected;
use Goldnead\WebhookManager\Facades\WebhookManager;
use Goldnead\WebhookManager\Jobs\ProcessOutboundDeliveryJob;
use Goldnead\WebhookManager\ValueObjects\TriggerEvent;
use Goldnead\WebhookManager\WebhookManagerServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\CP\Nav;

require_once __DIR__.'/SeatsTest.php';

/**
 * Der echte statamic-webhook-manager neben diesem Addon, ohne Fakes: sein
 * Provider, seine Tabellen, seine Zustellkette. Die Site ohne ihn prüft
 * {@see BootWithoutWebhookManagerTest} in einem eigenen Prozess.
 */
class WebhookManagerBridgeTest extends TestCase
{
    protected FakeSeatAccess $access;

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [WebhookManagerServiceProvider::class]);
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-webhook-manager/database/migrations');

        // AddonTestCase tauscht Nav gegen einen strengen Mock; die Navigation
        // des Webhook-Managers ist hier nicht das Thema.
        Nav::shouldReceive('extend');

        $provider = $this->app->getProvider(WebhookManagerServiceProvider::class);
        $provider->bootAddon();

        // Statamic verdrahtet die $listen-Liste eines Addons (TriggerDetected →
        // DispatchTriggerListener) aus einem booted-Callback, den Testbench nie
        // auslöst.
        (new \ReflectionMethod($provider, 'bootEvents'))->invoke($provider);

        // Beide booted-Versuche der Brücke liefen vor dem bootAddon() oben und
        // gaben auf, wie auf einer Site, auf der der Webhook-Manager später
        // bootet. Das hier ist der Retry, den eine Site bekommt.
        $this->app->make(WebhookManagerBridge::class)->boot($this->app->make('events'));

        $this->access = new FakeSeatAccess;
        $this->app->instance(SeatAccess::class, $this->access);
        Mail::fake();
    }

    protected function hook(string $trigger, string $handle = 'hook'): OutboundWebhook
    {
        return OutboundWebhook::create([
            'uuid' => (string) Str::uuid(),
            'name' => $handle,
            'handle' => $handle,
            'enabled' => true,
            'trigger_type' => $trigger,
            'url' => 'https://example.test/'.$handle,
            'method' => 'POST',
            'payload_type' => 'raw_json',
            'queue_enabled' => true,
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

    protected function buy(?Discount $discount = null): Payment
    {
        $payment = app(Checkout::class)->start('offer:stimmgruppe', ['email' => 'leitung@chor.example', 'name' => 'Anna Leitung'], null, $discount)->payment;
        $this->gateway->markPaid($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);

        return $payment->fresh();
    }

    /** @return array<string, list<TriggerEvent>> */
    protected function detected(): array
    {
        return Event::dispatched(TriggerDetected::class)
            ->map(fn ($args) => $args[0]->trigger)
            ->groupBy('triggerHandle')
            ->map->values()->map->all()->all();
    }

    #[Test]
    public function every_offer_event_is_a_trigger_of_its_own_source_type(): void
    {
        $registry = WebhookManager::triggers();

        $this->assertCount(8, WebhookManagerBridge::TRIGGERS);

        foreach (WebhookManagerBridge::TRIGGERS as $handle) {
            $this->assertInstanceOf(OffersTrigger::class, $registry->get($handle));
            $this->assertSame('offers', $registry->get($handle)->sourceType());
            $this->assertArrayHasKey($handle, $registry->options());
        }

        app()->setLocale('de');
        $this->assertSame('Angebote: Platz angenommen', $registry->get('offers.seat_accepted')->label());
        app()->setLocale('en');
        $this->assertSame('Offers: seat accepted', $registry->get('offers.seat_accepted')->label());
    }

    #[Test]
    public function the_seat_events_carry_buyer_and_invitee_but_never_a_token(): void
    {
        $this->offer(['seats' => 3]);
        Event::fake([TriggerDetected::class]);

        $this->buy();
        $pool = SeatPool::query()->sole();
        $seats = app(SeatPools::class);
        $seat = $seats->invite($pool, 'sopran@chor.example', 'Sara Sopran');
        $seats->accept($seat);
        $seats->revoke($seat, 'Stimmgruppe gewechselt');

        $events = $this->detected();

        foreach (['offers.seat_pool_opened', 'offers.seat_invited', 'offers.seat_accepted', 'offers.seat_revoked'] as $handle) {
            $this->assertArrayHasKey($handle, $events, $handle.' fehlt');
        }

        $accepted = $events['offers.seat_accepted'][0];
        $this->assertSame('offers', $accepted->sourceType);
        $this->assertSame((string) $seat->id, $accepted->sourceReference);
        $this->assertSame(['event', 'event_id', 'occurred_at', 'brand', 'subject_type', 'subject_id', 'offer', 'pool', 'seat'], array_keys($accepted->payload));
        $this->assertSame(['seat', $seat->id], [$accepted->payload['subject_type'], $accepted->payload['subject_id']]);
        $this->assertSame(['seat_pool', $pool->id], [$events['offers.seat_pool_opened'][0]->payload['subject_type'], $events['offers.seat_pool_opened'][0]->payload['subject_id']]);
        $this->assertSame(['id' => $pool->offerModel()->id, 'handle' => 'stimmgruppe', 'name' => 'Workshop für die Stimmgruppe'], $accepted->payload['offer']);
        $this->assertSame(['email' => 'leitung@chor.example', 'name' => 'Anna Leitung'], $accepted->payload['pool']['owner']);
        $this->assertSame(3, $accepted->payload['pool']['seats']);
        $this->assertSame('sopran@chor.example', $accepted->payload['seat']['email']);
        $this->assertSame('claimed', $accepted->payload['seat']['status']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $accepted->payload['seat']['claimed_at']);

        $revoked = $events['offers.seat_revoked'][0]->payload;
        $this->assertSame('claimed', $revoked['previous_status']);
        $this->assertSame('Stimmgruppe gewechselt', $revoked['reason']);

        foreach ($events as $list) {
            foreach ($list as $event) {
                $json = json_encode($event->payload);
                $this->assertStringNotContainsString($pool->manage_token, $json);
                $this->assertStringNotContainsString($seat->token, $json);
                $this->assertStringNotContainsString('token', $json);
            }
        }
    }

    #[Test]
    public function sold_out_and_coupon_carry_the_numbers_a_receiver_needs(): void
    {
        $this->offer(['quantity_limit' => 1]);
        Coupon::create(['code' => 'CHOR20', 'name' => 'Chor 20', 'percent' => 20, 'active' => true]);
        Event::fake([TriggerDetected::class]);

        $payment = $this->buy(new Discount('CHOR20', 7800));

        $events = $this->detected();

        $soldOut = $events['offers.sold_out'][0]->payload;
        $this->assertSame(1, $soldOut['quantity_limit']);
        $this->assertSame(1, $soldOut['sold']);
        $this->assertSame('offer', $soldOut['subject_type']);

        $coupon = $events['offers.coupon_redeemed'][0]->payload;
        $couponId = Coupon::query()->value('id');
        $this->assertSame(['coupon', $couponId], [$coupon['subject_type'], $coupon['subject_id']]);
        $this->assertSame(['id' => $couponId, 'code' => 'CHOR20', 'name' => 'Chor 20', 'percent' => 20, 'amount_cent' => null, 'currency' => null], $coupon['coupon']);
        $this->assertSame(7800, $coupon['discount_cent']);
        $this->assertSame($payment->currency, $coupon['currency']);
        $this->assertSame(['email' => 'leitung@chor.example', 'name' => 'Anna Leitung'], $coupon['buyer']);

        // Derselbe Zahlungsblock wie in den Webhooks von statamic-payments.
        $this->assertSame([
            'id', 'provider', 'provider_id', 'status', 'product', 'amount_cent', 'currency', 'discount_code',
            'discount_cent', 'refunded_cent', 'email', 'name', 'country', 'subscription_id', 'parent_payment_id',
            'items', 'attribution', 'created_at', 'paid_at', 'refunded_at', 'charged_back_at',
        ], array_keys($coupon['payment']));
        $this->assertSame($payment->id, $coupon['payment']['id']);
        $this->assertSame($payment->amount_cent, $coupon['payment']['amount_cent']);
        $this->assertSame('CHOR20', $coupon['payment']['discount_code']);
        $this->assertArrayNotHasKey('card_last4', $coupon['payment']);
    }

    #[Test]
    public function a_seat_acceptance_is_delivered_to_the_webhook_that_listens_for_it(): void
    {
        Queue::fake();
        $this->hook('offers.seat_accepted', 'angenommen');
        $this->offer(['seats' => 3]);
        $this->buy();

        $seats = app(SeatPools::class);
        $seat = $seats->invite(SeatPool::query()->sole(), 'sopran@chor.example');
        $seats->accept($seat);

        Queue::assertPushed(ProcessOutboundDeliveryJob::class, 1);
        $this->assertSame(['offers.seat_accepted'], DB::table('webhook_deliveries')->pluck('trigger_type')->all());
    }

    #[Test]
    public function an_event_fires_the_hooks_of_its_own_brand_when_none_is_current(): void
    {
        config()->set('brand-context.multi_brand', true);
        app('brand-context')->forget();
        $akademie = Brand::create(['handle' => 'akademie', 'name' => 'Akademie']);
        $studio = Brand::create(['handle' => 'studio', 'name' => 'Studio']);

        Queue::fake();
        app('brand-context')->runFor($akademie, fn () => $this->hook('offers.sold_out', 'akademie'));
        app('brand-context')->runFor($studio, fn () => $this->hook('offers.sold_out', 'studio'));
        $offer = app('brand-context')->runFor($akademie, fn () => $this->offer(['quantity_limit' => 1]));

        app('brand-context')->forget();
        OfferSoldOut::dispatch($offer, 1);

        $delivery = DB::table('webhook_deliveries')->sole();
        $this->assertSame($akademie->id, (int) $delivery->brand_id);
        $this->assertSame(['id' => $akademie->id, 'handle' => 'akademie'], json_decode((string) $delivery->request_body, true)['payload']['brand']);
    }

    #[Test]
    public function the_same_moment_keeps_its_event_id_and_its_own_time_on_every_delivery(): void
    {
        $this->offer(['seats' => 3]);
        Coupon::create(['code' => 'CHOR20', 'percent' => 20, 'active' => true]);
        Event::fake([TriggerDetected::class]);

        $payment = $this->buy(new Discount('CHOR20', 7800));
        $seats = app(SeatPools::class);
        $seat = $seats->invite(SeatPool::query()->sole(), 'sopran@chor.example');
        $seats->accept($seat);
        $seat->refresh();
        $coupon = Coupon::query()->sole();

        $this->travel(5)->minutes();

        // A redelivered "paid" is the same redemption: same id.
        $again = WebhookManager::triggers()->get('offers.coupon_redeemed')->build(new CouponRedeemed($coupon, $payment));
        $first = $this->detected()['offers.coupon_redeemed'][0];
        $this->assertSame($first->payload['event_id'], $again->payload['event_id']);
        // The suite's recipe: sha1(handle|<type>:<id>|…|<the row's time>).
        $this->assertSame(sha1('offers.coupon_redeemed|coupon:'.$coupon->id.'|payment:'.$payment->id.'|'.$payment->paid_at->format(\DATE_ATOM)), $again->payload['event_id']);
        $this->assertSame($payment->paid_at->format(\DATE_ATOM), $again->eventAt->format(\DATE_ATOM));

        $accepted = $this->detected()['offers.seat_accepted'][0];
        $this->assertSame(sha1('offers.seat_accepted|seat:'.$seat->id.'|'.$seat->claimed_at->format(\DATE_ATOM)), $accepted->payload['event_id']);
        $this->assertSame($seat->claimed_at->format(\DATE_ATOM), $accepted->payload['occurred_at']);
        $this->assertSame(
            $accepted->payload['event_id'],
            WebhookManager::triggers()->get('offers.seat_accepted')->build(new SeatAccepted($seat, $seat->pool))->payload['event_id'],
        );
    }

    #[Test]
    public function a_moment_whose_brand_does_not_exist_goes_to_nobody(): void
    {
        Queue::fake();
        Log::spy();
        $this->hook('offers.sold_out', 'aktuell');
        $offer = $this->offer(['quantity_limit' => 1]);
        $offer->forceFill(['brand_id' => 999])->save();

        OfferSoldOut::dispatch($offer, 1);

        $this->assertSame(0, DB::table('webhook_deliveries')->count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains($message, 'brand that cannot be set') && ($context['brand_id'] ?? null) === 999);
    }

    #[Test]
    public function a_moment_is_sent_after_its_commit_and_never_after_a_rollback(): void
    {
        $offer = $this->offer(['quantity_limit' => 1]);
        Event::fake([TriggerDetected::class]);

        try {
            DB::transaction(function () use ($offer) {
                OfferSoldOut::dispatch($offer, 1);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $this->detected());

        DB::transaction(function () use ($offer) {
            OfferSoldOut::dispatch($offer, 1);
            $this->assertSame([], $this->detected());
        });

        $this->assertArrayHasKey('offers.sold_out', $this->detected());
    }

    #[Test]
    public function a_failing_webhook_manager_does_not_break_the_seat(): void
    {
        $this->offer(['seats' => 3]);
        $this->buy();
        Event::listen(TriggerDetected::class, fn () => throw new \RuntimeException('down'));

        $seats = app(SeatPools::class);
        $seat = $seats->invite(SeatPool::query()->sole(), 'sopran@chor.example');

        $this->assertTrue($seats->accept($seat));
        $this->assertCount(1, $this->access->granted);
    }
}
