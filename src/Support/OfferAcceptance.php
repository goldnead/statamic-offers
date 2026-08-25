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
        $prefix = Offer::prefix();

        // Every line, because an order bump is a line and it was accepted just
        // as much as the thing the buyer came for.
        $handles = $payment->items->pluck('product')->push($payment->product)->unique();

        foreach ($handles as $handle) {
            if (! is_string($handle) || ! str_starts_with($handle, $prefix)) {
                continue;
            }

            Offer::query()
                ->where('handle', substr($handle, strlen($prefix)))
                ->first()
                ?->recordAccepted();
        }
    }
}
