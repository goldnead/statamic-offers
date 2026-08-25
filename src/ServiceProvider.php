<?php

namespace Goldnead\StatamicOffers;

use Goldnead\StatamicOffers\Http\Controllers\Cp\OffersController;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Support\Catalogue;
use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $viewNamespace = 'statamic-offers';

    /**
     * @var array<string, mixed>
     */
    protected $vite = [
        'hotFile' => __DIR__.'/../dist/hot',
        'publicDirectory' => 'dist',
        'input' => ['resources/js/cp.js', 'resources/css/cp.css'],
    ];

    /**
     * The parent boots config off the addon directory, which is resolved
     * through the manifest and comes up empty in package test suites.
     */
    protected $config = false;

    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/statamic-offers.php', 'statamic-offers');
    }

    public function bootAddon()
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'statamic-offers');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->bootCatalogue()->bootUtility();

        $this->publishes([
            __DIR__.'/../config/statamic-offers.php' => config_path('statamic-offers.php'),
        ], 'statamic-offers-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'statamic-offers-migrations');
    }

    /**
     * Teach the payment catalogue about offers.
     *
     * `offer:fruehling-upsell` then resolves to a priced thing like any product
     * does, so the checkout, the follow-up charge and every guard around them
     * keep working untouched — and the price still never comes from a request,
     * which is the whole reason this indirection exists instead of a parameter.
     */
    protected function bootCatalogue(): self
    {
        Catalogue::extend(function (string $handle): ?array {
            $prefix = (string) config('statamic-offers.handle_prefix', 'offer:');

            if (! str_starts_with($handle, $prefix)) {
                return null;
            }

            $offer = Offer::query()
                ->where('handle', substr($handle, strlen($prefix)))
                ->first();

            if (! $offer || ! $offer->isSellable()) {
                return null;
            }

            return [
                'name' => $offer->name,
                'amount_cent' => $offer->amountCent(),
                'currency' => $offer->currency(),
                // What the payment line will remember it was sold as. An offer
                // renamed next year must not rewrite an old order.
                'offer' => $offer->handle,
            ];
        });

        return $this;
    }

    protected function bootUtility(): self
    {
        // Inside `Utility::extend`, not straight in boot: `__()` during boot
        // resolves before core's `Localize` middleware has set the user's
        // language, and the nav entry would freeze in the application locale.
        Utility::extend(fn () => $this->registerUtility());

        return $this;
    }

    protected function registerUtility(): void
    {
        Utility::register('offers')
            ->action([OffersController::class, 'index'])
            ->title(__('statamic-offers::messages.utility_title'))
            ->navTitle(__('statamic-offers::messages.utility_nav'))
            ->icon('money-cashier-price-tag')
            ->description(__('statamic-offers::messages.utility_description'))
            ->docsUrl('https://github.com/goldnead/statamic-offers#readme')
            ->routes(function ($router) {
                $router->post('/', [OffersController::class, 'store'])->name('store');
                $router->patch('{offer}', [OffersController::class, 'update'])->name('update');
                $router->delete('{offer}', [OffersController::class, 'destroy'])->name('destroy');
            });
    }
}
