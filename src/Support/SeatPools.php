<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\StatamicOffers\Contracts\SeatAccess;
use Goldnead\StatamicOffers\Events\SeatAccepted;
use Goldnead\StatamicOffers\Events\SeatInvited;
use Goldnead\StatamicOffers\Events\SeatPoolClosed;
use Goldnead\StatamicOffers\Events\SeatPoolOpened;
use Goldnead\StatamicOffers\Events\SeatRevoked;
use Goldnead\StatamicOffers\Mail\SeatInvitationMail;
use Goldnead\StatamicOffers\Mail\SeatPoolMail;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Models\Seat;
use Goldnead\StatamicOffers\Models\SeatPool;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Catalogue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Plaetze fuer Gruppen: anlegen, einladen, annehmen, zurueckholen.
 *
 * Alles, was an einem Platz Geld oder Zugang bedeutet, laeuft hier durch und
 * nicht durch einen Controller. Die Seiten der Kaeuferin und der Eingeladenen
 * sind nur zwei Wege hierher; ein dritter (ein Kommando, eine Automation)
 * soll dieselben Regeln bekommen, ohne sie abzuschreiben.
 */
class SeatPools
{
    public function __construct(protected SeatAccess $access) {}

    /**
     * Aus einer bezahlten Zahlung die Kontingente anlegen, je Zeile eines.
     *
     * Der Webhook kommt mehrfach. Die Wache ist der eindeutige Index auf
     * `payment_id` + `offer`, nicht eine Abfrage davor: zwei Zustellungen in
     * derselben Sekunde sehen beide „noch keins da".
     *
     * @return list<SeatPool> die neu angelegten
     */
    public function openFor(Payment $payment): array
    {
        $email = mb_strtolower(trim((string) $payment->email));

        $neu = [];

        foreach ($payment->items as $item) {
            $teile = OfferHandle::parse((string) $item->product);

            if ($teile === null || ! $teile->countsAsSale()) {
                continue;
            }

            // **Vom Angebot, nicht aus dem Katalogeintrag des Angebots.** Der
            // loest nur auf, solange das Angebot verkaufbar ist, und genau
            // dieser Kauf kann das letzte Stueck gewesen sein: dann kaeme der
            // Webhook an, faende keinen Eintrag, und bezahlte Plaetze gaebe es
            // nicht. Die Zugaenge kommen von den Produkten darunter, die nicht
            // ausverkauft sein koennen.
            $offer = Offer::query()->where('handle', $teile->offer)->first();
            $plaetze = $offer?->seatCount();

            if ($offer === null || $plaetze === null) {
                continue;
            }

            if ($email === '') {
                // Ohne Adresse gibt es niemanden, dem die Plaetze gehoeren.
                // Laut, weil hier bezahlt wurde und nichts geliefert wird.
                Log::error('statamic-offers: a purchase of seats has no buyer address; no seats were opened.', [
                    'payment_id' => $payment->getKey(),
                    'offer' => $teile->offer,
                ]);

                continue;
            }

            try {
                $pool = SeatPool::create([
                    'brand_id' => (int) $offer->brand_id,
                    'payment_id' => (int) $payment->getKey(),
                    'offer' => $teile->offer,
                    'product' => (string) $item->product,
                    'owner_email' => $email,
                    'owner_name' => $payment->name,
                    'seats' => $plaetze * max(1, (int) $item->quantity),
                    'grants' => self::grantsOf($offer),
                    'access' => self::accessFor($payment, $offer),
                    'manage_token' => Str::random(48),
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            $neu[] = $pool;

            // Plaetze fuer etwas, das keinen Zugang vergibt, sind Einladungen
            // ins Leere: angenommen, und niemand kommt irgendwo hinein. Das
            // Kontingent entsteht trotzdem (bezahlt ist bezahlt), aber laut.
            if ($pool->grantList() === []) {
                Log::warning('statamic-offers: seats were sold for an offer whose products grant nothing; accepted seats will give no access.', [
                    'payment_id' => $payment->getKey(),
                    'offer' => $teile->offer,
                    'pool_id' => $pool->getKey(),
                ]);
            }

            $this->mail(fn () => Mail::to($email)->send(new SeatPoolMail($pool)), $pool->id, 'pool');

            SeatPoolOpened::dispatch($pool);
        }

        return $neu;
    }

    /**
     * Einen Platz an eine Adresse vergeben.
     *
     * Geprueft wird hier und nicht im Formular: eine Adresse, die im Kontingent
     * schon einen Platz hat, bekommt keinen zweiten, und ein volles Kontingent
     * nimmt keine Einladung mehr. Das Zaehlen und das Anlegen stehen in einer
     * Transaktion; auf MySQL und Postgres schliesst die Sperre auf die
     * Kontingentzeile das Rennen zweier gleichzeitiger Einladungen, auf SQLite
     * tut es die Schreibsperre der ganzen Datenbank.
     *
     * @throws ValidationException
     */
    public function invite(SeatPool $pool, string $email, ?string $name = null): Seat
    {
        $email = mb_strtolower(trim($email));

        $seat = DB::transaction(function () use ($pool, $email, $name): Seat {
            $frisch = SeatPool::query()->whereKey($pool->getKey())->lockForUpdate()->first();

            if ($frisch === null || $frisch->isClosed()) {
                throw ValidationException::withMessages(['email' => __('statamic-offers::messages.seats_closed')]);
            }

            $belegt = $pool->seatRows()->where('status', '!=', Seat::STATUS_REVOKED);

            if ((clone $belegt)->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages(['email' => __('statamic-offers::messages.seats_already_invited')]);
            }

            if ($belegt->count() >= $pool->seats) {
                throw ValidationException::withMessages(['email' => __('statamic-offers::messages.seats_full')]);
            }

            return Seat::create([
                'pool_id' => $pool->getKey(),
                'email' => $email,
                'name' => $name !== null && trim($name) !== '' ? trim($name) : null,
                'token' => Str::random(48),
                'status' => Seat::STATUS_INVITED,
                'invited_at' => Carbon::now(),
            ]);
        });

        $this->mail(fn () => Mail::to($email)->send(new SeatInvitationMail($seat)), $pool->id, 'invitation');

        SeatInvited::dispatch($seat, $pool);

        return $seat;
    }

    /**
     * Die Einladung annehmen: der Zugang wird vergeben.
     *
     * Einmal, auch bei zwei Klicks: der Zustandswechsel ist ein bedingtes
     * UPDATE, und nur wer ihn gewinnt, vergibt.
     */
    public function accept(Seat $seat): bool
    {
        $pool = $seat->pool;

        // Ein geschlossenes Kontingent vergibt nichts mehr. Die Seite fragt
        // vorher schon; das hier ist die Wache fuer jeden anderen Weg.
        if ($pool->fresh()?->isClosed() ?? true) {
            return false;
        }

        $gewonnen = Seat::query()
            ->whereKey($seat->getKey())
            ->where('status', Seat::STATUS_INVITED)
            ->update(['status' => Seat::STATUS_CLAIMED, 'claimed_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        if ($gewonnen === 0) {
            return false;
        }

        $this->inBrandOf($pool, fn () => $this->access->grant($seat->email, $pool->grantList(), $seat->sourceRef(), $pool->access));

        SeatAccepted::dispatch($seat->fresh() ?? $seat, $pool);

        return true;
    }

    /**
     * Einen Platz zurueckholen. Er ist danach frei.
     *
     * War er angenommen, wird der Zugang entzogen, mit Grund. War er nur
     * eingeladen, gibt es nichts zu entziehen; die Einladung gilt nicht mehr.
     *
     * **Der Stand vorher kommt aus der Datenbank, und das UPDATE setzt ihn
     * voraus.** Mit dem Stand aus dem uebergebenen Objekt konnte eine Annahme
     * zwischen Lesen und Zurueckholen durchrutschen: das Objekt sagte
     * „eingeladen", der Platz war inzwischen angenommen, und entzogen wurde
     * nichts. Jetzt gewinnt das UPDATE nur, wenn der Stand noch derselbe ist;
     * sonst wird neu gelesen.
     *
     * **Erst entziehen, dann als zurueckgeholt markieren.** Umgekehrt stand ein
     * Platz, dessen Entzug gescheitert war, als „zurueckgeholt" da und gab weiter
     * Zugang, und nichts holte es je nach. Jetzt bleibt er angenommen, das Log
     * sagt warum, und `offers:seats-reconcile` versucht es wieder. Ein doppelter
     * Entzug schadet nicht: entitlements entzieht nur, was noch besteht.
     */
    public function revoke(Seat $seat, ?string $reason = null): bool
    {
        for ($versuch = 0; $versuch < 3; $versuch++) {
            $vorher = Seat::query()->whereKey($seat->getKey())->value('status');

            if ($vorher === null || $vorher === Seat::STATUS_REVOKED) {
                return false;
            }

            if ($vorher === Seat::STATUS_CLAIMED) {
                $pool = $seat->pool;

                $entzogen = $this->inBrandOf($pool, fn () => $this->access->revoke(
                    $seat->email,
                    $pool->grantList(),
                    $seat->sourceRef(),
                    $reason ?? 'Platz von der Käuferin zurückgeholt (Kontingent '.$seat->pool_id.')',
                ));

                if ($entzogen !== true) {
                    Log::warning('statamic-offers: a seat stays open because its access could not be revoked.', [
                        'seat_id' => $seat->getKey(),
                        'pool_id' => $seat->pool_id,
                    ]);

                    return false;
                }
            }

            $gewonnen = Seat::query()
                ->whereKey($seat->getKey())
                ->where('status', $vorher)
                ->update(['status' => Seat::STATUS_REVOKED, 'revoked_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

            if ($gewonnen > 0) {
                SeatRevoked::dispatch($seat->fresh() ?? $seat, $seat->pool, (string) $vorher, $reason);

                return true;
            }
        }

        return false;
    }

    /**
     * Offene Plaetze geschlossener Kontingente nachholen.
     *
     * Fuer `offers:seats-reconcile`: ein Entzug, der bei der Erstattung
     * scheiterte (entitlements gerade weg, Datenbank kurz nicht erreichbar),
     * wird hier wiederholt. Eine erneute Zustellung des Ereignisses tut dasselbe
     * ueber {@see self::close()}; auf die allein ist kein Verlass, weil payments
     * eine schon gebuchte Erstattung nicht erneut meldet.
     *
     * @return array{closed_pools: int, revoked: int, still_open: int}
     */
    public function reconcile(): array
    {
        $ergebnis = ['closed_pools' => 0, 'revoked' => 0, 'still_open' => 0];

        $pools = SeatPool::query()
            ->whereNotNull('closed_at')
            ->whereHas('seatRows', fn ($q) => $q->where('status', '!=', Seat::STATUS_REVOKED))
            ->get();

        foreach ($pools as $pool) {
            $ergebnis['closed_pools']++;
            $grund = $pool->closed_reason ?: 'Kontingent geschlossen';

            foreach ($pool->seatRows()->where('status', '!=', Seat::STATUS_REVOKED)->get() as $seat) {
                $this->revoke($seat, $grund) ? $ergebnis['revoked']++ : $ergebnis['still_open']++;
            }
        }

        return $ergebnis;
    }

    /**
     * Das Kontingent schliessen: volle Erstattung oder Rueckbuchung.
     *
     * Jeder Platz wird zurueckgeholt, angenommene mit Entzug des Zugangs. Ein
     * bedingtes UPDATE auf `closed_at`, damit Datum und Grund der ersten
     * Meldung stehen bleiben.
     *
     * **Die Plaetze werden auch bei einer zweiten Zustellung durchgegangen.**
     * Frueher brach der Aufruf ab, sobald das Kontingent schon geschlossen war,
     * und ein Platz, dessen Entzug beim ersten Mal scheiterte, blieb fuer immer
     * offen.
     *
     * @return bool ob das Kontingent mit diesem Aufruf geschlossen wurde
     */
    public function close(SeatPool $pool, string $reason): bool
    {
        $gewonnen = SeatPool::query()
            ->whereKey($pool->getKey())
            ->whereNull('closed_at')
            ->update(['closed_at' => Carbon::now(), 'closed_reason' => mb_substr($reason, 0, 191), 'updated_at' => Carbon::now()]);

        foreach ($pool->seatRows()->where('status', '!=', Seat::STATUS_REVOKED)->get() as $seat) {
            $this->revoke($seat, $reason);
        }

        // Nach den Plaetzen: wer das hoert, findet sie schon zurueckgeholt.
        if ($gewonnen > 0) {
            SeatPoolClosed::dispatch($pool->fresh() ?? $pool, $reason);
        }

        return $gewonnen > 0;
    }

    /**
     * Den Verwaltungslink noch einmal an die Kaeuferin schicken.
     *
     * An die Adresse des Kaufs und an keine andere: wer den Link an eine
     * beliebige Adresse schicken koennte, gaebe fremde Plaetze weiter.
     */
    public function resend(SeatPool $pool): void
    {
        Mail::to($pool->owner_email)->send(new SeatPoolMail($pool));
    }

    /**
     * Alle Kontingente einer Zahlung schliessen.
     *
     * @return int wie viele geschlossen wurden
     */
    public function closeForPayment(Payment $payment, string $reason): int
    {
        $geschlossen = 0;

        foreach (SeatPool::query()->where('payment_id', $payment->getKey())->get() as $pool) {
            $geschlossen += $this->close($pool, $reason) ? 1 : 0;
        }

        return $geschlossen;
    }

    /**
     * Den Zugang unter der Marke des Kontingents schreiben, nicht unter der
     * der Anfrage.
     *
     * Die Seiten der Plaetze werden aus einer Mail geoeffnet, und welche Marke
     * die Anfrage traegt, entscheidet die Site, unter der der Link aufgerufen
     * wird. entitlements stempelt die Marke beim Anlegen aus der aktuellen und
     * sucht beim Entziehen nur in ihr. Beides muss die Marke des Kaufs sein.
     *
     * Null ist ein Betrieb ohne Mandanten; dort gibt es nichts umzustellen,
     * und `runFor(0)` kennte keine Marke.
     */
    protected function inBrandOf(SeatPool $pool, callable $tun): mixed
    {
        if ($pool->brand_id > 0 && app()->bound('brand-context')) {
            return app('brand-context')->runFor($pool->brand_id, fn () => $tun());
        }

        return $tun();
    }

    /**
     * Was ein Platz dieses Angebots freischaltet: die Zugaenge aller Produkte
     * darunter, wie beim Buendel.
     *
     * @return list<string>
     */
    public static function grantsOf(Offer $offer): array
    {
        $slugs = [];

        foreach ($offer->productHandles() as $handle) {
            $grants = app(Catalogue::class)->find($handle)['grants'] ?? null;

            foreach (is_array($grants) ? $grants : [$grants] as $slug) {
                if (is_string($slug) && $slug !== '') {
                    $slugs[] = $slug;
                }
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * Das Zugangsfenster: was die Kasse an die Zahlung geheftet hat, sonst das
     * des Angebots.
     *
     * @return array{starts_at: string|null, days: int|null}|null
     */
    protected static function accessFor(Payment $payment, ?Offer $offer): ?array
    {
        $meta = $payment->meta['access'] ?? null;

        if (is_array($meta)) {
            return [
                'starts_at' => is_string($meta['starts_at'] ?? null) ? $meta['starts_at'] : null,
                'days' => is_numeric($meta['days'] ?? null) ? (int) $meta['days'] : null,
            ];
        }

        return $offer?->accessWindow();
    }

    /**
     * Eine Mail, die scheitern darf, ohne den Rest mitzunehmen.
     *
     * Das Kontingent steht, die Einladung steht; ein Mailserver, der gerade
     * nicht antwortet, darf daran nichts zuruecknehmen. Der Link zur Verwaltung
     * funktioniert weiter, und das Log sagt, welche Mail fehlt.
     */
    protected function mail(callable $senden, int $poolId, string $was): void
    {
        try {
            $senden();
        } catch (Throwable $e) {
            Log::error('statamic-offers: a seat mail could not be sent.', [
                'pool_id' => $poolId,
                'mail' => $was,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
