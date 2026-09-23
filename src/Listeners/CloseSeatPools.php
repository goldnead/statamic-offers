<?php

namespace Goldnead\StatamicOffers\Listeners;

use Goldnead\StatamicOffers\Support\SeatPools;
use Goldnead\StatamicPayments\Events\PaymentChargedBack;
use Goldnead\StatamicPayments\Events\PaymentRefunded;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Geld zurueck, Plaetze zurueck.
 *
 * Eine volle Erstattung oder eine Rueckbuchung schliesst jedes Kontingent der
 * Zahlung: alle Plaetze werden zurueckgeholt, angenommene verlieren ihren
 * Zugang. Ohne das blieben zehn Zugaenge offen, fuer die niemand mehr bezahlt
 * hat, und payments sieht sie nicht, weil es sie nie vergeben hat.
 *
 * Eine Teilerstattung schliesst nicht. Welcher Platz dann zurueckgeht, kann
 * nur die Kaeuferin sagen; dafuer ist die Seite da.
 *
 * Autoloaded by core off the first parameter type of each handle method.
 * `PaymentChargedBack` gibt es erst ab payments 1.23; auf einer aelteren Fassung
 * wird das Ereignis nie gefeuert, und die Methode bleibt still.
 */
class CloseSeatPools
{
    public function __construct(protected SeatPools $pools) {}

    public function handleRefunded(PaymentRefunded $event): void
    {
        if (! $event->isFull) {
            return;
        }

        $this->close($event->payment, 'Kauf erstattet (Zahlung '.$event->payment->getKey().')');
    }

    public function handleChargedBack(PaymentChargedBack $event): void
    {
        $this->close($event->payment, 'Kauf zurückgebucht (Zahlung '.$event->payment->getKey().')');
    }

    protected function close(Payment $payment, string $reason): void
    {
        if (! Schema::hasTable('offer_seat_pools') || ! Schema::hasColumn('offer_seat_pools', 'closed_at')) {
            return;
        }

        try {
            $this->pools->closeForPayment($payment, $reason);
        } catch (Throwable $e) {
            // Laut, weil hier Zugaenge offen bleiben, fuer die das Geld weg ist.
            Log::error('statamic-offers: seats of a refunded or charged back purchase could not be closed.', [
                'payment_id' => $payment->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
