<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\OfferAcceptance;
use Goldnead\StatamicOffers\Support\OfferMoments;
use Goldnead\StatamicOffers\Support\OfferSales;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Support\Checkout;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

/**
 * Zwei Stellen, die bis 1.21.1 zu leise waren.
 *
 * Ein Moment nach dem Kauf (Gutschein eingeloest, ausverkauft), der wirft,
 * stand nur mit seiner Meldung im Log, ohne Datei, Zeile und Stack. Und ein
 * Angebot mit Kontingent galt ohne die Zahlungstabellen als unbegrenzt.
 */
class LoudFailuresTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products.workshop', [
            'name' => 'Workshop',
            'amount_cent' => 4900,
        ]);
    }

    #[Test]
    public function a_moment_that_throws_is_logged_with_its_exception(): void
    {
        $payment = app(Checkout::class)->start('workshop', ['email' => 'k@example.com'])->payment;

        $kaputt = new RuntimeException('Tabelle gesperrt');
        $this->mock(OfferMoments::class, fn ($mock) => $mock->shouldReceive('couponOf')->andThrow($kaputt));

        Log::spy();

        // Der Kauf bleibt unberuehrt: nichts wirft bis hierher durch.
        app(OfferAcceptance::class)->handle(new PaymentPaid($payment));

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context = []) => ($context['exception'] ?? null) instanceof Throwable
                && $context['exception'] === $kaputt
                && $context['payment_id'] === $payment->getKey()
        );
    }

    #[Test]
    public function an_offer_with_a_limit_is_not_sold_while_the_payment_tables_are_missing(): void
    {
        $knapp = Offer::create(['handle' => 'knapp', 'name' => 'Knapp', 'product' => 'workshop',
            'amount_cent' => 4900, 'quantity_limit' => 5, 'slot' => Offer::SLOT_STANDALONE, 'active' => true]);
        $offen = Offer::create(['handle' => 'offen', 'name' => 'Offen', 'product' => 'workshop',
            'amount_cent' => 4900, 'slot' => Offer::SLOT_STANDALONE, 'active' => true]);

        // Umbenannt statt geloescht: das Aufraeumen nach dem Test braucht sie.
        Schema::rename('payment_items', 'payment_items_weg');
        Schema::rename('payments', 'payments_weg');
        OfferSales::forget();

        try {
            Log::spy();

            // Ein Kontingent, das niemand pruefen kann, wird nicht verkauft.
            $this->assertSame(0, $knapp->remainingQuantity());
            $this->assertFalse($knapp->isSellable());
            $this->assertSame(0, $knapp->remainingQuantity());

            // Ohne Kontingent gibt es nichts zu pruefen.
            $this->assertNull($offen->remainingQuantity());
            $this->assertTrue($offen->isSellable());

            // Laut, und einmal je Anfrage, nicht je Aufruf.
            Log::shouldHaveReceived('error')->once()->withArgs(
                fn (string $message, array $context = []) => str_contains($message, 'quantity limit')
                    && ($context['offer'] ?? null) === 'knapp'
            );
        } finally {
            Schema::rename('payments_weg', 'payments');
            Schema::rename('payment_items_weg', 'payment_items');
            OfferSales::forget();
        }
    }
}
