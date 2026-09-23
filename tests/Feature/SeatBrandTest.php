<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\BrandContext\Models\Brand;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\IdentityContracts\ServiceProvider;
use Goldnead\StatamicOffers\Contracts\SeatAccess;
use Goldnead\StatamicOffers\Integrations\EntitlementsSeatAccess;
use Goldnead\StatamicOffers\Models\Seat;
use Goldnead\StatamicOffers\Models\SeatPool;
use Goldnead\StatamicOffers\Support\SeatPools;
use Goldnead\StatamicOffers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * O7 mit der echten Zugangsbruecke und zwei Marken.
 *
 * Die Seiten der Plaetze werden aus einer Mail geoeffnet, von Menschen ohne
 * Konto. Welche Marke die Anfrage traegt, entscheidet dann die Site, unter der
 * der Link aufgerufen wurde, und das ist nicht zwingend die Marke des Kaufs.
 * Der Zugang gehoert aber der Marke des Kontingents: dort wird er verkauft,
 * dort wird er abgefragt.
 */
class SeatBrandTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [
            ServiceProvider::class,
            \Goldnead\Entitlements\ServiceProvider::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-entitlements/database/migrations');
        $this->app->forgetInstance(SeatAccess::class);

        // Erst nach dem Hochfahren: brand-context prueft beim Booten im
        // Mehrmarkenbetrieb die Middleware der Web-Routen, und die gibt es in
        // einer Paket-Testumgebung so nicht. Fuer das, was hier geprueft wird
        // (welche Marke gerade gilt), reicht der Schalter zur Laufzeit.
        config(['brand-context.multi_brand' => true, 'brand-context.license_check' => null]);
    }

    #[Test]
    public function the_access_belongs_to_the_brand_of_the_pool_not_of_the_request(): void
    {
        $this->assertInstanceOf(EntitlementsSeatAccess::class, app(SeatAccess::class));

        $a = Brand::query()->create(['handle' => 'marke-a', 'name' => 'Marke A']);
        $b = Brand::query()->create(['handle' => 'marke-b', 'name' => 'Marke B']);

        $pool = SeatPool::query()->create([
            'brand_id' => $b->id, 'payment_id' => 1, 'offer' => 'gruppe', 'product' => 'offer:gruppe',
            'owner_email' => 'leitung@chor.example', 'seats' => 5, 'grants' => ['kurs'],
            'manage_token' => str_repeat('t', 48),
        ]);
        $seat = Seat::query()->create([
            'pool_id' => $pool->id, 'email' => 'sopran@chor.example', 'token' => str_repeat('s', 48),
            'status' => Seat::STATUS_INVITED, 'invited_at' => now(),
        ]);

        // Die Anfrage laeuft unter Marke A.
        app('brand-context')->setCurrent($a->id);

        app(SeatPools::class)->accept($seat);

        $zugang = Entitlement::query()->acrossBrands()->where('source_ref', 'seat:'.$seat->id)->first();
        $this->assertNotNull($zugang);
        $this->assertSame($b->id, (int) $zugang->brand_id);

        // Und die Anfrage ist danach wieder bei ihrer eigenen Marke.
        $this->assertSame($a->id, app('brand-context')->currentId());

        // Zurueckholen findet den Zugang in Marke B, obwohl die Anfrage in A laeuft.
        app(SeatPools::class)->revoke($seat->fresh());

        $this->assertNotNull(Entitlement::query()->acrossBrands()->find($zugang->id)->revoked_at);
    }
}
