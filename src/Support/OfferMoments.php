<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\StatamicOffers\Events\CouponRedeemed;
use Goldnead\StatamicOffers\Events\OfferSoldOut;
use Goldnead\StatamicOffers\Events\ShortLinkSwitched;
use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The moments of an offer that nobody decides at one place: sold out, the
 * short link switching, a coupon redeemed.
 *
 * Whoever notices first announces: the paid purchase, the first visit after
 * the switch date. Once each: sold out and the switch by a conditional
 * UPDATE on a mark column (`sold_out_at`, `link_switched_at`), a redemption
 * by the unique index of `offer_coupon_redemptions`; never by reading first,
 * two deliveries in the same second would both see "not yet". When the offer
 * opens again (limit raised, date moved) the mark is cleared, so the next
 * change is announced again.
 *
 * **On a site that has not run the migration yet, the moments are skipped.**
 * These calls sit in the payment path and in the short link: a missing column
 * there would keep a paid purchase from being fulfilled and answer the link
 * with a 500. Checked once per process.
 */
class OfferMoments
{
    /** @var array<string, bool> */
    protected static array $schema = [];

    /** After a paid purchase of this offer: sold out now, and did its link switch? */
    public function afterSale(Offer $offer): void
    {
        if (self::has('offers', 'sold_out_at') && $offer->quantity_limit !== null) {
            // Paid only. An open checkout counts against the limit when a new
            // one starts (OfferSales::sold()), but it has not sold anything:
            // announcing "sold out" for it would be wrong every time a card is
            // declined.
            $paid = OfferSales::paid($offer);

            if ($paid !== null && $paid >= $offer->quantity_limit) {
                if ($offer->sold_out_at === null && $this->mark($offer, 'sold_out_at')) {
                    OfferSoldOut::dispatch($offer->fresh() ?? $offer, $paid);
                }
            } elseif ($offer->sold_out_at !== null) {
                $this->unmark($offer, 'sold_out_at');
            }
        }

        $this->link($offer);
    }

    /**
     * Where the short link leads now, announced the first time it is the
     * second target.
     *
     * @param  string|null  $destination  what the caller already worked out
     */
    public function link(Offer $offer, ?string $destination = null): void
    {
        if (! self::has('offers', 'link_switched_at')) {
            return;
        }

        if (trim((string) $offer->link_slug) === '' || trim((string) $offer->link_fallback) === '') {
            return;
        }

        $destination ??= $offer->linkDestination();

        if ($destination !== Offer::LINK_FALLBACK) {
            if ($offer->link_switched_at !== null) {
                $this->unmark($offer, 'link_switched_at');
            }

            return;
        }

        // Already announced: every later visit reads, and writes nothing.
        if ($offer->link_switched_at !== null) {
            return;
        }

        if ($this->mark($offer, 'link_switched_at')) {
            ShortLinkSwitched::dispatch($offer->fresh() ?? $offer, $this->switchReason($offer));
        }
    }

    /**
     * A paid payment that used one of this addon's coupons redeemed it, once:
     * payments delivers "paid" again when a listener fails, and a redemption
     * announced twice starts every automation on it twice.
     */
    public function couponOf(Payment $payment): void
    {
        $code = $payment->discount_code;

        if (! is_string($code) || trim($code) === '' || ! self::hasTable('offer_coupon_redemptions')) {
            return;
        }

        $coupon = Coupon::findByCode($code);

        if ($coupon === null) {
            return;
        }

        try {
            DB::table('offer_coupon_redemptions')->insert([
                'coupon_id' => $coupon->getKey(),
                'payment_id' => $payment->getKey(),
                'created_at' => Carbon::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        CouponRedeemed::dispatch($coupon, $payment);
    }

    /** For tests: a column added or dropped between two cases. */
    public static function forgetColumns(): void
    {
        self::$schema = [];
    }

    /**
     * Only a column that is there is remembered: a worker that started before
     * `migrate` notices the column at its next sale, not at its restart.
     */
    protected static function has(string $table, string $column): bool
    {
        return self::remember($table.'.'.$column, fn () => Schema::hasColumn($table, $column));
    }

    protected static function hasTable(string $table): bool
    {
        return self::remember($table, fn () => Schema::hasTable($table));
    }

    /** @param  callable(): bool  $check */
    protected static function remember(string $key, callable $check): bool
    {
        if (isset(self::$schema[$key])) {
            return true;
        }

        if ($check()) {
            self::$schema[$key] = true;

            return true;
        }

        return false;
    }

    protected function switchReason(Offer $offer): string
    {
        $stichtag = $offer->link_switch_at ?? $offer->available_until;

        return $stichtag !== null && Carbon::now()->gte($stichtag)
            ? ShortLinkSwitched::REASON_DATE
            : ShortLinkSwitched::REASON_SOLD_OUT;
    }

    protected function mark(Offer $offer, string $column): bool
    {
        // toBase(): a mark is not an edit of the offer, `updated_at` stays.
        return Offer::query()
            ->whereKey($offer->getKey())
            ->whereNull($column)
            ->toBase()
            ->update([$column => Carbon::now()]) > 0;
    }

    protected function unmark(Offer $offer, string $column): void
    {
        Offer::query()->whereKey($offer->getKey())->toBase()->update([$column => null]);
    }
}
