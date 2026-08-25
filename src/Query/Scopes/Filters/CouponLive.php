<?php

namespace Goldnead\StatamicOffers\Query\Scopes\Filters;

use Goldnead\StatamicOffers\Http\Controllers\Cp\CouponsController;
use Goldnead\StatamicOffers\Support\CouponQuery;
use Statamic\Query\Scopes\Filter;

/**
 * Whether this code would work if somebody typed it right now.
 *
 * The whole of `Coupon::isLive()`: switched on, inside its window, not used up,
 * and actually worth something. Applied as `where` clauses rather than by
 * filtering the collection afterwards, so the pagination counts the rows the
 * filter left rather than the rows the query returned.
 */
class CouponLive extends Filter
{
    protected static $handle = 'statamic_offers_coupon_live';

    protected $pinned = true;

    public static function title()
    {
        return __('statamic-offers::messages.coupon_filter_live');
    }

    public function fieldItems()
    {
        return [
            'value' => [
                'type' => 'select',
                'placeholder' => __('statamic-offers::messages.coupon_filter_live'),
                'options' => $this->options(),
            ],
        ];
    }

    public function apply($query, $values)
    {
        $values['value'] === 'yes'
            ? CouponQuery::live($query)
            : CouponQuery::notLive($query);
    }

    public function badge($values)
    {
        return self::title().': '.($this->options()[$values['value']] ?? $values['value']);
    }

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
