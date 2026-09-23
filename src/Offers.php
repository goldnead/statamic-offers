<?php

namespace Goldnead\StatamicOffers;

use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\OfferHandle;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The addon's static surface for siblings.
 *
 * A plain class rather than a Laravel facade on purpose: the neighbours call
 * this behind `method_exists()`, and a facade answers that question with
 * `false` for everything it forwards through `__callStatic`.
 */
final class Offers
{
    /** The field types the library understands; anything else is read as text. */
    public const FIELD_TYPES = ['text', 'select', 'checkbox'];

    /**
     * Every field a checkout could ask for, normalised.
     *
     * Read from `config('statamic-offers.checkout_fields')` on every call so a
     * site's own additions appear without a cache to clear. Labels go through
     * `__()`: a translation key becomes the translation, plain text stays as
     * it is.
     *
     * @return array<string, array{key: string, label: string, type: string, required: bool, options: array<string, string>|null, rules: list<string>}>
     */
    public static function fieldLibrary(): array
    {
        $library = [];

        foreach ((array) config('statamic-offers.checkout_fields', []) as $key => $definition) {
            if (! is_string($key) || $key === '' || ! is_array($definition)) {
                continue;
            }

            $type = (string) ($definition['type'] ?? 'text');

            if (! in_array($type, self::FIELD_TYPES, true)) {
                $type = 'text';
            }

            $options = null;

            if ($type === 'select' && is_array($definition['options'] ?? null)) {
                $options = [];

                foreach ($definition['options'] as $value => $label) {
                    $options[(string) $value] = __((string) $label);
                }
            }

            $library[$key] = [
                'key' => $key,
                'label' => __((string) ($definition['label'] ?? $key)),
                'type' => $type,
                'required' => (bool) ($definition['required'] ?? false),
                'options' => $options,
                'rules' => array_values(array_filter((array) ($definition['rules'] ?? []), 'is_string')),
            ];
        }

        return $library;
    }

    /**
     * Just the keys, for a validation rule.
     *
     * @return list<string>
     */
    public static function fieldKeys(): array
    {
        return array_keys(self::fieldLibrary());
    }

    /**
     * Der Name des URL-Parameters, der einen Gutschein-Code vorbelegt.
     *
     * **Die eine Stelle, an der er steht.** Der Gutschein-Link im Control Panel
     * schreibt ihn, die Kasse (statamic-funnels) liest ihn; stuende er an zwei
     * Stellen, fuehrte jeder Flyer nach der ersten Umbenennung ins Leere.
     */
    public static function couponParameter(): string
    {
        $name = trim((string) config('statamic-offers.coupon_link.parameter', 'coupon'));

        return $name === '' ? 'coupon' : $name;
    }

    /**
     * Der Gutschein aus der Adresse, wenn er hier gilt, sonst null.
     *
     * Fuer die Kasse, die das Code-Feld vorbelegt. Ein Code, der nicht (mehr)
     * gilt, **bricht nichts ab**: wer einen alten Flyer abfotografiert, soll
     * die Seite sehen, nur ohne Rabatt. Der Grund steht im Log, damit der
     * Betreiber einen Flyer mit falschem Code findet.
     *
     * Vorbelegt heisst nicht eingeloest. Eingeloest wird weiter im
     * {@see Support\Basket}, gegen dieselbe Tabelle.
     */
    public static function couponFromRequest(Request $request, ?Offer $offer = null): ?Coupon
    {
        $code = $request->query(self::couponParameter());

        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        $coupon = Coupon::findByCode($code);
        $grund = match (true) {
            $coupon === null => 'unknown',
            ! $coupon->isLive() => 'not live',
            $offer !== null && ! $coupon->appliesTo($offer) => 'not for this offer',
            default => null,
        };

        if ($grund !== null) {
            Log::info('statamic-offers: a coupon from a link was ignored.', [
                'code' => mb_substr(trim($code), 0, 64),
                'reason' => $grund,
                'offer' => $offer?->handle,
            ]);

            return null;
        }

        return $coupon;
    }

    /**
     * Darf dieses Katalog-Handle in dieses Land verkauft werden?
     *
     * Fuer die Kasse in `statamic-payments`, die das Land der Kaeuferin kennt
     * und vor dem Anlegen der Zahlung fragen kann. Was kein Angebot ist,
     * regelt dieses Paket nicht, und ein Angebot, das es nicht gibt, auch nicht
     * (das lehnt der Katalog ab).
     */
    public static function availableIn(string $handle, ?string $country): bool
    {
        $teile = OfferHandle::parse($handle);

        if ($teile === null) {
            return true;
        }

        $offer = Offer::query()->where('handle', $teile->offer)->first();

        return $offer === null || $offer->isAvailableIn($country);
    }

