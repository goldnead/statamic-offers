<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * The terms of withdrawal an offer carries.
 *
 * What matters here is the layering — offer over config over default — and
 * the version: it is the one value the payment freezes, so it has to change
 * exactly when the wording a buyer agrees to changes, and not otherwise.
 */
class WithdrawalTermsTest extends TestCase
{
    protected function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'kurs',
            'name' => 'Kurs',
            'product' => 'noten-paket',
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ], $overrides));
    }

    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    #[Test]
    public function an_offer_that_says_nothing_gets_the_config_defaults(): void
    {
        config()->set('statamic-offers.seller', ['name' => 'Notenwerkstatt', 'contact' => 'post@example.com']);

        $terms = $this->offer()->withdrawalTerms();

        $this->assertSame(14, $terms['days']);
        $this->assertTrue($terms['checkbox_required']);
        $this->assertNull($terms['b2b_text']);
        $this->assertStringContainsString('binnen 14 Tagen', $terms['text']);
        $this->assertStringContainsString('Notenwerkstatt, post@example.com', $terms['text']);
        $this->assertStringNotContainsString('{', $terms['text']);
        $this->assertStringContainsString('Widerrufsrecht verliere', $terms['waiver_text']);
        $this->assertSame(12, strlen($terms['version']));
    }

    #[Test]
    public function every_field_can_be_overridden_on_the_offer(): void
    {
        $terms = $this->offer([
            'withdrawal_days' => 30,
            'withdrawal_text' => 'Eigener Text, {days} Tage.',
            'withdrawal_waiver_text' => 'Eigene Einwilligung.',
            'withdrawal_checkbox_required' => false,
            'withdrawal_b2b_text' => 'Für Unternehmer gilt: kein Widerruf.',
        ])->withdrawalTerms();

        $this->assertSame(30, $terms['days']);
        $this->assertSame('Eigener Text, 30 Tage.', $terms['text']);
        $this->assertSame('Eigene Einwilligung.', $terms['waiver_text']);
        $this->assertFalse($terms['checkbox_required']);
        $this->assertSame('Für Unternehmer gilt: kein Widerruf.', $terms['b2b_text']);
    }

    #[Test]
    public function a_partial_override_keeps_the_rest_from_the_config(): void
    {
        $terms = $this->offer(['withdrawal_days' => 30])->withdrawalTerms();

        // The period is the offer's, the wording is still the site's — with
        // the offer's period written into it.
        $this->assertSame(30, $terms['days']);
        $this->assertStringContainsString('binnen 30 Tagen', $terms['text']);
    }

    #[Test]
    public function the_version_changes_with_the_wording_and_not_with_anything_else(): void
    {
        $offer = $this->offer();
        $before = $offer->withdrawalTerms()['version'];

        // Something a buyer does not agree to: no new version.
        $offer->update(['withdrawal_b2b_text' => 'Hinweis']);
        $this->assertSame($before, $offer->fresh()->withdrawalTerms()['version']);

        // Something they do: new version.
        $offer->update(['withdrawal_waiver_text' => 'Anderer Satz.']);
        $afterWaiver = $offer->fresh()->withdrawalTerms()['version'];
        $this->assertNotSame($before, $afterWaiver);

        $offer->update(['withdrawal_days' => 21]);
        $this->assertNotSame($afterWaiver, $offer->fresh()->withdrawalTerms()['version']);
    }

    #[Test]
    public function the_control_panel_saves_the_terms_and_defaults_the_checkbox_to_required(): void
    {
        $this->actingAs($this->user())->postJson('/cp/utilities/offers', [
            'name' => 'Kurs',
            'handle' => 'kurs',
            'product' => 'noten-paket',
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
            'withdrawal_days' => 21,
            'withdrawal_text' => 'Text',
            // `withdrawal_checkbox_required` deliberately not sent.
            'withdrawal_pdf' => true,
        ])->assertRedirect();

        $offer = Offer::query()->where('handle', 'kurs')->firstOrFail();

        $this->assertSame(21, $offer->withdrawal_days);
        $this->assertSame('Text', $offer->withdrawal_text);
        $this->assertTrue($offer->withdrawal_checkbox_required);
        $this->assertTrue($offer->withdrawal_pdf);
        $this->assertSame(21, $offer->withdrawalTerms()['days']);
    }

    #[Test]
    public function the_control_panel_refuses_a_period_over_a_year_or_of_zero_days(): void
    {
        foreach ([400, 0] as $days) {
            $this->actingAs($this->user())->postJson('/cp/utilities/offers', [
                'name' => 'Kurs',
                'handle' => 'kurs',
                'product' => 'noten-paket',
                'slot' => Offer::SLOT_STANDALONE,
                'withdrawal_days' => $days,
            ])->assertJsonValidationErrors(['withdrawal_days']);
        }

        $this->assertSame(0, Offer::count());
    }

    #[Test]
    public function a_new_seller_name_is_a_new_version(): void
    {
        config()->set('statamic-offers.seller', ['name' => 'Alte Firma', 'contact' => 'post@example.com']);
        $offer = $this->offer();
        $before = $offer->withdrawalTerms()['version'];

        // The name is inside the text the buyer reads, so the text — and with
        // it the wording that gets frozen on a payment — is a different one.
        config()->set('statamic-offers.seller', ['name' => 'Neue Firma GmbH', 'contact' => 'post@example.com']);

        $this->assertNotSame($before, $offer->withdrawalTerms()['version']);
    }
}
