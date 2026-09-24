<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Counting what was accepted.
 *
 * Deliberately hung off the *paid* event and not off the click: a click is
 * interest, and an offer whose conversion rate counts clicks flatters itself
 * every time a card is declined.
 */
class OfferAcceptance
{
    public function __construct(protected OfferMoments $moments) {}

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
            $offer = Offer::query()->where('handle', $angebot)->first();

            if ($offer === null) {
                continue;
            }

            $offer->recordAccepted();

            // Ausverkauft und die Link-Weiche: bemerkt vom Kauf, der sie
            // ausloest. Nie auf Kosten des Kaufs: wirft das hier, gibt
            // payments die Erfuellung frei, und die bezahlte Zahlung bliebe
            // unerfuellt, bei jeder Neuzustellung wieder.
            $this->guarded(fn () => $this->moments->afterSale($offer), 'sold out / short link', $payment, $angebot);
        }

        $this->guarded(fn () => $this->moments->couponOf($payment), 'coupon redeemed', $payment);
    }

    protected function guarded(callable $moment, string $what, Payment $payment, ?string $offer = null): void
    {
        try {
            $moment();
        } catch (Throwable $e) {
            Log::error('statamic-offers: the '.$what.' moment failed; the purchase is unaffected.', [
                'payment_id' => $payment->getKey(),
                'offer' => $offer,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
