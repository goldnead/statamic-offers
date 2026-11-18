<?php

namespace Goldnead\StatamicOffers\Listeners;

use Goldnead\StatamicOffers\Support\CouponUses;
use Goldnead\StatamicPayments\Events\PaymentFailed;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Eine Zahlung ohne Geld gibt ihre Gutschein-Einloesung zurueck.
 *
 * Wirft hier etwas, wird es geloggt und nicht weitergereicht: payments feuert
 * `PaymentFailed` genau einmal, und ein Fehler hier darf die Listener dahinter
 * (die Mail an den Kaeufer) nicht mitnehmen.
 */
class ReleaseCouponOfFailedPayment
{
    public function __construct(protected CouponUses $uses) {}

    public function handle(PaymentFailed $event): void
    {
        try {
            $this->uses->failed($event->payment);
        } catch (Throwable $e) {
            Log::error('statamic-offers: the coupon use of a failed payment could not be given back; used_count stays one too high.', [
                'payment_id' => $event->payment->getKey(),
                'code' => $event->payment->discount_code,
                'exception' => $e,
            ]);
        }
    }
}
