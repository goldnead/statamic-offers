<?php

namespace Goldnead\StatamicOffers\Events;

use Goldnead\StatamicOffers\Models\Offer;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An offer's short link leads to its second target for the first time.
 *
 * `$reason`: `date` (the switch date or `available_until` has passed) or
 * `sold_out`. Noticed at the first visit after the switch, or at the paid
 * purchase that sold the offer out, whichever comes first. Should the link
 * lead to the first target again (a date moved), the next switch fires again.
 */
class ShortLinkSwitched
{
    use Dispatchable;

    public const REASON_DATE = 'date';

    public const REASON_SOLD_OUT = 'sold_out';

    /** The brand of the offer, null on a site without brands. */
    public readonly ?int $brandId;

    public function __construct(
        public readonly Offer $offer,
        public readonly string $reason,
        ?int $brandId = null,
    ) {
        $this->brandId = $brandId ?? ($offer->brand_id > 0 ? $offer->brand_id : null);
    }
}
