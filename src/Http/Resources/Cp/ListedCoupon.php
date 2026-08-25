<?php

namespace Goldnead\StatamicOffers\Http\Resources\Cp;

use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Support\CpNumber;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Statamic\Facades\Action;

/**
 * One row.
 *
 * Everything a cell shows is worked out here, in words, and handed over
 * finished. The reason is not tidiness: the template runs in a Control Panel
 * whose translation dictionary is assembled per request, and a discount
 * assembled in Javascript would be the one string on the screen that ignores
 * the site's language and its decimal comma.
 *
 * @mixin Coupon
 */
class ListedCoupon extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'discount' => $this->discount(),
            'validity' => $this->validity(),
            // Why a code that is switched on still does nothing. Without it the
            // listing shows "Active: yes" next to a coupon the checkout
            // refuses, and the next question is asked in an email.
            'note' => $this->note(),
            'usage' => $this->usage(),
            'used_count' => $this->used_count,
            'active' => $this->active,
            'live' => $this->resource->isLive(),
            // Handed over rather than fetched: the row actions menu would
            // otherwise ask the server the moment it is opened.
            'actions' => Action::for($this->resource, []),
            'edit_values' => [
                'code' => $this->code,
                'name' => $this->name,
                'percent' => $this->percent,
                'amount_cent' => $this->amount_cent,
                'currency' => $this->currency,
                'offers' => (array) ($this->offers ?? []),
                // Day precision, because that is the precision the form offers.
                // A round trip through the editor must not silently move the
                // end of a campaign by a few hours.
                'starts_at' => $this->starts_at?->format('Y-m-d'),
                'ends_at' => $this->ends_at?->format('Y-m-d'),
                'max_uses' => $this->max_uses,
                'active' => $this->active,
            ],
        ];
    }

    protected function discount(): ?string
    {
        if ($this->percent !== null) {
            return CpNumber::decimal($this->percent, 0).' %';
        }

        if ($this->amount_cent === null) {
            return null;
        }

        $currency = $this->currency ?: (string) config('statamic-payments.currency', 'EUR');

        return CpNumber::decimal($this->amount_cent / 100, 2).' '.mb_strtoupper($currency);
    }

    /**
     * The window, as a sentence.
     */
    protected function validity(): string
    {
        $from = $this->starts_at;
        $until = $this->ends_at;

        if (! $from && ! $until) {
            return __('statamic-offers::messages.coupon_validity_always');
        }

        if ($from && $until) {
            return __('statamic-offers::messages.coupon_validity_between', [
                'from' => $this->date($from),
                'until' => $this->date($until),
            ]);
        }

        return $from
            ? __('statamic-offers::messages.coupon_validity_from', ['date' => $this->date($from)])
            : __('statamic-offers::messages.coupon_validity_until', ['date' => $this->date($until)]);
    }

    /**
     * Deliberately silent about the switch: the Active column already says that,
     * and a row that answers the same question twice reads as two problems. What
     * is left is everything the switch does *not* explain.
     */
    protected function note(): ?string
    {
        if ($this->percent === null && $this->amount_cent === null) {
            return __('statamic-offers::messages.coupon_note_no_discount');
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return __('statamic-offers::messages.coupon_note_not_started');
        }

        if ($this->ends_at && $this->ends_at->isPast()) {
            return __('statamic-offers::messages.coupon_note_expired');
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return __('statamic-offers::messages.coupon_note_exhausted');
        }

        return null;
    }

    protected function usage(): string
    {
        return $this->max_uses === null
            ? CpNumber::decimal($this->used_count, 0)
            : CpNumber::decimal($this->used_count, 0).' / '.CpNumber::decimal($this->max_uses, 0);
    }

    protected function date(Carbon $date): string
    {
        // `isoFormat('L')` is the locale's own short date, so a German Control
        // Panel gets 30.09.2026 and an English one 09/30/2026, without this
        // addon keeping a list of formats per language.
        return $date->locale(app()->getLocale())->isoFormat('L');
    }
}
