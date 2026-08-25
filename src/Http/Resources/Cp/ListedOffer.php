<?php

namespace Goldnead\StatamicOffers\Http\Resources\Cp;

use Goldnead\StatamicOffers\Models\Offer;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row.
 *
 * @mixin Offer
 */
class ListedOffer extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'handle' => $this->handle,
            'name' => $this->name,
            'product' => $this->product,
            'amount' => $this->amount(),
            'currency' => $this->currency(),
            'compare_at' => $this->compareAt(),
            'own_price' => $this->amount_cent !== null,
            'slot' => $this->slot,
            'slot_label' => __('statamic-offers::messages.slot_'.$this->slot),
            'active' => $this->active,
            'sellable' => $this->isSellable(),
            'shown_count' => $this->shown_count,
            'accepted_count' => $this->accepted_count,
            // Worked out here rather than in the template so the screen and any
            // report agree, and so nobody divides by zero in Antlers.
            'conversion' => $this->shown_count > 0
                ? round($this->accepted_count / $this->shown_count * 100, 1)
                : null,
            'edit_values' => [
                'name' => $this->name,
                'handle' => $this->handle,
                'product' => $this->product,
                'amount_cent' => $this->amount_cent,
                'compare_at_cent' => $this->compare_at_cent,
                'currency' => $this->currency,
                'headline' => $this->headline,
                'body' => $this->body,
                'button_label' => $this->button_label,
                'slot' => $this->slot,
                'active' => $this->active,
            ],
        ];
    }
}
