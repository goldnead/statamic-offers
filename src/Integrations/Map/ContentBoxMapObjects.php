<?php

namespace Goldnead\StatamicOffers\Integrations\Map;

use Goldnead\BrandContext\Contracts\ProvidesMapEdges;
use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapEdge;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\ContentBoxes;
use Illuminate\Support\Collection;
use Statamic\Entries\Entry as EntryContract;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry;

/**
 * Inhalts-Kästen (Sammlung `content_boxes`) für die Funnel-Karte.
 *
 * Der stabile Schlüssel ist der Slug des Eintrags, wie das Handle bei den
 * anderen Karten. Eine Zahl gibt es nicht: ein Kasten hat keine eigene
 * Zählregel (Einblendungen zählt das Angebot, nicht der Kasten), und eine
 * erfundene Null wäre schlimmer als keine.
 *
 * Kanten, nur berechnet und nie gespeichert, jede aus einem Feld:
 * - Kurs → Kasten: Kasten-Raster am Kurs (`course_boxes`) und an dessen Lektionen
 *   (`lesson_boxes`, `video_hints`). Das Raster speichert die Entry-ID des
 *   Kastens; eine ID, die nichts trifft, wird zur toten Kante mit dem
 *   geschriebenen Text. Materialien sind keine Kurs-Karte und zeichnen nichts.
 * - Kasten → Produkt: das Feld `offer` am Kasten, über die Produkte dieses
 *   Angebots. Gibt es das Angebot nicht, bleibt eine tote Kante zum Angebot.
 *
 * Die Sammlungen des Kurs-Addons werden über dessen Config gelesen, ohne das
 * Addon vorauszusetzen: ohne Kurse gibt es schlicht keine Kurs-Kanten.
 */
class ContentBoxMapObjects implements ProvidesMapEdges, ProvidesMapObjects
{
    public function type(): string
    {
        return 'content_box';
    }

    public function typeLabel(): string
    {
        return __('statamic-offers::messages.map_box_type_label');
    }

    /** @return array<int, MapObject> */
    public function objects(?string $query = null, int $limit = 50): array
    {
        $term = mb_strtolower(trim((string) $query));

        return $this->boxes()
            ->filter(fn (EntryContract $box) => $term === ''
                || str_contains(mb_strtolower((string) $box->get('title')), $term)
                || str_contains(mb_strtolower((string) $box->slug()), $term))
            ->sortBy(fn (EntryContract $box) => mb_strtolower((string) $box->get('title')))
            ->take(max(1, $limit))
            ->map(fn (EntryContract $box) => $this->toMapObject($box))
            ->values()
            ->all();
    }

    public function find(string $id): ?MapObject
    {
        $box = $id === '' ? null : $this->boxes()->first(fn (EntryContract $b) => (string) $b->slug() === $id);

        return $box === null ? null : $this->toMapObject($box);
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return null;
    }

    /** @return list<MapEdge> */
    public function edges(): iterable
    {
        $boxes = $this->boxes();
        $slugById = $boxes->mapWithKeys(fn (EntryContract $b) => [(string) $b->id() => (string) $b->slug()])->all();

        return [...$this->courseEdges($slugById), ...$this->offerEdges($boxes)];
    }

