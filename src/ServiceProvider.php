<?php

namespace Goldnead\StatamicOffers;

use Goldnead\StatamicOffers\Actions\ActivateCoupon;
use Goldnead\StatamicOffers\Actions\DeactivateCoupon;
use Goldnead\StatamicOffers\Http\Controllers\Cp\CouponActionsController;
use Goldnead\StatamicOffers\Http\Controllers\Cp\CouponsController;
use Goldnead\StatamicOffers\Http\Controllers\Cp\OffersController;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Query\Scopes\Filters\CouponActive;
use Goldnead\StatamicOffers\Query\Scopes\Filters\CouponLive;
use Goldnead\StatamicPayments\Support\Catalogue;
use Statamic\Actions\Action;
use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Query\Scopes\Scope;

class ServiceProvider extends AddonServiceProvider
{
    protected $viewNamespace = 'statamic-offers';

    /**
     * Listed rather than left to the folder scan: autoloading resolves the
     * addon through the manifest, which is exactly what is missing in a package
     * test suite. A filter that is not registered does not fail loudly — it
     * simply never appears on the screen.
     *
     * @var list<class-string<Scope>>
     */
    protected $scopes = [
        CouponActive::class,
        CouponLive::class,
    ];

    /**
     * @var list<class-string<Action>>
     */
    protected $actions = [
        ActivateCoupon::class,
        DeactivateCoupon::class,
    ];

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

        // Registered here rather than in `bootAddon()`, which only runs when
        // the addon is discovered through the manifest. Without it an offer
        // resolves to nothing and simply cannot be bought — a failure that
        // looks like a missing product rather than a missing registration.
        $this->bootCatalogue();
    }

    public function bootAddon()
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'statamic-offers');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->bootUtilities();

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
        // Handles currently being resolved. An offer whose *product* is another
        // offer would otherwise ask the catalogue, which asks this resolver,
        // which asks the offer — until the process runs out of memory. It is
        // reachable by a hand-made POST, by an import, by a seed; and once such
        // a row exists, the listing you would delete it from dies too, because
        // every row asks whether it is sellable.
        $resolving = [];

        Catalogue::extend(function (string $handle) use (&$resolving): ?array {
            $prefix = Offer::prefix();

            if (! str_starts_with($handle, $prefix)) {
                return null;
            }

            if (isset($resolving[$handle])) {
                return null;
            }

            $resolving[$handle] = true;

            try {
                return $this->resolveOffer($handle, $prefix);
            } finally {
                unset($resolving[$handle]);
            }
        });

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveOffer(string $handle, string $prefix): ?array
    {

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
    }

    protected function bootUtilities(): self
    {
        // Inside `Utility::extend`, not straight in boot: `__()` during boot
        // resolves before core's `Localize` middleware has set the user's
        // language, and the nav entry would freeze in the application locale.
        Utility::extend(function () {
            $this->registerOffersUtility();
            $this->registerCouponsUtility();
        });

        return $this;
    }

    /**
     * Coupons get their own utility, which is what buys the nav entry, the
     * `access coupons utility` permission and the `can:` middleware on every
     * route below it. A second screen hung off the offers utility would have
     * inherited the offers permission, and "may edit the words on an upsell"
     * is not the same authority as "may hand out discounts".
     */
    protected function registerCouponsUtility(): void
    {
        Utility::register('coupons')
            ->action([CouponsController::class, 'index'])
            ->title(__('statamic-offers::messages.coupons_utility_title'))
            ->navTitle(__('statamic-offers::messages.coupons_utility_nav'))
            ->icon('shopping-store-discount-percent')
            ->description(__('statamic-offers::messages.coupons_utility_description'))
            ->docsUrl('https://github.com/goldnead/statamic-offers#readme')
            ->routes(function ($router) {
                // Before the `{coupon}` routes, or "actions" is read as a
                // coupon id on the way in.
                $router->post('actions', [CouponActionsController::class, 'run'])->name('actions');
                $router->post('actions/list', [CouponActionsController::class, 'bulkActions'])->name('actions.list');
                $router->post('/', [CouponsController::class, 'store'])->name('store');
                $router->patch('{coupon}', [CouponsController::class, 'update'])->name('update');
                $router->delete('{coupon}', [CouponsController::class, 'destroy'])->name('destroy');
            });
    }

    protected function registerOffersUtility(): void
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
