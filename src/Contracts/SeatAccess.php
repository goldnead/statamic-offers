<?php

namespace Goldnead\StatamicOffers\Contracts;

use Goldnead\StatamicOffers\Integrations\EntitlementsSeatAccess;

/**
 * Wer einem angenommenen Platz den Zugang gibt und ihn wieder nimmt.
 *
 * Ein Vertrag statt eines direkten Aufrufs, weil `statamic-entitlements` eine
 * **optionale** Nachbarin ist: ohne sie gibt es Plaetze trotzdem, nur vergibt
 * dann die Seite selbst den Zugang (ueber das Ereignis) oder niemand. Die
 * Vorgabe {@see EntitlementsSeatAccess}
 * redet mit entitlements, wenn es installiert ist.
 */
interface SeatAccess
{
    /** Kann diese Installation ueberhaupt Zugaenge vergeben? */
    public function available(): bool;

    /**
     * @param  list<string>  $slugs  was vergeben wird, in der Sprache von entitlements
     * @param  string  $sourceRef  eindeutig je Platz (`seat:12`), damit ein neu
     *                             vergebener Platz nie einen entzogenen
     *                             wiederbelebt
     * @param  array{starts_at?: string|null, days?: int|null}|null  $access  das Zugangsfenster des Angebots
     */
    public function grant(string $email, array $slugs, string $sourceRef, ?array $access = null): void;

    /** @param  list<string>  $slugs */
    public function revoke(string $email, array $slugs, string $sourceRef, string $reason): void;
}
