<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\Basket;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Eine Einloesung, aus der keine Zahlung wurde, kommt zurueck.
 *
 * `Basket::discount()` zaehlt beim Start der Zahlung. Laeuft die Zahlung ab,
 * scheitert sie oder bricht der Kaeufer ab, war der Code bis 1.21.1 trotzdem
 * verbraucht: ein Code mit einer Einloesung war nach einem geschlossenen
 * Kartenformular tot.
 */
class CouponReleaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products.workshop', [
            'name' => 'Workshop',
            'amount_cent' => 4900,
        ]);
    }

    protected function offer(): Offer
    {
        return Offer::create([
            'handle' => 'stimmgruppe',
            'name' => 'Workshop',
            'product' => 'workshop',
            'amount_cent' => 39000,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ]);
    }

    /** Ein Korb mit Code, der zur Zahlung wird, wie es die Kasse tut. */
    protected function checkout(Offer $offer, string $code): Payment
    {
        $basket = Basket::make($offer, [], $code);

        return app(Checkout::class)->start($basket->handles(), ['email' => 'k@example.com'], null, $basket->discount())->payment;
    }

    protected function deliver(Payment $payment, string $status): void
    {
        $status === Payment::STATUS_PAID
            ? $this->gateway->markPaid($payment->provider_id)
            : $this->gateway->markStatus($payment->provider_id, $status);

        // Der Anbieter liefert doppelt, mit Absicht.
        app(Fulfilment::class)->handle($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);
    }

    protected function uses(string $code = 'EINMAL'): int
    {
        return (int) Coupon::findByCode($code)->used_count;
    }

    /** @return array<string, array{string}> */
    public static function ohneGeld(): array
    {
        return [
            'abgelaufen' => [Payment::STATUS_EXPIRED],
            'gescheitert' => [Payment::STATUS_FAILED],
            'abgebrochen' => [Payment::STATUS_CANCELED],
        ];
    }

    #[Test]
    #[DataProvider('ohneGeld')]
    public function a_payment_that_never_paid_gives_its_use_back_once(string $status): void
    {
        $offer = $this->offer();
        Coupon::create(['code' => 'EINMAL', 'percent' => 10, 'active' => true, 'max_uses' => 1]);

        $payment = $this->checkout($offer, 'EINMAL');
        $this->assertSame(1, $this->uses());

        $this->deliver($payment, $status);

        $this->assertSame(0, $this->uses());
        $this->assertNotNull(Basket::make($offer, [], 'EINMAL')->discount());
    }

    #[Test]
    public function a_paid_payment_keeps_its_use(): void
    {
        $offer = $this->offer();
        Coupon::create(['code' => 'EINMAL', 'percent' => 10, 'active' => true, 'max_uses' => 1]);

        $this->deliver($this->checkout($offer, 'EINMAL'), Payment::STATUS_PAID);

        $this->assertSame(1, $this->uses());
    }

    #[Test]
    public function a_payment_without_one_of_our_codes_releases_nothing(): void
    {
        $offer = $this->offer();
        Coupon::create(['code' => 'EINMAL', 'percent' => 10, 'active' => true, 'max_uses' => 5, 'used_count' => 2]);

        $payment = app(Checkout::class)->start(Basket::make($offer)->handles(), ['email' => 'k@example.com'])->payment;
        $this->deliver($payment, Payment::STATUS_EXPIRED);

        $this->assertSame(2, $this->uses());
    }

    #[Test]
    public function a_resumed_checkout_counts_once_when_it_is_paid(): void
    {
        $offer = $this->offer();
        Coupon::create(['code' => 'EINMAL', 'percent' => 10, 'active' => true, 'max_uses' => 1]);

        $original = $this->checkout($offer, 'EINMAL');
        $this->deliver($original, Payment::STATUS_EXPIRED);
        $this->assertSame(0, $this->uses());

        // Die Erinnerungsmail: dieselbe Bestellung, derselbe Rabatt, ohne dass
        // der Korb neu einloest. Bezahlt, ist das eine Einloesung.
        $resumed = app(Checkout::class)->resume($original->fresh())->payment;
        $this->assertSame('EINMAL', $resumed->discount_code);

        $this->deliver($resumed, Payment::STATUS_PAID);

        $this->assertSame(1, $this->uses());
    }

    #[Test]
    public function a_failed_resumed_checkout_gives_back_nothing_it_never_took(): void
    {
        $offer = $this->offer();
        Coupon::create(['code' => 'EINMAL', 'percent' => 10, 'active' => true, 'max_uses' => 3]);

        // Zwei andere haben bezahlt, die dritte Einloesung haelt das Original.
        Coupon::findByCode('EINMAL')->forceFill(['used_count' => 2])->save();
        $original = $this->checkout($offer, 'EINMAL');
        $this->assertSame(3, $this->uses());

        $resumed = app(Checkout::class)->resume($original->fresh())->payment;
        $this->deliver($resumed, Payment::STATUS_EXPIRED);

        // Das Original haelt seine Einloesung noch.
        $this->assertSame(3, $this->uses());

        $this->deliver($original, Payment::STATUS_EXPIRED);
        $this->assertSame(2, $this->uses());
    }

    #[Test]
    public function a_last_use_taken_in_between_is_logged_and_the_basket_says_so(): void
    {
        $offer = $this->offer();
        Coupon::create(['code' => 'EINMAL', 'percent' => 10, 'active' => true, 'max_uses' => 1]);

        $basket = Basket::make($offer, [], 'EINMAL');
        $this->assertNotNull($basket->coupon());
        $this->assertFalse($basket->couponLost());

        // Waehrend dieser Korb offen ist, bekommt jemand anders die letzte
        // Einloesung. Gekauft wird trotzdem, zum vollen Preis.
        Coupon::findByCode('EINMAL')->claim();

        Log::spy();

        $this->assertNull($basket->discount());
        $this->assertTrue($basket->couponLost());

        // Was der Korb danach zeigt, ist was abgebucht wird.
        $this->assertNull($basket->coupon());
        $this->assertSame($basket->grossCent(), $basket->netCent());

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context = []) => ($context['code'] ?? null) === 'EINMAL'
        );
    }

    #[Test]
    public function without_the_redemptions_table_nothing_is_given_back(): void
    {
        $offer = $this->offer();
        Coupon::create(['code' => 'EINMAL', 'percent' => 10, 'active' => true, 'max_uses' => 1]);
        $payment = $this->checkout($offer, 'EINMAL');

        // Ohne die Tabelle kann bezahlt nicht nachzaehlen; nur zurueckzugeben
        // oeffnete den Code ueber Wiederaufnahmen beliebig oft.
        Schema::rename('offer_coupon_redemptions', 'offer_coupon_redemptions_weg');

        try {
            $this->deliver($payment, Payment::STATUS_EXPIRED);
            $this->assertSame(1, $this->uses());
        } finally {
            Schema::rename('offer_coupon_redemptions_weg', 'offer_coupon_redemptions');
        }
    }

    #[Test]
    public function a_code_deleted_in_between_is_nothing_to_release(): void
    {
        $offer = $this->offer();
        Coupon::create(['code' => 'EINMAL', 'percent' => 10, 'active' => true, 'max_uses' => 1]);
        $payment = $this->checkout($offer, 'EINMAL');

        Coupon::query()->whereKey(Coupon::findByCode('EINMAL')->getKey())->delete();

        Log::spy();
        $this->deliver($payment, Payment::STATUS_EXPIRED);

        // Ein geloeschter Code ist kein Fehler, nur nichts zu tun.
        Log::shouldNotHaveReceived('error');
    }
}
