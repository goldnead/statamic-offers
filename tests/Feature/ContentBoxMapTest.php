<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\BrandContext\Map\MapEdge;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicOffers\Integrations\Map\ContentBoxMapObjects;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Der Kasten als Karte der Funnel-Landkarte und seine Kanten.
 *
 * Eine Kante entsteht nur, wenn ein Feld es sagt: das Kasten-Raster am Kurs
 * (`course_boxes`), an der Lektion (`lesson_boxes`, `video_hints`) und das Feld
 * `offer` am Kasten. Nichts wird geschätzt.
 */
class ContentBoxMapTest extends TestCase
{
    protected ContentBoxMapObjects $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('offers:install-boxes')->assertSuccessful();
        Collection::make('courses')->routes(null)->save();
        Collection::make('course_lessons')->routes(null)->save();

        $this->provider = new ContentBoxMapObjects;
    }

    protected function box(string $slug, array $data = [], bool $published = true): \Statamic\Contracts\Entries\Entry
    {
        $entry = Entry::make()->collection('content_boxes')->slug($slug)->published($published)
            ->data(array_merge(['title' => 'Kasten '.$slug, 'button_label' => 'Zum Kurs', 'url' => 'https://example.com/x'], $data));
        $entry->save();

        return $entry;
    }

    protected function course(string $slug, array $data = []): \Statamic\Contracts\Entries\Entry
    {
        $entry = Entry::make()->collection('courses')->slug($slug)->data(array_merge(['title' => $slug], $data));
        $entry->save();

        return $entry;
    }

    protected function offer(string $handle, array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => $handle, 'name' => 'Angebot '.$handle, 'product' => 'noten-paket',
            'amount_cent' => 39000, 'slot' => Offer::SLOT_STANDALONE, 'active' => true,
        ], $overrides));
    }

    /** @return list<MapEdge> */
    protected function edges(): array
    {
        return iterator_to_array($this->provider->edges(), false);
    }

    #[Test]
    public function a_box_is_a_map_object_keyed_by_its_slug(): void
    {
        $this->box('vokale-einladung', ['headline' => 'Vokale & Aussprache']);
        $this->box('entwurf', [], false);

        $this->assertSame('content_box', $this->provider->type());

        $found = $this->provider->find('vokale-einladung');
        $this->assertSame('content_box', $found->type);
        $this->assertSame('vokale-einladung', $found->id);
        $this->assertSame('Kasten vokale-einladung', $found->label);
        $this->assertTrue($found->enabled);
        $this->assertSame('Vokale & Aussprache', $found->detail);
        $this->assertNotSame('', $found->cpUrl);

        $this->assertFalse($this->provider->find('entwurf')->enabled);
        $this->assertNull($this->provider->find('gibt-es-nicht'));
        $this->assertCount(2, $this->provider->objects());
        $this->assertCount(1, $this->provider->objects('vokale'));
        // Keine saubere Zählregel: keine Zahl.
        $this->assertNull($this->provider->metric('vokale-einladung', MapPeriod::lastDays(30)));
    }

    #[Test]
    public function the_registry_knows_the_box_type(): void
    {
        $this->box('vokale-einladung');

        $this->assertSame('vokale-einladung', app(MapObjectRegistry::class)->lookup('content_box', 'vokale-einladung')->object->id);
    }

    #[Test]
    public function a_course_that_lists_a_box_draws_a_line_to_it(): void
    {
        $box = $this->box('vokale-einladung');
        $this->course('kurs-vokale', ['course_boxes' => [['box' => [$box->id()], 'place' => 'end']]]);

        $edges = $this->edges();

        $this->assertCount(1, $edges);
        $this->assertSame('course', $edges[0]->fromType);
        $this->assertSame('kurs-vokale', $edges[0]->fromId);
        $this->assertSame('content_box', $edges[0]->toType);
        $this->assertSame('vokale-einladung', $edges[0]->toId);
        $this->assertSame('shows_box', $edges[0]->kind);
        $this->assertSame('course field `course_boxes`', $edges[0]->derivedFrom);
        $this->assertNull($edges[0]->toLabel);
    }

    #[Test]
    public function a_box_on_a_lesson_or_video_hint_is_a_line_from_the_course_of_that_lesson(): void
    {
        $a = $this->box('a');
        $b = $this->box('b');
        $course = $this->course('kurs-vokale');
        Entry::make()->collection('course_lessons')->slug('l1')->data([
            'title' => 'L1', 'course' => [$course->id()],
            'lesson_boxes' => [['box' => [$a->id()], 'place' => 'end']],
            'video_hints' => [['box' => [$b->id()], 'from' => '0:10', 'to' => '']],
        ])->save();

        $edges = $this->edges();

        $this->assertEqualsCanonicalizing(
            ['course:kurs-vokale>shows_box>content_box:a', 'course:kurs-vokale>shows_box>content_box:b'],
            array_map(fn (MapEdge $e) => $e->key(), $edges),
        );
    }

    #[Test]
    public function the_same_box_on_course_and_lesson_is_one_line(): void
    {
        $box = $this->box('a');
        $course = $this->course('kurs-vokale', ['course_boxes' => [['box' => [$box->id()]]]]);
        Entry::make()->collection('course_lessons')->slug('l1')->data([
            'title' => 'L1', 'course' => [$course->id()], 'lesson_boxes' => [['box' => [$box->id()]]],
        ])->save();

        $this->assertCount(1, $this->edges());
    }

    #[Test]
    public function a_box_id_that_names_nothing_is_a_broken_line_with_the_written_text(): void
    {
        $this->course('kurs-vokale', ['course_boxes' => [['box' => ['geloescht-123']]]]);

        $edges = $this->edges();

        $this->assertCount(1, $edges);
        $this->assertSame('geloescht-123', $edges[0]->toId);
        $this->assertSame('geloescht-123', $edges[0]->toLabel);
    }

    #[Test]
    public function a_box_with_an_offer_draws_a_line_to_the_products_of_that_offer(): void
    {
        $this->offer('stimmbildung', ['products' => ['probe-zugang']]);
        $this->box('mit-angebot', ['offer' => 'stimmbildung']);
        $this->box('ohne-angebot');

        $edges = $this->edges();

        $this->assertCount(2, $edges);
        $this->assertSame('content_box', $edges[0]->fromType);
        $this->assertSame('mit-angebot', $edges[0]->fromId);
        $this->assertSame('product', $edges[0]->toType);
        $this->assertSame('noten-paket', $edges[0]->toId);
        $this->assertSame('invites_to', $edges[0]->kind);
        $this->assertSame('field `offer` of the box', $edges[0]->derivedFrom);
        $this->assertSame('probe-zugang', $edges[1]->toId);
    }

    #[Test]
    public function a_box_with_an_offer_that_does_not_exist_is_a_broken_line_to_the_offer(): void
    {
        $this->box('mit-angebot', ['offer' => 'tippfehler']);

        $edges = $this->edges();

        $this->assertCount(1, $edges);
        $this->assertSame('offer', $edges[0]->toType);
        $this->assertSame('tippfehler', $edges[0]->toId);
        $this->assertSame('tippfehler', $edges[0]->toLabel);
    }

    #[Test]
    public function a_material_is_no_course_card_and_draws_no_line(): void
    {
        $box = $this->box('a');
        $this->course('handout', ['kind' => 'material', 'course_boxes' => [['box' => [$box->id()]]]]);

        $this->assertSame([], $this->edges());
    }

    #[Test]
    public function without_the_course_collections_the_boxes_still_appear_and_nothing_crashes(): void
    {
        Collection::findByHandle('courses')->delete();
        Collection::findByHandle('course_lessons')->delete();
        $this->offer('stimmbildung', ['products' => ['probe-zugang']]);
        $this->box('mit-angebot', ['offer' => 'stimmbildung']);

        $this->assertCount(1, $this->provider->objects());
        $this->assertSame('mit-angebot', $this->provider->find('mit-angebot')->id);

        $edges = $this->edges();
        $this->assertNotEmpty($edges);
        $this->assertSame(['content_box'], array_values(array_unique(array_map(fn (MapEdge $e) => $e->fromType, $edges))));
    }

    #[Test]
    public function the_offers_of_all_boxes_are_loaded_once_not_per_box(): void
    {
        $this->offer('eins', ['products' => ['p1']]);
        $this->offer('zwei', ['products' => ['p2']]);
        $this->box('a', ['offer' => 'eins']);
        $this->box('b', ['offer' => 'zwei']);
        $this->box('c', ['offer' => 'eins']);
        $this->box('d', ['offer' => 'tippfehler']);

        $offerQueries = 0;
        DB::listen(function ($q) use (&$offerQueries) {
            if (str_contains($q->sql, (new Offer)->getTable())) {
                $offerQueries++;
            }
        });

        $edges = $this->edges();

        $this->assertCount(7, $edges);
        $this->assertSame(1, $offerQueries);
    }
}
