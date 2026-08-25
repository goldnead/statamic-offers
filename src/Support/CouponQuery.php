<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\StatamicOffers\Models\Coupon;
use Statamic\Query\Builder;

/**
 * `Coupon::isLive()`, said in SQL.
 *
 * The model answers the question for one row, which is the right shape for a
 * checkout and the wrong shape for a listing: filtering three hundred coupons in
 * PHP means loading three hundred coupons, and the pager then counts the wrong
 * total — the listing would say "1 of 42" over four rows. So the same five
 * conditions live here as `where` clauses, in the same order and with the same
 * meaning, and the filter on the screen uses these.
 *
 * They are two methods rather than one with a flag because "not live" is not the
 * negation of a single clause: a coupon is out if *any* condition fails, so the
 * opposite is an OR over all of them, and a reader should be able to see that.
 */
class CouponQuery
{
    /**
     * The parameter is untyped on purpose: this is called both with an Eloquent
     * builder from the controller and with whatever core hands a filter's
     * `apply()`, and both understand these clauses.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Coupon>|Builder  $query
     */
    public static function live($query): void
    {
        $now = now();

        $query->where('active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->where(fn ($q) => $q->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses'))
            ->where(fn ($q) => $q->whereNotNull('percent')->orWhereNotNull('amount_cent'));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Coupon>|Builder  $query
     */
    public static function notLive($query): void
    {
        $now = now();

        $query->where(function ($q) use ($now) {
            $q->where('active', false)
                ->orWhere('starts_at', '>', $now)
                ->orWhere('ends_at', '<', $now)
                ->orWhereColumn('used_count', '>=', 'max_uses')
                ->orWhere(fn ($inner) => $inner->whereNull('percent')->whereNull('amount_cent'));
        });
    }
}
