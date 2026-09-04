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
use Goldnead\StatamicOffers\Query\Scopes\Filters\OfferSlot;
use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;
use Goldnead\StatamicPayments\Support\Catalogue;
use Illuminate\Support\Facades\Log;
use Goldnead\StatamicPayments\Cp\SuiteNav;
use Statamic\Actions\Action;
use Statamic\Facades\CP\Nav;
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
        OfferSlot::class,
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
        $this->bootNavigation();

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

        // The product underneath, with the offer's own overrides on top.
        //
        // An offer is "a product, presented" — the class docblock says so, and
        // amountCent()/currency() already fall through to the product when the
        // offer has no opinion. Everything else about the thing being sold
        // belongs to the product and always did: whether it is digital, which
        // tax class applies, what access it grants.
        //
        // Returning only name and price is what broke the family. `digital`
        // and the tax class live on the product, so an invoice for an offer
        // purchase could not be written at all; `grants` lives there too, so
        // nobody who bought through an offer received the access they paid
        // for. Both failed silently — one produced no document, the other no
        // entitlement, and neither said anything.
        //
        // Merged in this order on purpose: the offer wins where it has an
        // opinion (its own name, its own discounted price), and inherits
        // everything it has none about. Inventing tax facts for an offer is
        // exactly what must not happen, and inheriting them from the product
        // the offer points at is the only answer that cannot be a guess.
        $product = (array) (app(Catalogue::class)->find($offer->product) ?? []);

        // `handle` would name the underlying product, and this line is the
        // offer. Dropped rather than overwritten so that Catalogue::find()
        // sets it, once, from the handle it was asked about.
        unset($product['handle']);

        // The subscription keys are NOT inherited, and that is a decision
        // rather than an oversight.
        //
        // Inheriting them was the accidental effect of merging the whole
        // product array, and it would have decided something about money that
        // nobody asked: `Subscriptions::start('offer:x')` was a guaranteed
        // no-op before (no `interval` in the resolver's answer) and would
        // suddenly open a running subscription whose recurring amount is the
        // *offer* price — the discount, forever. `trial_amount_cent` would
        // ride along unconverted on top of that, and silently vanish once it
        // reached the offer's price.
        //
        // "An upsell at €12 for a €29 product" says nothing about what the
        // second month costs. Until somebody decides that, an offer stays a
        // one-off, which is exactly what it was before this method learned to
        // inherit.
        unset($product['interval'], $product['times'], $product['trial_days'], $product['trial_amount_cent']);

        // Ein Buendel gibt her, was alle seine Teile hergeben.
        //
        // **`grants` ist die Vereinigung, und das ist der Punkt eines
        // Buendels.** Wer drei Kurse in einem Kauf bezahlt, bekommt drei
        // Zugaenge. `statamic-payments` nimmt dafuer eine Liste; eine aeltere
        // Fassung daneben laesst sie an `is_string()` fallen und vergibt dann
        // gar nichts — deshalb steht die Mindestversion in der composer.json.
        //
        // **`digital` muss ueber alle Teile dasselbe sagen, sonst gibt es das
        // Buendel nicht.** Es ist keine Beschreibung des Mediums, sondern die
        // Angabe, die ueber den Leistungsort und damit ueber den Pflichthinweis
        // auf der Rechnung entscheidet (§ 3a UStG). Eine Zeile, die zur Haelfte
        // elektronisch erbracht ist, hat keinen richtigen Hinweis, und einen
        // davon zu waehlen hiesse, eine Steuerfrage zu raten. Also lieber nicht
        // verkaufbar: `null` heisst hier, `Checkout::start()` verweigert den
        // ganzen Vorgang — laut, und bevor Geld fliesst.
        //
        // Links, damit die Buendel-Fakten die des Leitprodukts schlagen: sonst
        // stuende dessen einzelnes `grants` weiter da und die uebrigen Teile
        // waeren bezahlt und nicht freigeschaltet.
        if ($offer->isBundle()) {
            $gebuendelt = $this->bundleFacts($offer);

            if ($gebuendelt === null) {
                return null;
            }

            $product = $gebuendelt + $product;

            // Und die Teile selbst, benannt.
            //
            // `product` nennt nur das Leitprodukt — das ist richtig fuer die
            // Steuerklasse der Zeile und falsch fuer jeden, der wissen muss,
            // *was* geliefert wurde. Ohne diesen Schluessel muesste ein
            // Geschwister die Angebotstabelle selbst abfragen, um vom Handle
            // auf die Teile zu kommen, und genau solche Abkuerzungen an der
            // Naht vorbei haben in dieser Familie schon zweimal still nichts
            // ausgeliefert.
            $product['products'] = $offer->productHandles();
        }

        // The offer's own values on the LEFT: `+` keeps the left operand for
        // duplicate keys. The other way round the product's name and full price
        // would win over the offer's, which is the whole point of an offer.
        return [
            'name' => $offer->name,
            'amount_cent' => $offer->effectiveAmountCent(),
            'currency' => $offer->currency(),
            // What the payment line will remember it was sold as. An offer
            // renamed next year must not rewrite an old order.
            'offer' => $offer->handle,
            // The handle of the thing underneath. A site declares tax classes
            // per product handle, and an offer has a handle of its own — so
            // without this the offer would silently fall to the default class
            // and put the wrong rate on a tax document. Inheriting the array
            // is not enough; the *name* it was declared under has to travel
            // too.
            'product' => $offer->product,
        ] + $product;
    }

    /**
     * Versteht die Zugangsbruecke nebenan eine Liste von `grants`?
     *
     * Gefragt wird die Methode, nicht die Version: eine Versionsnummer aus
     * `composer.json` zu lesen heisst, der Datei zu glauben statt dem Code, und
     * die beiden gehen bei einem Fork oder einem Pfad-Repository auseinander.
     * `method_exists` sieht auch geschuetzte Methoden — dieselbe Pruefung, die
     * die Bruecke selbst fuer `renew()` macht.
     */
    protected static function siblingUnderstandsGrantLists(): bool
    {
        if (! config('statamic-payments.entitlements.enabled', false)) {
            return true;
        }

        // Against the *installed* sibling, which the analyser reads as the
        // newest one; the check is for the older ones a site may still run.
        return method_exists(EntitlementsBridge::class, 'slugsFor'); // @phpstan-ignore function.alreadyNarrowedType
    }

    /**
     * Was ein Buendel gemeinsam behauptet, oder nichts, wenn seine Teile sich
     * widersprechen.
     *
     * @return array<string, mixed>|null
     */
    protected function bundleFacts(Offer $offer): ?array
    {
        $grants = [];
        $digital = null;

        foreach ($offer->productHandles() as $teilHandle) {
            $teil = app(Catalogue::class)->find($teilHandle);

            if (! is_array($teil)) {
                // `isSellable()` hat das schon geprueft. Hier steht es noch
                // einmal, damit diese Methode auch dann stimmt, wenn sie
                // irgendwann von woanders gerufen wird.
                return null;
            }

            $teilGrants = $teil['grants'] ?? null;

            foreach (is_array($teilGrants) ? $teilGrants : [$teilGrants] as $slug) {
                if (is_string($slug) && $slug !== '') {
                    $grants[] = $slug;
                }
            }

            if (! array_key_exists('digital', $teil)) {
                continue;
            }

            $teilDigital = (bool) $teil['digital'];

            if ($digital !== null && $digital !== $teilDigital) {
                Log::warning('statamic-offers: a bundle whose parts disagree about `digital` is not sellable; the tax note on its invoice line would have to be guessed.', [
                    'offer' => $offer->handle,
                    'products' => $offer->productHandles(),
                ]);

                return null;
            }

            $digital = $teilDigital;
        }

        $fakten = [];

        if ($grants !== []) {
            $fakten['grants'] = array_values(array_unique($grants));
        }

        // Kann das Geschwister nebenan ueberhaupt mehrere Zugaenge vergeben?
        //
        // Vor `statamic-payments` 1.14 nimmt `grants` nur eine Zeichenkette;
        // eine Liste faellt dort an `is_string()` heraus und vergibt **gar
        // nichts** — nicht etwa das erste Stueck. Ein Buendel wuerde also
        // bezahlt, berechnet und nie geliefert, ohne dass irgendwo ein Fehler
        // steht.
        //
        // Lieber nicht verkaufbar. Das ist unbequem und sichtbar: das Angebot
        // erscheint nicht, jemand sucht danach und findet diese Zeile im Log.
        // Die Alternative waere ein Verkauf, bei dem erst der Kaeufer merkt,
        // dass nichts ankam.
        //
        // Nur wenn Zugaenge ueberhaupt vergeben werden. Ist die Bruecke aus,
        // sagt `grants` nichts ueber diese Installation, und ein Buendel aus
        // drei Dingen ohne Zugangsverwaltung ist voellig in Ordnung.
        if (count($fakten['grants'] ?? []) > 1 && ! self::siblingUnderstandsGrantLists()) {
            Log::warning('statamic-offers: a bundle that grants several things needs statamic-payments 1.14 or newer; on this version the grants would silently arrive as none at all.', [
                'offer' => $offer->handle,
                'grants' => $fakten['grants'],
            ]);

            return null;
        }

        if ($digital !== null) {
            $fakten['digital'] = $digital;
        }

        return $fakten;
    }

    /**
     * Angebote und Gutscheine in die Seitenleiste holen.
     *
     * Sie bleiben Statamic-Utilities — dieselbe Route, dasselbe Recht. Was
     * fehlte, war der Weg dorthin: unter „Hilfsmittel" stehen sie zwischen
     * Cache und PHP-Info, und genau das hat Adrian am 03.09.2026 als verwirrend
     * gemeldet.
     *
     * Der Abschnittsname kommt aus `statamic-payments`, an dem dieses Addon
     * ohnehin haengt. Ein eigener String hier waere ein zweiter Abschnitt mit
     * fast demselben Namen, denn Statamic uebersetzt Abschnittsnamen nicht.
     */
    protected function bootNavigation(): self
    {
        Nav::extend(function ($nav) {
            $section = SuiteNav::section();

            // Erst aushaengen, dann einhaengen — sonst steht jeder Bildschirm
            // zweimal da: einmal unter „Hilfsmittel", wohin `Utility::register`
            // ihn haengt, und einmal hier. Die Registrierung bleibt, sie traegt
            // Route, Recht und Middleware; nur der Eintrag unter Hilfsmittel
            // faellt weg. Der Elternpunkt heisst intern `Utilities`, das Kind
            // traegt seinen bereits uebersetzten `navTitle`.
            foreach ([
                'statamic-offers::messages.utility_nav',
                'statamic-offers::messages.coupons_utility_nav',
            ] as $schluessel) {
                $nav->remove('Tools', 'Utilities', __($schluessel));
            }

            $nav->create(__('statamic-offers::messages.utility_nav'))
                ->section($section)
                ->icon('money-cashier-price-tag')
                ->route('utilities.offers')
                ->can('access offers utility');

            $nav->create(__('statamic-offers::messages.coupons_utility_nav'))
                ->section($section)
                ->icon('media-ticket')
                ->route('utilities.coupons')
                ->can('access coupons utility');
        });

        return $this;
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
                // Same reason: "generate" must not be read as a coupon id.
                $router->post('generate', [CouponsController::class, 'generate'])->name('generate');
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
