<?php

namespace Goldnead\StatamicOffers\Integrations\WebhookManager;

use Goldnead\StatamicOffers\Events\CouponRedeemed;
use Goldnead\StatamicOffers\Events\OfferSoldOut;
use Goldnead\StatamicOffers\Events\SeatAccepted;
use Goldnead\StatamicOffers\Events\SeatInvited;
use Goldnead\StatamicOffers\Events\SeatPoolClosed;
use Goldnead\StatamicOffers\Events\SeatPoolOpened;
use Goldnead\StatamicOffers\Events\SeatRevoked;
use Goldnead\StatamicOffers\Events\ShortLinkSwitched;
use Goldnead\WebhookManager\Events\TriggerDetected;
use Goldnead\WebhookManager\Facades\WebhookManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Optional coupling to goldnead/statamic-webhook-manager: every offer event
 * becomes a trigger an outbound webhook can listen to.
 *
 * Nothing of the webhook manager is touched before its classes are checked by
 * name; {@see OffersTrigger} implements its interface and is only created
 * after that check.
 *
 * Booted from an `app->booted()` callback with a retry at the end of that
 * queue, because whether the webhook manager has bound its service by then
 * depends on package order. Idempotent, so the retry costs nothing.
 */
class WebhookManagerBridge
{
    public const FACADE = 'Goldnead\\WebhookManager\\Facades\\WebhookManager';

    public const TRIGGER_INTERFACE = 'Goldnead\\WebhookManager\\Contracts\\TriggerInterface';

    /**
     * Event class => trigger handle.
     *
     * @var array<class-string, string>
     */
    public const TRIGGERS = [
        SeatPoolOpened::class => 'offers.seat_pool_opened',
        SeatInvited::class => 'offers.seat_invited',
        SeatAccepted::class => 'offers.seat_accepted',
        SeatRevoked::class => 'offers.seat_revoked',
        SeatPoolClosed::class => 'offers.seat_pool_closed',
        OfferSoldOut::class => 'offers.sold_out',
        CouponRedeemed::class => 'offers.coupon_redeemed',
        ShortLinkSwitched::class => 'offers.link_switched',
    ];

    protected bool $booted = false;

    public static function available(): bool
    {
        return (bool) config('statamic-offers.webhook_manager.enabled', true)
            && class_exists(self::FACADE)
            && interface_exists(self::TRIGGER_INTERFACE);
    }

    public function boot(Dispatcher $events): void
    {
        if ($this->booted || ! static::available()) {
            return;
        }

        // Bound once the webhook manager's own provider has booted. Not yet:
        // stay unbooted so the retry can still register.
        if (! app()->bound('webhook-manager')) {
            return;
        }

        $this->booted = true;

        foreach (static::TRIGGERS as $eventClass => $handle) {
            try {
                WebhookManager::registerTrigger(new OffersTrigger($handle, 'statamic-offers::webhooks.'.self::key($handle)));
            } catch (\Throwable $e) {
                Log::warning('Offers → Webhook Manager: trigger ['.$handle.'] not registered: '.$e->getMessage());

                continue;
            }

            $events->listen($eventClass, function (object $event) use ($handle): void {
                $this->dispatch($handle, $event);
            });
        }
    }

    /** `offers.seat_accepted` → `seat_accepted`, the label's translation key. */
    public static function key(string $handle): string
    {
        return substr($handle, strlen('offers.'));
    }

    /**
     * In the event's brand: the webhook manager looks hooks up per brand, and
     * a seat page opened from a mail or the provider's webhook may run under
     * another brand or none. Never breaks the offer's own write.
     */
    protected function dispatch(string $handle, object $event): void
    {
        // After the write is committed: a moment inside a transaction that
        // is rolled back never happened (an invitation inside `invite()`'s
        // transaction, a seat taken back during a refund). Outside a
        // transaction this runs at once.
        try {
            DB::afterCommit(fn () => $this->deliver($handle, $event));
        } catch (\Throwable $e) {
            Log::warning('Offers → Webhook Manager: ['.$handle.'] not dispatched: '.$e->getMessage());
        }
    }

    protected function deliver(string $handle, object $event): void
    {
        try {
            $trigger = WebhookManager::triggers()->get($handle);

            if ($trigger === null) {
                return;
            }

            $fire = fn () => event(new TriggerDetected($trigger->build($event)));
            $brandId = $event->brandId ?? null;

            if (! is_int($brandId) || $brandId <= 0 || ! app()->bound('brand-context')) {
                $fire();

                return;
            }

            // A brand that cannot be made current is not replaced by the one
            // that happens to be: that would hand a buyer's seats to another
            // brand's hooks. Not sent, and said so.
            if (! self::brandExists($brandId)) {
                Log::warning('Offers → Webhook Manager: ['.$handle.'] not dispatched, its brand ['.$brandId.'] does not exist.');

                return;
            }

            app('brand-context')->runFor($brandId, $fire);
        } catch (\Throwable $e) {
            Log::warning('Offers → Webhook Manager: ['.$handle.'] not dispatched: '.$e->getMessage());
        }
    }

    protected static function brandExists(int $brandId): bool
    {
        $model = 'Goldnead\\BrandContext\\Models\\Brand';

        return class_exists($model) && $model::query()->whereKey($brandId)->exists();
    }
}
