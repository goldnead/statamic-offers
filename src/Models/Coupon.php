<?php

namespace Goldnead\StatamicOffers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A code somebody types to pay less.
 *
 * The rule the whole payment family is built on is that **an amount never comes
 * from a request**. A coupon is the one thing that looks like an exception and
 * is not: what arrives from the browser is a *code*, and the discount it stands
 * for is looked up here. A request that says "20 % off" is ignored; a request
 * that says "FRUEHLING" is a question this table answers.
 *
 * @property int $id
 * @property string $code
 * @property string|null $name
 * @property int|null $percent
 * @property int|null $amount_cent
 * @property string|null $currency
 * @property list<string>|null $offers
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $max_uses
 * @property int $used_count
 * @property bool $active
 */
class Coupon extends Model
{
    protected $table = 'offer_coupons';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'percent' => 'integer',
            'amount_cent' => 'integer',
            'offers' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** Codes are typed by people, so they are matched the way people type them. */
    public static function findByCode(?string $code): ?self
    {
        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        return static::query()->whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))])->first();
    }

    public function isLive(): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->ends_at && $this->ends_at->isPast()) {
            return false;
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return false;
        }

        return $this->percent !== null || $this->amount_cent !== null;
    }

    /** Empty means every offer, which is the useful default for a campaign code. */
    public function appliesTo(Offer $offer): bool
    {
        $only = $this->offers ?? [];

        return $only === [] || in_array($offer->handle, $only, true);
    }

    /**
     * What this code makes of a price, in minor units.
     *
     * Never below zero and never above the price: a fixed 50 € off a 20 € offer
     * is a free offer, not a refund, and a percentage over 100 is a typo
     * somebody made in the Control Panel, not an instruction to pay the buyer.
     */
    public function apply(int $amountCent, ?string $currency = null): int
    {
        if ($this->percent !== null) {
            $off = (int) round($amountCent * min($this->percent, 100) / 100);

            return max(0, $amountCent - $off);
        }

        // A fixed discount in the wrong currency is not applied at all. Taking
        // 10 off a price in another currency is arithmetic that means nothing.
        if ($this->currency !== null && $currency !== null && mb_strtoupper($this->currency) !== mb_strtoupper($currency)) {
            return $amountCent;
        }

        return max(0, $amountCent - (int) $this->amount_cent);
    }

    /**
     * Claim one use.
     *
     * A conditional UPDATE rather than read-then-write, so two people typing the
     * last available code at the same moment cannot both get it. Returns false
     * when the code was exhausted between the check and the claim, and the
     * caller then charges full price rather than failing the purchase — a sale
     * lost to a race is worse than a discount missed.
     */
    public function claim(): bool
    {
        if ($this->max_uses === null) {
            $this->increment('used_count');

            return true;
        }

        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('used_count', '<', $this->max_uses)
            ->update(['used_count' => DB::raw('used_count + 1')]);

        return $claimed > 0;
    }
}
