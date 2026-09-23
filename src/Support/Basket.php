<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Offers;
use Goldnead\StatamicPayments\Support\Discount;
use Illuminate\Support\Facades\Log;

/**
 * What somebody actually agreed to buy on an offer page.
 *
 * One offer, the bumps they ticked, and the code they typed. Built entirely on
 * the server from a *list of handles and a string*: the page says which boxes
 * were checked and what was typed in the field, and nothing else about the
 * request is believed. Prices come from the offers table, the discount comes
 * from the coupons table, and the payment addon looks the products up again in
 * the catalogue before charging anything.
 *
 * Seit 1.12 kommen zwei Angaben dazu, und beide werden hier geprueft statt
 * geglaubt: der frei gewaehlte Betrag bei „Zahl, was du willst" (gegen die
 * Grenzen des Angebots) und das Land der Kaeuferin (gegen die Laenderregel).
 * Der Korb ist damit **der Kontrollpunkt**, den eine Kasse ruft, bevor sie
 * `Checkout::start()` ruft.
 *
 * The reason for the class rather than a few lines in a controller: an offer
 * page in a funnel and an offer page in somebody's own template have to agree
 * about what a ticked box means, down to which bumps are allowed to be ticked.
 */
class Basket
{
    /**
     * @param  list<string>  $bumpHandles  What the browser says was ticked.
     * @param  string|null  $pricingOption  Welche Zahlweise gewaehlt wurde, wenn
     *                                      das Angebot mehrere fuehrt.
     * @param  int|null  $amountCent  Der gewaehlte Betrag bei „Zahl, was du
     *                                willst", in kleinster Einheit. Ohne
     *                                Angabe der Vorschlag des Angebots.
     * @param  string|null  $country  Das Land der Kaeuferin, zweistellig. Nur
     *                                Pflicht, wenn das Angebot eine
     *                                Laenderregel hat.
     *
     * @throws \InvalidArgumentException wenn das Angebot diese Zahlweise nicht fuehrt
     *                                   oder der Betrag ausserhalb der Grenzen liegt
     * @throws OfferNotAvailable wenn das Angebot in diesem Land nicht verkauft wird
     */
    public static function make(
        Offer $offer,
        array $bumpHandles = [],
        ?string $code = null,
        ?string $pricingOption = null,
        ?int $amountCent = null,
        ?string $country = null,
    ): self {
        if (! $offer->isAvailableIn($country)) {
            // Laut, und mit Grund im Log. Eine Kasse, die das Angebot gar
            // nicht erst anzeigt, fragt vorher `isAvailableIn()`; wer hier
            // ankommt, hat entweder am Formular gedreht oder das Land nicht
            // abgefragt, und beides soll sichtbar sein.
            Log::info('statamic-offers: an offer was refused for the buyer\'s country.', [
                'offer' => $offer->handle,
                'country' => $country,
                'mode' => $offer->countryMode(),
            ]);

            throw OfferNotAvailable::inCountry($offer, $country);
        }

        $option = null;

        if ($pricingOption !== null && $pricingOption !== '') {
            $option = $offer->pricingOption($pricingOption);

            // **Laut, nicht stillschweigend zum Grundpreis.** Ein Schluessel,
            // den das Angebot nicht fuehrt, kommt aus einem veralteten
            // Formular, einem alten Link oder einer geloeschten Option — und
            // „dann eben der volle Preis" waere eine Abbuchung ueber einen
            // Betrag, den niemand ausgewaehlt hat. Die aufrufende Strecke
            // prueft vorher; das hier ist die Wache dahinter.
            if ($option === null) {
                throw new \InvalidArgumentException(
                    'statamic-offers: das Angebot '.$offer->handle.' fuehrt keine Zahlweise '.$pricingOption.'.'
                );
            }
        }

        $gewaehlt = null;

        if ($offer->isPayWhatYouWant()) {
            $gewaehlt = $amountCent ?? $offer->pwywSuggestedCent();

            // Dieselbe Pruefung wie im Katalog, hier nur frueher und mit
            // Grund. Der Katalog wuerde den Handle ohnehin nicht aufloesen;
            // `Checkout::start()` gaebe dann ein stummes `null` zurueck, und die
            // Kasse muesste raten, warum.
            if (! $offer->acceptsAmount($gewaehlt)) {
                throw AmountNotAccepted::forOffer($offer, $gewaehlt);
            }
        } elseif ($amountCent !== null) {
            // Ein Betrag an einem Festpreis-Angebot ist ein Formular, das nicht
            // zu dieser Seite gehoert.
            throw new \InvalidArgumentException('statamic-offers: '.$offer->handle.' hat einen festen Preis.');
        }

        return new self(
            $offer,
            self::allowedBumps($offer, $bumpHandles, $country),
            Coupon::findByCode($code),
            $option,
            $gewaehlt,
        );
    }

