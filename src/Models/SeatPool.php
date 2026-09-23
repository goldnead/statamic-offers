<?php

namespace Goldnead\StatamicOffers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Die Plaetze eines Kaufs.
 *
 * Entsteht aus einer bezahlten Zahlung ueber ein Angebot mit `seats`, genau
 * einmal (eindeutig je `payment_id`). Die Kaeuferin verwaltet es ueber einen
 * Link mit `manage_token`; mehr Berechtigung gibt es auf dieser Seite nicht.
 *
 * @property int $id
 * @property int $brand_id
 * @property int $payment_id
 * @property string $offer
 * @property string $product
 * @property string $owner_email
 * @property string|null $owner_name
 * @property int $seats
 * @property list<string>|null $grants
 * @property array{starts_at?: string|null, days?: int|null}|null $access
 * @property string $manage_token
 * @property Carbon|null $created_at
 */
class SeatPool extends Model
{
    protected $table = 'offer_seat_pools';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'brand_id' => 'integer',
            'payment_id' => 'integer',
            'seats' => 'integer',
            'grants' => 'array',
            'access' => 'array',
        ];
    }

    /** @return HasMany<Seat, $this> */
    public function seatRows(): HasMany
    {
        return $this->hasMany(Seat::class, 'pool_id');
    }

    /** Eingeladen oder angenommen, also nicht frei. */
    public function takenCount(): int
    {
        return $this->seatRows()->where('status', '!=', Seat::STATUS_REVOKED)->count();
    }

    public function freeCount(): int
    {
        return max(0, $this->seats - $this->takenCount());
    }

    /** Das Angebot dahinter, falls es noch existiert. */
    public function offerModel(): ?Offer
    {
        return Offer::query()->where('handle', $this->offer)->first();
    }

    /** Der Name, wie ihn die Kaeuferin gekauft hat, sonst der Handle. */
    public function title(): string
    {
        return $this->offerModel()->name ?? $this->offer;
    }

    /** @return list<string> */
    public function grantList(): array
    {
        // Die Spalte ist JSON, geschrieben von diesem Paket; eine leere
        // Zeichenkette darin waere ein Zugang ohne Namen.
        return array_values(array_filter((array) $this->grants, fn (string $slug) => $slug !== ''));
    }
}
