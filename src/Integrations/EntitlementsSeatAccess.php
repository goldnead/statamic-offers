<?php

namespace Goldnead\StatamicOffers\Integrations;

use Goldnead\StatamicOffers\Contracts\SeatAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plaetze ueber `statamic-entitlements`.
 *
 * Optional gekoppelt, wie in der Familie ueblich: `class_exists` auf die
 * Fassade, die gerufen wird, und `method_exists` auf das Objekt dahinter, nie
 * auf die Fassade selbst (die leitet ueber `__callStatic` weiter und
 * beantwortet die Frage immer mit nein).
 *
 * Quelle ist `statamic-offers`, die Referenz `seat:<id>`. Zwei Plaetze
 * derselben Person sind damit zwei Zugaenge, und ein neu vergebener Platz
 * belebt nie einen entzogenen wieder: entitlements lehnt das bei gleichem
 * Tupel ausdruecklich ab.
 */
class EntitlementsSeatAccess implements SeatAccess
{
    public const SOURCE = 'statamic-offers';

    protected const FACADE = 'Goldnead\\Entitlements\\Facades\\Entitlements';

    public function available(): bool
    {
        if (! class_exists(self::FACADE)) {
            return false;
        }

        try {
            $root = (self::FACADE)::getFacadeRoot();
        } catch (Throwable) {
            return false;
        }

        return is_object($root) && method_exists($root, 'grant') && method_exists($root, 'revoke');
    }

    public function grant(string $email, array $slugs, string $sourceRef, ?array $access = null): void
    {
        if (! $this->available()) {
            Log::warning('statamic-offers: a seat was accepted, but statamic-entitlements is not installed; no access was granted.', [
                'source_ref' => $sourceRef,
                'grants' => $slugs,
            ]);

            return;
        }

        [$ab, $bis] = self::window($access);

        foreach ($slugs as $slug) {
            try {
                (self::FACADE)::grant(
                    $this->subject($email),
                    $slug,
                    self::SOURCE,
                    $sourceRef,
                    startsAt: $ab,
                    expiresAt: $bis,
                );
            } catch (Throwable $e) {
                // Je Slug ein eigener Versuch: scheitert einer, sind die
                // anderen trotzdem vergeben, und das Log nennt genau den, der
                // fehlt.
                Log::error('statamic-offers: a seat could not be granted.', [
                    'source_ref' => $sourceRef,
                    'grants' => $slug,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    public function revoke(string $email, array $slugs, string $sourceRef, string $reason): void
    {
        if (! $this->available()) {
            return;
        }

        try {
            $zugaenge = (self::FACADE)::forSubject($this->subject($email))
                ->where('source', self::SOURCE)
                ->where('source_ref', $sourceRef)
                ->whereIn('product_slug', $slugs)
                ->get();

            foreach ($zugaenge as $zugang) {
                (self::FACADE)::revoke($zugang, $reason);
            }
        } catch (Throwable $e) {
            // Laut: ein Platz, der zurueckgeholt aussieht und noch Zugang gibt,
            // ist genau der Fehler, den die Kaeuferin nicht sehen kann.
            Log::error('statamic-offers: a seat was taken back, but its access could not be revoked.', [
                'source_ref' => $sourceRef,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Die Adresse als Subjekt, so wie `statamic-payments` sie uebergibt.
     *
     * Dieselbe Form, damit ein Zugang aus einem Platz und einer aus einem Kauf
     * bei derselben Person landen.
     */
    protected function subject(string $email): mixed
    {
        $email = mb_strtolower(trim($email));
        $klasse = 'Goldnead\\Entitlements\\Support\\SubjectReference';

        return class_exists($klasse) ? new $klasse('email', $email) : $email;
    }

    /**
     * @param  array{starts_at?: string|null, days?: int|null}|null  $access
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    protected static function window(?array $access): array
    {
        if ($access === null) {
            return [null, null];
        }

        $ab = is_string($access['starts_at'] ?? null) ? Carbon::parse($access['starts_at'])->startOfDay() : null;
        $tage = $access['days'] ?? null;
        $bis = is_int($tage) && $tage > 0 ? ($ab ?? Carbon::now())->copy()->addDays($tage) : null;

        // Ein Beginn in der Vergangenheit ist „ab jetzt"; entitlements soll
        // keinen Zugang anlegen, der schon begonnen hat, bevor es ihn gab.
        if ($ab !== null && $ab->isPast()) {
            $ab = null;
        }

        return [$ab, $bis];
    }
}
