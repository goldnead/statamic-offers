<?php

namespace Goldnead\StatamicOffers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ein vergebener Platz.
 *
 * `invited` (Einladung raus, noch kein Zugang), `claimed` (angenommen, Zugang
 * vergeben), `revoked` (zurueckgeholt; der Platz ist wieder frei). Ein
 * zurueckgeholter Platz wird nicht wiederbelebt, sondern neu vergeben: eine
 * neue Zeile mit neuem Token. So kann eine alte Einladung nie wieder gelten.
 *
 * @property int $id
 * @property int $pool_id
 * @property string $email
 * @property string|null $name
 * @property string $token
 * @property string $status
 * @property Carbon|null $invited_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $revoked_at
 * @property SeatPool $pool
 */
class Seat extends Model
{
    public const STATUS_INVITED = 'invited';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'offer_seats';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'pool_id' => 'integer',
            'invited_at' => 'datetime',
            'claimed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SeatPool, $this> */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(SeatPool::class, 'pool_id');
    }

    /** Die Referenz des Zugangs in entitlements. */
    public function sourceRef(): string
    {
        return 'seat:'.$this->id;
    }
}
