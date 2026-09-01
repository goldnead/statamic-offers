<?php

namespace Goldnead\StatamicOffers\Query\Scopes\Filters;

use Goldnead\StatamicOffers\Http\Controllers\Cp\OffersController;
use Goldnead\StatamicOffers\Models\Offer;
use Statamic\Query\Scopes\Filter;

/**
 * Where an offer appears.
 *
 * The listing's way of becoming an upsell overview: pick "after the purchase"
 * and the rows left are the upsells, with their shown, accepted and revenue
 * columns beside them. A separate screen for that would be the same table
 * with one filter pre-applied.
 */
class OfferSlot extends Filter
{
    protected static $handle = 'statamic_offers_slot';

    protected $pinned = true;

    public static function title()
    {
        return __('statamic-offers::messages.field_slot');
    }

    public function fieldItems()
    {
        return [
            'value' => [
                'type' => 'select',
                'placeholder' => __('statamic-offers::messages.field_slot'),
                'options' => $this->options(),
            ],
        ];
    }

    public function apply($query, $values)
    {
        $slot = (string) ($values['value'] ?? '');

        if (in_array($slot, Offer::slots(), true)) {
            $query->where('slot', $slot);
        }
    }

    public function badge($values)
    {
        return self::title().': '.($this->options()[$values['value']] ?? $values['value']);
    }

    public function visibleTo($key)
    {
        return $key === OffersController::SCOPE;
    }

    /**
     * @return array<string, string>
     */
    protected function options(): array
    {
        $options = [];

        foreach (Offer::slots() as $slot) {
            $options[$slot] = __('statamic-offers::messages.slot_'.$slot);
        }

        return $options;
    }
}
