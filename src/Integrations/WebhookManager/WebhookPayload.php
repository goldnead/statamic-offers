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
use Illuminate\Support\Facades\Log;
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
        [$type, $id] = self::subjectOf($event);
        $parts = self::momentParts($event);

        return [
            'event' => $handle,
            // The same moment always gets the same id, however often it is
            // sent. A coupon is keyed by the payment, so a redelivered "paid"
            // is recognisable as the same redemption.
            'event_id' => self::eventId($handle, $parts),
            'occurred_at' => self::occurredAt($parts)->format(\DATE_ATOM),
            // The event's brand; on a site without brands the one current,
            // as the other suite addons send it.
            'brand' => self::brand(is_int($brandId) ? $brandId : self::currentBrandId()),
            // Named outright, as the payments addon does: without it the
            // manager files every `offers.*` delivery under an offer, a seat
            // included.
            'subject_type' => $type,
            'subject_id' => $id,
            ...self::body($event),
        ];
    }

    /**
     * `sha1(handle|part|part…)`, dates as DATE_ATOM: the same recipe in every
     * addon of the suite.
     *
     * @param  list<mixed>  $parts
     */
    public static function eventId(string $handle, array $parts): string
    {
        return sha1(implode('|', array_map(
            fn ($part) => $part instanceof \DateTimeInterface ? $part->format(\DATE_ATOM) : (string) $part,
            [$handle, ...$parts],
        )));
    }

    /**
     * The first date among the parts, the moment's own time. The clock only
     * where no row records one.
     *
     * @param  list<mixed>  $parts
     */
    public static function occurredAt(array $parts): \DateTimeInterface
    {
        foreach ($parts as $part) {
            if ($part instanceof \DateTimeInterface) {
                return $part;
            }
        }

        return now();
    }

    /**
     * What separates this moment from every other moment of the same kind:
     * the row as `<type>:<id>`, then the time the moment wrote on it
     * (invited_at, claimed_at, revoked_at, created_at, closed_at, sold_out_at,
     * link_switched_at). A redemption is the coupon and the payment, then
     * paid_at. Never the time of sending.
     *
     * @return list<mixed>
     */
    public static function momentParts(object $event): array
    {
        return match (true) {
            $event instanceof SeatInvited => ['seat:'.$event->seat->id, $event->seat->invited_at ?? 'invited'],
            $event instanceof SeatAccepted => ['seat:'.$event->seat->id, $event->seat->claimed_at ?? 'claimed'],
            $event instanceof SeatRevoked => ['seat:'.$event->seat->id, $event->seat->revoked_at ?? 'revoked'],
            $event instanceof SeatPoolOpened => ['seat_pool:'.$event->pool->id, $event->pool->created_at ?? 'opened'],
            $event instanceof SeatPoolClosed => ['seat_pool:'.$event->pool->id, $event->pool->closed_at ?? 'closed'],
            $event instanceof OfferSoldOut => ['offer:'.$event->offer->getKey(), $event->offer->sold_out_at ?? 'sold_out'],
            $event instanceof ShortLinkSwitched => ['offer:'.$event->offer->getKey(), $event->offer->link_switched_at ?? 'switched:'.$event->reason],
            $event instanceof CouponRedeemed => ['coupon:'.$event->coupon->id, 'payment:'.$event->payment->id, $event->payment->paid_at ?? 'paid'],
            default => [$event::class],
        };
    }

    /**
     * Run the hand-over as the brand the moment names, or not at all.
     *
     * A brand that cannot be made current (a pool or offer stamped with a
     * brand since deleted) is not replaced by whichever brand is current: its
     * hooks belong to another tenant. Logged, not delivered. No brand named,
     * or no brand-context installed: runs as it is. The same rule as the
     * payments addon's `WebhookPayload::runForBrand()`.
     *
     * @param  \Closure(): void  $callback
     */
    public static function runForBrand(?int $brand, \Closure $callback, string $handle): bool
    {
        if (! $brand || ! app()->bound('brand-context')) {
            $callback();

            return true;
        }

        $ran = false;

        try {
            app('brand-context')->runFor($brand, function () use ($callback, &$ran): void {
                $ran = true;
                $callback();
            });

            return true;
        } catch (Throwable $e) {
            if ($ran) {
                throw $e;
            }

            Log::warning('statamic-offers: the moment names a brand that cannot be set; the webhook was not delivered rather than sent through another brand\'s hooks.', [
                'trigger' => $handle,
                'brand_id' => $brand,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The object a moment is about: the seat, the pool, the coupon, else the
     * offer.
     *
     * @return array{0: string|null, 1: int|null}
     */
    public static function subjectOf(object $event): array
    {
        return match (true) {
            $event instanceof SeatInvited, $event instanceof SeatAccepted, $event instanceof SeatRevoked => ['seat', (int) $event->seat->id],
            $event instanceof SeatPoolOpened, $event instanceof SeatPoolClosed => ['seat_pool', (int) $event->pool->id],
            $event instanceof CouponRedeemed => ['coupon', (int) $event->coupon->id],
            $event instanceof OfferSoldOut, $event instanceof ShortLinkSwitched => ['offer', $event->offer->getKey() !== null ? (int) $event->offer->getKey() : null],
            default => [null, null],
        };
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
                'currency' => $event->payment->currency,
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
            'currency' => $coupon->currency,
        ];
    }

    /**
     * The payment block exactly as statamic-payments sends it in its own
     * webhooks (`WebhookPayload::payment()` there, 1.26), so a receiver reads
     * one shape from every addon. A copy, not a call: offers runs on payments
     * before 1.26, which has no such class. The test pins the keys.
     * Not: card digits or label, mandate, customer reference, meta, consent
     * text, referrer, landing page.
     *
     * @return array<string, mixed>
     */
    protected static function payment(Payment $payment): array
    {
        $providerId = (string) $payment->provider_id;

        return [
            'id' => $payment->getKey(),
            'provider' => $payment->provider,
            // Null while the provider has not answered: a placeholder nobody can look up.
            // `Payment::PLACEHOLDER_PROVIDER_PREFIX`, spelled out: offers still allows
            // payments versions that do not have it.
            'provider_id' => $providerId === '' || str_starts_with($providerId, 'pending-') ? null : $providerId,
            'status' => $payment->status,
            'product' => $payment->product,
            'amount_cent' => (int) $payment->amount_cent,
            'currency' => $payment->currency,
            'discount_code' => $payment->discount_code,
            'discount_cent' => $payment->discount_cent === null ? null : (int) $payment->discount_cent,
            'refunded_cent' => (int) ($payment->refunded_cent ?? 0),
            'email' => $payment->email,
            'name' => $payment->name,
            'country' => $payment->country,
            'subscription_id' => $payment->subscription_id,
            'parent_payment_id' => $payment->parent_payment_id,
            'items' => $payment->exists ? $payment->items()->orderBy('id')->get()->map(fn ($item): array => [
                'product' => $item->product,
                'offer' => $item->offer,
                'name' => $item->name,
                'kind' => $item->kind,
                'quantity' => (int) $item->quantity,
                'amount_cent' => (int) $item->amount_cent,
                'discount_cent' => (int) ($item->discount_cent ?? 0),
            ])->values()->all() : [],
            'attribution' => [
                'utm_source' => $payment->utm_source,
                'utm_medium' => $payment->utm_medium,
                'utm_campaign' => $payment->utm_campaign,
                'utm_term' => $payment->utm_term,
                'utm_content' => $payment->utm_content,
            ],
            'created_at' => $payment->created_at?->format(\DATE_ATOM),
            'paid_at' => $payment->paid_at?->format(\DATE_ATOM),
            'refunded_at' => $payment->refunded_at?->format(\DATE_ATOM),
            'charged_back_at' => $payment->charged_back_at?->format(\DATE_ATOM),
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
