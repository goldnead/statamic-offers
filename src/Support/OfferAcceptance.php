<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;

/**
 * Counting what was accepted.
 *
 * Deliberately hung off the *paid* event and not off the click: a click is
 * interest, and an offer whose conversion rate counts clicks flatters itself
 * every time a card is declined.
 */
class OfferAcceptance
{
    public function handle(PaymentPaid $event): void
    {
        $this->countFor($event->payment);
    }

    protected function countFor(Payment $payment): void
    {
        // Every line, because an order bump is a line and it was accepted just
        // as much as the thing the buyer came for.
        $handles = $payment->items->pluck('product')->push($payment->product)->unique();
        $angebote = [];

        foreach ($handles as $handle) {
            // Mit Zusatz zerlegt: `offer:x:raten3` und `offer:x:=2500` sind
            // Annahmen von `x`. Die Gebuehr zaehlt nicht, und ein Angebot
            // zaehlt je Zahlung einmal, auch wenn es zwei Zeilen hat.
            $teile = is_string($handle) ? OfferHandle::parse($handle) : null;

            if ($teile !== null && $teile->countsAsSale()) {
                $angebote[$teile->offer] = true;
            }
        }

        foreach (array_keys($angebote) as $angebot) {
            Offer::query()
                ->where('handle', $angebot)
                ->first()
                ?->recordAccepted();
        }
    }
}
