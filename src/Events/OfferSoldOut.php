<?php

namespace Goldnead\StatamicOffers\Events;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\OfferSales;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An offer with a quantity limit has nothing left: a paid purchase took the
 * last unit. Once per sell-out; should the limit be raised and the offer
 * sell out again, it fires again.
 *
 * `$sold` is what the limit is compared against, open checkouts of the last
 * hour included ({@see OfferSales}).
 */
class OfferSoldOut
{
    use Dispatchable;

    /** The brand of the offer, null on a site without brands. */
    public readonly ?int $brandId;

    public function __construct(
        public readonly Offer $offer,
        public readonly int $sold,
        ?int $brandId = null,
    ) {
        $this->brandId = $brandId ?? ($offer->brand_id > 0 ? $offer->brand_id : null);
    }
}
