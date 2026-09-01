<?php

namespace Goldnead\StatamicOffers\Support;

use Closure;
use Goldnead\StatamicOffers\Models\Coupon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Many codes at once.
 *
 * A campaign hands out a hundred single-use codes; typing them one by one into
 * the form is how somebody ends up with ninety-eight. This makes them in one
 * transaction: either every code exists afterwards or none does, because a
 * partial batch is the one outcome nobody can act on — the sheet handed to the
 * partner says a hundred, and nobody knows which two are missing.
 *
 * **The alphabet leaves out what people misread.** No `0`/`O`, no `1`/`I`/`l`.
 * Codes are read off a slide and typed on a phone; a code that can be
 * mistyped by an attentive person is a support ticket, not a discount.
 */
class CouponBatch
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const MAX_COUNT = 100;

    public const MAX_PREFIX = 12;

    public const MIN_LENGTH = 6;

    public const MAX_LENGTH = 12;

    public const DEFAULT_LENGTH = 8;

    /** How often one slot may collide before the whole batch is given up. */
    public const MAX_ATTEMPTS = 10;

    /**
     * @param  (Closure(int $length): string)|null  $random  The random part of a code. Only ever replaced by a test.
     */
    public function __construct(protected ?Closure $random = null) {}

    /**
     * @param array{
     *     count: int,
     *     prefix?: string|null,
     *     length?: int|null,
     *     percent?: int|null,
     *     amount_cent?: int|null,
     *     currency?: string|null,
     *     offers?: list<string>|null,
     *     starts_at?: Carbon|null,
     *     ends_at?: Carbon|null,
     *     max_uses?: int|null,
     *     name?: string|null,
     *     active?: bool,
     * } $options
     * @return Collection<int, Coupon>
     *
     * @throws RuntimeException when a slot could not find a free code in {@see self::MAX_ATTEMPTS} tries
     */
    public function generate(array $options): Collection
    {
        $count = max(1, min(self::MAX_COUNT, (int) $options['count']));
        $prefix = mb_strtoupper(trim((string) ($options['prefix'] ?? '')));
        $length = max(self::MIN_LENGTH, min(self::MAX_LENGTH, (int) ($options['length'] ?? self::DEFAULT_LENGTH)));

        $hasPercent = ($options['percent'] ?? null) !== null;
        $hasAmount = ($options['amount_cent'] ?? null) !== null;

        if ($hasPercent === $hasAmount) {
            throw new RuntimeException(__('statamic-offers::messages.coupon_discount_exactly_one'));
        }

        $namePattern = trim((string) ($options['name'] ?? ''));

        return DB::transaction(function () use ($count, $prefix, $length, $options, $namePattern) {
            $made = collect();
            $taken = [];

            for ($n = 1; $n <= $count; $n++) {
                $code = $this->freeCode($prefix, $length, $taken);
                $taken[mb_strtolower($code)] = true;

                $made->push(Coupon::create([
                    'code' => $code,
                    'name' => $namePattern === '' ? null : str_replace(['{n}', '{code}'], [(string) $n, $code], $namePattern),
                    'percent' => $options['percent'] ?? null,
                    'amount_cent' => $options['amount_cent'] ?? null,
                    'currency' => ($options['currency'] ?? null) !== null ? mb_strtoupper((string) $options['currency']) : null,
                    'offers' => array_values(array_unique(array_filter((array) ($options['offers'] ?? []), 'is_string'))),
                    'starts_at' => $options['starts_at'] ?? null,
                    'ends_at' => $options['ends_at'] ?? null,
                    // One use per code is what a batch is for. Unlimited is
                    // available, but it has to be asked for.
                    'max_uses' => array_key_exists('max_uses', $options) ? $options['max_uses'] : 1,
                    'active' => (bool) ($options['active'] ?? true),
                ]));
            }

            return $made;
        });
    }

    /**
     * A code nobody has yet, or an exception.
     *
     * Checked against the table the way the checkout looks codes up
     * (case-insensitively, through `findByCode()`), and against the codes this
     * very batch has already made but not yet committed. Ten misses in a row
     * on a random string of six or more characters means the space is nearly
     * full — or the prefix plus length was chosen far too short — and either
     * way the honest answer is an error, not ninety-three codes.
     *
     * @param  array<string, true>  $taken
     */
    protected function freeCode(string $prefix, int $length, array $taken): string
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $code = $prefix.$this->randomPart($length);

            if (isset($taken[mb_strtolower($code)]) || Coupon::findByCode($code) !== null) {
                continue;
            }

            return $code;
        }

        // Thrown inside the transaction, so everything made so far is undone.
        throw new RuntimeException(__('statamic-offers::messages.coupon_batch_collisions', ['attempts' => self::MAX_ATTEMPTS]));
    }

    protected function randomPart(int $length): string
    {
        if ($this->random !== null) {
            return ($this->random)($length);
        }

        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }
}
