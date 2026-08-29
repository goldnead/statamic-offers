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
            'products' => 'array',
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

        // Ohne eigenen Preis gilt der Katalog — und bei einem Buendel die Summe
        // seiner Teile, nicht der Preis des ersten.
        //
        // **Das ist die Stelle, an der ein Buendel Geld verschenkt haette.**
        // Frueher fiel diese Methode auf `$this->product` zurueck; ein Buendel
        // aus drei Produkten ohne eigenen Preis haette dann eines davon
        // gekostet, und zwar wortlos. Ein Bundlepreis gehoert eingetragen, aber
        // wenn keiner eingetragen ist, ist die Summe die einzige Zahl, die
        // niemanden benachteiligt.
        $summe = 0;

        foreach ($this->productHandles() as $handle) {
            $product = app(Catalogue::class)->find($handle);
            $teil = $product['amount_cent'] ?? null;

            // Ein Teil, das der Katalog nicht kennt, macht die Summe zu einer
            // Erfindung. Dann lieber gar kein Preis: `isSellable()` faellt
            // darueber, und das Angebot verschwindet, statt falsch zu rechnen.
            if (! is_int($teil)) {
                return null;
            }

            $summe += $teil;
        }

        return $summe;
    }

    /**
     * Alles, was dieses Angebot verkauft: das Leitprodukt und was mitkommt.
     *
     * Das Leitprodukt steht immer vorn. An ihm haengen Name, Steuerklasse und
     * der Handle, unter dem die Rechnungszeile gefuehrt wird — ein Buendel muss
     * diese Fragen mit einer Stimme beantworten, und die erste ist die des
     * Leitprodukts.
     *
     * @return list<string>
     */
    public function productHandles(): array
    {
        $weitere = array_filter(
            (array) ($this->products ?? []),
            static fn (mixed $handle): bool => is_string($handle) && $handle !== '',
        );

        return array_values(array_unique([$this->product, ...$weitere]));
    }

    /** Verkauft dieses Angebot mehr als eine Sache? */
    public function isBundle(): bool
    {
        return count($this->productHandles()) > 1;
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

    /**
     * Whether the products behind this offer actually exist and are sellable.
     *
     * **Jedes Teil, nicht nur das erste.** Faellt ein Stueck eines Buendels aus
     * dem Katalog, wird nicht still weniger geliefert als verkauft wurde — dann
     * ist das Buendel nicht mehr das, was auf der Seite steht, und gehoert
     * nicht angeboten. Dieselbe Regel, die die Kasse schon fuer Bumps hat.
     */
    public function isSellable(): bool
    {
        if (! $this->active || $this->amountCent() === null) {
            return false;
        }

        foreach ($this->productHandles() as $handle) {
            if (app(Catalogue::class)->find($handle) === null) {
                return false;
            }
        }

        return true;
    }

    /** The price as a decimal string, for display. */
    /**
     * The machine-readable amount: always a dot, always two decimals.
     *
     * Keep it that way. It is public API, it goes into JSON, and a comma here
     * would silently change what a consumer parses. For something a person
     * reads, use {@see self::amountLocal()}.
     */
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
     * The amount as the reader's locale writes it.
     *
     * A German page showing "249.00 EUR" reads as a machine talking, and the
     * dot is not a decimal separator in that language at all — it groups
     * thousands. This is what belongs in a template.
     */
    public function amountLocal(): ?string
    {
        return self::localise($this->amountCent());
    }

    public function compareAtLocal(): ?string
    {
        return self::localise($this->compare_at_cent);
    }

    /** Without ext-intl there is no locale knowledge, so the dot stays. */
    public static function localise(?int $cent): ?string
    {
        if ($cent === null) {
            return null;
        }

        if (! class_exists(\NumberFormatter::class)) {
            return number_format($cent / 100, 2, '.', '');
        }

        $formatter = new \NumberFormatter(app()->getLocale(), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 2);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);

        return $formatter->format($cent / 100) ?: number_format($cent / 100, 2, '.', '');
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
