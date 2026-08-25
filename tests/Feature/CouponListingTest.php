<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\CouponQuery;
use Goldnead\StatamicOffers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * The listing endpoint behind the coupon screen.
 *
 * Core's `<Listing>` is not a table this addon draws; it is a component that
 * asks questions and believes the answers. These tests are the answers: the
 * column set on every response, the filters as real query scopes, and a page
 * count that means something.
 */
class CouponListingTest extends TestCase
{
    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    protected function coupon(string $code, array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => $code,
            'percent' => 10,
            'active' => true,
        ], $overrides));
    }

    protected function filtered(array $filters): string
    {
        return '/cp/utilities/coupons?filters='.base64_encode(json_encode($filters));
    }

    #[Test]
    public function every_response_carries_the_columns(): void
    {
        $this->coupon('EINS');

        $response = $this->actingAs($this->user())->getJson('/cp/utilities/coupons')->assertOk();

        // Read out of *every* response by the Listing. Missing, the read throws
        // inside its own promise and the screen shows "Something went wrong"
        // over a page where everything works.
        $fields = array_column($response->json('meta.columns'), 'field');

        $this->assertSame(['code', 'name', 'discount', 'validity', 'usage', 'active'], $fields);
    }

    #[Test]
    public function a_row_arrives_with_its_words_already_written(): void
    {
        $this->coupon('PROZENT', ['percent' => 20, 'max_uses' => 100, 'used_count' => 3]);

        $row = $this->actingAs($this->user())->getJson('/cp/utilities/coupons')->json('data.0');

        $this->assertSame('20 %', $row['discount']);
        $this->assertSame('3 / 100', $row['usage']);
        $this->assertTrue($row['live']);
        $this->assertNull($row['note']);
    }

    #[Test]
    public function a_coupon_without_a_limit_shows_a_bare_count(): void
    {
        $this->coupon('OHNE', ['used_count' => 7]);

        $row = $this->actingAs($this->user())->getJson('/cp/utilities/coupons')->json('data.0');

        $this->assertSame('7', $row['usage']);
    }

    #[Test]
    public function a_coupon_that_is_switched_on_but_not_working_says_why(): void
    {
        $this->coupon('ABGELAUFEN', ['ends_at' => now()->subDay()]);

        $row = $this->actingAs($this->user())->getJson('/cp/utilities/coupons')->json('data.0');

        // "Active: yes" next to a code the checkout refuses is the question
        // that otherwise arrives by email.
        $this->assertFalse($row['live']);
        $this->assertNotNull($row['note']);
    }

    #[Test]
    public function the_active_filter_narrows_the_query(): void
    {
        $this->coupon('AN');
        $this->coupon('AUS', ['active' => false]);

        $response = $this->actingAs($this->user())
            ->getJson($this->filtered(['statamic_offers_coupon_active' => ['value' => 'no']]))
            ->assertOk();

        $this->assertSame(['AUS'], array_column($response->json('data'), 'code'));
        // The pager counts what the filter left, which is only true when the
        // filter is a `where` rather than a `->filter()` on the collection.
        $this->assertSame(1, $response->json('meta.total'));
    }

    #[Test]
    public function the_live_filter_is_the_whole_of_is_live(): void
    {
        $live = $this->coupon('LEBT');
        $this->coupon('AUS', ['active' => false]);
        $this->coupon('SPAETER', ['starts_at' => now()->addWeek()]);
        $this->coupon('VORBEI', ['ends_at' => now()->subDay()]);
        $this->coupon('LEER', ['used_count' => 5, 'max_uses' => 5]);
        $this->coupon('WERTLOS', ['percent' => null]);

        $response = $this->actingAs($this->user())
            ->getJson($this->filtered(['statamic_offers_coupon_live' => ['value' => 'yes']]))
            ->assertOk();

        $this->assertSame(['LEBT'], array_column($response->json('data'), 'code'));
        $this->assertTrue($live->fresh()->isLive());

        // And the opposite is every other row, not the negation of one clause.
        $notLive = $this->actingAs($this->user())
            ->getJson($this->filtered(['statamic_offers_coupon_live' => ['value' => 'no']]))
            ->assertOk();

        $this->assertSame(
            ['AUS', 'LEER', 'SPAETER', 'VORBEI', 'WERTLOS'],
            array_column($notLive->json('data'), 'code')
        );
    }

    #[Test]
    public function the_two_filters_agree_with_the_model_row_by_row(): void
    {
        $this->coupon('LEBT');
        $this->coupon('AUS', ['active' => false]);
        $this->coupon('SPAETER', ['starts_at' => now()->addWeek()]);
        $this->coupon('VORBEI', ['ends_at' => now()->subDay()]);
        $this->coupon('LEER', ['used_count' => 5, 'max_uses' => 5]);

        // The filter is `isLive()` said in SQL. If the two ever drift, the
        // screen and the checkout disagree about the same code.
        $viaSql = Coupon::query()->tap(fn ($q) => CouponQuery::live($q))->pluck('code')->sort()->values()->all();
        $viaModel = Coupon::all()->filter->isLive()->pluck('code')->sort()->values()->all();

        $this->assertSame($viaModel, $viaSql);
    }

    #[Test]
    public function money_is_written_the_way_the_language_writes_it(): void
    {
        $this->coupon('FEST', ['percent' => null, 'amount_cent' => 500, 'currency' => 'EUR']);
        Offer::create(['handle' => 'haupt', 'name' => 'Haupt', 'product' => 'noten-paket', 'amount_cent' => 500, 'slot' => Offer::SLOT_STANDALONE, 'active' => true]);

        $this->app->setLocale('de');

        // The two screens of this addon have to agree with each other and with
        // the Control Panel around them. "5.00" next to "5,00" in a German CP
        // reads as two products rather than two screens.
        $coupon = $this->actingAs($this->user())->getJson('/cp/utilities/coupons')->json('data.0');
        $offer = $this->actingAs($this->user())->getJson('/cp/utilities/offers')->json('data.0');

        $this->assertSame('5,00 EUR', $coupon['discount']);
        $this->assertSame('5,00', $offer['amount']);
    }

    #[Test]
    public function search_finds_a_code_and_a_name(): void
    {
        $this->coupon('FRUEHLING', ['name' => 'Frühlingsaktion']);
        $this->coupon('HERBST', ['name' => 'Herbstaktion']);

        $byCode = $this->actingAs($this->user())->getJson('/cp/utilities/coupons?search=FRUEH')->json('data');
        $this->assertSame(['FRUEHLING'], array_column($byCode, 'code'));

        $byName = $this->actingAs($this->user())->getJson('/cp/utilities/coupons?search=Herbst')->json('data');
        $this->assertSame(['HERBST'], array_column($byName, 'code'));
    }

    #[Test]
    public function a_wildcard_in_the_search_box_is_a_character_not_a_pattern(): void
    {
        $this->coupon('EINS');
        $this->coupon('ZWEI');

        // Unescaped, `%` matches everything and the search box silently stops
        // narrowing anything.
        $data = $this->actingAs($this->user())->getJson('/cp/utilities/coupons?search=%25')->json('data');

        $this->assertSame([], $data);
    }

    #[Test]
    public function sorting_only_accepts_columns_the_screen_has(): void
    {
        $this->coupon('A', ['used_count' => 9]);
        $this->coupon('B', ['used_count' => 1]);

        // `sort` comes off the query string and would otherwise order by any
        // column in the table, including ones the screen never shows. Asked to
        // sort by `used_count` the endpoint falls back to the code, so the
        // busiest coupon does *not* come first.
        $data = $this->actingAs($this->user())->getJson('/cp/utilities/coupons?sort=used_count&order=desc')->json('data');

        $this->assertSame(['B', 'A'], array_column($data, 'code'));
    }
}
