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
use Illuminate\Support\Str;
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

    /**
     * Paare, die dieses Addon schreiben kann (Vertrag "Verbindung ziehen", duck-typed).
     * Kasten → Produkt gibt es bewusst nicht: der Kasten kennt nur ein Angebot.
     *
     * @return list<array{from_type:string,to_type:string,kind:string,label:string}>
     */
    public function connectionRules(): array
    {
        return [
            ['from_type' => 'course', 'to_type' => $this->type(), 'kind' => 'shows_box',
                'label' => __('statamic-offers::messages.map_edge_shows_box')],
            ['from_type' => $this->type(), 'to_type' => 'offer', 'kind' => 'invites_to',
                'label' => __('statamic-offers::messages.map_edge_invites_to')],
        ];
    }

    /** @param  array<string, string>  $c */
    public function connectionAuthorized(array $c, $user): bool
    {
        if ($user === null || ! method_exists($user, 'can')) {
            return false;
        }

        if ($this->isCourseBox($c)) {
            return (bool) $user->can('edit '.$this->courseCollection().' entries');
        }

        if ($this->isBoxOffer($c)) {
            return (bool) $user->can('edit '.ContentBoxes::collection().' entries');
        }

        return false;
    }

    /**
     * @param  array<string, string>  $c
     * @return array{consequence:string,before:string,after:string}
     */
    public function connectionPreview(array $c, string $action): array
    {
        if (! in_array($action, ['connect', 'disconnect'], true)) {
            throw new \DomainException(__('statamic-offers::messages.map_connect_unsupported'));
        }

        return $this->isCourseBox($c) ? $this->previewCourseBox($c, $action) : $this->previewBoxOffer($c, $action);
    }

    /** @param  array<string, string>  $c  @return array{before:string,after:string} */
    public function connect(array $c): array
    {
        return $this->write($c, 'connect');
    }

    /** @param  array<string, string>  $c  @return array{before:string,after:string} */
    public function disconnect(array $c): array
    {
        return $this->write($c, 'disconnect');
    }

    /** @param  array<string, string>  $c  @return array{before:string,after:string} */
    protected function write(array $c, string $action): array
    {
        $preview = $this->connectionPreview($c, $action);

        if ($this->isCourseBox($c)) {
            $course = $this->courseEntry((string) $c['from_id']);
            $box = $this->boxEntry((string) $c['to_id']);
            $rows = $this->courseRows($course);

            if ($action === 'connect') {
                $rows[] = ['id' => Str::random(8), 'box' => [(string) $box->id()], 'place' => 'end'];
            } else {
                $rows = array_values(array_filter($rows, fn ($row) => $this->rowBoxId($row) !== (string) $box->id()));
            }

            $course->set('course_boxes', $rows)->save();
        } else {
            $box = $this->boxEntry((string) $c['from_id']);
            $box->set('offer', $action === 'connect' ? (string) $c['to_id'] : null)->save();
        }

        return ['before' => $preview['before'], 'after' => $preview['after']];
    }

    /** @param  array<string, string>  $c  @return array{consequence:string,before:string,after:string} */
    protected function previewCourseBox(array $c, string $action): array
    {
        $course = $this->courseEntry((string) $c['from_id']);
        $box = $this->boxEntry((string) $c['to_id']);
        $connected = collect($this->courseRows($course))
            ->contains(fn ($row) => $this->rowBoxId($row) === (string) $box->id());
        $names = ['course' => $this->nameOf($course), 'box' => $this->nameOf($box)];

        if ($action === 'connect' && $connected) {
            throw new \DomainException(__('statamic-offers::messages.map_connect_box_already', $names));
        }

        if ($action === 'disconnect' && ! $connected) {
            throw new \DomainException(__('statamic-offers::messages.map_connect_box_not_in_course', $names));
        }

        $yes = __('statamic-offers::messages.map_connect_box_shown', $names);
        $no = __('statamic-offers::messages.map_connect_box_not_shown', $names);

        return [
            'consequence' => __('statamic-offers::messages.map_connect_box_'.$action, $names),
            'before' => $action === 'connect' ? $no : $yes,
            'after' => $action === 'connect' ? $yes : $no,
        ];
    }

    /** @param  array<string, string>  $c  @return array{consequence:string,before:string,after:string} */
    protected function previewBoxOffer(array $c, string $action): array
    {
        $box = $this->boxEntry((string) $c['from_id']);
        $handle = (string) $c['to_id'];
        $offer = Offer::query()->forBrand()->where('handle', $handle)->first();

        if ($offer === null) {
            throw new \DomainException(__('statamic-offers::messages.map_connect_offer_missing', ['offer' => $handle]));
        }

        $current = trim((string) $box->get('offer'));
        $names = ['box' => $this->nameOf($box), 'offer' => (string) ($offer->name ?: $handle)];

        if ($action === 'connect' && $current === $handle) {
            throw new \DomainException(__('statamic-offers::messages.map_connect_offer_already', $names));
        }

        if ($action === 'connect' && $current !== '') {
            throw new \DomainException(__('statamic-offers::messages.map_connect_offer_other', $names + ['current' => $current]));
        }

        if ($action === 'disconnect' && $current !== $handle) {
            throw new \DomainException($current === ''
                ? __('statamic-offers::messages.map_connect_offer_none', $names)
                : __('statamic-offers::messages.map_connect_offer_other', $names + ['current' => $current]));
        }

        $none = __('statamic-offers::messages.map_connect_offer_empty');

        return [
            'consequence' => __('statamic-offers::messages.map_connect_offer_'.$action, $names),
            'before' => $action === 'connect' ? $none : $handle,
            'after' => $action === 'connect' ? $handle : $none,
        ];
    }

    /** @param  array<string, string>  $c */
    protected function isCourseBox(array $c): bool
    {
        return ($c['from_type'] ?? '') === 'course' && ($c['to_type'] ?? '') === $this->type();
    }

    /** @param  array<string, string>  $c */
    protected function isBoxOffer(array $c): bool
    {
        return ($c['from_type'] ?? '') === $this->type() && ($c['to_type'] ?? '') === 'offer';
    }

    protected function boxEntry(string $slug): EntryContract
    {
        $box = $slug === '' ? null : $this->boxes()->first(fn (EntryContract $b) => (string) $b->slug() === $slug);

        return $box ?? throw new \DomainException(__('statamic-offers::messages.map_connect_box_missing', ['box' => $slug]));
    }

    protected function courseEntry(string $slug): EntryContract
    {
        $course = $slug === '' ? null : $this->entriesOf($this->courseCollection())
            ->first(fn ($e) => (string) $e->slug() === $slug && $e->get('kind') !== 'material');

        return $course ?? throw new \DomainException(__('statamic-offers::messages.map_connect_course_missing', ['course' => $slug]));
    }

    /** @return list<mixed> */
    protected function courseRows(EntryContract $course): array
    {
        $rows = $course->get('course_boxes');

        return is_array($rows) ? array_values($rows) : [];
    }

    protected function rowBoxId(mixed $row): string
    {
        $raw = is_array($row) ? $row['box'] ?? null : null;

        return trim((string) (is_array($raw) ? ($raw[0] ?? '') : $raw));
    }

    protected function nameOf(EntryContract $entry): string
    {
        $title = trim((string) $entry->get('title'));

        return $title !== '' ? $title : (string) $entry->slug();
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
