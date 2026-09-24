<?php

namespace Goldnead\StatamicOffers\Events;

use Goldnead\StatamicOffers\Models\SeatPool;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A paid purchase of seats opened its pool: the buyer can now invite people.
 * Once per payment line, however often the provider redelivers.
 */
class SeatPoolOpened
{
    use Dispatchable;

    /**
     * The brand of the pool (the offer's), null on a site without brands.
     * A listener started from the provider's webhook runs in this brand.
     */
    public readonly ?int $brandId;

    public function __construct(
        public readonly SeatPool $pool,
        ?int $brandId = null,
    ) {
        $this->brandId = $brandId ?? ($pool->brand_id > 0 ? $pool->brand_id : null);
    }
}
