<?php

namespace Goldnead\StatamicOffers\Integrations\Map;

use Goldnead\BrandContext\Contracts\ProvidesMapEdges;
use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapEdge;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Models\Seat;
use Goldnead\StatamicOffers\Models\SeatPool;
use Goldnead\StatamicOffers\Support\OfferHandle;
use Goldnead\StatamicOffers\Support\OfferSales;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicPayments\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
class OfferMapObjects implements ProvidesMapEdges, ProvidesMapObjects
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

        $count = $offer === null ? null : $this->seatsAccepted($offer, $period);

        if ($count === null) {
            return null;
        }

        return new MapMetric(
            value: $count,
            what: __('statamic-offers::messages.map_metric_seats'),
            period: $period,
        );
    }

    /**
     * Optionale Blöcke für das Seitenfenster der Karte (Landkarte v4, Teil 3):
     * ein Faktenblock mit Preis, bezahlten Bestellungen und angenommenen
     * Plätzen im Zeitraum. Jede Zeile fehlt, wenn ihre Quelle fehlt: kein Preis,
     * keine Zahlungstabellen, nie ein Kontingent. Null bei unbekanntem Angebot.
     *
     * Die Bestellungen sind eine Anzahl, keine Summe, also werden Währungen
     * nicht addiert.
     *
     * @return list<array<string, mixed>>|null
     */
    public function details(string $id, MapPeriod $period): ?array
    {
        $offer = $this->offerFor($id);

        if ($offer === null) {
            return null;
        }

        $rows = [];

        $cent = $offer->amountCent();
        $price = $cent === null ? null : $this->money($cent, $offer->currency());

        if ($price !== null) {
            $rows[] = ['label' => __('statamic-offers::messages.map_detail_price'), 'value' => Str::limit($price, 120), 'href' => null];
        }

        $orders = $this->paidOrders($offer, $period);

        if ($orders !== null) {
            $rows[] = [
                'label' => __('statamic-offers::messages.map_detail_orders', ['period' => __('statamic-offers::messages.map_detail_period', ['days' => $period->days])]),
                'value' => (string) $orders,
                'href' => null,
            ];
        }

        $seats = $this->seatsAccepted($offer, $period);

        if ($seats !== null) {
            $rows[] = [
                'label' => __('statamic-offers::messages.map_detail_seats', ['period' => __('statamic-offers::messages.map_detail_period', ['days' => $period->days])]),
                'value' => (string) $seats,
                'href' => null,
            ];
        }

        return $rows === [] ? null : [['kind' => 'facts', 'title' => null, 'rows' => $rows]];
    }

    /**
     * Der Preis, wie ihn die Sprache des CP schreibt, „1.790,00 EUR" auf
     * Deutsch. Dieselbe Schreibweise wie im Seitenfenster von statamic-products,
     * aus den eigenen Sprachdateien; die Nachkommastellen je Währung kommen
     * von payments.
     */
    protected function money(int $cent, string $currency): string
    {
        $decimals = Money::decimals($currency);

        return number_format(
            $cent / (10 ** $decimals),
            $decimals,
            __('statamic-offers::messages.map_decimal_point'),
            __('statamic-offers::messages.map_thousands_separator'),
        ).' '.$currency;
    }

    /** Angenommene Plätze im Zeitraum; null ohne Kontingent. Die Regel der Karten-Zahl. */
    protected function seatsAccepted(Offer $offer, MapPeriod $period): ?int
    {
        $poolIds = SeatPool::query()
            ->where('offer', $offer->handle)
            ->where('brand_id', (int) $offer->brand_id)
            ->pluck('id');

        if ($poolIds->isEmpty()) {
            return null;
        }

        return Seat::query()
            ->whereIn('pool_id', $poolIds)
            ->whereNotNull('claimed_at')
            ->whereBetween('claimed_at', [$period->from, $period->to])
            ->count();
    }

    /**
     * Bezahlte Bestellungen mit einer Zeile dieses Angebots im Zeitraum, nach
     * der Regel der Produkt-Karte in statamic-products: bezahlt, `paid_at` im
     * Zeitraum und nicht in der Zukunft, in der aktuellen Marke, jede Zahlung
     * einmal. Zeilen mit jedem Zusatz (`offer:x:raten3`) gehören zum Angebot,
     * die Einrichtungsgebühr allein ist kein Verkauf (wie in {@see OfferSales}).
     * Null ohne die Zahlungstabellen.
     */
    protected function paidOrders(Offer $offer, MapPeriod $period): ?int
    {
        if (! Schema::hasTable('payments') || ! Schema::hasTable('payment_items')) {
            return null;
        }

        $basis = OfferHandle::of($offer);
        $like = addcslashes($basis.':', '%_\\').'%';
        $line = fn ($q) => $q->where('payment_items.product', $basis)->orWhere('payment_items.product', 'like', $like);

        $ids = Brands::only(Payment::query(), Brands::readerId())
            ->where('status', Payment::STATUS_PAID)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $period->from)
            ->where('paid_at', '<', $period->to)
            ->where('paid_at', '<=', Carbon::now())
            ->whereExists(fn ($q) => $q->from('payment_items')
                ->whereColumn('payment_items.payment_id', 'payments.id')
                ->where($line))
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        $paid = [];

        foreach (DB::table('payment_items')->whereIn('payment_id', $ids)->where($line)->get(['payment_id', 'product']) as $row) {
            $parts = OfferHandle::parse((string) $row->product);

            if ($parts !== null && $parts->offer === $offer->handle && $parts->countsAsSale()) {
                $paid[$row->payment_id] = true;
            }
        }

        return count($paid);
    }

    /**
     * Angebot → Produkt, je Paar eine Kante. Die Produkte kommen aus
     * `Offer::productHandles()`, dem Leitprodukt und den weiteren. Ein Produkt
     * ohne Slug ergibt keine Kante. Dieselbe Marken-Sicht wie `objects()`.
     *
     * @return list<MapEdge>
     */
    public function edges(): iterable
    {
        $edges = [];

        foreach (Offer::query()->forBrand()->get() as $offer) {
            foreach ($offer->productHandles() as $slug) {
                if ((string) $slug === '') {
                    continue;
                }

                $edges[] = new MapEdge(
                    fromType: $this->type(),
                    fromId: (string) $offer->handle,
                    toType: 'product',
                    toId: $slug,
                    kind: 'sells',
                    label: __('statamic-offers::messages.map_edge_sells'),
                    derivedFrom: 'products of the offer',
                );
            }
        }

        return $edges;
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
