<?php

namespace Goldnead\StatamicOffers\Listeners;

use Goldnead\StatamicOffers\Support\SeatPools;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Aus einem bezahlten Kauf von Plaetzen ein Kontingent machen.
 *
 * Autoloaded by core off the first parameter type below. Ein Fehler hier wird
 * geloggt und nicht geworfen: die Zahlung steht, und ein Wurf aus einem
 * Zuhoerer darf die Erfuellung nicht zuruecknehmen und den Webhook erneut
 * ausloesen.
 */
class OpenSeatPools
{
    public function __construct(protected SeatPools $pools) {}

    public function handle(PaymentPaid $event): void
    {
        // Eine Installation, die die neue Migration noch nicht hat, verkauft
        // auch keine Plaetze. Ohne die Wache stuerbe jede Zahlung an einer
        // fehlenden Tabelle.
        if (! Schema::hasTable('offer_seat_pools')) {
            return;
        }

        try {
            $this->pools->openFor($event->payment);
        } catch (Throwable $e) {
            Log::error('statamic-offers: seats for a paid purchase could not be opened.', [
                'payment_id' => $event->payment->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
