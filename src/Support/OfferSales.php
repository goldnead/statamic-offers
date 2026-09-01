<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * What an offer has actually sold, read from the payment tables.
 *
 * **Counted, not kept.** A `sold_count` column next to `accepted_count` would
 * have been the easy way, and it would have drifted: a refunded payment, a
 * deleted test order, a manual fix in the database — none of them would know
 * to decrement it. The payment lines are the truth about money, so the number
 * is read from there every time.
 *
 * **Paid lines only,** by the payment's status. An open or failed payment has
 * not bought anything, and a limit that counted it would sell out on
 * abandoned carts.
 *
 * `statamic-payments` is a composer dependency, so its classes are always
 * here — but its tables are not, on a site that installed this addon before
 * running the migrations. In that state every question here is answered with
 * "unknown" and a single log line per request, rather than a 500 on every
 * listing row.
 */
class OfferSales
{
    /** @var array<string, array{sold: int, revenue: array<string, int>}>|null */
    private static ?array $karte = null;

    private static ?bool $verfuegbar = null;

    /** Are the payment tables there to be asked? */
    public static function available(): bool
    {
        if (self::$verfuegbar !== null) {
            return self::$verfuegbar;
        }

        self::$verfuegbar = Schema::hasTable('payments') && Schema::hasTable('payment_items');

        if (! self::$verfuegbar) {
            Log::notice('statamic-offers: payment tables are missing, so quantity limits and revenue cannot be read. Run the statamic-payments migrations.');
        }

        return self::$verfuegbar;
    }

    /**
     * Units of this offer paid for, fresh from the table.
     *
     * Not from the per-request map: this is what a quantity limit is checked
     * against at checkout, and a number cached a moment ago is a number two
     * simultaneous buyers can both be shown.
     */
    public static function sold(Offer $offer): ?int
    {
        if (! self::available()) {
            return null;
        }

        return (int) self::paidLines()
            ->where('payment_items.product', Offer::prefix().$offer->handle)
            ->sum('payment_items.quantity');
    }

    /**
     * Revenue in minor units, in the offer's currency.
     *
     * Lines paid in another currency are not added — 12 € and 12 CHF do not
     * make 24 of anything — and are not shown either; a listing that needs to
     * see them can read {@see self::revenueByCurrency()}.
     */
    public static function revenueCent(Offer $offer): ?int
    {
        $byCurrency = self::revenueByCurrency($offer);

        if ($byCurrency === null) {
            return null;
        }

        return $byCurrency[mb_strtoupper($offer->currency())] ?? 0;
    }

    /**
     * @return array<string, int>|null currency => minor units
     */
    public static function revenueByCurrency(Offer $offer): ?array
    {
        if (! self::available()) {
            return null;
        }

        self::$karte ??= self::bauen();

        return self::$karte[$offer->handle]['revenue'] ?? [];
    }

    /** Units sold, from the per-request map; for listings, not for limits. */
    public static function soldForListing(Offer $offer): ?int
    {
        if (! self::available()) {
            return null;
        }

        self::$karte ??= self::bauen();

        return self::$karte[$offer->handle]['sold'] ?? 0;
    }

    /** Only for tests: forget what this request already read. */
    public static function forget(): void
    {
        self::$karte = null;
        self::$verfuegbar = null;
    }

    /**
     * One query for every offer at once, for the listing.
     *
     * @return array<string, array{sold: int, revenue: array<string, int>}>
     */
    private static function bauen(): array
    {
        $prefix = Offer::prefix();
        $karte = [];

        $rows = self::paidLines()
            ->where('payment_items.product', 'like', addcslashes($prefix, '%_\\').'%')
            ->groupBy('payment_items.product', 'payments.currency')
            ->get([
                'payment_items.product',
                'payments.currency',
                DB::raw('SUM(payment_items.quantity) as sold'),
                DB::raw('SUM(payment_items.amount_cent * payment_items.quantity) as revenue'),
            ]);

        foreach ($rows as $row) {
            $handle = substr((string) $row->product, strlen($prefix));
            $currency = mb_strtoupper((string) $row->currency);

            $karte[$handle] ??= ['sold' => 0, 'revenue' => []];
            $karte[$handle]['sold'] += (int) $row->sold;
            $karte[$handle]['revenue'][$currency] = ($karte[$handle]['revenue'][$currency] ?? 0) + (int) $row->revenue;
        }

        return $karte;
    }

    /** @return Builder */
    private static function paidLines()
    {
        return DB::table('payment_items')
            ->join('payments', 'payments.id', '=', 'payment_items.payment_id')
            ->where('payments.status', Payment::STATUS_PAID);
    }
}
