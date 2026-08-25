<?php

namespace Goldnead\StatamicOffers\Actions;

use Goldnead\StatamicOffers\Models\Coupon;
use Statamic\Actions\Action;

/**
 * The two things every coupon action has to get right.
 *
 * Actions are registered globally and offered on every listing in the Control
 * Panel, so an action that forgets `visibleTo` turns up in the bulk toolbar of
 * the Entries screen — and one that forgets `authorize` is a writing endpoint
 * with no lock on it, reachable by anybody who can open the CP at all.
 */
abstract class CouponAction extends Action
{
    public function visibleTo($item)
    {
        return $item instanceof Coupon;
    }

    public function authorize($user, $item)
    {
        return $user->can('access coupons utility');
    }
}
