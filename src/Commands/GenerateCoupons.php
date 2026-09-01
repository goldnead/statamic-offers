<?php

namespace Goldnead\StatamicOffers\Commands;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\CouponBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;
use Statamic\Console\RunsInPlease;

/**
 * The batch generator from the terminal, with the same options as the form.
 *
 * Prints the codes it made, one per line, so the output can be piped straight
 * into the sheet a partner gets.
 */
class GenerateCoupons extends Command
{
    use RunsInPlease;

    protected $signature = 'offers:coupons:generate
        {--count=10 : How many codes, 1 to 100}
        {--prefix= : Put in front of every code, up to 12 characters}
        {--length=8 : Length of the random part, 6 to 12}
        {--percent= : Discount in percent, 1 to 100 (either this or --amount)}
        {--amount= : Discount in minor units (either this or --percent)}
        {--currency= : Currency of a fixed amount, e.g. EUR}
        {--offer=* : Restrict to these offer handles; none means every offer}
        {--from= : Valid from this day (Y-m-d), in the app timezone}
        {--until= : Valid until the end of this day (Y-m-d), in the app timezone}
        {--max-uses=1 : Uses per code; 0 for no limit}
        {--name= : Name pattern; the placeholders n and code, each in curly braces, become the running number and the code}';

    protected $description = 'Generate a batch of coupon codes';

    public function __construct(protected CouponBatch $batch)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = (int) $this->option('count');
        $length = (int) $this->option('length');
        $prefix = (string) $this->option('prefix');

        if ($count < 1 || $count > CouponBatch::MAX_COUNT) {
            $this->components->error(sprintf('--count must be between 1 and %d.', CouponBatch::MAX_COUNT));

            return self::INVALID;
        }

        if ($length < CouponBatch::MIN_LENGTH || $length > CouponBatch::MAX_LENGTH) {
            $this->components->error(sprintf('--length must be between %d and %d.', CouponBatch::MIN_LENGTH, CouponBatch::MAX_LENGTH));

            return self::INVALID;
        }

        if (mb_strlen($prefix) > CouponBatch::MAX_PREFIX || preg_match('/\s/', $prefix)) {
            $this->components->error(sprintf('--prefix may have no whitespace and at most %d characters.', CouponBatch::MAX_PREFIX));

            return self::INVALID;
        }

        $offers = array_values(array_filter((array) $this->option('offer'), 'is_string'));
        $unknown = array_diff($offers, Offer::query()->whereIn('handle', $offers)->pluck('handle')->all());

        if ($unknown !== []) {
            $this->components->error('Unknown offer(s): '.implode(', ', $unknown));

            return self::INVALID;
        }

        $percent = $this->option('percent');
        $amount = $this->option('amount');
        $maxUses = (int) $this->option('max-uses');

        if ($percent !== null && $percent !== '' && ((int) $percent < 1 || (int) $percent > 100 || (string) (int) $percent !== (string) $percent)) {
            $this->components->error('--percent must be a whole number between 1 and 100.');

            return self::INVALID;
        }

        if ($amount !== null && $amount !== '' && ((int) $amount < 1 || (string) (int) $amount !== (string) $amount)) {
            $this->components->error('--amount must be a whole number of minor units, at least 1.');

            return self::INVALID;
        }

        try {
            $made = $this->batch->generate([
                'count' => $count,
                'prefix' => $prefix,
                'length' => $length,
                'percent' => $percent === null || $percent === '' ? null : (int) $percent,
                'amount_cent' => $amount === null || $amount === '' ? null : (int) $amount,
                'currency' => $this->option('currency') ?: null,
                'offers' => $offers,
                'starts_at' => $this->option('from') ? Carbon::parse((string) $this->option('from'))->startOfDay() : null,
                'ends_at' => $this->option('until') ? Carbon::parse((string) $this->option('until'))->endOfDay() : null,
                'max_uses' => $maxUses > 0 ? $maxUses : null,
                'name' => $this->option('name'),
            ]);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($made as $coupon) {
            $this->line($coupon->code);
        }

        $this->components->info(sprintf('%d coupon(s) generated. Times are in %s.', $made->count(), (string) config('app.timezone', 'UTC')));

        return self::SUCCESS;
    }
}
