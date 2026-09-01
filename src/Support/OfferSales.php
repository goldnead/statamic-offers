<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
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
 * **Revenue is net.** Gross line value minus the share of the payment's
 * discount that fell on the line (`payment_items.discount_cent`), minus the
 * line's share of whatever was refunded (`payments.refunded_cent`, apportioned
 * by the line's part of the payment total). A coupon and a refund are the two
 * ordinary ways money does not arrive, and a revenue figure that ignored them
 * would flatter every campaign that used one. Both columns arrived with
 * `statamic-payments` 1.7/1.8; on an older install they are simply not
 * subtracted, and the class says so once in the log.
 *
 * **Paid lines count as sold; open ones count as reserved for an hour.** The
 * quantity limit is checked when a checkout *starts* and a sale is counted
 * when it is *paid*, so between the two the limit has a hole the size of a
 * checkout. Counting open payments younger than {@see self::RESERVATION_MINUTES}
 * closes it in practice: a checkout somebody is still typing in holds its
 * unit, an abandoned one gives it back an hour later. It is still not a
 * reservation in the database sense — see {@see Offer::remainingQuantity()}.
 *
 * `statamic-payments` is a composer dependency, so its classes are always
 * here — but its tables are not, on a site that installed this addon before
 * running the migrations. In that state every question here is answered with
 * "unknown" and a single log line per request, rather than a 500 on every
 * listing row.
 */
class OfferSales
{
    /** How long an unpaid checkout holds its unit against the limit. */
    public const RESERVATION_MINUTES = 60;

    /** @var array<string, array{sold: int, reserved: int, revenue: array<string, int>}>|null */
    private static ?array $karte = null;

    private static ?bool $verfuegbar = null;

    private static ?bool $netto = null;

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
     * Units of this offer paid for or held by a checkout in progress, fresh
     * from the table.
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

        $product = Offer::prefix().$offer->handle;

        $paid = (int) self::paidLines()
            ->where('payment_items.product', $product)
            ->sum('payment_items.quantity');

        $reserved = (int) self::reservedLines()
            ->where('payment_items.product', $product)
            ->sum('payment_items.quantity');

