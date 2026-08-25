<?php

namespace Goldnead\StatamicOffers\Tags;

use Goldnead\StatamicOffers\Models\Offer;
use Statamic\Tags\Tags;

/**
 * Offers, for a template.
 *
 * {{ offers:show handle="fruehling-upsell" }} … {{ /offers:show }}
 * {{ offers:slot slot="bump" }} … {{ /offers:slot }}
 *
 * Both yield nothing at all when there is nothing to offer, and — like every
 * Statamic tag pair — parse their block once anyway with `no_results` set. Put
 * the markup in the `{{ else }}` branch or an empty slot prints an empty offer.
 */
class Offers extends Tags
{
    protected static $handle = 'offers';

    /** One offer, by handle. */
    public function show(): array|string
    {
        $offer = Offer::query()->where('handle', (string) $this->params->get('handle', ''))->first();

        if (! $offer || ! $offer->isSellable()) {
            return $this->parseNoResults();
        }

        if (config('statamic-offers.count_impressions', true)) {
            $offer->recordShown();
        }

        return $this->parse($this->row($offer));
    }

    /** Every active offer for a slot, in the order they were made. */
    public function slot(): array|string
    {
        $slot = (string) $this->params->get('slot', Offer::SLOT_STANDALONE);

        if (! in_array($slot, Offer::slots(), true)) {
            return $this->parseNoResults();
        }

        $offers = Offer::query()
            ->active()
            ->forSlot($slot)
            ->orderBy('id')
            ->limit(max(1, (int) $this->params->get('limit', 5)))
            ->get()
            ->filter(fn (Offer $offer) => $offer->isSellable());

        if ($offers->isEmpty()) {
            return $this->parseNoResults();
        }

        if (config('statamic-offers.count_impressions', true)) {
            $offers->each(fn (Offer $offer) => $offer->recordShown());
        }

        return $this->parseLoop($offers->map(fn (Offer $offer) => $this->row($offer))->all());
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Offer $offer): array
    {
        $prefix = Offer::prefix();

        return [
            'id' => $offer->id,
            'handle' => $offer->handle,
            // What a checkout is given. Handing the template the buyable handle
            // rather than the bare one means nobody has to remember the prefix,
            // and a typo cannot silently buy a *product* of the same name.
            'buy_handle' => $prefix.$offer->handle,
            'name' => $offer->name,
            'headline' => $offer->headline ?: $offer->name,
            'body' => $offer->body,
            'image' => $offer->image,
            'button_label' => $offer->button_label,
            'product' => $offer->product,
            'amount' => $offer->amount(),
            'amount_cent' => $offer->amountCent(),
            'compare_at' => $offer->compareAt(),
            'currency' => $offer->currency(),
            'slot' => $offer->slot,
        ];
    }
}
