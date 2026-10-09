<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\Basket;
use Goldnead\StatamicOffers\Tests\Support\SubscribingGateway;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Subscriptions;
use PHPUnit\Framework\Attributes\Test;

/**
 * Was „heute faellig" sagt, ist das, was die erste Zahlung bucht.
 *
 * Staging-Testkauf 09.10.2026: die Kasse zeigte bei einem Abo mit bezahltem
 * Testmonat 65 statt 45 EUR und bei einem Gutschein 37 statt 27. `netCent()` kennt
 * den Testmonat nicht, und `Offer::firstPaymentCent()` weder Gutschein noch Bumps.
 * {@see Basket::firstPaymentCent()} ist die eine Zahl dafuer; jeder Test bucht
 * ueber denselben Weg wie eine Kasse und vergleicht mit der Zahlung.
 */
class BasketFirstPaymentTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.follow_up.enabled', true);
        $app['config']->set('statamic-payments.follow_up.collect_mandate', true);

        foreach (['plan' => 'Plan', 'cd' => 'CD'] as $handle => $name) {
            $app['config']->set('statamic-payments.products.'.$handle, [
                'name' => $name,
                'amount_cent' => 100,
                'digital' => true,
            ]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Ein Anbieter, der Abos beginnen kann; sonst gibt `Subscriptions::start()` null.
        $this->gateway = new SubscribingGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    protected function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'plan',
            'name' => 'Plan',
            'product' => 'plan',
            'amount_cent' => 3700,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ], $overrides));
    }

    protected function bump(int $cent = 1900): Offer
    {
        return Offer::create(['handle' => 'cd', 'name' => 'CD', 'product' => 'cd', 'amount_cent' => $cent, 'slot' => Offer::SLOT_BUMP, 'active' => true]);
    }

    /** Wie die Kasse bucht: Abo ueber `Subscriptions`, alles andere ueber `Checkout`. */
    protected function gebucht(Basket $basket): int
    {
        $ergebnis = $basket->isRecurring()
            ? app(Subscriptions::class)->start($basket->handles(), ['email' => 'k@example.com'], null, [], $basket->discount())
            : app(Checkout::class)->start($basket->handles(), ['email' => 'k@example.com'], null, $basket->discount());

        return (int) $ergebnis->payment->amount_cent;
    }

    #[Test]
    public function a_plain_offer_is_its_price(): void
    {
        $basket = Basket::make($this->offer());

        $this->assertSame(3700, $basket->firstPaymentCent());
        $this->assertSame($basket->firstPaymentCent(), $this->gebucht($basket));
    }

    #[Test]
    public function a_paid_trial_counts_the_trial_amount_not_the_plan(): void
    {
        $offer = $this->offer(['amount_cent' => 6500, 'interval' => '1 month', 'trial_days' => 30, 'trial_amount_cent' => 4500]);
        $basket = Basket::make($offer);

        $this->assertSame(4500, $basket->firstPaymentCent());
        $this->assertSame(4500, $this->gebucht($basket));
    }

    #[Test]
    public function an_amount_coupon_comes_off_the_first_payment(): void
    {
        Coupon::create(['code' => 'ZEHN', 'amount_cent' => 1000, 'currency' => 'EUR', 'active' => true]);
        $basket = Basket::make($this->offer(), [], 'ZEHN');

        $this->assertSame(2700, $basket->firstPaymentCent());
        $this->assertSame(2700, $this->gebucht($basket));
    }

    #[Test]
    public function a_percent_coupon_comes_off_the_first_payment(): void
    {
        Coupon::create(['code' => 'ZWANZIG', 'percent' => 20, 'active' => true]);
        $basket = Basket::make($this->offer(['amount_cent' => 9900]), [], 'ZWANZIG');

        $this->assertSame(7920, $basket->firstPaymentCent());
        $this->assertSame(7920, $this->gebucht($basket));
    }

    #[Test]
    public function the_bump_is_added_to_the_main_line(): void
    {
        $this->bump();
        $basket = Basket::make($this->offer(['amount_cent' => 9900, 'bumps' => ['cd']]), ['cd']);

        $this->assertSame(11800, $basket->firstPaymentCent());
        $this->assertSame(11800, $this->gebucht($basket));
    }

    #[Test]
    public function a_trial_plus_bump_is_the_trial_amount_plus_the_bump(): void
    {
        $this->bump();
        $offer = $this->offer(['amount_cent' => 6500, 'interval' => '1 month', 'trial_days' => 30, 'trial_amount_cent' => 4500, 'bumps' => ['cd']]);
        $basket = Basket::make($offer, ['cd']);

        $this->assertSame(6400, $basket->firstPaymentCent());
        $this->assertSame(6400, $this->gebucht($basket));
    }

    #[Test]
    public function a_percent_coupon_on_the_whole_basket_sees_the_bump(): void
    {
        $this->bump();
        Coupon::create(['code' => 'ZWANZIG', 'percent' => 20, 'active' => true]);
        $basket = Basket::make($this->offer(['amount_cent' => 9900, 'bumps' => ['cd']]), ['cd'], 'ZWANZIG');

        $this->assertSame(9440, $basket->firstPaymentCent());
        $this->assertSame(9440, $this->gebucht($basket));
    }

    #[Test]
    public function a_setup_fee_is_part_of_the_first_payment_and_not_discounted(): void
    {
        Coupon::create(['code' => 'ZEHN', 'percent' => 10, 'active' => true]);
        $offer = $this->offer(['amount_cent' => 2900, 'interval' => '1 month', 'setup_fee_cent' => 5000]);
        $basket = Basket::make($offer, [], 'ZEHN');

        // 29,00 - 10 % + 50,00 Gebuehr.
        $this->assertSame(7610, $basket->firstPaymentCent());
        $this->assertSame(7610, $this->gebucht($basket));
    }

    #[Test]
    public function the_chosen_pricing_option_decides_the_trial(): void
    {
        $offer = $this->offer(['amount_cent' => 9900, 'pricing_options' => [
            ['key' => 'voll', 'amount_cent' => 9900],
            ['key' => 'abo', 'amount_cent' => 6500, 'interval' => '1 month', 'trial_days' => 30, 'trial_amount_cent' => 4500],
        ]]);

        $this->assertSame(9900, Basket::make($offer, pricingOption: 'voll')->firstPaymentCent());
        $abo = Basket::make($offer, pricingOption: 'abo');
        $this->assertSame(4500, $abo->firstPaymentCent());
        $this->assertSame(4500, $this->gebucht($abo));
    }
}
