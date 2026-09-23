<?php

namespace Goldnead\StatamicOffers\Commands;

use Goldnead\StatamicOffers\Support\SeatPools;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Offene Plaetze erstatteter oder zurueckgebuchter Kaeufe nachholen.
 *
 * Scheitert der Entzug eines Zugangs, wenn ein Kontingent geschlossen wird,
 * bleibt der Platz angenommen und das Log sagt es. Dieser Befehl versucht es
 * erneut. Er ist gefahrlos beliebig oft aufrufbar; im Scheduler zum Beispiel
 * stuendlich:
 *
 *     Schedule::command('offers:seats-reconcile')->hourly();
 */
class ReconcileSeats extends Command
{
    protected $signature = 'offers:seats-reconcile';

    protected $description = 'Take back the seats of refunded or charged back purchases whose access could not be revoked yet.';

    public function handle(SeatPools $pools): int
    {
        if (! Schema::hasTable('offer_seat_pools') || ! Schema::hasColumn('offer_seat_pools', 'closed_at')) {
            $this->components->info('No seat tables yet; nothing to do.');

            return self::SUCCESS;
        }

        $ergebnis = $pools->reconcile();

        $this->components->info(sprintf(
            '%d closed pool(s) with open seats, %d seat(s) taken back, %d still open.',
            $ergebnis['closed_pools'],
            $ergebnis['revoked'],
            $ergebnis['still_open'],
        ));

        // Nicht null, wenn etwas offen bleibt: ein Scheduler, der den Code
        // meldet, sieht es, statt jede Stunde still „fertig" zu lesen.
        return $ergebnis['still_open'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
