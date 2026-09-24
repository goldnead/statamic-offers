<?php

namespace Goldnead\StatamicOffers\Events;

use Goldnead\StatamicOffers\Models\Seat;
use Goldnead\StatamicOffers\Models\SeatPool;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A seat was given to an address. No access yet: that comes when the invited
 * person accepts ({@see SeatAccepted}).
 */
class SeatInvited
{
    use Dispatchable;

    /** The brand of the pool, null on a site without brands. */
    public readonly ?int $brandId;

    public function __construct(
        public readonly Seat $seat,
        public readonly SeatPool $pool,
        ?int $brandId = null,
    ) {
        $this->brandId = $brandId ?? ($pool->brand_id > 0 ? $pool->brand_id : null);
    }
}
