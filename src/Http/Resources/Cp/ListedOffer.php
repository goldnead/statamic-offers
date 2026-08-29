<?php

namespace Goldnead\StatamicOffers\Http\Resources\Cp;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\CpNumber;
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
            // Wie viele Stuecke insgesamt, damit ein Buendel in der Liste als
            // Buendel zu erkennen ist und nicht als ein Produkt mit einem
            // seltsam hohen Preis. Null statt 1, aus demselben Grund wie bei
            // `bumps_count`: eine Spalte voller Einsen liest sich wie ein
            // Fehler, eine leere Zelle wie „kein Buendel".
            'products_count' => $this->isBundle() ? count($this->productHandles()) : null,
            // Formatted here rather than by the model, so this listing and the
            // coupons listing next door write a price the same way. The model's
            // `amount()` is a machine-readable decimal and stays that way.
            'amount' => $this->money($this->amountCent()),
            'currency' => $this->currency(),
            'compare_at' => $this->money($this->compare_at_cent),
            'own_price' => $this->amount_cent !== null,
            'slot' => $this->slot,
            'slot_label' => __('statamic-offers::messages.slot_'.$this->slot),
            // A count, not a list: the column has one line and a row carrying
            // four bumps would push the price out of view. Null rather than 0,
            // because a column full of zeroes reads as a broken feature while
            // an empty cell reads as "none".
            'bumps_count' => count((array) ($this->bumps ?? [])) ?: null,
            'active' => $this->active,
            'sellable' => $this->isSellable(),
            'shown_count' => $this->shown_count,
            'accepted_count' => $this->accepted_count,
            // Worked out here rather than in the template so the screen and any
            // report agree, and so nobody divides by zero in Antlers.
            'conversion' => $this->shown_count > 0
                ? CpNumber::decimal($this->accepted_count / $this->shown_count * 100, 1)
                : null,
            'edit_values' => [
                'name' => $this->name,
                'handle' => $this->handle,
                'product' => $this->product,
                'products' => array_values((array) ($this->products ?? [])),
                'amount_cent' => $this->amount_cent,
                'compare_at_cent' => $this->compare_at_cent,
                'currency' => $this->currency,
                'headline' => $this->headline,
                'body' => $this->body,
                'button_label' => $this->button_label,
                'image' => $this->image,
                'slot' => $this->slot,
                'bumps' => (array) ($this->bumps ?? []),
                'active' => $this->active,
            ],
        ];
    }

    protected function money(?int $cent): ?string
    {
        return $cent === null ? null : CpNumber::decimal($cent / 100, 2);
    }
}
