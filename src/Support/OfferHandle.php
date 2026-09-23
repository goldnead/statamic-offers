<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\StatamicOffers\Models\Offer;

/**
 * Was ein Katalog-Handle ueber ein Angebot sagt.
 *
 * Ein Angebot steht im Katalog nicht nur als `offer:kurs`. Dahinter kann ein
 * Zusatz haengen, und jeder Zusatz ist eine eigene Aussage:
 *
 * | Handle                    | heisst                                          |
 * |---------------------------|-------------------------------------------------|
 * | `offer:kurs`              | das Angebot, wie es ist                         |
 * | `offer:kurs:raten3`       | in der Zahlweise `raten3` (seit 1.9)            |
 * | `offer:kurs:=2500`        | frei gewaehlter Betrag, 25,00 (Zahl, was du willst) |
 * | `offer:kurs:+setup`       | die Einrichtungsgebuehr des Angebots, eine eigene Zeile |
 *
 * **Eine Stelle, die das zerlegt.** Vorher trennten der Resolver, der
 * Verkaufszaehler und der Annahmezaehler je fuer sich — und zwei davon kannten
 * die Zahlweisen nicht: ein Kauf ueber `offer:kurs:raten3` zaehlte weder gegen
 * das Kontingent noch als angenommen. Mit zwei neuen Zusaetzen waere das die
 * dritte und vierte Stelle geworden, an der ein Kauf still verschwindet.
 *
 * Getrennt wird am **ersten** Doppelpunkt nach dem Praefix. Ein Angebots-Handle
 * ist ein Slug ohne Doppelpunkt (die Validierung laesst keinen zu), also ist
 * das dieselbe Trennung wie frueher am letzten — nur ohne Annahme darueber,
 * dass hinten nie mehr als ein Zusatz steht.
 */
final class OfferHandle
{
    /** Der Zusatz einer Einrichtungsgebuehr. */
    public const SETUP_FEE = '+setup';

    private function __construct(
        public readonly string $offer,
        public readonly ?string $option,
        public readonly ?int $amountCent,
        public readonly bool $setupFee,
    ) {}

    /**
     * Null, wenn der Handle kein Angebot nennt oder einen Zusatz traegt, den
     * dieses Paket nicht kennt. Kein Rueckfall: ein unbekannter Zusatz ist
     * nicht „dann eben das Angebot", sondern etwas, das niemand verkauft hat.
     */
    public static function parse(string $handle): ?self
    {
        $prefix = Offer::prefix();

        if (! str_starts_with($handle, $prefix)) {
            return null;
        }

        $rest = substr($handle, strlen($prefix));
        $suffix = null;

        if (($trenner = strpos($rest, ':')) !== false) {
            $suffix = substr($rest, $trenner + 1);
            $rest = substr($rest, 0, $trenner);
        }

        if ($rest === '') {
            return null;
        }

        if ($suffix === null) {
            return new self($rest, null, null, false);
        }

        if ($suffix === self::SETUP_FEE) {
            return new self($rest, null, null, true);
        }

        // Ein Betrag in kleinster Einheit, nur Ziffern. Keine Vorzeichen,
        // keine Dezimalstellen, keine fuehrenden Nullen: `=0100` und `=100`
        // waeren sonst zwei Handles fuer dieselbe Zeile, und der Verkaufszaehler
        // gruppiert nach der Zeichenkette.
        if (str_starts_with($suffix, '=')) {
            $ziffern = substr($suffix, 1);

            if (preg_match('/^(0|[1-9][0-9]{0,9})$/', $ziffern) !== 1) {
                return null;
            }

            return new self($rest, null, (int) $ziffern, false);
        }

        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $suffix) !== 1) {
            return null;
        }

        return new self($rest, $suffix, null, false);
    }

    /** Der Handle des Angebots selbst, mit Praefix, ohne Zusatz. */
    public static function of(Offer $offer): string
    {
        return Offer::prefix().$offer->handle;
    }

    /** `offer:kurs:=2500` */
    public static function withAmount(Offer $offer, int $amountCent): string
    {
        return self::of($offer).':='.$amountCent;
    }

    /** `offer:kurs:+setup` */
    public static function setupFee(Offer $offer): string
    {
        return self::of($offer).':'.self::SETUP_FEE;
    }

    /**
     * Zaehlt diese Zeile als verkauftes Stueck des Angebots?
     *
     * Die Gebuehr nicht: sie ist ein Aufschlag auf einen Kauf, der schon als
     * eigene Zeile zaehlt. Zaehlte sie mit, waere ein Kontingent von zehn Plaetzen
     * nach fuenf Abos ausverkauft.
     */
    public function countsAsSale(): bool
    {
        return ! $this->setupFee;
    }
}
