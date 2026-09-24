<?php

namespace Goldnead\StatamicOffers\Events;

use Goldnead\StatamicOffers\Models\SeatPool;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The seats of a purchase were closed: full refund or chargeback. Fired once,
 * after its seats were taken back (each with its own {@see SeatRevoked});
 * the pool takes no further invitation or acceptance.
 */
class SeatPoolClosed
{
    use Dispatchable;

    /** The brand of the pool, null on a site without brands. */
    public readonly ?int $brandId;

    public function __construct(
        public readonly SeatPool $pool,
        public readonly string $reason,
        ?int $brandId = null,
    ) {
        $this->brandId = $brandId ?? ($pool->brand_id > 0 ? $pool->brand_id : null);
    }
}
