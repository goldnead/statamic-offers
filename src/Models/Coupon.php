<?php

namespace Goldnead\StatamicOffers\Models;

use Goldnead\StatamicOffers\Offers;
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
 * @property string|null $duration — `once`, `repeating` oder `forever`.
 * @property int|null $duration_cycles — bei `repeating`: fuer wie viele Zahlungen, die erste mitgezaehlt.
 * @property string|null $applies_to — `order`, `main` oder `bumps`.
 * @property bool $funnel_wide
 * @property string|null $link_url
 */
class Coupon extends Model
{
    /** Nur die erste Zahlung. Das, was jeder Gutschein vor 1.12 tat. */
    public const DURATION_ONCE = 'once';

    /** Die ersten `duration_cycles` Zahlungen, die erste mitgezaehlt. */
    public const DURATION_REPEATING = 'repeating';

    /** Jede Zahlung, solange das Abo laeuft. */
    public const DURATION_FOREVER = 'forever';

    /** Hauptangebot und Bumps. Der Stand vor 1.12. */
    public const APPLIES_ORDER = 'order';

    /** Nur das Hauptangebot. */
    public const APPLIES_MAIN = 'main';

    /** Nur die Bumps. */
    public const APPLIES_BUMPS = 'bumps';

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
            'duration_cycles' => 'integer',
            'funnel_wide' => 'boolean',
        ];
    }

    /** @return list<string> */
    public static function durations(): array
    {
        return [self::DURATION_ONCE, self::DURATION_REPEATING, self::DURATION_FOREVER];
    }

    /** @return list<string> */
    public static function scopes(): array
    {
        return [self::APPLIES_ORDER, self::APPLIES_MAIN, self::APPLIES_BUMPS];
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

    /** Die Dauer, die gilt. Ein unbekannter Wert ist die erste Zahlung, wie vor 1.12. */
    public function duration(): string
    {
        return in_array($this->duration, self::durations(), true) ? $this->duration : self::DURATION_ONCE;
    }

    /** Worauf der Code wirkt. Ein unbekannter Wert ist der ganze Korb, wie vor 1.12. */
    public function scope(): string
    {
        return in_array($this->applies_to, self::scopes(), true) ? $this->applies_to : self::APPLIES_ORDER;
    }

    /**
     * Gilt der Code fuer die n-te Zahlung eines Abos? Die erste ist 1.
     *
     * `repeating` ohne Anzahl gilt wie `once`: eine Wiederholung, von der
     * niemand gesagt hat, wie oft, ist keine Anweisung, fuer immer zu
     * rabattieren.
     */
    public function appliesToPayment(int $number): bool
    {
        if ($number < 1) {
            return false;
        }

        return match ($this->duration()) {
            self::DURATION_FOREVER => true,
            self::DURATION_REPEATING => $number <= max(1, (int) $this->duration_cycles),
            default => $number === 1,
        };
    }

    /**
     * Gilt der Code auch fuer spaetere Angebote im selben Funnel-Lauf?
     *
     * Hier steht nur die Antwort. Den Code von Schritt zu Schritt zu tragen ist
     * Sache des Funnels, der den Lauf kennt.
     */
    public function coversFollowUps(): bool
    {
        return (bool) $this->funnel_wide;
    }

    /**
     * Was eine Folgezahlung ueber diesen Code wissen muss.
     *
     * Eingefroren, nicht verwiesen: wird der Gutschein spaeter geaendert oder
     * geloescht, gilt fuer ein laufendes Abo weiter, was beim Kauf zugesagt war.
     *
     * @return array{code: string, percent: int|null, amount_cent: int|null, currency: string|null, duration: string, cycles: int|null}
     */
    public function terms(): array
    {
        return [
            'code' => $this->code,
            'percent' => $this->percent,
            'amount_cent' => $this->percent === null ? $this->amount_cent : null,
            'currency' => $this->percent === null && $this->currency ? mb_strtoupper($this->currency) : null,
            'duration' => $this->duration(),
            'cycles' => $this->duration() === self::DURATION_REPEATING ? max(1, (int) $this->duration_cycles) : null,
        ];
    }

    /**
     * Der Gutschein-Link: die Zielseite mit dem Code als Parameter.
     *
     * Ohne eigene Zielseite die Startseite der Site. Eine relative Zielseite
     * wird absolut, denn ein Link auf einem Flyer hat keinen Kontext, gegen den
     * er aufgeloest werden koennte.
     */
    public function link(): string
    {
        $ziel = trim((string) $this->link_url);

        return self::withCode($ziel === '' ? Offers::publicUrl('/') : self::absolute($ziel), $this->code);
    }

    /**
     * Alle Links, unter denen dieser Code vorbelegt ankommt.
     *
     * Die Zielseite, und jedes Angebot mit Kurzlink, fuer das der Code gilt.
     * Der Kurzlink reicht die Anfrage an sein Ziel weiter, also kommt der Code
     * auch nach dem Umschalten der Weiche an.
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    public function links(): array
    {
        $ziel = trim((string) $this->link_url);

        $links = [[
            'key' => 'page',
            'label' => $ziel === '' ? Offers::publicUrl('/') : $ziel,
            'url' => $this->link(),
        ]];

        $angebote = Offer::query()
            ->forBrand()
            ->whereNotNull('link_slug')
            ->where('link_slug', '!=', '')
            ->orderBy('name')
            ->get();

        foreach ($angebote as $offer) {
            $kurz = $offer->shortLinkUrl();

            if ($kurz === null || ! $this->appliesTo($offer)) {
                continue;
            }

            $links[] = [
                'key' => 'offer:'.$offer->handle,
                'label' => $offer->name,
                'url' => self::withCode($kurz, $this->code),
            ];
        }

        return $links;
    }

    /** Ein Link dieses Gutscheins, oder null, wenn er keinen mit diesem Schluessel fuehrt. */
    public function linkFor(string $key): ?string
    {
        foreach ($this->links() as $link) {
            if ($link['key'] === $key) {
                return $link['url'];
            }
        }

        return null;
    }

    /** Den Code an eine Adresse haengen, ohne deren eigene Parameter zu verlieren. */
    public static function withCode(string $url, string $code): string
    {
        $parameter = Offers::couponParameter();
        [$ohneAnker, $anker] = array_pad(explode('#', $url, 2), 2, null);

        $trenner = str_contains($ohneAnker, '?') ? '&' : '?';

        return $ohneAnker.$trenner.rawurlencode($parameter).'='.rawurlencode($code)
            .($anker === null ? '' : '#'.$anker);
    }

    protected static function absolute(string $ziel): string
    {
        return preg_match('#^https?://#i', $ziel) === 1 ? $ziel : Offers::publicUrl($ziel);
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
