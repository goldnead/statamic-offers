<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\Basket;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Fulfilment;
use PHPUnit\Framework\Attributes\Test;

/**
 * O2: Einrichtungsgebuehr bei Abo und Raten.
 *
 * Die Gebuehr ist eine eigene Zeile und kein hoeherer erster Betrag. Nur so
 * steht sie auf der Rechnung als eigene Position, und nur so bleibt der
 * Rhythmus des Abos der Betrag, der jeden Monat abgebucht wird: der
 * Abo-Anfang liest den **ersten** Handle, und die Gebuehr steht dahinter.
 */
class SetupFeeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products.kurs', [
            'name' => 'Kurs',
            'amount_cent' => 9900,
            'grants' => 'kurs-zugang',
            'digital' => true,
        ]);
    }

    protected function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'mitgliedschaft',
            'name' => 'Mitgliedschaft',
            'product' => 'kurs',
            'amount_cent' => 2900,
            'interval' => '1 month',
            'setup_fee_cent' => 5000,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ], $overrides));
    }

    #[Test]
    public function the_fee_is_its_own_line_behind_the_plan(): void
    {
        $offer = $this->offer();

        $basket = Basket::make($offer);

        $this->assertSame(['offer:mitgliedschaft', 'offer:mitgliedschaft:+setup'], $basket->handles());
        $this->assertSame(7900, $basket->grossCent());
    }

    #[Test]
    public function the_fee_line_is_charged_once_and_grants_nothing(): void
    {
        $this->offer(['setup_fee_label' => 'Aufnahmegespräch']);

        $fee = app(Catalogue::class)->find('offer:mitgliedschaft:+setup');

        $this->assertSame(5000, $fee['amount_cent']);
        $this->assertSame('Aufnahmegespräch', $fee['name']);
        // Kein Rhythmus: sonst würde die Gebühr jeden Monat wieder abgebucht.
        $this->assertArrayNotHasKey('interval', $fee);
        // Kein Zugang: der kommt mit der Hauptzeile, und ein zweiter wäre eine
        // doppelte Freischaltung aus einer Zahlung.
        $this->assertArrayNotHasKey('grants', $fee);
        // Steuerfakten vom Produkt darunter, wie jede Angebotszeile.
        $this->assertSame('kurs', $fee['product']);
        $this->assertTrue($fee['digital']);
    }

    #[Test]
    public function a_one_off_purchase_carries_no_fee(): void
    {
        $offer = $this->offer(['interval' => null]);

        $this->assertSame(['offer:mitgliedschaft'], Basket::make($offer)->handles());
    }

    #[Test]
    public function the_fee_follows_the_chosen_option(): void
    {
        $offer = $this->offer(['interval' => null, 'amount_cent' => 9900, 'pricing_options' => [
            ['key' => 'einmal', 'amount_cent' => 9900],
            ['key' => 'monatlich', 'amount_cent' => 2900, 'interval' => '1 month'],
        ]]);

        $this->assertSame(['offer:mitgliedschaft:einmal'], Basket::make($offer, pricingOption: 'einmal')->handles());
        $this->assertSame(
            ['offer:mitgliedschaft:monatlich', 'offer:mitgliedschaft:+setup'],
            Basket::make($offer, pricingOption: 'monatlich')->handles(),
        );
    }

    #[Test]
    public function a_coupon_does_not_reduce_the_fee(): void
    {
        $offer = $this->offer();
        Coupon::create(['code' => 'START', 'percent' => 10, 'active' => true]);

        // 10 % von 29,00, nicht von 79,00.
        $this->assertSame(290, Basket::make($offer, [], 'START')->discount()->amountCent);
    }

    #[Test]
    public function the_first_payment_carries_both_lines_and_only_the_plan_counts_as_sold(): void
    {
        $offer = $this->offer(['quantity_limit' => 10]);

        $payment = app(Checkout::class)->start(Basket::make($offer)->handles(), ['email' => 'k@example.com'])->payment;
        $this->gateway->markPaid($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);

        $this->assertSame(7900, $payment->fresh()->amount_cent);
        $this->assertCount(2, $payment->items);
        $this->assertSame(__('statamic-offers::messages.setup_fee_line', ['name' => 'Mitgliedschaft']), $payment->items[1]->name);
        $this->assertSame(9, $offer->fresh()->remainingQuantity());
        $this->assertSame(1, $offer->fresh()->accepted_count);
    }

    #[Test]
    public function the_first_payment_is_worked_out_for_display(): void
    {
        $offer = $this->offer(['trial_days' => null]);

        $this->assertSame(7900, $offer->firstPaymentCent());
        $this->assertSame(2900, $offer->effectiveAmountCent());
    }
}