        return $paid + $reserved;
    }

    /**
     * Net revenue in minor units, in the offer's currency.
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
     * @return array<string, int>|null currency => minor units, net
     */
    public static function revenueByCurrency(Offer $offer): ?array
    {
        if (! self::available()) {
            return null;
        }

        self::$karte ??= self::bauen();

        return self::$karte[$offer->handle]['revenue'] ?? [];
    }

    /** Paid units, from the per-request map; for listings, not for limits. */
    public static function soldForListing(Offer $offer): ?int
    {
        if (! self::available()) {
            return null;
        }

        self::$karte ??= self::bauen();

        return self::$karte[$offer->handle]['sold'] ?? 0;
    }

    /**
     * Paid plus reserved units, from the per-request map.
     *
     * What the listing's "available" column subtracts from the limit, so it
     * agrees with what a checkout would be told — one query for the whole
     * page instead of one per row.
     */
    public static function committedForListing(Offer $offer): ?int
    {
        if (! self::available()) {
            return null;
        }

        self::$karte ??= self::bauen();

        $row = self::$karte[$offer->handle] ?? null;

        return $row === null ? 0 : $row['sold'] + $row['reserved'];
    }

    /** Only for tests: forget what this request already read. */
    public static function forget(): void
    {
        self::$karte = null;
        self::$verfuegbar = null;
        self::$netto = null;
    }

    /**
     * One pass over every offer line at once, for the listing.
     *
     * Per line rather than grouped in SQL, because the refund share needs the
     * payment's own total as a divisor and integer division in SQL truncates
     * differently on every engine. The number of paid offer lines a site has
     * is the number of sales it made; that is a list, not a warehouse.
     *
     * @return array<string, array{sold: int, reserved: int, revenue: array<string, int>}>
     */
    private static function bauen(): array
    {
        $prefix = Offer::prefix();
        $like = addcslashes($prefix, '%_\\').'%';
        $netto = self::netColumnsAvailable();
        $karte = [];

        $columns = [
            'payment_items.product',
            'payment_items.quantity',
            'payment_items.amount_cent as line_cent',
            'payments.currency',
            'payments.amount_cent as total_cent',
        ];

        if ($netto) {
            $columns[] = 'payment_items.discount_cent';
            $columns[] = 'payments.refunded_cent';
        }

        foreach (self::paidLines()->where('payment_items.product', 'like', $like)->get($columns) as $row) {
            $handle = substr((string) $row->product, strlen($prefix));
            $currency = mb_strtoupper((string) $row->currency);

            $karte[$handle] ??= ['sold' => 0, 'reserved' => 0, 'revenue' => []];
            $karte[$handle]['sold'] += (int) $row->quantity;
            $karte[$handle]['revenue'][$currency] = ($karte[$handle]['revenue'][$currency] ?? 0) + self::netForLine($row, $netto);
        }

        $reserved = self::reservedLines()
            ->where('payment_items.product', 'like', $like)
            ->groupBy('payment_items.product')
            ->get(['payment_items.product', DB::raw('SUM(payment_items.quantity) as reserved')]);

        foreach ($reserved as $row) {
            $handle = substr((string) $row->product, strlen($prefix));

            $karte[$handle] ??= ['sold' => 0, 'reserved' => 0, 'revenue' => []];
            $karte[$handle]['reserved'] += (int) $row->reserved;
        }

        return $karte;
    }

    /**
     * What one paid line brought in, after its discount and its share of a
     * refund.
     *
     * The refund is apportioned by the line's net share of the payment total:
     * a 10 € refund on a 100 € payment takes 10 % off every line. Rounded
     * per line, so a report over lines and a report over payments can differ
     * by a cent — a cent, and never a whole line.
     */
    private static function netForLine(object $row, bool $netto): int
    {
        $net = (int) $row->line_cent * (int) $row->quantity;

        if (! $netto) {
            return $net;
        }

        $net = max(0, $net - (int) ($row->discount_cent ?? 0));
        $refunded = (int) ($row->refunded_cent ?? 0);
        $total = (int) $row->total_cent;

        if ($refunded <= 0 || $total <= 0) {
            return $net;
        }

        $share = (int) round($refunded * $net / $total);

        return max(0, $net - $share);
    }

    /**
     * Does this install's payments schema know line discounts and refunds?
     *
     * Checked once per request. Without the columns revenue stays gross, and
     * the log says so instead of the column quietly meaning something else.
     */
    private static function netColumnsAvailable(): bool
    {
        if (self::$netto !== null) {
            return self::$netto;
        }

        self::$netto = Schema::hasColumn('payment_items', 'discount_cent')
            && Schema::hasColumn('payments', 'refunded_cent');

        if (! self::$netto) {
            Log::notice('statamic-offers: payment_items.discount_cent or payments.refunded_cent is missing, so the revenue column is gross. Update statamic-payments and run its migrations.');
        }

        return self::$netto;
    }

    /** @return Builder */
    private static function paidLines()
    {
        return DB::table('payment_items')
            ->join('payments', 'payments.id', '=', 'payment_items.payment_id')
            ->where('payments.status', Payment::STATUS_PAID);
    }

    /**
     * Lines of checkouts that were started and not yet decided, young enough
     * to still be somebody typing a card number.
     *
     * @return Builder
     */
    private static function reservedLines()
    {
        return DB::table('payment_items')
            ->join('payments', 'payments.id', '=', 'payment_items.payment_id')
            ->whereIn('payments.status', [Payment::STATUS_INITIATED, Payment::STATUS_OPEN])
            ->where('payments.created_at', '>', Carbon::now()->subMinutes(self::RESERVATION_MINUTES));
    }
}
