<?php

namespace Goldnead\StatamicOffers\Query\Scopes\Filters;

use Goldnead\StatamicOffers\Http\Controllers\Cp\CouponsController;
use Statamic\Query\Scopes\Filter;

/**
 * The switch on the coupon, on its own.
 *
 * Deliberately not the same question as "valid right now": a code can be
 * switched on and still refuse to work because its window has passed. Someone
 * looking for the one they turned off in March wants this filter; someone
 * asking why nothing applies at checkout wants the other one.
 */
class CouponActive extends Filter
{
    protected static $handle = 'statamic_offers_coupon_active';

    protected $pinned = true;

    public static function title()
    {
        return __('statamic-offers::messages.coupon_filter_active');
    }

    public function fieldItems()
    {
        return [
            'value' => [
                'type' => 'select',
                'placeholder' => __('statamic-offers::messages.coupon_filter_active'),
                'options' => $this->options(),
            ],
        ];
    }

    public function apply($query, $values)
    {
        $query->where('active', $values['value'] === 'yes');
    }

    public function badge($values)
    {
        return self::title().': '.($this->options()[$values['value']] ?? $values['value']);
    }

    /**
     * Registered globally, like every scope, so without this it would also turn
     * up on Entries and Users — where it would filter on a column that is not
     * there.
     */
    public function visibleTo($key)
    {
        return $key === CouponsController::SCOPE;
    }

    /**
     * @return array<string, string>
     */
    protected function options(): array
    {
        return [
            'yes' => __('statamic-offers::messages.yes'),
            'no' => __('statamic-offers::messages.no'),
        ];
    }
}
