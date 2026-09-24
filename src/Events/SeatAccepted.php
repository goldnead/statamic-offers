<?php

namespace Goldnead\StatamicOffers\Events;

use Goldnead\StatamicOffers\Models\Seat;
use Goldnead\StatamicOffers\Models\SeatPool;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An invited person accepted their seat, and its access was granted. Once per
 * seat, also on a double click.
 */
class SeatAccepted
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
