<?php

namespace Goldnead\StatamicOffers\Actions;

use Goldnead\StatamicOffers\Models\Coupon;

class ActivateCoupon extends CouponAction
{
    protected static $handle = 'statamic_offers_activate_coupon';

    protected $confirm = false;

    public static function title()
    {
        return __('statamic-offers::messages.coupon_action_activate');
    }

    public function icon(): string
    {
        return 'checkmark';
    }

    public function visibleTo($item)
    {
        return parent::visibleTo($item) && ! $item->active;
    }

    public function run($items, $values)
    {
        $items->each(fn (Coupon $coupon) => $coupon->update(['active' => true]));

        return trans_choice(__('statamic-offers::messages.coupon_activated'), $items->count(), ['count' => $items->count()]);
    }
}
