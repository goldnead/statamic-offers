<?php

namespace Goldnead\StatamicOffers\Http\Controllers\Web;

use Goldnead\StatamicOffers\Models\Seat;
use Goldnead\StatamicOffers\Models\SeatPool;
use Goldnead\StatamicOffers\Support\SeatPools;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * Die Seiten der Kaeuferin (Plaetze verteilen) und der Eingeladenen (Platz
 * annehmen).
 *
 * **Der Token ist die Berechtigung**, und nur er. Beide Seiten werden aus einer
 * Mail geoeffnet, von Menschen ohne Konto auf dieser Site; ein Login davor
 * waere eine Huerde genau fuer die Chorleiterin, die zehn Plaetze fuer ihre
 * Stimmgruppe gekauft hat. Die Tokens sind 48 Zeichen Zufall und eindeutig.
 * Ein unbekannter Token ist ein 404, kein 403: „gibt es nicht" verraet nichts.
 */
class SeatsController extends Controller
{
    public function __construct(protected SeatPools $pools) {}

    public function manage(string $token): View
    {
        $pool = $this->pool($token);
        $seats = $pool->seatRows()->where('status', '!=', Seat::STATUS_REVOKED)->orderBy('id')->get();

        return view('statamic-offers::seats.manage', [
            'pool' => $pool,
            'title' => $pool->title(),
            'seats' => $seats,
            'taken' => $seats->count(),
            'free' => max(0, $pool->seats - $seats->count()),
        ]);
    }

    public function invite(Request $request, string $token): RedirectResponse
    {
        $pool = $this->pool($token);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'name' => ['nullable', 'string', 'max:191'],
        ]);

        $seat = $this->pools->invite($pool, (string) $data['email'], $data['name'] ?? null);

        return redirect()
            ->route('statamic-offers.seats.manage', $pool->manage_token)
            ->with('statamic-offers.seats.status', __('statamic-offers::messages.seats_invited', ['email' => $seat->email]));
    }

    public function revoke(string $token, string $seat): RedirectResponse
    {
        $pool = $this->pool($token);

        // Nur ein Platz **dieses** Kontingents. Ohne die Bindung liesse sich
        // mit dem eigenen Token jeder Platz jeder anderen Kaeuferin
        // zurueckholen, indem man die Nummer hochzaehlt.
        $row = Seat::query()->where('pool_id', $pool->getKey())->whereKey($seat)->firstOrFail();

        $this->pools->revoke($row);

        return redirect()
            ->route('statamic-offers.seats.manage', $pool->manage_token)
            ->with('statamic-offers.seats.status', __('statamic-offers::messages.seats_revoked', ['email' => $row->email]));
    }

    public function claim(string $token): View
    {
        $seat = $this->seat($token);

        return $this->claimView($seat);
    }

    public function accept(string $token): View
    {
        $seat = $this->seat($token);

        $this->pools->accept($seat);

        return $this->claimView($seat->fresh() ?? $seat);
    }

    protected function claimView(Seat $seat): View
    {
        $pool = $seat->pool;
        $next = config('statamic-offers.seats.after_claim_url');

        return view('statamic-offers::seats.claim', [
            'seat' => $seat,
            'title' => $pool->title(),
            'inviter' => $pool->owner_name ?: $pool->owner_email,
            'claimed' => $seat->status === Seat::STATUS_CLAIMED,
            'next' => is_string($next) && $next !== '' ? $next : null,
        ]);
    }

    protected function pool(string $token): SeatPool
    {
        return SeatPool::query()->where('manage_token', $token)->firstOrFail();
    }

    /** Ein zurueckgeholter Platz ist fuer die Eingeladene nicht mehr da. */
    protected function seat(string $token): Seat
    {
        return Seat::query()
            ->where('token', $token)
            ->where('status', '!=', Seat::STATUS_REVOKED)
            ->firstOrFail();
    }
}
