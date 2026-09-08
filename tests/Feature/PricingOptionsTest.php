<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\Basket;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Subscriptions;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * Ein Angebot, mehrere Zahlweisen.
 *
 * Der Katalog ist die einzige Stelle, an der ein Preis herkommt, und
 * `offer:<handle>:<key>` ist ein Katalog-Handle wie jedes andere. Diese Tests
 * fragen deshalb den Katalog, nicht das Modell: was hier steht, ist das, was
 * abgebucht wird.
 */
class PricingOptionsTest extends TestCase
{
    protected function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'choiraccelerator',
            'name' => 'ChoirAccelerator',
            'product' => 'noten-paket',
            'amount_cent' => 150000,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
            'pricing_options' => [
                ['key' => 'voll', 'label' => 'Einmalig', 'amount_cent' => 150000],
                ['key' => 'raten3', 'label' => 'In 3 Raten', 'amount_cent' => 52000, 'interval' => '1 month', 'times' => 3],
                ['key' => 'abo', 'label' => 'Monatlich', 'amount_cent' => 9000, 'interval' => '1 month'],
            ],
        ], $overrides));
    }

    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    #[Test]
    public function the_three_payment_types_are_read_off_the_fields(): void
    {
        $optionen = $this->offer()->pricingOptions();

        $this->assertSame(
            [Offer::PRICING_ONCE, Offer::PRICING_INSTALMENTS, Offer::PRICING_SUBSCRIPTION],
            array_column($optionen, 'type'),
        );

        // Ein Abo hat keine Anzahl, und eine einmalige Zahlung keinen Rhythmus.
        $this->assertNull($optionen[2]['times']);
        $this->assertNull($optionen[0]['interval']);
    }

    #[Test]
    public function a_one_off_option_resolves_to_its_own_amount_and_no_plan(): void
    {
        $this->offer();

        $eintrag = app(Catalogue::class)->find('offer:choiraccelerator:voll');

        $this->assertSame(150000, $eintrag['amount_cent']);
        $this->assertArrayNotHasKey('interval', $eintrag);
        $this->assertNull(app(Subscriptions::class)->planFor('offer:choiraccelerator:voll'));
    }

    #[Test]
    public function an_instalment_option_resolves_to_the_instalment_and_a_finite_plan(): void
    {
        $this->offer();

        $eintrag = app(Catalogue::class)->find('offer:choiraccelerator:raten3');

        // Der Betrag ist die **Rate**, nicht die Summe. Das ist dieselbe Regel
        // wie am Angebot selbst seit 1.8.0, und die Stelle, an der jemand
        // 1.500 statt 520 eintraegt, wenn sie nicht dasteht.
        $this->assertSame(52000, $eintrag['amount_cent']);

        $plan = app(Subscriptions::class)->planFor('offer:choiraccelerator:raten3');

        $this->assertSame('1 month', $plan['interval']);
        $this->assertSame(3, $plan['times']);
    }

    #[Test]
    public function a_subscription_option_resolves_to_a_plan_without_an_end(): void
    {
        $this->offer();

        $plan = app(Subscriptions::class)->planFor('offer:choiraccelerator:abo');

        $this->assertSame('1 month', $plan['interval']);
        $this->assertNull($plan['times']);
    }

    #[Test]
    public function the_offer_keeps_the_name_so_the_invoice_line_stays_the_same(): void
    {
        $this->offer();

        foreach (['voll', 'raten3', 'abo'] as $key) {
            $this->assertSame('ChoirAccelerator', app(Catalogue::class)->find('offer:choiraccelerator:'.$key)['name']);
        }
    }

    #[Test]
    public function an_unknown_key_resolves_to_nothing_rather_than_the_base_price(): void
    {
        $this->offer();

        // Ein alter Link, eine geloeschte Option, ein Tippfehler: „dann eben
        // der volle Preis" waere eine Abbuchung ueber 1.500 Euro, die niemand
        // ausgewaehlt hat.
        $this->assertNull(app(Catalogue::class)->find('offer:choiraccelerator:gibtsnicht'));
    }

    #[Test]
    public function an_offer_without_options_is_unchanged(): void
    {
        $this->offer(['pricing_options' => null]);

        $eintrag = app(Catalogue::class)->find('offer:choiraccelerator');

        $this->assertSame(150000, $eintrag['amount_cent']);
        $this->assertSame('ChoirAccelerator', $eintrag['name']);
        $this->assertArrayNotHasKey('interval', $eintrag);
        $this->assertNull(app(Catalogue::class)->find('offer:choiraccelerator:voll'));
    }

    #[Test]
    public function the_base_handle_still_answers_when_options_exist(): void
    {
        $this->offer();

        $this->assertSame(150000, app(Catalogue::class)->find('offer:choiraccelerator')['amount_cent']);
        $this->assertNull(app(Subscriptions::class)->planFor('offer:choiraccelerator'));
    }

    #[Test]
    public function a_one_off_option_does_not_inherit_the_offers_own_rhythm(): void
    {
        // Das Angebot selbst laeuft monatlich, die gewaehlte Option ist
        // einmalig. Erbte sie das `interval`, stuende „einmalig" in der Kasse
        // und im Konto eine monatliche Abbuchung.
        $this->offer([
            'interval' => '1 month',
            'times' => 3,
            'pricing_options' => [
                ['key' => 'voll', 'label' => 'Einmalig', 'amount_cent' => 150000],
            ],
        ]);

        $this->assertNull(app(Subscriptions::class)->planFor('offer:choiraccelerator:voll'));
    }

    #[Test]
    public function half_written_options_are_dropped_rather_than_guessed(): void
    {
        $offer = $this->offer([
            'pricing_options' => [
                ['key' => 'ohne-betrag', 'label' => 'Kaputt'],
                ['key' => 'Falscher Key', 'amount_cent' => 1000],
                ['key' => 'mit:doppelpunkt', 'amount_cent' => 1000],
                ['key' => 'gut', 'amount_cent' => 1000],
                ['key' => 'gut', 'amount_cent' => 9999],
                'kein array',
            ],
        ]);

        $this->assertSame(['gut'], array_column($offer->pricingOptions(), 'key'));

        // Und der doppelte Schluessel nimmt den ersten Betrag, nicht den
        // letzten: zwei Preise fuer dasselbe Handle sind kein Angebot.
        $this->assertSame(1000, app(Catalogue::class)->find('offer:choiraccelerator:gut')['amount_cent']);
    }

    #[Test]
    public function the_control_panel_writes_and_cleans_the_options(): void
    {
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/offers', [
                'name' => 'ChoirAccelerator',
                'handle' => 'choiraccelerator',
                'product' => 'noten-paket',
                'amount_cent' => 150000,
                'slot' => Offer::SLOT_STANDALONE,
                'active' => true,
                'pricing_options' => [
                    ['key' => 'voll', 'label' => 'Einmalig', 'amount_cent' => 150000, 'times' => 3],
                    ['key' => 'raten3', 'label' => 'In 3 Raten', 'amount_cent' => 52000, 'interval' => '1 month', 'times' => 3],
                ],
            ])
            ->assertRedirect();

        $offer = Offer::query()->where('handle', 'choiraccelerator')->firstOrFail();

        // Die Anzahl an der einmaligen Zeile ist weg. Sie war wirkungslos und
        // haette gewirkt, sobald jemand spaeter ein Intervall eintraegt.
        $this->assertArrayNotHasKey('times', $offer->pricing_options[0]);
        $this->assertSame(3, $offer->pricing_options[1]['times']);
    }

    #[Test]
    public function an_option_without_a_label_keeps_an_empty_one_rather_than_growing_its_key(): void
    {
        // Der Rueckfall auf den Schluessel gehoert dorthin, wo angezeigt wird.
        // Stuende er hier, faende das CP-Formular ihn als Bezeichnung vor und
        // schriebe ihn beim naechsten Speichern in die Spalte — als Eingabe,
        // die niemand gemacht hat.
        $offer = $this->offer(['pricing_options' => [
            ['key' => 'raten3', 'amount_cent' => 52000, 'interval' => '1 month', 'times' => 3],
        ]]);

        $this->assertSame('', $offer->pricingOptions()[0]['label']);
    }

    #[Test]
    public function the_control_panel_writes_a_trial_per_option(): void
    {
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/offers', [
                'name' => 'ChoirAccelerator',
                'handle' => 'choiraccelerator',
                'product' => 'noten-paket',
                'amount_cent' => 150000,
                'slot' => Offer::SLOT_STANDALONE,
                'active' => true,
                'pricing_options' => [
                    ['key' => 'abo', 'label' => 'Monatlich', 'amount_cent' => 9000, 'interval' => '1 month', 'trial_days' => 14, 'trial_amount_cent' => 100],
                    // Ohne Rhythmus wirkungslos, also beim Speichern geleert.
                    ['key' => 'voll', 'label' => 'Einmalig', 'amount_cent' => 150000, 'trial_days' => 14],
                ],
            ])
            ->assertRedirect();

        $offer = Offer::query()->where('handle', 'choiraccelerator')->firstOrFail();

        $this->assertSame(14, $offer->pricing_options[0]['trial_days']);
        $this->assertSame(100, $offer->pricing_options[0]['trial_amount_cent']);

        // Die einmalige Zeile behaelt keine Testphase: sie waere wirkungslos
        // und wuerde in dem Moment wirken, in dem jemand spaeter ein Intervall
        // eintraegt.
        $this->assertArrayNotHasKey('trial_days', $offer->pricing_options[1]);

        // Und der Katalog gibt die Testphase der Option heraus, nicht die des
        // Angebots.
        $plan = app(Subscriptions::class)->planFor('offer:choiraccelerator:abo');

        $this->assertSame(14, $plan['trial_days']);
        $this->assertSame(100, $plan['trial_amount_cent']);
    }

    #[Test]
    public function the_control_panel_refuses_a_key_that_would_split_a_handle(): void
    {
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/offers', [
                'name' => 'ChoirAccelerator',
                'handle' => 'choiraccelerator',
                'product' => 'noten-paket',
                'amount_cent' => 150000,
                'slot' => Offer::SLOT_STANDALONE,
                'active' => true,
                'pricing_options' => [
                    ['key' => 'raten:3', 'amount_cent' => 52000],
                ],
            ])
            ->assertJsonValidationErrors('pricing_options.0.key');
    }

    #[Test]
    public function a_basket_buys_the_chosen_option_and_a_coupon_counts_on_its_amount(): void
    {
        $offer = $this->offer();

        $basket = Basket::make($offer, [], null, 'raten3');

        $this->assertSame(['offer:choiraccelerator:raten3'], $basket->handles());

        // Der Gutschein rechnet auf die Rate, nicht auf den Grundpreis. Sonst
        // zoege „10 Prozent" 150 Euro von einer Abbuchung ueber 520 ab.
        $this->assertSame(52000, $basket->grossCent());
    }

    #[Test]
    public function a_basket_refuses_a_key_the_offer_does_not_carry(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Basket::make($this->offer(), [], null, 'gibtsnicht');
    }
}
