<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * Both screens hang off `Utility::register()`, so their nav entries appear as
 * soon as composer has put the package there — while the migrations are still
 * a separate, manual step. In that window both answered HTTP 500. These tests
 * reproduce that database — everything present except this addon's own tables —
 * and hold the pages to an empty state plus a line in the log.
 */
class SetupGuardTest extends TestCase
{
    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('setup@example.com')->makeSuper())->save();
    }

    /** @var list<string> */
    private const TABLES = ['offer_coupons', 'offers'];

    /**
     * The un-migrated database, without losing it for good.
     *
     * `loadMigrationsFrom()` rolls the addon's migrations back when the
     * application is torn down, and a `down()` that alters a table somebody
     * dropped fails — the run would end in seven errors on top of green
     * assertions. Renaming is what the guard actually asks about
     * (`Schema::hasTable()` says no), and `tearDown()` puts it back.
     */
    private function dropAddonTables(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                Schema::rename($table, 'hidden_'.$table);
            }
        }
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable('hidden_'.$table)) {
                Schema::rename('hidden_'.$table, $table);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function the_offers_index_answers_200_when_its_table_is_missing(): void
    {
        $this->dropAddonTables();

        $this->actingAs($this->user())
            ->get(cp_route('utilities.offers'))
            ->assertOk();
    }

    #[Test]
    public function the_offers_index_renders_the_setup_screen_and_names_the_missing_table(): void
    {
        $this->dropAddonTables();

        $page = $this->actingAs($this->user())
            ->get(cp_route('utilities.offers'))
            ->assertOk()
            ->viewData('page');

        $this->assertSame('statamic-offers::SetupRequired', $page['component']);
        $this->assertContains('offers', $page['props']['tables']);
        $this->assertNotEmpty($page['props']['heading']);
        $this->assertNotEmpty($page['props']['description']);
    }

    /**
     * The point of the guard is a readable page, not a quiet one. If this test
     * ever goes red the addon has traded a visible 500 for a silent nothing.
     */
    #[Test]
    public function the_reason_reaches_the_log(): void
    {
        $this->dropAddonTables();

        Log::spy();

        $this->actingAs($this->user())
            ->get(cp_route('utilities.offers'))
            ->assertOk();

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'statamic-offers')
                && str_contains($message, 'php artisan migrate'))
            ->once();
    }

    #[Test]
    public function the_coupons_index_answers_200_when_its_tables_are_missing(): void
    {
        $this->dropAddonTables();

        $this->actingAs($this->user())
            ->get(cp_route('utilities.coupons'))
            ->assertOk();
    }

    /**
     * The offer picker on the coupon screen reads the offers table, so a
     * coupons table on its own is not enough to render the page.
     */
    #[Test]
    public function the_coupons_index_names_both_of_its_tables(): void
    {
        $this->dropAddonTables();

        $page = $this->actingAs($this->user())
            ->get(cp_route('utilities.coupons'))
            ->assertOk()
            ->viewData('page');

        $this->assertSame('statamic-offers::SetupRequired', $page['component']);
        $this->assertContains('offer_coupons', $page['props']['tables']);
        $this->assertContains('offers', $page['props']['tables']);
    }

    #[Test]
    public function the_coupons_reason_reaches_the_log(): void
    {
        $this->dropAddonTables();

        Log::spy();

        $this->actingAs($this->user())
            ->get(cp_route('utilities.coupons'))
            ->assertOk();

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'statamic-offers')
                && str_contains($message, 'php artisan migrate'))
            ->once();
    }

    /**
     * Each listing fetches its rows over XHR against the same action. Guarding
     * only the Inertia branch would leave those requests answering 500 behind a
     * page that looked fine.
     */
    #[Test]
    public function the_listing_xhr_is_guarded_on_both_screens(): void
    {
        $this->dropAddonTables();

        foreach ([cp_route('utilities.offers'), cp_route('utilities.coupons')] as $url) {
            $this->actingAs($this->user())->getJson($url)->assertOk();
        }
    }

    #[Test]
    public function a_migrated_install_still_renders_both_listings(): void
    {
        $offers = $this->actingAs($this->user())->get(cp_route('utilities.offers'))->assertOk();
        $coupons = $this->actingAs($this->user())->get(cp_route('utilities.coupons'))->assertOk();

        $this->assertSame('statamic-offers::Offers/Index', $offers->viewData('page')['component']);
        $this->assertSame('statamic-offers::Coupons/Index', $coupons->viewData('page')['component']);
    }
}
