<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\BrandContext\Map\ConnectionConflict;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * Ende zu Ende durch die Registry von brand-context (nicht den Provider direkt):
 * Regel, Recht, Vorschau, Schreiben, Undo. Braucht brand-context >= 1.19.
 */
class ContentBoxRegistryConnectionTest extends TestCase
{
    protected MapObjectRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('offers:install-boxes')->assertSuccessful();
        Collection::make('courses')->routes(null)->save();
        Collection::make('course_lessons')->routes(null)->save();

        $this->registry = app(MapObjectRegistry::class);

        if (! method_exists($this->registry, 'connectionRules')) {
            $this->markTestSkipped('brand-context >= 1.19 (connections) is not installed.');
        }
    }

    protected function box(string $slug): \Statamic\Contracts\Entries\Entry
    {
        $entry = Entry::make()->collection('content_boxes')->slug($slug)->published(true)
            ->data(['title' => 'Kasten '.$slug, 'button_label' => 'Los', 'url' => 'https://example.com/x']);
        $entry->save();

        return $entry;
    }

    protected function course(string $slug, array $data = []): \Statamic\Contracts\Entries\Entry
    {
        $entry = Entry::make()->collection('courses')->slug($slug)->data(array_merge(['title' => 'Kurs '.$slug], $data));
        $entry->save();

        return $entry;
    }

    protected function reload($entry): \Statamic\Contracts\Entries\Entry
    {
        return Entry::find($entry->id());
    }

    protected function superUser()
    {
        return tap(User::make()->id('u-super')->email('super@example.com')->makeSuper())->save();
    }

    /** @return array<string, string> */
    protected function courseBox(string $course, string $box): array
    {
        return ['from_type' => 'course', 'from_id' => $course, 'to_type' => 'content_box', 'to_id' => $box];
    }

    #[Test]
    public function the_registry_offers_both_pairs_owned_by_the_box_provider(): void
    {
        $rules = collect($this->registry->connectionRules())->keyBy(fn ($r) => "{$r['from_type']}>{$r['to_type']}");

        $this->assertSame('content_box', $rules['course>content_box']['owner']);
        $this->assertSame('content_box', $rules['content_box>offer']['owner']);
    }

    #[Test]
    public function derived_box_edges_come_from_the_box_provider_and_match_the_rule_owner(): void
    {
        $this->offer('vokale');
        $box = $this->box('a');
        $box->set('offer', 'vokale')->save();
        $this->course('kurs', ['course_boxes' => [['id' => 'r1', 'box' => [$box->id()], 'place' => 'end']]]);

        $edges = collect($this->registry->edges())
            ->filter(fn ($e) => ($e->kind === 'shows_box') || ($e->kind === 'invites_to' && $e->toType === 'offer'));

        $this->assertCount(2, $edges);

        foreach ($edges as $edge) {
            $rule = $this->registry->ruleFor($edge->fromType, $edge->toType);

            $this->assertSame('content_box', $edge->ownerType);
            $this->assertSame($rule['owner'], $edge->ownerType);
        }
    }

    protected function offer(string $handle): Offer
    {
        return Offer::create([
            'handle' => $handle, 'name' => 'Angebot '.$handle, 'product' => 'noten-paket',
            'amount_cent' => 39000, 'slot' => Offer::SLOT_STANDALONE, 'active' => true,
        ]);
    }

    #[Test]
    public function connect_disconnect_and_undo_through_the_registry_restore_the_exact_row(): void
    {
        $a = $this->box('a');
        $b = $this->box('b');
        $rows = [
            ['id' => 'r1', 'box' => [$a->id()], 'place' => 'below_video', 'note' => 'x'],
            ['id' => 'r2', 'box' => [$b->id()], 'place' => 'before_lessons', 'headline' => 'H'],
            ['id' => 'r3', 'box' => [$b->id()], 'place' => 'end'],
        ];
        $course = $this->course('kurs', ['course_boxes' => $rows]);
        $user = $this->superUser();
        $c = $this->courseBox('kurs', 'b');

        $preview = $this->registry->previewConnection($c, 'disconnect', $user);
        $result = $this->registry->applyConnection($c, 'disconnect', $user, $preview['before']);

        $this->assertSame([$rows[0], $rows[2]], $this->reload($course)->get('course_boxes'));
        $this->assertSame('connect', $result['undo']['action']);
        $this->assertSame($rows[1], $result['undo']['restore']['row']);

        $undo = $result['undo'];
        $action = $undo['action'];
        unset($undo['action']);
        $this->registry->applyConnection($undo, $action, $user);

        $this->assertSame($rows, $this->reload($course)->get('course_boxes'));
    }

    #[Test]
    public function a_field_changed_between_preview_and_write_writes_nothing(): void
    {
        $this->box('a');
        $b = $this->box('b');
        $course = $this->course('kurs');
        $user = $this->superUser();
        $c = $this->courseBox('kurs', 'a');

        $preview = $this->registry->previewConnection($c, 'connect', $user);

        // Somebody else adds the very same box in the meantime.
        $this->reload($course)->set('course_boxes', [['id' => 'zz', 'box' => [Entry::query()->where('slug', 'a')->first()->id()], 'place' => 'end']])->save();

        try {
            $this->registry->applyConnection($c, 'connect', $user, $preview['before']);
            $this->fail('expected refusal');
        } catch (\DomainException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertCount(1, $this->reload($course)->get('course_boxes'));
        $this->assertNotNull($b);
    }

    #[Test]
    public function a_changed_before_state_is_a_conflict_through_the_registry(): void
    {
        $this->box('a');
        $this->offer('vokale');
        $course = $this->course('kurs');
        $user = $this->superUser();
        $c = $this->courseBox('kurs', 'a');

        $this->expectException(ConnectionConflict::class);
        try {
            $this->registry->applyConnection($c, 'connect', $user, 'ein anderer Zustand');
        } finally {
            $this->assertNull($this->reload($course)->get('course_boxes'));
        }
    }

    #[Test]
    public function one_permission_is_not_enough_for_preview_or_write(): void
    {
        $this->box('a');
        $course = $this->course('kurs');
        $role = tap(Role::make('only-course')->addPermission('edit courses entries'))->save();
        $user = tap(User::make()->id('u-oc')->email('oc@example.com')->assignRole($role))->save();
        $c = $this->courseBox('kurs', 'a');

        foreach (['previewConnection', 'applyConnection'] as $method) {
            try {
                $this->registry->$method($c, 'connect', $user);
                $this->fail("expected refusal in {$method}");
            } catch (AuthorizationException) {
            }
        }

        $this->assertNull($this->reload($course)->get('course_boxes'));
    }
}
