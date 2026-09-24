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
use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Models\Seat;
use Goldnead\StatamicOffers\Models\SeatPool;
use Goldnead\StatamicPayments\Models\Payment;
use Throwable;

/**
 * What a webhook receiver gets for an offer event.
 *
 * Chosen field by field. **Never a token**: a seat's token and a pool's
 * `manage_token` are the only thing that authorises the two seat pages, and
 * whoever holds one acts as the invited person or the buyer. Nor a payment's
 * provider ids, mandate or card hint.
 *
 * Every payload has the frame its suite siblings share:
 *
 *     event        the trigger handle, e.g. offers.seat_accepted
 *     occurred_at  ISO 8601 with offset
 *     brand        {id, handle} or null
 *
 * Amounts are integer cents next to their currency.
 *
 * Free of webhook-manager classes, so it loads on a site without that addon.
 */
final class WebhookPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function for(string $handle, object $event): array
    {
        $brandId = $event->brandId ?? null;

        return [
            'event' => $handle,
            'occurred_at' => now()->toIso8601String(),
            // The event's brand; on a site without brands the one current,
            // as the other suite addons send it.
            'brand' => self::brand(is_int($brandId) ? $brandId : self::currentBrandId()),
            ...self::body($event),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function body(object $event): array
    {
        return match (true) {
            $event instanceof SeatPoolOpened => [
                'offer' => self::offerOf($event->pool),
                'pool' => self::pool($event->pool),
            ],
            $event instanceof SeatInvited, $event instanceof SeatAccepted => [
                'offer' => self::offerOf($event->pool),
                'pool' => self::pool($event->pool),
                'seat' => self::seat($event->seat),
            ],
            $event instanceof SeatRevoked => [
                'offer' => self::offerOf($event->pool),
                'pool' => self::pool($event->pool),
                'seat' => self::seat($event->seat),
                'previous_status' => $event->previousStatus,
                'reason' => $event->reason,
            ],
            $event instanceof SeatPoolClosed => [
                'offer' => self::offerOf($event->pool),
                'pool' => self::pool($event->pool),
                'reason' => $event->reason,
            ],
            $event instanceof OfferSoldOut => [
                'offer' => self::offer($event->offer),
                'quantity_limit' => $event->offer->quantity_limit,
                'sold' => $event->sold,
            ],
            $event instanceof CouponRedeemed => [
                'coupon' => self::coupon($event->coupon),
                'discount_cent' => (int) $event->payment->discount_cent,
                'currency' => strtoupper((string) $event->payment->currency),
                'buyer' => ['email' => $event->payment->email, 'name' => $event->payment->name],
                'payment' => self::payment($event->payment),
            ],
            $event instanceof ShortLinkSwitched => [
                'offer' => self::offer($event->offer),
                'reason' => $event->reason,
                'link' => [
                    'slug' => $event->offer->link_slug,
                    'target' => $event->offer->link_target,
                    'fallback' => $event->offer->link_fallback,
                    'switch_at' => $event->offer->link_switch_at?->toIso8601String(),
                ],
            ],
            default => [],
        };
    }

    /**
     * @return array{id: int|null, handle: string, name: string|null}
     */
    protected static function offerOf(SeatPool $pool): array
    {
        $offer = $pool->offerModel();

        return $offer !== null ? self::offer($offer) : ['id' => null, 'handle' => $pool->offer, 'name' => null];
    }

    /**
     * @return array{id: int|null, handle: string, name: string|null}
     */
    protected static function offer(Offer $offer): array
    {
        return [
            'id' => $offer->getKey() !== null ? (int) $offer->getKey() : null,
            'handle' => (string) $offer->handle,
            'name' => $offer->name,
        ];
    }

    /**
     * The buyer's seats, without the link that manages them.
     *
     * @return array<string, mixed>
     */
    protected static function pool(SeatPool $pool): array
    {
        return [
            'id' => (int) $pool->id,
            'product' => $pool->product,
            'seats' => (int) $pool->seats,
            'taken' => $pool->takenCount(),
            'owner' => ['email' => $pool->owner_email, 'name' => $pool->owner_name],
            'payment_id' => (int) $pool->payment_id,
            'closed_at' => $pool->closed_at?->toIso8601String(),
        ];
    }

    /**
     * One seat, without the link that accepts it.
     *
     * @return array<string, mixed>
     */
    protected static function seat(Seat $seat): array
    {
        return [
            'id' => (int) $seat->id,
            'email' => $seat->email,
            'name' => $seat->name,
            'status' => $seat->status,
            'invited_at' => $seat->invited_at?->toIso8601String(),
            'claimed_at' => $seat->claimed_at?->toIso8601String(),
            'revoked_at' => $seat->revoked_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function coupon(Coupon $coupon): array
    {
        return [
            'id' => (int) $coupon->id,
            'code' => $coupon->code,
            'name' => $coupon->name,
            'percent' => $coupon->percent,
            'amount_cent' => $coupon->amount_cent,
            'currency' => $coupon->currency !== null ? strtoupper($coupon->currency) : null,
        ];
    }

    /**
     * @return array{id: int, product: string, amount_cent: int, currency: string, status: string, provider: string, paid_at: string|null}
     */
    protected static function payment(Payment $payment): array
    {
        return [
            'id' => (int) $payment->id,
            'product' => (string) $payment->product,
            'amount_cent' => (int) $payment->amount_cent,
            'currency' => strtoupper((string) $payment->currency),
            'status' => (string) $payment->status,
            'provider' => (string) $payment->provider,
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ];
    }

    protected static function currentBrandId(): ?int
    {
        try {
            $manager = app()->bound('brand-context') ? app('brand-context') : null;

            return $manager !== null && $manager->hasCurrent() ? (int) $manager->currentId() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{id: int, handle: string|null}|null
     */
    public static function brand(?int $brandId): ?array
    {
        if ($brandId === null || $brandId <= 0) {
            return null;
        }

        $model = 'Goldnead\\BrandContext\\Models\\Brand';
        $handle = null;

        if (class_exists($model)) {
            try {
                $handle = $model::query()->whereKey($brandId)->value('handle');
            } catch (Throwable) {
                $handle = null;
            }
        }

        return ['id' => $brandId, 'handle' => is_string($handle) ? $handle : null];
    }
}
