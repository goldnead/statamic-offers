<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\StatamicOffers\Contracts\SeatAccess;
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

            $this->mail(fn () => Mail::to($email)->send(new SeatPoolMail($pool)), $pool->id, 'pool');
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
            SeatPool::query()->whereKey($pool->getKey())->lockForUpdate()->first();

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
        $gewonnen = Seat::query()
            ->whereKey($seat->getKey())
            ->where('status', Seat::STATUS_INVITED)
            ->update(['status' => Seat::STATUS_CLAIMED, 'claimed_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        if ($gewonnen === 0) {
            return false;
        }

        $pool = $seat->pool;
        $this->access->grant($seat->email, $pool->grantList(), $seat->sourceRef(), $pool->access);

        return true;
    }

    /**
     * Einen Platz zurueckholen. Er ist danach frei.
     *
     * War er angenommen, wird der Zugang entzogen, mit Grund. War er nur
     * eingeladen, gibt es nichts zu entziehen; die Einladung gilt nicht mehr.
     */
    public function revoke(Seat $seat): bool
    {
        $vorher = $seat->status;

        $gewonnen = Seat::query()
            ->whereKey($seat->getKey())
            ->where('status', '!=', Seat::STATUS_REVOKED)
            ->update(['status' => Seat::STATUS_REVOKED, 'revoked_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        if ($gewonnen === 0) {
            return false;
        }

        if ($vorher === Seat::STATUS_CLAIMED) {
            $this->access->revoke(
                $seat->email,
                $seat->pool->grantList(),
                $seat->sourceRef(),
                'Platz von der Käuferin zurückgeholt (Kontingent '.$seat->pool_id.')',
            );
        }

        return true;
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
