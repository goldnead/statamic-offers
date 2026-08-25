<?php

namespace Goldnead\StatamicOffers\Models;

use Goldnead\StatamicPayments\Support\Catalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A product, presented.
 *
 * @property int $id
 * @property string $handle
 * @property string $name
 * @property string $product
 * @property int|null $amount_cent
 * @property string|null $currency
 * @property int|null $compare_at_cent
 * @property string|null $headline
 * @property string|null $body
 * @property string|null $image
 * @property string|null $button_label
 * @property list<string>|null $bumps
 * @property string $slot
 * @property bool $active
 * @property int $shown_count
 * @property int $accepted_count
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 */
class Offer extends Model
{
    /** A checkbox at checkout, charged together with what the buyer came for. */
    public const SLOT_BUMP = 'bump';

    /** After a payment has gone through, charged on its own. */
    public const SLOT_POST_PURCHASE = 'post_purchase';

    /** Anywhere a template asks for it. */
    public const SLOT_STANDALONE = 'standalone';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_cent' => 'integer',
            'compare_at_cent' => 'integer',
            'active' => 'boolean',
            'shown_count' => 'integer',
            'accepted_count' => 'integer',
            'meta' => 'array',
            'bumps' => 'array',
        ];
    }

    /**
     * How an offer is referred to where a product handle is expected.
     *
     * An empty prefix would let an offer and a product of the same name be
     * mistaken for one another, and the offer — the one with the discount —
     * would lose. So an empty setting falls back rather than being honoured.
     */
    public static function prefix(): string
    {
        $prefix = (string) config('statamic-offers.handle_prefix', 'offer:');

        return $prefix === '' ? 'offer:' : $prefix;
    }

    /**
     * The offers this one carries as checkboxes at checkout.
     *
     * Only ones marked as a bump and still sellable. An offer whose bump was
     * switched off should quietly stop showing it, not present a box that
     * refuses the whole checkout when ticked.
     *
     * @return list<self>
     */
    public function bumpOffers(): array
    {
        $handles = array_values(array_filter((array) ($this->bumps ?? []), 'is_string'));

        if ($handles === []) {
            return [];
        }

        return static::query()
            ->whereIn('handle', $handles)
            ->where('slot', self::SLOT_BUMP)
            ->get()
            ->filter(fn (self $offer) => $offer->isSellable() && $offer->handle !== $this->handle)
            ->sortBy(fn (self $offer) => array_search($offer->handle, $handles, true))
            ->values()
            ->all();
    }

    /** @return list<string> */
    public static function slots(): array
    {
        return [self::SLOT_BUMP, self::SLOT_POST_PURCHASE, self::SLOT_STANDALONE];
    }

    /**
     * What this offer costs, in minor units.
     *
     * Its own price if it has one, the catalogue's otherwise. **Never from a
     * request** — an offer exists precisely so that a discounted upsell price
     * has a server-side home, and putting it anywhere a browser can reach would
     * undo the one rule the payment addon is built around.
     */
    public function amountCent(): ?int
    {
        if ($this->amount_cent !== null) {
            return $this->amount_cent;
        }

        $product = app(Catalogue::class)->find($this->product);

        return $product['amount_cent'] ?? null;
    }

    public function currency(): string
    {
        // `$this->currency` and not `$this->attributes['currency']` is a trap
        // here, and it bit: this method is *called* `currency`, so when the
        // column is not among the loaded attributes Eloquent falls through to
        // relation resolution and tries to call this very method as a relation.
        // On a model built in memory rather than read back from the table, that
        // is an error rather than a null.
        $own = $this->attributes['currency'] ?? null;

        if (is_string($own) && $own !== '') {
            return $own;
        }

        $product = app(Catalogue::class)->find($this->product);

        return $product['currency'] ?? (string) config('statamic-payments.currency', 'EUR');
    }

    /** Whether the product behind this offer actually exists and is sellable. */
    public function isSellable(): bool
    {
        return $this->active && $this->amountCent() !== null && app(Catalogue::class)->find($this->product) !== null;
    }

    /** The price as a decimal string, for display. */
    public function amount(): ?string
    {
        $cent = $this->amountCent();

        return $cent === null ? null : number_format($cent / 100, 2, '.', '');
    }

    public function compareAt(): ?string
    {
        return $this->compare_at_cent === null
            ? null
            : number_format($this->compare_at_cent / 100, 2, '.', '');
    }

    /**
     * Counted without reading first.
     *
     * Two people seeing the same offer in the same second is the ordinary case
     * on a page that works; `increment()` is one statement in the database and
     * cannot lose one of them the way read-add-write does.
     */
    public function recordShown(): void
    {
        static::query()->whereKey($this->getKey())->increment('shown_count');
    }

    public function recordAccepted(): void
    {
        static::query()->whereKey($this->getKey())->increment('accepted_count');
    }

    /** @param Builder<Offer> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** @param Builder<Offer> $query */
    public function scopeForSlot(Builder $query, string $slot): void
    {
        $query->where('slot', $slot);
    }
}
