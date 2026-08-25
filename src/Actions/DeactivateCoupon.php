<?php

namespace Goldnead\StatamicOffers\Actions;

use Goldnead\StatamicOffers\Models\Coupon;

class DeactivateCoupon extends CouponAction
{
    protected static $handle = 'statamic_offers_deactivate_coupon';

    protected $confirm = false;

    public static function title()
    {
        return __('statamic-offers::messages.coupon_action_deactivate');
    }

    public function icon(): string
    {
        return 'x-square';
    }

    public function visibleTo($item)
    {
        return parent::visibleTo($item) && $item->active;
    }

    public function run($items, $values)
    {
        $items->each(fn (Coupon $coupon) => $coupon->update(['active' => false]));

        return trans_choice(__('statamic-offers::messages.coupon_deactivated'), $items->count(), ['count' => $items->count()]);
    }
}
