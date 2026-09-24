<?php

namespace Goldnead\StatamicOffers\Events;

use Goldnead\StatamicOffers\Models\Seat;
use Goldnead\StatamicOffers\Models\SeatPool;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A seat was taken back and is free again.
 *
 * `$previousStatus` says what it was: `claimed` (its access was revoked
 * before this fired) or `invited` (the invitation no longer counts).
 * `$reason` is the text written to the revoked access, null when the buyer
 * took it back without one.
 */
class SeatRevoked
{
    use Dispatchable;

    /** The brand of the pool, null on a site without brands. */
    public readonly ?int $brandId;

    public function __construct(
        public readonly Seat $seat,
        public readonly SeatPool $pool,
        public readonly string $previousStatus,
        public readonly ?string $reason = null,
        ?int $brandId = null,
    ) {
        $this->brandId = $brandId ?? ($pool->brand_id > 0 ? $pool->brand_id : null);
    }
}
