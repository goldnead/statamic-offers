<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Commands\GenerateCoupons;
use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\CouponBatch;
use Goldnead\StatamicOffers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * Many codes at once — and, when that cannot be done, none.
 */
class CouponBatchTest extends TestCase
{
    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    protected function userWithoutPermission()
    {
        $role = tap(Role::make('nur-cp')->addPermission('access cp'))->save();

        return tap(User::make()->email('ohne@example.com')->assignRole($role))->save();
    }

    #[Test]
    public function it_makes_n_unique_codes_from_the_safe_alphabet(): void
    {
        $made = (new CouponBatch)->generate(['count' => 100, 'prefix' => 'chor-', 'length' => 8, 'percent' => 10]);

        $this->assertCount(100, $made);
        $this->assertSame(100, Coupon::count());

        $codes = $made->pluck('code');
        $this->assertSame(100, $codes->unique()->count());

        foreach ($codes as $code) {
            $this->assertStringStartsWith('CHOR-', $code);
            $this->assertSame(13, strlen($code));
            // Nothing that reads as something else: no 0/O, no 1/I/l.
            $this->assertMatchesRegularExpression('/^CHOR-['.CouponBatch::ALPHABET.']{8}$/', $code);
        }

        // Single use each, unless asked otherwise.
        $this->assertSame(1, $made->first()->max_uses);
        $this->assertTrue($made->first()->isLive());
    }

    #[Test]
    public function a_collision_is_retried_and_ten_misses_abort_the_whole_batch(): void
    {
        Coupon::create(['code' => 'FIXED', 'percent' => 5, 'active' => true]);

        // A generator that can only ever produce the code that is taken.
        $stuck = new CouponBatch(fn (int $length): string => 'FIXED');

        try {
            $stuck->generate(['count' => 3, 'length' => 6, 'percent' => 10]);
            $this->fail('A batch that cannot find a free code must throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('10', $e->getMessage());
        }

        // Nothing but the pre-existing row: no partial batch.
        $this->assertSame(1, Coupon::count());
    }

    #[Test]
    public function a_collision_with_itself_within_the_batch_is_retried_too(): void
    {
        $sequence = ['AAAAAA', 'AAAAAA', 'BBBBBB'];
        $i = 0;

        $batch = new CouponBatch(function () use (&$sequence, &$i): string {
            return $sequence[$i++] ?? 'CCCCCC';
        });

        $made = $batch->generate(['count' => 2, 'percent' => 10]);

        $this->assertSame(['AAAAAA', 'BBBBBB'], $made->pluck('code')->all());
    }

    #[Test]
    public function the_time_window_the_offers_and_the_name_pattern_are_stored(): void
    {
        Offer::create(['handle' => 'kurs', 'name' => 'Kurs', 'product' => 'noten-paket', 'slot' => 'standalone']);

        $made = (new CouponBatch)->generate([
            'count' => 2,
            'amount_cent' => 500,
            'currency' => 'eur',
            'offers' => ['kurs'],
            'starts_at' => now()->parse('2027-03-01')->startOfDay(),
            'ends_at' => now()->parse('2027-03-31')->endOfDay(),
            'max_uses' => 3,
            'name' => 'Frühling #{n}',
        ]);

        $first = $made->first()->fresh();

        $this->assertSame('Frühling #1', $first->name);
        $this->assertSame('Frühling #2', $made->last()->fresh()->name);
        $this->assertSame(500, $first->amount_cent);
        $this->assertSame('EUR', $first->currency);
        $this->assertSame(['kurs'], $first->offers);
        $this->assertSame('2027-03-01 00:00:00', $first->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2027-03-31 23:59:59', $first->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame(3, $first->max_uses);
    }

    #[Test]
    public function it_refuses_both_or_neither_discount(): void
    {
        $this->expectException(RuntimeException::class);

        (new CouponBatch)->generate(['count' => 2]);
    }

    #[Test]
    public function the_control_panel_route_is_forbidden_without_the_permission(): void
    {
        $this->actingAs($this->userWithoutPermission())
            ->postJson('/cp/utilities/coupons/generate', ['count' => 5, 'percent' => 10])
            ->assertForbidden();

        $this->assertSame(0, Coupon::count());
    }

    #[Test]
    public function the_control_panel_generates_a_batch(): void
    {
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/coupons/generate', [
                'count' => 5,
                'prefix' => 'SOMMER',
                'length' => 6,
                'percent' => 15,
                'starts_at' => '2027-06-01',
                'ends_at' => '2027-06-30',
                'max_uses' => 1,
            ])
            ->assertRedirect();

        $this->assertSame(5, Coupon::count());
        $this->assertSame(5, Coupon::query()->where('code', 'like', 'SOMMER%')->count());
        $this->assertSame('2027-06-30 23:59:59', Coupon::query()->first()->ends_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_control_panel_validates_the_shape(): void
    {
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/coupons/generate', [
                'count' => 101,
                'prefix' => 'MIT LEERZEICHEN',
                'length' => 3,
                'percent' => 10,
                'amount_cent' => 100,
            ])
            ->assertJsonValidationErrors(['count', 'prefix', 'length', 'percent', 'amount_cent']);

        $this->assertSame(0, Coupon::count());
    }

    #[Test]
    public function the_artisan_command_makes_the_same_batch(): void
    {
        $this->artisan(GenerateCoupons::class, [
            '--count' => 4,
            '--prefix' => 'CLI',
            '--length' => 6,
            '--amount' => 300,
            '--currency' => 'EUR',
            '--from' => '2027-01-01',
            '--until' => '2027-01-31',
            '--name' => 'CLI {n}',
        ])->assertExitCode(0);

        $this->assertSame(4, Coupon::count());

        $coupon = Coupon::query()->orderBy('id')->first();
        $this->assertStringStartsWith('CLI', $coupon->code);
        $this->assertSame('CLI 1', $coupon->name);
        $this->assertSame(300, $coupon->amount_cent);
        $this->assertSame(1, $coupon->max_uses);
        $this->assertSame('2027-01-31 23:59:59', $coupon->ends_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_artisan_command_refuses_an_unknown_offer_and_a_bad_count(): void
    {
        $this->artisan(GenerateCoupons::class, ['--count' => 0, '--percent' => 10])->assertExitCode(2);
        $this->artisan(GenerateCoupons::class, ['--count' => 2, '--percent' => 10, '--offer' => ['gibt-es-nicht']])->assertExitCode(2);

        // The percentage is bounded the same way the form bounds it.
        $this->artisan(GenerateCoupons::class, ['--count' => 2, '--percent' => 0])->assertExitCode(2);
        $this->artisan(GenerateCoupons::class, ['--count' => 2, '--percent' => 150])->assertExitCode(2);
        $this->artisan(GenerateCoupons::class, ['--count' => 2, '--percent' => '12.5'])->assertExitCode(2);
        $this->artisan(GenerateCoupons::class, ['--count' => 2, '--amount' => 0])->assertExitCode(2);

        $this->assertSame(0, Coupon::count());
    }
}
