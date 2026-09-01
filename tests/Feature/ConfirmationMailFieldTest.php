<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * The mail a buyer gets for their money.
 *
 * Every test here is about the same question asked from a different side:
 * **when does a purchase end in silence?** The answer this addon commits to is
 * "only when somebody chose it", and the ways to break that are all here —
 * a field left out of the request, a row written before the column existed, a
 * half-filled form, a template that no longer resolves.
 *
 * **Beide Installationen kommen vor.** Die meisten Tests laufen ohne das
 * Schwester-Addon `statamic-email-templates` — das ist die Gestalt einer
 * Installation ohne es, und die, in der ein Vorlagen-Picker einen Wert
 * zurueckgeben koennte, den nichts rendern kann. Die beiden Tests um
 * {@see self::templateAnlegen()} bauen dagegen auf, was eine Installation *mit*
 * dem Addon vorfindet. Ohne die zweite Haelfte waere die Vorlagenliste in jedem
 * Test leer, `Rule::in([])` lehnte auch den richtigen Slug ab, und die
 * Kernfunktion „eigene Mail waehlen" koennte vollstaendig kaputt sein, ohne
 * dass hier etwas rot wird.
 */
class ConfirmationMailFieldTest extends TestCase
{
    protected function user()
    {
        return tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    /**
     * @return array<string, mixed>
     */
    protected function valid(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Frühlings-Upsell',
            'handle' => 'fruehling-upsell',
            'product' => 'noten-paket',
            'amount_cent' => 1200,
            'slot' => Offer::SLOT_POST_PURCHASE,
            'active' => true,
        ], $overrides);
    }

    #[Test]
    public function an_offer_saved_without_the_field_still_sends_a_mail(): void
    {
        // The whole defect this column closes, in one assertion: somebody
        // creates an offer, never scrolls to the new field, and the buyer is
        // served anyway. A column defaulting to "none" would have reproduced
        // the bug rather than fixed it.
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/offers', $this->valid())
            ->assertValid();

        $offer = Offer::query()->where('handle', 'fruehling-upsell')->firstOrFail();

        $this->assertSame(Offer::CONFIRMATION_DEFAULT, $offer->confirmation_mode);
        $this->assertTrue($offer->sendsConfirmation());
        $this->assertNull($offer->confirmationTemplate());
    }

    #[Test]
    public function an_empty_mode_is_not_silence(): void
    {
        $offer = Offer::create($this->valid());

        // The column is NOT NULL with a default, so a null is impossible and a
        // row from before the migration is backfilled to `default` — proven by
        // the fact that this update has to use an empty string to get an
        // unusable value in at all. Empty is what a bad import or a hand-edited
        // row leaves behind, and it must not read as "send nothing": only the
        // word `none` means that.
        DB::table('offers')->where('id', $offer->id)->update(['confirmation_mode' => '']);

        $fresh = Offer::query()->findOrFail($offer->id);

        $this->assertTrue($fresh->sendsConfirmation());
    }

    #[Test]
    public function silence_is_possible_but_only_on_purpose(): void
    {
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/offers', $this->valid([
                'confirmation_mode' => Offer::CONFIRMATION_NONE,
            ]))
            ->assertValid();

        $offer = Offer::query()->where('handle', 'fruehling-upsell')->firstOrFail();

        $this->assertSame(Offer::CONFIRMATION_NONE, $offer->confirmation_mode);
        $this->assertFalse($offer->sendsConfirmation());
    }

    #[Test]
    public function a_mode_nobody_defined_is_refused(): void
    {
        // Not coerced to the default and not stored: a value the form cannot
        // produce means something upstream is wrong, and a shop is the wrong
        // place to guess what somebody meant.
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/offers', $this->valid(['confirmation_mode' => 'vielleicht']))
            ->assertJsonValidationErrors('confirmation_mode');

        $this->assertSame(0, Offer::query()->count());
    }

    #[Test]
    public function a_template_that_does_not_resolve_is_refused(): void
    {
        // Without the email-templates addon there are no templates at all, so
        // every slug is one that resolves to nothing. Caught at save time, in
        // front of the person who can fix it — not at send time, in a queue,
        // hours later, in front of nobody.
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/offers', $this->valid([
                'confirmation_mode' => Offer::CONFIRMATION_CUSTOM,
                'confirmation_template' => 'gibt-es-nicht',
            ]))
            ->assertJsonValidationErrors('confirmation_template');
    }

    #[Test]
    public function own_mail_without_a_template_falls_back_rather_than_going_quiet(): void
    {
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/offers', $this->valid([
                'confirmation_mode' => Offer::CONFIRMATION_CUSTOM,
            ]))
            ->assertValid();

        $offer = Offer::query()->where('handle', 'fruehling-upsell')->firstOrFail();

        // Half a decision is still a decision to send something.
        $this->assertTrue($offer->sendsConfirmation());
        $this->assertNull($offer->confirmationTemplate());
    }

    #[Test]
    public function a_template_is_dropped_when_the_mode_no_longer_wants_one(): void
    {
        $offer = Offer::create($this->valid([
            'confirmation_mode' => Offer::CONFIRMATION_CUSTOM,
            'confirmation_template' => 'irgendeine-vorlage',
        ]));

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/offers/'.$offer->id, $this->valid([
                'confirmation_mode' => Offer::CONFIRMATION_DEFAULT,
                'confirmation_template' => 'irgendeine-vorlage',
            ]))
            ->assertValid();

        // Kept, it would come back the moment somebody flips the mode again —
        // as a choice they never made, in a mail a buyer receives.
        $this->assertNull($offer->fresh()->confirmation_template);
    }

    /**
     * Legt an, was eine Installation mit dem Schwester-Addon vorfindet: die
     * Collection und einen Eintrag darin.
     *
     * Ohne diesen Aufbau lief die ganze Klasse gegen eine leere Vorlagenliste,
     * und `Rule::in([])` lehnte jeden Slug ab — auch den richtigen. Alle Tests
     * blieben gruen, waehrend „eigene Mail waehlen" komplett kaputt sein
     * konnte: falscher Collection-Handle, falsche Query, falsches Feld.
     */
    protected function templateAnlegen(string $slug, string $titel): void
    {
        // Das Addon erkennt das Schwester-Paket an seiner Fassade, nicht an der
        // Collection-Datei — die bleibt naemlich liegen, wenn jemand das Paket
        // deinstalliert. Fuer den Test muss deshalb beides da sein. Ein Alias
        // reicht: geprueft wird die Anwesenheit, nicht das Verhalten.
        if (! class_exists('Goldnead\\EmailTemplates\\Facades\\EmailTemplates')) {
            class_alias(\stdClass::class, 'Goldnead\\EmailTemplates\\Facades\\EmailTemplates');
        }

        if (! Collection::handleExists('et_templates')) {
            tap(Collection::make('et_templates')->title('E-Mail-Vorlagen'))->save();
        }

        tap(Entry::make()->collection('et_templates')->slug($slug)->data(['title' => $titel]))->save();
    }

    #[Test]
    public function a_template_that_exists_is_accepted_and_stored(): void
    {
        $this->templateAnlegen('kauf-bestaetigung', 'Kaufbestätigung');

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/offers', $this->valid([
                'confirmation_mode' => Offer::CONFIRMATION_CUSTOM,
                'confirmation_template' => 'kauf-bestaetigung',
            ]))
            ->assertValid();

        $offer = Offer::query()->where('handle', 'fruehling-upsell')->firstOrFail();

        $this->assertSame(Offer::CONFIRMATION_CUSTOM, $offer->confirmation_mode);
        $this->assertSame('kauf-bestaetigung', $offer->confirmationTemplate());
    }

    #[Test]
    public function the_picker_offers_the_templates_that_exist(): void
    {
        $this->templateAnlegen('kauf-bestaetigung', 'Kaufbestätigung');
        $this->templateAnlegen('willkommen', 'Willkommen');

        $props = $this->actingAs($this->user())
            ->get('/cp/utilities/offers')
            ->assertOk()
            ->viewData('page')['props'];

        // Der Kern der Gegenprobe: die Liste ist nicht leer. Waere sie es —
        // falscher Handle, falsche Query — schluege oben jeder gueltige Slug
        // fehl, und zwar erst bei Adrian im Control Panel.
        $this->assertSame(
            ['kauf-bestaetigung', 'willkommen'],
            array_column($props['confirmationTemplates'], 'value'),
        );
    }

    #[Test]
    public function the_listing_says_which_offers_send_nothing(): void
    {
        Offer::create($this->valid(['handle' => 'sendet', 'name' => 'Sendet']));
        Offer::create($this->valid([
            'handle' => 'schweigt',
            'name' => 'Schweigt',
            'confirmation_mode' => Offer::CONFIRMATION_NONE,
        ]));

        $rows = collect(
            $this->actingAs($this->user())
                ->getJson('/cp/utilities/offers')
                ->assertOk()
                ->json('data')
        )->keyBy('handle');

        // A setting nobody can see is the reason the missing mail went
        // unnoticed for a month. The column is the fix for that half.
        $this->assertFalse($rows['sendet']['confirmation_silent']);
        $this->assertTrue($rows['schweigt']['confirmation_silent']);
    }
}
