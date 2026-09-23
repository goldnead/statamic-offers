<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\StatamicOffers\Models\Offer;
use RuntimeException;

/**
 * Ein Angebot, das dieser Kaeuferin nicht verkauft wird.
 *
 * Eine eigene Klasse, damit eine Kasse sie von einem Programmierfehler
 * unterscheiden kann: `InvalidArgumentException` heisst „das Formular passt
 * nicht zu dieser Seite", das hier heisst „die Seite passt, aber nicht fuer
 * dieses Land". Das zweite bekommt eine Meldung fuer die Kaeuferin, das erste
 * eine neu geladene Seite.
 */
class OfferNotAvailable extends RuntimeException
{
    public ?string $country = null;

    public ?string $offerHandle = null;

    public static function inCountry(Offer $offer, ?string $country): self
    {
        $e = new self('statamic-offers: '.$offer->handle.' is not sold in '.($country ?: 'an unknown country').'.');
        $e->country = $country;
        $e->offerHandle = $offer->handle;

        return $e;
    }

    /** Der Satz fuer die Kaeuferin, uebersetzt. */
    public function buyerMessage(): string
    {
        return (string) __($this->country
            ? 'statamic-offers::messages.country_not_available'
            : 'statamic-offers::messages.country_required');
    }
}
