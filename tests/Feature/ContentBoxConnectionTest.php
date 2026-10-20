<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Integrations\Map\ContentBoxMapObjects;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * Verbindung ziehen: Kurs → Kasten (`course_boxes`) und Kasten → Angebot (`offer`).
 * connect schreibt wirklich, disconnect ist die exakte Umkehr.
 */
class ContentBoxConnectionTest extends TestCase
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

    protected function box(string $slug, array $data = []): \Statamic\Contracts\Entries\Entry
    {
        $entry = Entry::make()->collection('content_boxes')->slug($slug)->published(true)
            ->data(array_merge(['title' => 'Kasten '.$slug, 'button_label' => 'Los', 'url' => 'https://example.com/x'], $data));
        $entry->save();

        return $entry;
    }

    protected function course(string $slug, array $data = []): \Statamic\Contracts\Entries\Entry
    {
        $entry = Entry::make()->collection('courses')->slug($slug)->data(array_merge(['title' => 'Kurs '.$slug], $data));
        $entry->save();

        return $entry;
    }

    protected function offer(string $handle): Offer
    {
        return Offer::create([
            'handle' => $handle, 'name' => 'Angebot '.$handle, 'product' => 'noten-paket',
            'amount_cent' => 39000, 'slot' => Offer::SLOT_STANDALONE, 'active' => true,
        ]);
    }

    protected function reload(\Statamic\Contracts\Entries\Entry $entry): \Statamic\Contracts\Entries\Entry
    {
        return Entry::find($entry->id());
    }

    /** @return array<string, string> */
    protected function courseBox(string $course, string $box): array
    {
        return ['from_type' => 'course', 'from_id' => $course, 'to_type' => 'content_box', 'to_id' => $box, 'kind' => 'shows_box'];
    }

    /** @return array<string, string> */
    protected function boxOffer(string $box, string $offer): array
    {
        return ['from_type' => 'content_box', 'from_id' => $box, 'to_type' => 'offer', 'to_id' => $offer, 'kind' => 'invites_to'];
    }

    #[Test]
    public function it_offers_exactly_the_two_pairs_and_no_box_to_product(): void
    {
        $rules = $this->provider->connectionRules();

        $this->assertSame(
            ['course>content_box>shows_box', 'content_box>offer>invites_to'],
            array_map(fn ($r) => "{$r['from_type']}>{$r['to_type']}>{$r['kind']}", $rules),
        );
        $this->assertNotSame('', $rules[0]['label']);
    }

    #[Test]
    public function connecting_a_course_to_a_box_writes_a_row_and_disconnect_removes_only_it(): void
    {
        $a = $this->box('a');
        $b = $this->box('b');
        $existing = ['id' => 'keep1234', 'box' => [$a->id()], 'place' => 'below_video'];
        $course = $this->course('kurs', ['course_boxes' => [$existing]]);
        $c = $this->courseBox('kurs', 'b');

        $this->provider->connect($c);

        $rows = $this->reload($course)->get('course_boxes');
        $this->assertCount(2, $rows);
        $this->assertSame($existing, $rows[0]);
        $this->assertSame([$b->id()], $rows[1]['box']);
        $this->assertSame('end', $rows[1]['place']);

        $this->provider->disconnect($c);

        $this->assertSame([$existing], $this->reload($course)->get('course_boxes'));
    }

    #[Test]
    public function a_box_already_in_the_course_is_refused_in_both_directions_of_state(): void
    {
        $box = $this->box('a');
        $this->course('kurs', ['course_boxes' => [['box' => [$box->id()], 'place' => 'end']]]);
        $this->box('b');

        try {
            $this->provider->connect($this->courseBox('kurs', 'a'));
            $this->fail('expected refusal');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Kasten a', $e->getMessage());
        }

        $this->expectException(\DomainException::class);
        $this->provider->connectionPreview($this->courseBox('kurs', 'b'), 'disconnect');
    }

    #[Test]
    public function unknown_course_or_box_is_refused(): void
    {
        $this->box('a');
        $this->course('kurs');

        foreach ([$this->courseBox('nope', 'a'), $this->courseBox('kurs', 'nope')] as $c) {
            try {
                $this->provider->connectionPreview($c, 'connect');
                $this->fail('expected refusal');
            } catch (\DomainException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    #[Test]
    public function the_course_preview_names_both_in_plain_text(): void
    {
        $this->box('a');
        $this->course('kurs');

        $p = $this->provider->connectionPreview($this->courseBox('kurs', 'a'), 'connect');

        $this->assertStringContainsString('Kurs kurs', $p['consequence']);
        $this->assertStringContainsString('Kasten a', $p['consequence']);
        $this->assertNotSame($p['before'], $p['after']);
    }

    #[Test]
    public function connecting_a_box_to_an_offer_sets_the_field_and_disconnect_clears_it(): void
    {
        $this->offer('vokale');
        $box = $this->box('a');
        $c = $this->boxOffer('a', 'vokale');

        $p = $this->provider->connectionPreview($c, 'connect');
        $this->assertStringContainsString('Kasten a', $p['consequence']);
        $this->assertStringContainsString('Angebot vokale', $p['consequence']);

        $this->provider->connect($c);
        $this->assertSame('vokale', $this->reload($box)->get('offer'));

        $this->provider->disconnect($c);
        $this->assertEmpty($this->reload($box)->get('offer'));
    }

    #[Test]
    public function a_box_with_another_offer_is_refused_and_untouched(): void
    {
        $this->offer('vokale');
        $this->offer('andere');
        $box = $this->box('a', ['offer' => 'andere']);

        try {
            $this->provider->connect($this->boxOffer('a', 'vokale'));
            $this->fail('expected refusal');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('andere', $e->getMessage());
        }

        try {
            $this->provider->disconnect($this->boxOffer('a', 'vokale'));
            $this->fail('expected refusal');
        } catch (\DomainException) {
        }

        $this->assertSame('andere', $this->reload($box)->get('offer'));
    }

    #[Test]
    public function an_offer_that_does_not_exist_is_refused(): void
    {
        $this->box('a');

        $this->expectException(\DomainException::class);
        $this->provider->connectionPreview($this->boxOffer('a', 'gibt-es-nicht'), 'connect');
    }

    #[Test]
    public function box_to_product_is_not_a_pair(): void
    {
        $this->box('a');
        $c = ['from_type' => 'content_box', 'from_id' => 'a', 'to_type' => 'product', 'to_id' => 'x', 'kind' => 'invites_to'];

        $this->assertFalse($this->provider->connectionAuthorized($c, User::make()->email('s@example.com')->makeSuper()));
    }

    #[Test]
    public function permissions_follow_the_edited_collection(): void
    {
        $super = tap(User::make()->email('super@example.com')->makeSuper())->save();
        $none = tap(User::make()->id('u-none')->email('none@example.com'))->save();
        $courseEditor = tap(User::make()->id('u-c')->email('c@example.com')->assignRole(tap(Role::make('course-editor')->addPermission('edit courses entries'))->save()))->save();
        $boxEditor = tap(User::make()->id('u-b')->email('b@example.com')->assignRole(tap(Role::make('box-editor')->addPermission('edit content_boxes entries'))->save()))->save();

        $cb = $this->courseBox('kurs', 'a');
        $bo = $this->boxOffer('a', 'vokale');

        $this->assertTrue($this->provider->connectionAuthorized($cb, $super));
        $this->assertTrue($this->provider->connectionAuthorized($bo, $super));
        $this->assertFalse($this->provider->connectionAuthorized($cb, $none));
        $this->assertFalse($this->provider->connectionAuthorized($cb, null));
        $this->assertTrue($this->provider->connectionAuthorized($cb, $courseEditor));
        $this->assertFalse($this->provider->connectionAuthorized($bo, $courseEditor));
        $this->assertTrue($this->provider->connectionAuthorized($bo, $boxEditor));
        $this->assertFalse($this->provider->connectionAuthorized($cb, $boxEditor));
    }
}
