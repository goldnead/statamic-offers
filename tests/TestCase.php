<?php

namespace Goldnead\StatamicOffers\Tests;

use Goldnead\StatamicOffers\ServiceProvider;
use Goldnead\StatamicOffers\Support\OfferSales;
use Goldnead\StatamicOffers\Tests\Support\FakeGateway;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Support\Catalogue;
use Illuminate\Support\Facades\Route;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected FakeGateway $gateway;

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [
            \Goldnead\StatamicPayments\ServiceProvider::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/goldnead/statamic-payments/database/migrations');

        // The fake is the point, not a convenience: a test that needs the
        // network is a test that gets skipped.
        $this->gateway = new FakeGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    /**
     * `AddonTestCase` boots the addon under test; the sibling it depends on is
     * a plain package here, so its route is not registered and the checkout
     * cannot build the webhook URL it hands the provider. Only the name is
     * needed — nothing in these tests posts to it.
     */
    protected function defineRoutes($router): void
    {
        $router->post('/!/statamic-payments/webhook', fn () => response()->json(['received' => true]))
            ->name('statamic-payments.webhook');
    }

    protected function tearDown(): void
    {
        // Resolvers are static, so one test's catalogue would otherwise still
        // be answering in the next.
        Catalogue::forgetResolvers();

        // Same for the per-request sales map: one process, many "requests".
        OfferSales::forget();

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('statamic.system.multisite', false);
        $app['config']->set('statamic-payments.products', [
            'noten-paket' => ['name' => 'Notenpaket', 'amount_cent' => 2900],
            'begleit-cd' => ['name' => 'Begleit-CD', 'amount_cent' => 1200],
        ]);
    }
}