    /**
     * @param  array<string, string>  $slugById
     * @return list<MapEdge>
     */
    protected function courseEdges(array $slugById): array
    {
        $courses = $this->entriesOf($this->courseCollection())
            ->filter(fn ($entry) => $entry->get('kind') !== 'material');

        $slugByCourseId = $courses->mapWithKeys(fn ($e) => [(string) $e->id() => (string) $e->slug()])->all();

        $edges = [];

        $add = function (string $courseSlug, mixed $rows, string $derivedFrom) use (&$edges, $slugById): void {
            foreach (is_array($rows) ? $rows : [] as $row) {
                $raw = is_array($row) ? $row['box'] ?? null : null;
                $id = trim((string) (is_array($raw) ? ($raw[0] ?? '') : $raw));

                if ($id === '' || $courseSlug === '') {
                    continue;
                }

                $known = $slugById[$id] ?? null;
                $edge = new MapEdge(
                    fromType: 'course',
                    fromId: $courseSlug,
                    toType: $this->type(),
                    toId: $known ?? $id,
                    kind: 'shows_box',
                    label: __('statamic-offers::messages.map_edge_shows_box'),
                    derivedFrom: $derivedFrom,
                    toLabel: $known === null ? $id : null,
                );

                $edges[$edge->key()] ??= $edge;
            }
        };

        foreach ($courses as $course) {
            $add((string) $course->slug(), $course->get('course_boxes'), 'course field `course_boxes`');
        }

        foreach ($this->entriesOf($this->lessonCollection()) as $lesson) {
            $raw = $lesson->get('course');
            $courseId = trim((string) (is_array($raw) ? ($raw[0] ?? '') : $raw));
            $courseSlug = $slugByCourseId[$courseId] ?? '';

            $add($courseSlug, $lesson->get('lesson_boxes'), 'lesson field `lesson_boxes` of a lesson of the course');
            $add($courseSlug, $lesson->get('video_hints'), 'lesson field `video_hints` of a lesson of the course');
        }

        return array_values($edges);
    }

    /**
     * @param  Collection<int, EntryContract>  $boxes
     * @return list<MapEdge>
     */
    protected function offerEdges($boxes): array
    {
        $edges = [];

        // Einmal laden statt je Kasten: ein Angebot, viele Kästen, eine Abfrage.
        $handles = $boxes->map(fn ($b) => trim((string) $b->get('offer')))->filter()->unique()->values()->all();
        $offers = $handles === []
            ? collect()
            : Offer::query()->forBrand()->whereIn('handle', $handles)->get()->keyBy('handle');

        foreach ($boxes as $box) {
            $handle = trim((string) $box->get('offer'));

            if ($handle === '') {
                continue;
            }

            $offer = $offers->get($handle);

            if ($offer === null) {
                $edges[] = new MapEdge(
                    fromType: $this->type(),
                    fromId: (string) $box->slug(),
                    toType: 'offer',
                    toId: $handle,
                    kind: 'invites_to',
                    label: __('statamic-offers::messages.map_edge_invites_to'),
                    derivedFrom: 'field `offer` of the box',
                    toLabel: $handle,
                );

                continue;
            }

            foreach ($offer->productHandles() as $slug) {
                if ((string) $slug === '') {
                    continue;
                }

                $edges[] = new MapEdge(
                    fromType: $this->type(),
                    fromId: (string) $box->slug(),
                    toType: 'product',
                    toId: (string) $slug,
                    kind: 'invites_to',
                    label: __('statamic-offers::messages.map_edge_invites_to'),
                    derivedFrom: 'field `offer` of the box',
                );
            }
        }

        return $edges;
    }

    /** @return Collection<int, EntryContract> */
    protected function boxes()
    {
        return Entry::whereCollection(ContentBoxes::collection())
            ->filter(fn ($entry) => (string) $entry->slug() !== '')
            ->values();
    }

    /**
     * Sammlung des Kurs-Addons; fehlt sie (Addon nicht installiert), ist sie leer.
     *
     * @return Collection<int, EntryContract>
     */
    protected function entriesOf(string $handle)
    {
        return CollectionFacade::findByHandle($handle) === null
            ? collect()
            : Entry::whereCollection($handle);
    }

    protected function courseCollection(): string
    {
        return (string) config('courses.collections.courses', 'courses');
    }

    protected function lessonCollection(): string
    {
        return (string) config('courses.collections.lessons', 'course_lessons');
    }

    protected function toMapObject(EntryContract $box): MapObject
    {
        $title = trim((string) $box->get('title'));
        $headline = trim((string) $box->get('headline'));

        return new MapObject(
            type: $this->type(),
            id: (string) $box->slug(),
            label: $title !== '' ? $title : (string) $box->slug(),
            cpUrl: (string) $box->editUrl(),
            enabled: (bool) $box->published(),
            detail: $headline !== '' ? $headline : null,
        );
    }
}
