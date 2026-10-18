<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\Leadhub\LeadHubManager;
use Goldnead\StatamicOffers\Contracts\BoxAudience;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Offers;
use Goldnead\StatamicOffers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Antlers;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

require_once __DIR__.'/../Support/leadhub.php';

/**
 * Inhalts-Kaesten: ein Hinweis mit Worten und einem Ziel, der unter einer
 * Lektion, am Ende eines Artikels oder auf einer Danke-Seite steht. Kein
 * Angebot im Sinn der Kasse (kein Produkt, kein Preis), darum ein Eintrag.
 */
class ContentBoxTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        LeadHubManager::reset();
        $this->artisan('offers:install-boxes')->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function box(string $slug, array $data = [], bool $published = true): \Statamic\Contracts\Entries\Entry
    {
        $entry = Entry::make()
            ->collection('content_boxes')
            ->slug($slug)
            ->published($published)
            ->data(array_merge([
                'title' => $slug,
                'headline' => 'Vokale & Aussprache',
                'body' => 'Ein Kurs, der Vokale hörbar macht.',
                'button_label' => 'Zum Kurs',
                'url' => 'https://example.com/kurs',
            ], $data));
        $entry->save();

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'vokale',
            'name' => 'Vokale',
            'product' => 'noten-paket',
            'amount_cent' => 4900,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ], $overrides));
    }

    #[Test]
    public function der_befehl_legt_sammlung_und_blueprint_an_und_ist_wiederholbar(): void
    {
        $this->artisan('offers:install-boxes')->assertSuccessful();

        $this->assertNotNull(Collection::find('content_boxes'));
        $blueprint = Blueprint::find('collections.content_boxes.content_box');
        $this->assertNotNull($blueprint);
        foreach (['headline', 'body', 'button_label', 'url', 'offer', 'kicker', 'only_tags', 'not_tags', 'only_groups'] as $handle) {
            $this->assertTrue($blueprint->hasField($handle), $handle);
        }
    }

    #[Test]
    public function ein_kasten_kommt_mit_seinen_worten_aus_slug_oder_id(): void
    {
        $entry = $this->box('vokale-kurs', ['kicker' => 'Wie es weitergehen kann']);

        $bySlug = Offers::contentBox('vokale-kurs');
        $byId = Offers::contentBox($entry->id());

        $this->assertSame('vokale-kurs', $bySlug['handle']);
        $this->assertSame('Wie es weitergehen kann', $bySlug['kicker']);
        $this->assertSame('Vokale & Aussprache', $bySlug['headline']);
        $this->assertSame('Ein Kurs, der Vokale hörbar macht.', $bySlug['body']);
        $this->assertSame('Zum Kurs', $bySlug['button_label']);
        $this->assertSame('https://example.com/kurs', $bySlug['url']);
        $this->assertSame($bySlug, $byId);
    }

    #[Test]
    public function fehlend_unveroeffentlicht_oder_ohne_brauchbares_ziel_ist_kein_kasten(): void
    {
        $this->box('entwurf', [], published: false);
        $this->box('ohne-ziel', ['url' => '']);
        $this->box('ohne-knopf', ['button_label' => '']);
        $this->box('javascript', ['url' => 'javascript:alert(1)']);
        $this->box('protokollrelativ', ['url' => '//evil.example/x']);

        foreach (['gibt-es-nicht', 'entwurf', 'ohne-ziel', 'ohne-knopf', 'javascript', 'protokollrelativ'] as $slug) {
            $this->assertNull(Offers::contentBox($slug), $slug);
        }
    }

    #[Test]
    public function ein_ziel_das_ein_browser_als_fremden_host_liest_ist_keines(): void
    {
        // Browser lesen `\` wie `/` und streichen Tabulator und Zeilenumbruch
        // aus einer Adresse: aus `/\evil.example` und `/<TAB>/evil.example`
        // wird `//evil.example`.
        foreach (['backslash' => '/\\evil.example/x', 'tab' => "/\t/evil.example", 'umbruch' => "/\n/evil.example", 'https-backslash' => 'https://evil.example\\@good.example', 'leerzeichen' => 'https://exa mple.com'] as $slug => $url) {
            $this->box($slug, ['url' => $url]);

            $this->assertNull(Offers::contentBox($slug), $slug);
        }
    }

    #[Test]
    public function ein_pfad_auf_der_eigenen_seite_ist_ein_gueltiges_ziel(): void
    {
        $this->box('ausbildung', ['url' => '/ausbildung#warteliste']);

        $this->assertSame('/ausbildung#warteliste', Offers::contentBox('ausbildung')['url']);
    }

    #[Test]
    public function ohne_eigene_adresse_fuehrt_der_kasten_ueber_den_kurzlink_des_angebots(): void
    {
        $this->offer(['link_slug' => 'vokale', 'link_target' => 'https://example.com/kurs/vokale']);
        $this->box('ueber-angebot', ['url' => '', 'offer' => 'vokale']);

        $box = Offers::contentBox('ueber-angebot');

        $this->assertNotNull($box);
        $this->assertStringEndsWith('/vokale', $box['url']);
        $this->assertSame('vokale', $box['offer']);
    }

    #[Test]
    public function ein_angebot_das_nicht_gilt_nimmt_den_kasten_mit_und_gezaehlt_wird_die_anzeige(): void
    {
        $offer = $this->offer();
        $this->box('ans-angebot', ['offer' => 'vokale']);

        $this->assertNotNull(Offers::contentBox('ans-angebot'));
        $this->assertSame(1, $offer->fresh()->shown_count);

        $offer->update(['active' => false]);

        $this->assertNull(Offers::contentBox('ans-angebot'));
    }

    #[Test]
    public function ein_angebot_das_es_nicht_gibt_versteckt_den_kasten(): void
    {
        $this->box('tippfehler', ['offer' => 'gibt-es-nicht']);

        $this->assertNull(Offers::contentBox('tippfehler'));
    }

    #[Test]
    public function die_zielgruppe_haengt_an_merkmalen_des_kontakts(): void
    {
        LeadHubManager::$contacts['chor@example.com'] = ['uuid' => 'c1', 'tags' => ['Leitet Chor']];
        LeadHubManager::$contacts['saenger@example.com'] = ['uuid' => 'c2', 'tags' => ['singt-im-chor']];
        $this->box('nur-saenger', ['not_tags' => ['leitet-chor']]);
        $this->box('nur-leitung', ['only_tags' => ['leitet-chor']]);

        $leitung = User::make()->email('chor@example.com');
        $saenger = User::make()->email('saenger@example.com');
        $fremd = User::make()->email('fremd@example.com');

        $this->assertNull(Offers::contentBox('nur-saenger', $leitung));
        $this->assertNotNull(Offers::contentBox('nur-saenger', $saenger));
        $this->assertNotNull(Offers::contentBox('nur-saenger', $fremd));
        $this->assertNotNull(Offers::contentBox('nur-saenger'));

        $this->assertNotNull(Offers::contentBox('nur-leitung', $leitung));
        $this->assertNull(Offers::contentBox('nur-leitung', $saenger));
        $this->assertNull(Offers::contentBox('nur-leitung', $fremd));
        $this->assertNull(Offers::contentBox('nur-leitung'), 'Ohne Besucher gilt eine Einschraenkung auf ein Merkmal nicht.');
    }

    #[Test]
    public function die_zielgruppe_kennt_auch_benutzergruppen(): void
    {
        $this->box('fuer-team', ['only_groups' => ['team']]);
        // Statamic-Benutzer sind in dieser Testumgebung nicht auf Platte
        // speicherbar; die Regel fragt nur `isInGroup()`.
        $mitglied = fn (bool $drin) => new class($drin)
        {
            public function __construct(private bool $drin) {}

            public function isInGroup(string $group): bool
            {
                return $this->drin && $group === 'team';
            }
        };
        $drin = $mitglied(true);
        $draussen = $mitglied(false);

        $this->assertNotNull(Offers::contentBox('fuer-team', $drin));
        $this->assertNull(Offers::contentBox('fuer-team', $draussen));
    }

    #[Test]
    public function eine_eigene_zielgruppenregel_ersetzt_die_eingebaute(): void
    {
        $this->app->bind(BoxAudience::class, fn () => new class implements BoxAudience
        {
            public function matches(mixed $viewer, array $rule): bool
            {
                return $viewer === 'ja';
            }
        });
        $this->box('eigen', ['only_tags' => ['egal']]);

        $this->assertNotNull(Offers::contentBox('eigen', 'ja'));
        $this->assertNull(Offers::contentBox('eigen', 'nein'));
    }

    #[Test]
    public function mehrere_kaesten_behalten_die_reihenfolge_und_lassen_aus_was_nicht_gilt(): void
    {
        $a = $this->box('a');
        $this->box('b', [], published: false);
        $c = $this->box('c');

        $boxes = Offers::contentBoxes([$c->id(), 'b', $a->id(), 'x']);

        $this->assertSame(['c', 'a'], array_column($boxes, 'handle'));
    }

    #[Test]
    public function das_tag_gibt_einen_kasten_aus_und_nichts_wenn_es_keinen_gibt(): void
    {
        $this->box('vokale-kurs', ['kicker' => 'Weiter']);

        $mit = (string) Antlers::parse('{{ offers:box handle="vokale-kurs" }}<h>{{ headline }}</h><a href="{{ url }}">{{ button_label }}</a>{{ /offers:box }}', [], true);
        $ohne = (string) Antlers::parse('{{ offers:box handle="nein" }}{{ if no_results }}{{ else }}X{{ /if }}{{ /offers:box }}', [], true);

        $this->assertSame('<h>Vokale & Aussprache</h><a href="https://example.com/kurs">Zum Kurs</a>', html_entity_decode($mit));
        $this->assertSame('', trim($ohne));
    }

    #[Test]
    public function das_tag_boxes_nimmt_eine_liste_von_ids(): void
    {
        $a = $this->box('a', ['headline' => 'Eins']);
        $b = $this->box('b', ['headline' => 'Zwei']);

        $out = (string) Antlers::parse('{{ offers:boxes :ids="ids" }}[{{ headline }}]{{ /offers:boxes }}', ['ids' => [$b->id(), $a->id()]], true);

        $this->assertSame('[Zwei][Eins]', $out);
    }
}
