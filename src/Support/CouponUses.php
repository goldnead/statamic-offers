<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Facades\Schema;

/**
 * Der Zaehler `used_count` nach dem Ausgang einer Zahlung.
 *
 * `Basket::discount()` zaehlt beim Start der Zahlung, mit einem bedingten
 * UPDATE: nur so bekommen zwei Leute den letzten Code nicht gleichzeitig.
 * Erst bei „bezahlt" zu zaehlen waere zu spaet, der Rabatt steht dann schon
 * auf der Zahlung. Also wird beim Start gezaehlt und hier korrigiert:
 *
 * - **Ohne Geld zu Ende** (failed, expired, canceled; `PaymentFailed`, das
 *   payments genau einmal je Zahlung feuert): die Einloesung kommt zurueck.
 *   Synchrone Ablehnungen (Kasse gibt null, Ein-Klick wirft) gibt der
 *   Aufrufer selbst mit `Basket::releaseCoupon()` zurueck.
 * - **Bezahlt, ohne dass gerade eine Einloesung gehalten wurde**: es wird
 *   nachgezaehlt. Das ist eine Wiederaufnahme (`meta.resumed_from`): sie
 *   uebernimmt den Rabatt der Originalzahlung, ohne neu einzuloesen. Und eine
 *   Zahlung, die schon als gescheitert gemeldet und deshalb zurueckgegeben war.
 *
 * Zyklen eines Abos zaehlen nie: ein Gutschein wird je Kauf eingeloest, nicht
 * je Monat.
 */
class CouponUses
{
    /** Eine Zahlung ist ohne Geld zu Ende gegangen. */
    public function failed(Payment $payment): void
    {
        // Zurueckgeben und Nachzaehlen gehoeren zusammen. Das Nachzaehlen
        // haengt am Unique-Index von `offer_coupon_redemptions`; fehlt die
        // Tabelle, wuerde nur zurueckgegeben, und ein Code mit einer Einloesung
        // liesse sich ueber Wiederaufnahmen beliebig oft nutzen.
        if (! Schema::hasTable('offer_coupon_redemptions')) {
            return;
        }

        if ($this->isResumed($payment)) {
            // Hat nie eingeloest, also gibt sie auch nichts zurueck. Die
            // Einloesung haelt weiter das Original.
            return;
        }

        $this->coupon($payment)?->release();
    }

    /**
     * Eine Zahlung ist bezahlt. Nur einmal je Zahlung zu rufen: der Aufrufer
     * (`OfferMoments::couponOf()`) haengt am Unique-Index der Einloesungen.
     */
    public function paid(Payment $payment): void
    {
        if (! $this->isResumed($payment) && $payment->failed_notified_at === null) {
            return;
        }

        // Ohne Grenze: das Geld ist da, der Rabatt gewaehrt. Eine Einloesung
        // ueber `max_uses` ist ehrlicher als eine, die nicht gezaehlt wird.
        $this->coupon($payment)?->increment('used_count');
    }

    protected function coupon(Payment $payment): ?Coupon
    {
        $code = $payment->discount_code;

        if (! is_string($code) || trim($code) === '' || $this->isCycle($payment)) {
            return null;
        }

        return Coupon::findByCode($code);
    }

    protected function isResumed(Payment $payment): bool
    {
        return (int) data_get($payment->meta, 'resumed_from') > 0;
    }

    protected function isCycle(Payment $payment): bool
    {
        return data_get($payment->meta, 'cycle_of') !== null;
    }
}