    /**
     * @param  list<Offer>  $bumps
     * @param  array{key: string, label: string, type: string, amount_cent: int, interval: string|null, times: int|null, trial_days: int|null, trial_amount_cent: int|null}|null  $option
     */
    protected function __construct(
        public readonly Offer $offer,
        public readonly array $bumps,
        protected readonly ?Coupon $coupon,
        public readonly ?array $option = null,
        public readonly ?int $chosenAmountCent = null,
    ) {}

    /**
     * Only bumps this offer actually lists, and only ones that can be sold.
     *
     * Without this, a ticked box is whatever the browser says it is: somebody
     * could add a cheap handle to the form and buy an unrelated product, or add
     * an expensive one to somebody else's basket. The list on the offer is the
     * authority, not the form.
     *
     * Ein Bump mit eigener Laenderregel, die dieses Land ausschliesst, faellt
     * still heraus, wie ein abgeschalteter: er soll nicht angeboten werden,
     * aber den Kauf des Hauptangebots nicht verhindern.
     *
     * @param  list<string>  $wanted
     * @return list<Offer>
     */
    protected static function allowedBumps(Offer $offer, array $wanted, ?string $country = null): array
    {
        $allowed = array_values(array_filter((array) ($offer->bumps ?? []), 'is_string'));

        if ($allowed === [] || $wanted === []) {
            return [];
        }

        $picked = array_values(array_intersect($allowed, array_filter($wanted, 'is_string')));

        if ($picked === []) {
            return [];
        }

        return Offer::query()
            ->whereIn('handle', $picked)
            ->get()
            ->filter(fn (Offer $bump) => $bump->isSellable()
                && $bump->handle !== $offer->handle
                && $bump->isAvailableIn($country)
                // Ein Bump ist ein Haekchen zu festem Preis. Einer mit frei
                // waehlbarem Betrag oder mit Plaetzen braucht eine eigene
                // Eingabe, die ein Haekchen nicht hat.
                && ! $bump->isPayWhatYouWant()
                && $bump->seatCount() === null)
            // Shown in the order the offer lists them, not the order the form
            // posted them: the editorial order is the one somebody chose.
            ->sortBy(fn (Offer $bump) => array_search($bump->handle, $allowed, true))
            ->values()
            ->all();
    }

    /**
     * The handles to hand the checkout. The offer first, then its setup fee,
     * bumps behind them.
     *
     * @return list<string>
     */
    public function handles(): array
    {
        // Die gewaehlte Zahlweise haengt am **ersten** Handle, weil der die
        // Zahlung traegt: `Subscriptions::start()` liest den Rhythmus dort,
        // und ein Bump daneben ist einmal gekauft und nicht jede Rate wieder.
        // Ebenso der frei gewaehlte Betrag.
        $erster = match (true) {
            $this->option !== null => OfferHandle::of($this->offer).':'.$this->option['key'],
            $this->chosenAmountCent !== null => OfferHandle::withAmount($this->offer, $this->chosenAmountCent),
            default => OfferHandle::of($this->offer),
        };

        $handles = [$erster];

        // Die Gebuehr direkt dahinter und **nie vorn**: der Abo-Anfang liest
        // den ersten Handle als Plan, und die Gebuehr hat keinen.
        if ($this->setupFeeCent() !== null) {
            $handles[] = OfferHandle::setupFee($this->offer);
        }

        foreach ($this->bumps as $bump) {
            $handles[] = OfferHandle::of($bump);
        }

        return $handles;
    }

    /** Die Gebuehr, wenn sie in diesem Kauf anfaellt: nur bei einer Zahlweise mit Rhythmus. */
    public function setupFeeCent(): ?int
    {
        $fee = $this->offer->setupFeeCent();

        if ($fee === null || ! $this->isRecurring()) {
            return null;
        }

        return $fee;
    }

    /** Beginnt dieser Kauf eine Vereinbarung? */
    public function isRecurring(): bool
    {
        return $this->offer->isRecurring($this->option['key'] ?? null);
    }

    /** Was die Hauptzeile heute kostet: Option, gewaehlter Betrag oder Angebotspreis. */
    public function mainCent(): int
    {
        return match (true) {
            $this->option !== null => $this->option['amount_cent'],
            $this->chosenAmountCent !== null => $this->chosenAmountCent,
            default => (int) $this->offer->effectiveAmountCent(),
        };
    }

    public function bumpsCent(): int
    {
        return array_sum(array_map(
            fn (Offer $o) => (int) $o->effectiveAmountCent(),
            $this->bumps,
        ));
    }

