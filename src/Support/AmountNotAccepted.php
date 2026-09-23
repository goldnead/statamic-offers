<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\StatamicOffers\Models\Offer;
use InvalidArgumentException;

/**
 * Ein frei gewaehlter Betrag ausserhalb der Grenzen des Angebots.
 *
 * Eine Unterklasse von `InvalidArgumentException`, damit jede Kasse, die das
 * bisher so fing, es weiter faengt. Eigene Klasse, damit eine Kasse sie von
 * einem kaputten Formular unterscheiden kann: das hier ist eine Eingabe der
 * Kaeuferin, und sie bekommt einen Satz mit den Grenzen, keine neu geladene Seite.
 */
class AmountNotAccepted extends InvalidArgumentException
{
    public int $amountCent = 0;

    public int $minCent = 0;

    public int $maxCent = 0;

    public string $currency = 'EUR';

    public static function forOffer(Offer $offer, int $amountCent): self
    {
        $e = new self(
            'statamic-offers: '.$amountCent.' liegt ausserhalb der Grenzen von '.$offer->handle
            .' ('.$offer->pwywMinCent().' bis '.$offer->pwywMaxCent().').'
        );

        $e->amountCent = $amountCent;
        $e->minCent = $offer->pwywMinCent();
        $e->maxCent = $offer->pwywMaxCent();
        $e->currency = $offer->currency();

        return $e;
    }

    /** Der Satz fuer die Kaeuferin, mit den Grenzen in ihrer Schreibweise. */
    public function buyerMessage(): string
    {
        return (string) __('statamic-offers::messages.pwyw_amount_out_of_bounds', [
            'min' => Offer::localise($this->minCent).' '.$this->currency,
            'max' => Offer::localise($this->maxCent).' '.$this->currency,
        ]);
    }
}
