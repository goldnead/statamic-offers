<?php

namespace Goldnead\StatamicOffers\Integrations\Map;

use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Models\Seat;
use Goldnead\StatamicOffers\Models\SeatPool;

/**
 * Angebote für die Funnel-Karte.
 *
 * Die Karte speichert nur `offer` und den Handle. Name, Link, an/aus und die
 * Zahl kommen von hier, jedes Mal frisch. Die Ansicht des Angebots ist dieselbe
 * wie im Control Panel, also nach Marke verengt wie dort.
 *
 * **Die Zahl ist die Platzannahme im Zeitraum, nicht der Verkauf.** Verkäufe
 * stehen in den Zahlungstabellen von statamic-payments, nicht in diesem Addon,
 * und `accepted_count` ist eine Summe über alle Zeiten ohne Datum. Was einen
 * Zeitraum hat und in der eigenen Tabelle steht, ist `claimed_at` an den Plätzen
 * (`offer_seats`). Gibt es für das Angebot nie ein Kontingent, bleibt die Zahl
 * leer, statt eine Null zu zeigen, die nach „Kontingent ohne Annahmen" klingt.
 */
class OfferMapObjects implements ProvidesMapObjects
{
    public function type(): string
    {
        return 'offer';
    }

    public function typeLabel(): string
    {
        return __('statamic-offers::messages.map_type_label');
    }

    /** @return array<int, MapObject> */
    public function objects(?string $query = null, int $limit = 50): array
    {
        $builder = Offer::query()->forBrand();

        $term = trim((string) $query);

        if ($term !== '') {
            // Wie im Control Panel: `%` und `_` sind Platzhalter, und SQLite
            // kennt das ESCAPE nicht von allein.
            $escaped = addcslashes($term, '%_\\');

            $builder->where(function ($q) use ($escaped) {
                $q->whereRaw("name LIKE ? ESCAPE '\\'", ['%'.$escaped.'%'])
                    ->orWhereRaw("handle LIKE ? ESCAPE '\\'", ['%'.$escaped.'%']);
            });
        }

        return $builder
            ->orderBy('name')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (Offer $offer) => $this->toMapObject($offer))
            ->values()
            ->all();
    }

    public function find(string $id): ?MapObject
    {
        $offer = $this->offerFor($id);

        return $offer === null ? null : $this->toMapObject($offer);
    }

    /**
     * Angenommene Plätze im Zeitraum, oder null.
     *
     * Gezählt wird `claimed_at` an den Plätzen aller Kontingente dieses
     * Angebots, auch wenn ein Platz inzwischen zurückgeholt ist: die Annahme
     * hat im Zeitraum stattgefunden. Null, wenn es das Angebot nicht gibt oder
     * es nie ein Kontingent dafür gab.
     */
    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        $offer = $this->offerFor($id);

        if ($offer === null) {
            return null;
        }

        $poolIds = SeatPool::query()
            ->where('offer', $offer->handle)
            ->where('brand_id', (int) $offer->brand_id)
            ->pluck('id');

        if ($poolIds->isEmpty()) {
            return null;
        }

        $count = Seat::query()
            ->whereIn('pool_id', $poolIds)
            ->whereNotNull('claimed_at')
            ->whereBetween('claimed_at', [$period->from, $period->to])
            ->count();

        return new MapMetric(
            value: $count,
            what: __('statamic-offers::messages.map_metric_seats'),
            period: $period,
        );
    }

    protected function offerFor(string $handle): ?Offer
    {
        return Offer::query()->forBrand()->where('handle', $handle)->first();
    }

    protected function toMapObject(Offer $offer): MapObject
    {
        return new MapObject(
            type: $this->type(),
            id: (string) $offer->handle,
            label: (string) $offer->name,
            cpUrl: cp_route('utilities.offers.show', ['offer' => $offer->id]),
            // Der Schalter des Angebots, nicht „verkäuflich jetzt": das
            // hängt zusätzlich an Verkaufsfenster, Kontingent und Katalog und
            // wäre eine Aussage, die diese Karte nicht allein belegt.
            enabled: (bool) $offer->active,
            detail: $this->detail($offer),
        );
    }

    /** Der Preis, so wie die Leserin ihn liest. Null, wenn es keinen gibt. */
    protected function detail(Offer $offer): ?string
    {
        $betrag = $offer->amountLocal();

        return $betrag === null ? null : $betrag.' '.$offer->currency();
    }
}