    public function grossCent(): int
    {
        // Bei einer Zahlweise ihr Betrag, sonst der des Angebots. Das ist die
        // Zahl, die der Katalog abbucht, Zeile fuer Zeile.
        return $this->mainCent() + ($this->setupFeeCent() ?? 0) + $this->bumpsCent();
    }

    /**
     * Die Zahl, auf die ein Gutschein rechnet.
     *
     * Je nach Geltung das Hauptangebot, die Bumps oder beides, und **nie die
     * Einrichtungsgebuehr**: ein Gutschein ist ein Nachlass auf das Angebot,
     * und ein Prozentsatz auf die Gebuehr waere einer, den niemand beworben hat.
     */
    public function couponBaseCent(Coupon $coupon): int
    {
        return match ($coupon->scope()) {
            Coupon::APPLIES_MAIN => $this->mainCent(),
            Coupon::APPLIES_BUMPS => $this->bumpsCent(),
            default => $this->mainCent() + $this->bumpsCent(),
        };
    }

    public function currency(): string
    {
        return $this->offer->currency();
    }

    /** The code as it will be applied, or null if it does not apply here. */
    public function coupon(): ?Coupon
    {
        if (! $this->coupon || ! $this->coupon->isLive() || ! $this->coupon->appliesTo($this->offer)) {
            return null;
        }

        return $this->coupon;
    }

    /**
     * What the payment addon should take off the total.
     *
     * Null when there is nothing to take off, so the ordinary path stays the
     * ordinary path and a payment with no coupon carries no discount columns.
     */
    public function discount(): ?Discount
    {
        $coupon = $this->coupon();

        if (! $coupon) {
            return null;
        }

        $off = $this->offCent($coupon);

        if ($off <= 0) {
            return null;
        }

        // Claimed here, at the moment a basket becomes a payment, not when the
        // code was typed. Somebody who types a code and closes the tab has not
        // used it up. If the last use went to somebody else in between, the
        // sale still happens, at full price.
        if (! $coupon->claim()) {
            return null;
        }

        return new Discount($coupon->code, $off, $coupon->name);
    }

    public function netCent(): int
    {
        $coupon = $this->coupon();

        return $this->grossCent() - ($coupon ? $this->offCent($coupon) : 0);
    }

    protected function offCent(Coupon $coupon): int
    {
        $base = $this->couponBaseCent($coupon);
        $off = max(0, $base - $coupon->apply($base, $this->currency()));

        // **Der Mindestpreis ist ein Boden, auch nach dem Rabatt.** Wer
        // „ab 10 Euro" anbietet, meint nicht „ab 5 Euro mit dem Herbstcode".
        // Also kommt vom frei gewaehlten Betrag hoechstens herunter, was ueber
        // dem Mindestpreis liegt; bei einem Gutschein auf den ganzen Korb
        // zusaetzlich die Bumps, die keinen Boden haben. (Entscheidung als
        // Vorgabe vom 23.09.2026; umstellbar, wenn Adrian es anders will.)
        if ($this->chosenAmountCent !== null && $coupon->scope() !== Coupon::APPLIES_BUMPS) {
            $spielraum = max(0, $this->chosenAmountCent - $this->offer->pwywMinCent());

            if ($coupon->scope() === Coupon::APPLIES_ORDER) {
                $spielraum += $this->bumpsCent();
            }

            $off = min($off, $spielraum);
        }

        return $off;
    }

    /**
     * Was die Folgezahlungen ueber den Gutschein wissen muessen, oder null.
     *
     * Nur wenn der Kauf eine Vereinbarung beginnt, der Gutschein auf das
     * Hauptangebot wirkt (die Folgezahlungen belasten nur dessen Betrag) und er
     * laenger als die erste Zahlung gilt. Die Form ist die, die
     * {@see Offers::recurringDiscountCent()} liest.
     *
     * @return array{code: string, percent: int|null, amount_cent: int|null, currency: string|null, duration: string, cycles: int|null}|null
     */
    public function couponTerms(): ?array
    {
        $coupon = $this->coupon();

        if (! $coupon || ! $this->isRecurring() || $coupon->scope() === Coupon::APPLIES_BUMPS) {
            return null;
        }

        if ($coupon->duration() === Coupon::DURATION_ONCE) {
            return null;
        }

        return $coupon->terms();
    }

    /**
     * Was die Kasse an die Zahlung heften soll (`PaymentDetails` → `meta`).
     *
     * Leer, wenn es nichts weiterzugeben gibt. `coupon` ist der Schluessel,
     * unter dem payments die Bedingungen fuer die Folgezahlungen findet.
     *
     * @return array<string, mixed>
     */
    public function paymentMeta(): array
    {
        $terms = $this->couponTerms();

        return $terms === null ? [] : ['coupon' => $terms];
    }
}