    /**
     * Der Danke-Text zu einem Kauf mit frei gewaehltem Betrag.
     *
     * Der Betrag steht im Handle (`offer:x:=2500`); wer ihn anders kennt, gibt
     * ihn mit. Null, wenn es kein Angebot ist oder keine Stufe passt.
     */
    public static function thankYouFor(string $handle, ?int $amountCent = null): ?string
    {
        $teile = OfferHandle::parse($handle);
        $betrag = $amountCent ?? $teile?->amountCent;

        if ($teile === null || $betrag === null) {
            return null;
        }

        return Offer::query()->where('handle', $teile->offer)->first()?->thankYouFor($betrag);
    }

    /**
     * Wie viel eine Folgezahlung wegen eines Gutscheins weniger kostet.
     *
     * Fuer `statamic-payments`: die Bedingungen stehen seit dem Kauf in
     * `meta['coupon']` der ersten Zahlung ({@see Support\Basket::paymentMeta()}),
     * eingefroren. `$number` zaehlt die Zahlungen der Vereinbarung, die erste
     * ist 1. Nie mehr als der Betrag und nie weniger als null.
     *
     * @param  array<string, mixed>  $terms
     */
    public static function recurringDiscountCent(array $terms, int $number, int $amountCent, ?string $currency = null): int
    {
        if ($number < 1 || $amountCent <= 0) {
            return 0;
        }

        $gilt = match ($terms['duration'] ?? Coupon::DURATION_ONCE) {
            Coupon::DURATION_FOREVER => true,
            Coupon::DURATION_REPEATING => $number <= max(1, (int) ($terms['cycles'] ?? 1)),
            default => $number === 1,
        };

        if (! $gilt) {
            return 0;
        }

        // Bei „Zahl, was du willst" der Mindestpreis: auch eine Folgezahlung
        // faellt nicht darunter, genau wie die erste im Korb.
        $boden = $terms['floor_cent'] ?? null;
        $hoechstens = is_int($boden) && $boden > 0 ? max(0, $amountCent - $boden) : $amountCent;

        $prozent = $terms['percent'] ?? null;

        if (is_int($prozent) && $prozent > 0) {
            return min($hoechstens, (int) round($amountCent * min($prozent, 100) / 100));
        }

        $fest = $terms['amount_cent'] ?? null;

        if (! is_int($fest) || $fest <= 0) {
            return 0;
        }

        // Derselbe Grundsatz wie in `Coupon::apply()`: ein fester Betrag in
        // einer anderen Waehrung ist Arithmetik ohne Bedeutung.
        $eigene = $terms['currency'] ?? null;

        if (is_string($eigene) && $currency !== null && mb_strtoupper($eigene) !== mb_strtoupper($currency)) {
            return 0;
        }

        return min($hoechstens, $fest);
    }

    /**
     * Die Zeitzone, in der Menschen Zeiten lesen und tippen.
     *
     * Statamics `display_timezone`, sonst die der Anwendung. Gespeichert wird
     * weiter in `app.timezone`; umgerechnet wird nur an der Grenze zum
     * Formular, in {@see self::fromDisplay()} und {@see self::toDisplay()}.
     */
    public static function displayTimezone(): string
    {
        $zone = config('statamic.system.display_timezone') ?: config('app.timezone', 'UTC');

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }

    /** Eine im Formular getippte Zeit (Anzeige-Zeitzone) als Zeitpunkt in der Zeitzone der Anwendung. */
    public static function fromDisplay(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value, self::displayTimezone())->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /** Ein gespeicherter Zeitpunkt, wie ihn ein `datetime-local`-Feld in der Anzeige-Zeitzone erwartet. */
    public static function toDisplay(?\DateTimeInterface $moment, string $format = 'Y-m-d\TH:i'): ?string
    {
        return $moment === null ? null : Carbon::instance($moment)->setTimezone(self::displayTimezone())->format($format);
    }

    /** Der Pfad vor dem Slug eines Kurzlinks, ohne Schraegstriche. */
    public static function linkPrefix(): string
    {
        $prefix = trim((string) config('statamic-offers.links.prefix', 'go'), '/');

        return $prefix === '' ? 'go' : $prefix;
    }

    /**
     * Eine Adresse, wie sie auf einem Flyer steht.
     *
     * Aus der Config, nicht aus der Anfrage: wer den Gutschein-Link im Control
     * Panel einer Staging-Umgebung kopiert, soll keinen Staging-Link drucken.
     * Reihenfolge: `statamic-offers.links.base_url`, `app.url`, die Anfrage.
     */
    public static function publicUrl(string $path): string
    {
        $basis = trim((string) (config('statamic-offers.links.base_url') ?: config('app.url') ?: url('/')));

        return rtrim($basis, '/').'/'.ltrim($path, '/');
    }
}
