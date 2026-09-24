<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\StatamicOffers\Events\CouponRedeemed;
use Goldnead\StatamicOffers\Events\OfferSoldOut;
use Goldnead\StatamicOffers\Events\ShortLinkSwitched;
use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Carbon;

/**
 * The moments of an offer that nobody decides at one place: sold out, the
 * short link switching, a coupon redeemed.
 *
 * Whoever notices first announces: the paid purchase, the first visit after
 * the switch date. Once each, by a conditional UPDATE on a mark column
 * (`sold_out_at`, `link_switched_at`), never by reading first; two deliveries
 * in the same second would both see "not yet". When the offer opens again
 * (limit raised, date moved) the mark is cleared, so the next change is
 * announced again.
 */
class OfferMoments
{
    /** After a paid purchase of this offer: sold out now, and did its link switch? */
    public function afterSale(Offer $offer): void
    {
        $rest = $offer->remainingQuantity();

        if ($rest === 0) {
            if ($this->mark($offer, 'sold_out_at')) {
                OfferSoldOut::dispatch($offer->fresh() ?? $offer, (int) OfferSales::sold($offer));
            }
        } elseif ($offer->sold_out_at !== null) {
            $this->unmark($offer, 'sold_out_at');
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

        if ($this->mark($offer, 'link_switched_at')) {
            ShortLinkSwitched::dispatch($offer->fresh() ?? $offer, $this->switchReason($offer));
        }
    }

    /** A paid payment that used one of this addon's coupons redeemed it. */
    public function couponOf(Payment $payment): void
    {
        $code = $payment->discount_code;

        if (! is_string($code) || trim($code) === '') {
            return;
        }

        $coupon = Coupon::findByCode($code);

        if ($coupon !== null) {
            CouponRedeemed::dispatch($coupon, $payment);
        }
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
