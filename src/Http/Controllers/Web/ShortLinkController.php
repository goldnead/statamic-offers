<?php

namespace Goldnead\StatamicOffers\Http\Controllers\Web;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\OfferMoments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Die Link-Weiche: `/go/<slug>` fuehrt zu Ziel A oder Ziel B.
 *
 * **Unverengt nach Marke**, und das ist Absicht: ein Kurzlink auf einem Flyer
 * kennt keine Marke, und der Slug ist ueber alle Marken eindeutig. Verraten
 * wird nichts ausser dem Ziel, das die Betreiberin selbst dafuer eingetragen
 * hat.
 *
 * Die Anfrage reist mit (`?coupon=CHOR20&utm_source=flyer`), damit ein
 * Gutschein-Link, der ueber den Kurzlink geht, auch nach dem Umschalten der
 * Weiche seinen Code mitbringt.
 *
 * Ein 302 und kein 301: ein Browser, der sich eine dauerhafte Weiterleitung
 * merkt, fuehrt nach dem Stichtag weiter zum alten Ziel.
 */
class ShortLinkController extends Controller
{
    public function go(Request $request, string $slug): RedirectResponse
    {
        $offer = Offer::query()->where('link_slug', $slug)->first();
        $ziel = $offer === null ? '' : trim((string) $offer->link_target);

        abort_if($offer === null || $ziel === '', 404);

        $weiche = $offer->linkDestination();
        $offer->recordLinkHit($weiche);

        // Der erste Aufruf nach dem Stichtag meldet den Wechsel.
        app(OfferMoments::class)->link($offer, $weiche);

        $url = $weiche === Offer::LINK_FALLBACK ? trim((string) $offer->link_fallback) : $ziel;

        return redirect()->to(self::withQuery($url, $request->query()), 302);
    }

    /**
     * Die Parameter der Anfrage an das Ziel haengen. Was das Ziel selbst schon
     * traegt, bleibt und gewinnt: die Betreiberin hat es eingetragen.
     *
     * @param  array<string, mixed>  $query
     */
    protected static function withQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        [$ohneAnker, $anker] = array_pad(explode('#', $url, 2), 2, null);
        [$pfad, $eigene] = array_pad(explode('?', $ohneAnker, 2), 2, '');

        parse_str((string) $eigene, $vorhanden);

        $zusammen = http_build_query($vorhanden + $query);

        return $pfad.($zusammen === '' ? '' : '?'.$zusammen).($anker === null ? '' : '#'.$anker);
    }
}
