<?php

namespace Goldnead\StatamicOffers\Models;

use Goldnead\StatamicOffers\Offers;
use Goldnead\StatamicOffers\Support\OfferSales;
use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicPayments\Support\Catalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A product, presented.
 *
 * @property int $id
 * @property int $brand_id — wem die Zeile gehoert; Null auf jedem Betrieb ohne
 *                         Mandanten. Gelesen wird ueber `forBrand()`, nie von Hand.
 * @property string $handle
 * @property string $name
 * @property string $product
 * @property int|null $amount_cent
 * @property string|null $interval — der eigene Zahlungsrhythmus des Angebots,
 *                                 im Wortlaut des Anbieters (`1 month`). Leer heisst einmalig. Wird
 *                                 NIE vom Produkt geerbt, siehe `ServiceProvider::resolveOffer()`.
 * @property int|null $times — Anzahl der Abbuchungen; `null` ist ein Abo.
 * @property int|null $trial_days
 * @property int|null $trial_amount_cent
 * @property string|null $currency
 * @property int|null $compare_at_cent
 * @property string|null $headline
 * @property string|null $body
 * @property string|null $image
 * @property string|null $button_label
 * @property string $confirmation_mode
 * @property string|null $confirmation_template
 * @property int|null $withdrawal_days
 * @property string|null $withdrawal_text
 * @property string|null $withdrawal_waiver_text
 * @property bool $withdrawal_checkbox_required
 * @property string|null $withdrawal_b2b_text
 * @property bool $withdrawal_pdf
 * @property list<string>|null $checkout_fields
 * @property Carbon|null $access_starts_at
 * @property int|null $access_days
 * @property int|null $discount_percent
 * @property int|null $quantity_limit
 * @property Carbon|null $available_from
 * @property Carbon|null $available_until
 * @property list<string>|null $bumps
 * @property array<mixed>|null $pricing_options — rohes JSON, mehrere
 *                                              Zahlweisen zur Auswahl in der Kasse. Leer heisst „ein Preis".
 *                                              Gelesen wird immer ueber {@see self::pricingOptions()}.
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

    /** Send the site's standard purchase confirmation. */
    public const CONFIRMATION_DEFAULT = 'default';

    /** Send the template named in `confirmation_template`. */
    public const CONFIRMATION_CUSTOM = 'custom';

    /** Send nothing. Only ever because somebody chose it. */
    public const CONFIRMATION_NONE = 'none';

    /** Einmal zahlen, fertig. */
    public const PRICING_ONCE = 'einmalig';

    /** Feste Anzahl Abbuchungen, danach ist es bezahlt. */
    public const PRICING_INSTALMENTS = 'raten';

    /** Rhythmus ohne Ende, bis gekuendigt wird. */
    public const PRICING_SUBSCRIPTION = 'abo';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // Die Garantie sitzt am Modell und nicht an einer Leseposition.
            // `statamic-products` castet dieselbe Spalte seit 1.0.0; ohne den
            // Cast liefert ein Treiber eine Zeichenkette, und jede Stelle, die
            // die Marke liest, muesste das selbst wissen — ein vergessener
            // Cast faellt dann nirgends auf.
            'brand_id' => 'integer',
            'amount_cent' => 'integer',
            'times' => 'integer',
            'trial_days' => 'integer',
            'trial_amount_cent' => 'integer',
            'compare_at_cent' => 'integer',
            'active' => 'boolean',
            'shown_count' => 'integer',
            'accepted_count' => 'integer',
            'meta' => 'array',
            'bumps' => 'array',
            'pricing_options' => 'array',
            'products' => 'array',
            'withdrawal_days' => 'integer',
            'withdrawal_checkbox_required' => 'boolean',
            'withdrawal_pdf' => 'boolean',
            'checkout_fields' => 'array',
            'access_starts_at' => 'date',
            'access_days' => 'integer',
            'discount_percent' => 'integer',
            'quantity_limit' => 'integer',
            'available_from' => 'datetime',
            'available_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $offer): void {
            // Wem dieses Angebot gehoert. Null auf jedem Betrieb ohne
            // Mandanten, und ein echtes Ergebnis im Mehrmarken-Betrieb, wo
            // niemand eine Marke genannt hat — ein Kommando, ein Seeder, ein
            // Import. Das Control Panel zeigt es dann niemandem, und das ist
            // die geschlossene Haelfte derselben Entscheidung.
            //
            // **Hier und nicht nur im CP-Controller.** Vorher stempelte allein
            // `OffersController::store()`, also legte jeder andere Weg
            // Angebote ohne Marke an, die danach in keiner einzigen Liste
            // erscheinen: angelegt, unsichtbar, keine Meldung. Genau so hat es
            // der Seeder des Playgrounds getan, bevor er die Spalte von Hand
            // setzte. Dieselbe Stelle wie in `statamic-products`.
            if ($offer->getAttribute('brand_id') === null) {
                $offer->setAttribute('brand_id', Brands::stampId());
            }
        });
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

    /** @return list<string> */
    public static function confirmationModes(): array
    {
        return [self::CONFIRMATION_DEFAULT, self::CONFIRMATION_CUSTOM, self::CONFIRMATION_NONE];
    }

    /**
     * Auf das verengen, was der jetzige Leser sehen darf.
     *
     * **Ausdruecklich, nicht als globaler Scope**, und das ist die ganze
     * Vorsicht an dieser Stelle. Ein globaler Scope laege auch auf dem Weg, den
     * der Katalog-Resolver und der Webhook nehmen — und der Webhook hat keine
     * Marke. `Brands::only()` schliesst bei unbeantwortbarer Frage zu, also
     * faende die Erfuellung einer bezahlten Bestellung ihr Angebot nicht mehr:
     * Geld geflossen, nichts ausgeliefert, keine Meldung. Genau diese Form hat
     * `brand-context` 1.11.0 schon einmal gehabt, dort auf der oeffentlichen
     * Website.
     *
     * Also verengen nur die Stellen, die eine Marke haben: die CP-Liste, die
     * Auswahlfelder, das Laden einer Zeile zum Bearbeiten. Dieselbe Naht wie in
     * `statamic-products`, wo sie seit 1.0.0 so herum liegt.
     *
     * `readerId()`, nie `stampId()`. Das zweite beantwortet „wessen Zeile wird
     * das gleich" und landet ohne Marke auf Null; an `only()` gereicht liest
     * sich das als „zeig die Zeilen, die niemand beansprucht" statt als „zeig
     * nichts".
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForBrand(Builder $query, ?int $brandId = null): Builder
    {
        return Brands::only($query, $brandId ?? Brands::readerId());
    }

    /** @return list<string> */
    public static function pricingTypes(): array
    {
        return [self::PRICING_ONCE, self::PRICING_INSTALMENTS, self::PRICING_SUBSCRIPTION];
    }

    /**
     * Die Zahlweisen, unter denen dieses Angebot gekauft werden kann.
     *
     * Normalisiert und **gefiltert**, nicht durchgereicht. Was in der Spalte
     * steht, hat ein Formular geschrieben — aber auch ein Import, ein Seed
     * oder eine aeltere Fassung dieses Codes koennte es geschrieben haben, und
     * eine halbe Option ist hier gefaehrlicher als keine: eine Zeile ohne
     * `amount_cent` waere ein Preis, den irgendwer spaeter zu 0 liest, und eine
     * `raten`-Zeile ohne `times` waere ein unbefristetes Abo, das als
     * Ratenzahlung beworben wurde.
     *
     * Deshalb faellt heraus, was nicht vollstaendig ist. Wer eine Option
     * anlegt und sie nicht in der Kasse sieht, sucht im Formular; wer eine
     * halbe verkauft, merkt es an der Abbuchung.
     *
     * @return list<array{key: string, label: string, type: string, amount_cent: int, interval: string|null, times: int|null, trial_days: int|null, trial_amount_cent: int|null}>
     */
    public function pricingOptions(): array
    {
        $optionen = [];
        $gesehen = [];

        // `(array)` statt `is_array()`: `null` wird zu `[]`, und alles andere
        // zu einer Liste, deren Zeilen die Pruefung darunter ohnehin nicht
        // besteht. Eine Spalte, die von Hand oder aus einem Import beschrieben
        // wurde, kann alles enthalten.
        foreach ((array) $this->pricing_options as $zeile) {
            if (! is_array($zeile)) {
                continue;
            }

            $key = is_string($zeile['key'] ?? null) ? trim($zeile['key']) : '';

            // Der Schluessel steht in einem Handle (`offer:x:raten3`), also
            // gilt dort, was fuer Handles gilt: keine Doppelpunkte, keine
            // Leerzeichen, nichts, was den Resolver spaeter anders trennt, als
            // es hier gemeint war.
            if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $key) !== 1 || isset($gesehen[$key])) {
                continue;
            }

            $betrag = $zeile['amount_cent'] ?? null;
            $betrag = is_numeric($betrag) ? (int) $betrag : null;

            if ($betrag === null || $betrag < 1) {
                continue;
            }

            $intervall = is_string($zeile['interval'] ?? null) ? trim($zeile['interval']) : '';
            $anzahl = $zeile['times'] ?? null;
            $anzahl = is_numeric($anzahl) && (int) $anzahl > 0 ? (int) $anzahl : null;

            // Der Typ wird **abgeleitet**, nicht geglaubt. Er ist die
            // Zusammenfassung von `interval` und `times`, und zwei Wahrheiten
            // ueber dieselbe Sache gehen irgendwann auseinander: eine Zeile
            // `type: einmalig` mit einem `interval` daneben wuerde je nach
            // Leser einmal oder ewig abgebucht.
            if ($intervall === '') {
                $typ = self::PRICING_ONCE;
                $anzahl = null;
            } else {
                $typ = $anzahl === null ? self::PRICING_SUBSCRIPTION : self::PRICING_INSTALMENTS;
            }

            $gesehen[$key] = true;

            $optionen[] = [
                'key' => $key,
                // **Roh, kein Rueckfall auf den Schluessel.** Der Rueckfall
                // gehoert dorthin, wo angezeigt wird, nicht hierher: das CP
                // liest diese Liste, um das Formular zu fuellen, und ein hier
                // erfundenes Label stuende beim naechsten Speichern als
                // Bezeichnung in der Spalte, ohne dass jemand es getippt hat.
                'label' => is_string($zeile['label'] ?? null) ? trim($zeile['label']) : '',
                'type' => $typ,
                'amount_cent' => $betrag,
                'interval' => $intervall === '' ? null : $intervall,
                'times' => $anzahl,
                'trial_days' => $intervall === '' ? null : self::positiveOrNull($zeile['trial_days'] ?? null),
                'trial_amount_cent' => $intervall === '' ? null : self::zeroOrMore($zeile['trial_amount_cent'] ?? null),
            ];
        }

        return $optionen;
    }

    /**
     * Eine einzelne Zahlweise, oder null.
     *
     * **Null statt Rueckfall auf den Grundpreis.** Ein unbekannter Schluessel
     * kommt aus einem Formular, einem alten Link oder einer geloeschten Option;
     * in allen drei Faellen ist „dann eben der volle Preis" eine Abbuchung, die
     * niemand ausgewaehlt hat.
     *
     * @return array{key: string, label: string, type: string, amount_cent: int, interval: string|null, times: int|null, trial_days: int|null, trial_amount_cent: int|null}|null
     */
    public function pricingOption(string $key): ?array
    {
        foreach ($this->pricingOptions() as $option) {
            if ($option['key'] === $key) {
                return $option;
            }
        }

        return null;
    }

    protected static function positiveOrNull(mixed $wert): ?int
    {
        return is_numeric($wert) && (int) $wert > 0 ? (int) $wert : null;
    }

    protected static function zeroOrMore(mixed $wert): ?int
    {
        return is_numeric($wert) && (int) $wert >= 0 ? (int) $wert : null;
    }

    /**
     * Which template the confirmation for this offer should be rendered from,
     * or `null` for the site's standard one.
     *
     * **A `custom` mode with no template is not silence.** It is an offer whose
     * author picked "own mail" and then did not name one, and the honest
     * reading of that is "they meant to send something". Falling through to the
     * standard mail keeps the buyer served; falling through to nothing would
     * turn a half-finished form into a silent defect, which is the shape of the
     * bug this whole column exists to close.
     *
     * Only {@see self::CONFIRMATION_NONE} means nothing goes out, and
     * {@see self::sendsConfirmation()} is the one place that decides it.
     */
    public function confirmationTemplate(): ?string
    {
        if ($this->confirmation_mode !== self::CONFIRMATION_CUSTOM) {
            return null;
        }

        $slug = trim((string) $this->confirmation_template);

        return $slug === '' ? null : $slug;
    }

    /**
     * Whether a purchase of this offer owes the buyer a confirmation.
     *
     * An unknown value in the column counts as yes. The column is written by a
     * validated form, so an unknown value means something went wrong upstream —
     * and of the two ways to be wrong, sending a mail nobody expected is the
     * recoverable one.
     */
    public function sendsConfirmation(): bool
    {
        return $this->confirmation_mode !== self::CONFIRMATION_NONE;
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
        // Kept as the name every caller knows; the arithmetic moved to
        // `effectiveAmountCent()` when the percentage discount arrived, and
        // this delegates so that nothing built on the old name ever charges
        // the undiscounted price.
        return $this->effectiveAmountCent();
    }

    /**
     * What is charged, after a percentage discount.
     *
     * Own price first, always — it is the one number somebody typed on
     * purpose. Otherwise the catalogue price, reduced by `discount_percent`
     * when one is set and rounded to the cent the ordinary way. The form
     * refuses an offer that has both, so the order here is never a tie-break
     * in practice; it is written down so that a row imported around the form
     * still charges one unambiguous number.
     */
    public function effectiveAmountCent(): ?int
    {
        if ($this->amount_cent !== null) {
            return $this->amount_cent;
        }

        $base = $this->basePriceCent();

        if ($base === null) {
            return null;
        }

        $percent = $this->discountPercent();

        if ($percent === null) {
            return $base;
        }

        return (int) round($base * (100 - $percent) / 100);
    }

    /**
     * What the buyer is told they would otherwise pay.
     *
     * A hand-set `compare_at_cent` wins. Without one, a percentage discount
     * makes the catalogue price the struck-through one — that is the whole
     * point of the percentage: the "instead of" number can no longer go stale.
     * Display only, never charged, same as it always was.
     */
    public function effectiveCompareAtCent(): ?int
    {
        if ($this->compare_at_cent !== null) {
            return $this->compare_at_cent;
        }

        if ($this->amount_cent === null && $this->discountPercent() !== null) {
            return $this->basePriceCent();
        }

        return null;
    }

    /** The percentage, or null when the column holds nothing usable. */
    protected function discountPercent(): ?int
    {
        $percent = $this->discount_percent;

        return is_int($percent) && $percent >= 1 && $percent <= 99 ? $percent : null;
    }

    /**
     * The catalogue's price for what this offer sells, before any discount.
     */
    protected function basePriceCent(): ?int
    {
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
        if (! $this->active || $this->effectiveAmountCent() === null) {
            return false;
        }

        foreach ($this->productHandles() as $handle) {
            if (app(Catalogue::class)->find($handle) === null) {
                return false;
            }
        }

        // Time and quantity last, because they are the two that change on
        // their own: an offer that was sellable this morning stops being so at
        // midnight or on the hundredth sale, with nobody touching the row.
        if (! $this->isWithinWindow()) {
            return false;
        }

        $remaining = $this->remainingQuantity();

        return $remaining === null || $remaining > 0;
    }

    /**
     * Whether now lies between `available_from` and `available_until`.
     *
     * Both ends inclusive of the moment itself; both optional. An offer with
     * neither is always inside its window.
     */
    public function isWithinWindow(): bool
    {
        $now = Carbon::now();

        if ($this->available_from !== null && $now->lt($this->available_from)) {
            return false;
        }

        if ($this->available_until !== null && $now->gt($this->available_until)) {
            return false;
        }

        return true;
    }

    /**
     * How many may still be sold, or null when there is no limit.
     *
     * Paid units, subtracted from the limit — read from the payment tables
     * every time, because this is the number a checkout is refused on and a
     * cached one is the number two people can both be shown. Never below
     * zero: a limit lowered under what was already sold reads as "sold out",
     * not as a debt.
     *
     * **This is a soft limit, not a reservation.** The check happens when a
     * checkout starts and the sale is counted when it is paid; nothing in
     * between holds a unit the way a stock table would. `OfferSales::sold()`
     * narrows the hole by counting unpaid checkouts younger than an hour as
     * taken, which closes it for practical purposes — but two people who
     * click within the same second on the last unit can both start a
     * checkout, and both may pay. For a contingent where one unit too many is
     * a real problem, set the limit with a reserve.
     *
     * With the payment tables missing there is nothing to count against, and
     * the honest answer is "no limit can be enforced" — returned as null, with
     * the log line `OfferSales` writes once per request.
     */
    public function remainingQuantity(): ?int
    {
        if ($this->quantity_limit === null) {
            return null;
        }

        $sold = OfferSales::sold($this);

        if ($sold === null) {
            return null;
        }

        return max(0, $this->quantity_limit - $sold);
    }

    /**
     * When access begins and how long it lasts, or null for "now and for good".
     *
     * Handed to the payment as `meta['access']` by whoever starts the checkout;
     * the entitlements bridge on the other side turns it into `starts_at` and
     * `expires_at`. This addon writes no entitlement itself.
     *
     * @return array{starts_at: string|null, days: int|null}|null
     */
    public function accessWindow(): ?array
    {
        $startsAt = $this->access_starts_at?->format('Y-m-d');
        $days = is_int($this->access_days) && $this->access_days > 0 ? $this->access_days : null;

        if ($startsAt === null && $days === null) {
            return null;
        }

        return ['starts_at' => $startsAt, 'days' => $days];
    }

    /**
     * Which fields the checkout asks for, as keys into the library.
     *
     * Only keys the library knows: a key removed from the config after the
     * offer was saved would otherwise ask the buyer for a field nothing can
     * validate. An empty list and null both mean "the offer has no opinion".
     *
     * @return list<string>
     */
    public function checkoutFields(): array
    {
        $known = Offers::fieldKeys();
        $picked = array_values(array_filter((array) ($this->checkout_fields ?? []), 'is_string'));

        return array_values(array_intersect($picked, $known));
    }

    /**
     * The terms of withdrawal that apply to this offer, right now.
     *
     * Offer over config over built-in default, key by key, so an offer may set
     * a longer period and still inherit the site's wording. Placeholders are
     * filled from `config('statamic-offers.seller')`, falling back to the
     * application name and the mail sender.
     *
     * **`version` is the contract with the payment.** It is a hash over the
     * three things a buyer actually agrees to — period, text, waiver — and
     * changes whenever any of them does. The checkout freezes `waiver_text`
     * plus this version on the payment as `consent_text`, and the whole array
     * as `meta['withdrawal']`; that is the funnel's job, and it is what makes
     * "which wording did this buyer see" answerable a year later. This method
     * only says what the terms are *today*.
     *
     * @return array{days: int, text: string, waiver_text: string, checkbox_required: bool, b2b_text: string|null, version: string}
     */
    public function withdrawalTerms(): array
    {
        $config = (array) config('statamic-offers.withdrawal', []);

        $days = $this->withdrawal_days ?? (int) ($config['days'] ?? 14);
        $text = self::firstText($this->withdrawal_text, $config['text'] ?? null) ?? '';
        $waiver = self::firstText($this->withdrawal_waiver_text, $config['waiver_text'] ?? null) ?? '';
        $b2b = self::firstText($this->withdrawal_b2b_text, $config['b2b_text'] ?? null);

        // The column defaults to true and has no null state of its own, so
        // the config's say only matters for a row built in memory.
        $checkbox = array_key_exists('withdrawal_checkbox_required', $this->attributes)
            ? (bool) $this->withdrawal_checkbox_required
            : (bool) ($config['checkbox_required'] ?? true);

        $seller = (array) config('statamic-offers.seller', []);
        $replacements = [
            '{days}' => (string) $days,
            '{seller_name}' => (string) (($seller['name'] ?? null) ?: config('app.name', '')),
            '{seller_contact}' => (string) (($seller['contact'] ?? null) ?: config('mail.from.address', '')),
        ];

        $text = strtr($text, $replacements);
        $waiver = strtr($waiver, $replacements);
        $b2b = $b2b === null ? null : strtr($b2b, $replacements);

        return [
            'days' => $days,
            'text' => $text,
            'waiver_text' => $waiver,
            'checkbox_required' => $checkbox,
            'b2b_text' => $b2b,
            'version' => substr(sha1($days.'|'.$text.'|'.$waiver), 0, 12),
        ];
    }

    /** The first of the two that says something, or null. */
    protected static function firstText(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
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
        $cent = $this->effectiveCompareAtCent();

        return $cent === null ? null : number_format($cent / 100, 2, '.', '');
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
        return self::localise($this->effectiveCompareAtCent());
    }

    /**
     * Der Betrag, wie ihn ein Mensch dieser Sprache liest.
     *
     * Ohne `ext-intl` gibt es kein Gebietswissen — aber eine Handvoll
     * Schreibweisen kennt dieses Paket selbst, und das ist besser als der
     * frühere Rückfall auf den englischen Punkt.
     *
     * **Warum das keine Kleinigkeit ist.** Der Kommentar über `amountLocal()`
     * sagt es selbst: „a German page showing 249.00 is a machine talking" —
     * und genau das lieferte der Rückfall. Auf adriangoldner.com fiel es am
     * 07.09.2026 auf: im Container ist `intl` nicht installiert, und in der
     * Kasse stand „520.00 €" statt „520,00 €". Ein Preis ist eine
     * Pflichtangabe; in einer fremden Schreibweise gedruckt ist er im besten
     * Fall unschön und im schlechtesten mehrdeutig — im Deutschen trennt der
     * Punkt Tausender.
     *
     * Die Liste ist absichtlich kurz und nennt nur, was sicher ist. Alles
     * Unbekannte bleibt beim Punkt: eine falsch geratene Schreibweise wäre
     * schlechter als eine fremde, die als fremd erkennbar ist.
     */
    public static function localise(?int $cent): ?string
    {
        if ($cent === null) {
            return null;
        }

        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter(app()->getLocale(), \NumberFormatter::DECIMAL);
            $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 2);
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);

            $formatiert = $formatter->format($cent / 100);

            if (is_string($formatiert) && $formatiert !== '') {
                return $formatiert;
            }
        }

        return self::localiseWithoutIntl($cent, (string) app()->getLocale());
    }

    /**
     * Derselbe Betrag ohne `ext-intl`.
     *
     * **Eigene Methode, damit ein Test sie erreicht.** Ob die Erweiterung
     * geladen ist, entscheidet die Umgebung und nicht der Aufrufer; ein Test,
     * der nur `localise()` ruft, prüft auf einem Rechner mit `intl` genau den
     * Zweig nicht, der auf dem Server läuft. Genau so blieb der Fehler
     * unentdeckt: lokal grün, im Container „520.00 €".
     *
     * Die Liste nennt nur, was sicher ist. Alles Unbekannte bleibt beim Punkt —
     * eine falsch geratene Schreibweise wäre schlechter als eine fremde, die
     * als fremd erkennbar ist.
     */
    public static function localiseWithoutIntl(int $cent, string $locale): string
    {
        // Sprache, nicht Land: `de_AT` und `de_CH` schreiben die Nachkommastelle
        // wie `de`, und ein Gebietsschema mit Bindestrich (`de-DE`) kommt vor.
        $sprache = strtolower(substr(str_replace('-', '_', $locale), 0, 2));

        [$dezimal, $tausender] = match ($sprache) {
            'de', 'es', 'it', 'nl', 'pt', 'da', 'tr', 'id' => [',', '.'],
            'fr', 'cs', 'pl', 'sv', 'fi', 'nb', 'no', 'ru', 'uk' => [',', ' '],
            default => ['.', ','],
        };

        return number_format($cent / 100, 2, $dezimal, $tausender);
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
