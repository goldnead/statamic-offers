<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicOffers\Integrations\Map\OfferMapObjects;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Models\Seat;
use Goldnead\StatamicOffers\Models\SeatPool;
use Goldnead\StatamicOffers\Support\MapPrice;
use Goldnead\StatamicOffers\Support\OfferSales;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * Angebote als Ziel der Funnel-Karte.
 *
 * Die Karte kennt nur Typ und Handle. Alles andere, Name, Link, an/aus, Preis
 * und die Zahl, fragt sie hier ab. Die Zahl ist nur die angenommene Platzzahl
 * im Zeitraum, und nur wenn es für das Angebot ein Kontingent gibt.
 */
class OfferMapObjectsTest extends TestCase
{
    protected OfferMapObjects $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new OfferMapObjects;
    }

    protected function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'stimmbildung',
            'name' => 'Stimmbildung im Chor',
            'product' => 'noten-paket',
            'amount_cent' => 39000,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ], $overrides));
    }

    protected function pool(Offer $offer, int $seats = 5): SeatPool
    {
        return SeatPool::create([
            'brand_id' => (int) $offer->brand_id,
            'payment_id' => random_int(1, 999999),
            'offer' => $offer->handle,
            'product' => $offer->product,
            'owner_email' => 'leitung@chor.example',
            'seats' => $seats,
            'manage_token' => bin2hex(random_bytes(16)),
        ]);
    }

    #[Test]
    public function the_provider_is_registered_after_boot(): void
    {
        $registry = app(MapObjectRegistry::class);

        $this->assertTrue($registry->has('offer'));
        $this->assertArrayHasKey('offer', $registry->types());
    }

    #[Test]
    public function it_names_its_type_and_its_kind(): void
    {
        $this->assertSame('offer', $this->provider->type());
        $this->assertSame('Offer', $this->provider->typeLabel());
    }

    #[Test]
    public function objects_are_listed_by_name_and_narrowed_by_name_or_handle(): void
    {
        $this->offer(['handle' => 'chorwerkstatt', 'name' => 'Chorwerkstatt']);
        $this->offer(['handle' => 'einzelstunde', 'name' => 'Einzelstunde Atem']);
        $this->offer(['handle' => 'probe-mit-atem', 'name' => 'Probe']);

        $alle = array_map(fn ($o) => $o->label, $this->provider->objects());
        $this->assertSame(['Chorwerkstatt', 'Einzelstunde Atem', 'Probe'], $alle);

        $nachName = array_map(fn ($o) => $o->id, $this->provider->objects('Einzelstunde'));
        $this->assertSame(['einzelstunde'], $nachName);

        $nachHandle = array_map(fn ($o) => $o->id, $this->provider->objects('mit-atem'));
        $this->assertSame(['probe-mit-atem'], $nachHandle);
    }

    #[Test]
    public function wildcards_in_the_search_are_taken_literally(): void
    {
        $this->offer(['handle' => 'a', 'name' => '100% Stimme']);
        $this->offer(['handle' => 'b', 'name' => 'Stimme']);

        $treffer = array_map(fn ($o) => $o->id, $this->provider->objects('100%'));

        $this->assertSame(['a'], $treffer);
    }

    #[Test]
    public function the_limit_caps_the_list(): void
    {
        foreach (['a', 'b', 'c'] as $handle) {
            $this->offer(['handle' => $handle, 'name' => 'Angebot '.$handle]);
        }

        $this->assertCount(2, $this->provider->objects(null, 2));
    }

    #[Test]
    public function find_returns_the_object_by_handle_and_null_when_it_is_missing(): void
    {
        $offer = $this->offer();

        $gefunden = $this->provider->find('stimmbildung');

        $this->assertNotNull($gefunden);
        $this->assertSame('offer', $gefunden->type);
        $this->assertSame('stimmbildung', $gefunden->id);
        $this->assertSame('Stimmbildung im Chor', $gefunden->label);
        $this->assertSame(cp_route('utilities.offers.show', ['offer' => $offer->id]), $gefunden->cpUrl);
        $this->assertNull($this->provider->find('gibt-es-nicht'));
    }

    #[Test]
    public function enabled_follows_the_active_switch(): void
    {
        $this->offer(['handle' => 'an', 'active' => true]);
        $this->offer(['handle' => 'aus', 'active' => false]);

        $this->assertTrue($this->provider->find('an')->enabled);
        $this->assertFalse($this->provider->find('aus')->enabled);
    }

    #[Test]
    public function detail_is_the_own_price_in_the_locale_format(): void
    {
        $this->offer(['amount_cent' => 39000]);
        $this->offer(['handle' => 'ohne-preis', 'amount_cent' => null, 'product' => 'unbekannt']);

        $this->assertSame('€390.00', $this->provider->find('stimmbildung')->detail);
        $this->assertNull($this->provider->find('ohne-preis')->detail);
    }

    #[Test]
    public function metric_is_null_for_an_offer_that_never_had_seats(): void
    {
        $this->offer();

        $this->assertNull($this->provider->metric('stimmbildung', MapPeriod::lastDays(30)));
    }

    #[Test]
    public function metric_is_null_for_an_unknown_offer(): void
    {
        $this->assertNull($this->provider->metric('gibt-es-nicht', MapPeriod::lastDays(30)));
    }

    #[Test]
    public function metric_counts_seats_accepted_inside_the_period_only(): void
    {
        $offer = $this->offer();
        $pool = $this->pool($offer);

        // Zwei Annahmen im Zeitraum, eine davor, eine nur eingeladen.
        Seat::create(['pool_id' => $pool->id, 'email' => 'a@chor.example', 'token' => bin2hex(random_bytes(16)), 'status' => Seat::STATUS_CLAIMED, 'claimed_at' => Carbon::now()->subDays(2)]);
        Seat::create(['pool_id' => $pool->id, 'email' => 'b@chor.example', 'token' => bin2hex(random_bytes(16)), 'status' => Seat::STATUS_CLAIMED, 'claimed_at' => Carbon::now()->subDays(20)]);
        Seat::create(['pool_id' => $pool->id, 'email' => 'c@chor.example', 'token' => bin2hex(random_bytes(16)), 'status' => Seat::STATUS_CLAIMED, 'claimed_at' => Carbon::now()->subDays(45)]);
        Seat::create(['pool_id' => $pool->id, 'email' => 'd@chor.example', 'token' => bin2hex(random_bytes(16)), 'status' => Seat::STATUS_INVITED]);

        $metric = $this->provider->metric('stimmbildung', MapPeriod::lastDays(30));

        $this->assertNotNull($metric);
        $this->assertSame(2, $metric->value);
        $this->assertSame('Seats accepted', $metric->what);
        $this->assertSame('last 30 days', $metric->period->label());
    }

    #[Test]
    public function metric_ignores_seats_of_another_offer(): void
    {
        $this->offer();
        $zwei = $this->offer(['handle' => 'andere', 'name' => 'Andere']);

        $pool = $this->pool($zwei);
        Seat::create(['pool_id' => $pool->id, 'email' => 'x@chor.example', 'token' => bin2hex(random_bytes(16)), 'status' => Seat::STATUS_CLAIMED, 'claimed_at' => Carbon::now()]);

        // Das erste Angebot hat nie ein Kontingent gehabt: keine Zahl, keine Null.
        $this->assertNull($this->provider->metric('stimmbildung', MapPeriod::lastDays(30)));
        $this->assertSame(1, $this->provider->metric('andere', MapPeriod::lastDays(30))->value);
    }

    /**
     * A payment with one line per given line product. `$payment` overrides
     * columns of the payment.
     *
     * @param  list<string>  $lines  line products, e.g. `offer:stimmbildung`
     */
    protected function order(array $lines, array $payment = []): Payment
    {
        $order = Payment::create(array_merge([
            'product' => $lines[0] ?? 'etwas-anderes',
            'provider_id' => 'tr_'.uniqid(),
            'amount_cent' => 39000,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'provider' => 'fake',
            'paid_at' => Carbon::now()->subDay(),
        ], $payment));

        foreach ($lines as $index => $handle) {
            PaymentItem::create([
                'payment_id' => $order->id,
                'product' => $handle,
                'name' => $handle,
                'amount_cent' => 39000,
                'kind' => $index === 0 ? PaymentItem::KIND_PRIMARY : PaymentItem::KIND_BUMP,
            ]);
        }

        return $order;
    }

    #[Test]
    public function details_show_only_the_paid_orders_and_never_repeat_price_or_seats(): void
    {
        $offer = $this->offer();
        $this->offer(['handle' => 'andere', 'name' => 'Andere']);
        $pool = $this->pool($offer);
        Seat::create(['pool_id' => $pool->id, 'email' => 'a@chor.example', 'token' => bin2hex(random_bytes(16)), 'status' => Seat::STATUS_CLAIMED, 'claimed_at' => Carbon::now()->subDays(2)]);

        $this->order(['offer:stimmbildung']);
        // A purchase with an instalment suffix is the same sale; another currency is still one order.
        $this->order(['offer:stimmbildung:raten3'], ['currency' => 'CHF']);
        // One payment with two lines of the offer counts once; a setup fee alone is no sale.
        $this->order(['noten-paket', 'offer:stimmbildung', 'offer:stimmbildung:=2500']);
        $this->order(['offer:stimmbildung:+setup']);
        // Not counted: unpaid, outside the period, in the future, another offer.
        $this->order(['offer:stimmbildung'], ['status' => Payment::STATUS_OPEN]);
        $this->order(['offer:stimmbildung'], ['paid_at' => Carbon::now()->subDays(60)]);
        $this->order(['offer:stimmbildung'], ['paid_at' => Carbon::now()->addDay()]);
        $this->order(['offer:andere']);

        $details = $this->provider->details('stimmbildung', MapPeriod::lastDays(30));

        $this->assertCount(1, $details);
        $this->assertSame('facts', $details[0]['kind']);
        $this->assertSame([
            ['label' => 'Paid orders (last 30 days)', 'value' => '3', 'href' => null],
        ], $details[0]['rows']);

        // The header already says price (detail line) and seats (metric): no row repeats them.
        $head = $this->provider->find('stimmbildung');
        $this->assertSame('€390.00', $head->detail);
        $this->assertSame(1, $this->provider->metric('stimmbildung', MapPeriod::lastDays(30))->value);
        foreach ($details[0]['rows'] as $row) {
            $this->assertStringNotContainsString('Price', $row['label']);
            $this->assertStringNotContainsString('Seats', $row['label']);
            $this->assertStringNotContainsString('390', $row['value']);
        }
    }

    #[Test]
    public function details_are_null_when_there_is_nothing_new_to_say(): void
    {
        $offer = $this->offer();
        $this->pool($offer);

        Schema::rename('payments', 'payments_away');
        OfferSales::forget();

        try {
            $this->assertNull($this->provider->details('stimmbildung', MapPeriod::lastDays(30)));
        } finally {
            Schema::rename('payments_away', 'payments');
            OfferSales::forget();
        }
    }

    #[Test]
    public function an_offer_without_orders_shows_a_zero_not_nothing(): void
    {
        $this->offer(['handle' => 'ohne-preis', 'amount_cent' => null, 'product' => 'unbekannt']);

        $rows = $this->provider->details('ohne-preis', MapPeriod::lastDays(30))[0]['rows'];

        $this->assertSame([['label' => 'Paid orders (last 30 days)', 'value' => '0', 'href' => null]], $rows);
    }

    #[Test]
    public function details_speak_german_in_a_german_cp(): void
    {
        app()->setLocale('de');
        $this->offer(['amount_cent' => 179000]);

        $rows = $this->provider->details('stimmbildung', MapPeriod::lastDays(30))[0]['rows'];

        $this->assertSame([['label' => 'Bezahlte Bestellungen (letzte 30 Tage)', 'value' => '0', 'href' => null]], $rows);
        $this->assertSame('1.790,00 €', $this->provider->find('stimmbildung')->detail);
    }

    #[Test]
    public function details_of_an_unknown_offer_are_null(): void
    {
        $this->assertNull($this->provider->details('gibt-es-nicht', MapPeriod::lastDays(30)));
    }

    #[Test]
    public function money_is_written_the_way_the_locale_writes_it(): void
    {
        app()->setLocale('de');
        $this->assertSame('199,00 €', MapPrice::format(19900, 'EUR'));
        $this->assertSame('1.790,00 €', MapPrice::format(179000, 'EUR'));
        $this->assertSame('12,50 $', MapPrice::format(1250, 'USD'));
        $this->assertSame('12,50 £', MapPrice::format(1250, 'GBP'));
        $this->assertSame('12,50 CHF', MapPrice::format(1250, 'CHF'));
        $this->assertSame('12,50 SEK', MapPrice::format(1250, 'SEK'));

        app()->setLocale('en');
        $this->assertSame('€199.00', MapPrice::format(19900, 'EUR'));
        $this->assertSame('$1,250.00', MapPrice::format(125000, 'USD'));
        $this->assertSame('£12.50', MapPrice::format(1250, 'GBP'));
        $this->assertSame('CHF 12.50', MapPrice::format(1250, 'CHF'));
        $this->assertSame('SEK 12.50', MapPrice::format(1250, 'SEK'));
    }

    /**
     * In a CP request the utility permissions exist (`CorePermissions`); the
     * test kernel boots the permission list before payments registers its
     * utility, so the two this test asks about are registered by hand.
     */
    protected function registerUtilityPermissions(): void
    {
        foreach (['access payments utility', 'access offers utility'] as $permission) {
            Permission::register($permission);
        }
    }

    #[Test]
    public function details_are_authorized_by_the_payments_permission_when_payments_is_there(): void
    {
        $this->offer();
        $this->registerUtilityPermissions();

        $payments = tap(User::make()->email('kasse@example.com')->assignRole(
            tap(Role::make('kasse')->addPermission('access payments utility'))->save()
        ))->save();
        $offersOnly = tap(User::make()->email('angebote@example.com')->assignRole(
            tap(Role::make('angebote')->addPermission('access offers utility'))->save()
        ))->save();
        $super = tap(User::make()->email('chef@example.com')->makeSuper())->save();

        $this->assertTrue($this->provider->detailsAuthorized('stimmbildung', $payments));
        $this->assertTrue($this->provider->detailsAuthorized('stimmbildung', $super));
        $this->assertFalse($this->provider->detailsAuthorized('stimmbildung', $offersOnly));
        $this->assertFalse($this->provider->detailsAuthorized('stimmbildung', null));
        $this->assertFalse($this->provider->detailsAuthorized('stimmbildung', new \stdClass));
    }

    #[Test]
    public function without_payments_the_offers_permission_decides(): void
    {
        $this->registerUtilityPermissions();

        $offersOnly = tap(User::make()->email('angebote@example.com')->assignRole(
            tap(Role::make('angebote')->addPermission('access offers utility'))->save()
        ))->save();
        $nobody = tap(User::make()->email('nix@example.com')->assignRole(
            tap(Role::make('nix')->addPermission('access cp'))->save()
        ))->save();

        $provider = new class extends OfferMapObjects
        {
            protected function paymentsInstalled(): bool
            {
                return false;
            }
        };

        $this->assertTrue($provider->detailsAuthorized('x', $offersOnly));
        $this->assertFalse($provider->detailsAuthorized('x', $nobody));
        $this->assertFalse($provider->detailsAuthorized('x', null));
    }

    #[Test]
    public function the_registry_allows_the_panel_through_the_provider(): void
    {
        $this->offer();
        $this->registerUtilityPermissions();
        $registry = app(MapObjectRegistry::class);

        if (! method_exists($registry, 'detailsAllowed')) {
            $this->markTestSkipped('brand-context older than 1.20');
        }

        $super = tap(User::make()->email('chef@example.com')->makeSuper())->save();

        $this->assertTrue($registry->detailsAllowed('offer', 'stimmbildung', $super));
        $this->assertFalse($registry->detailsAllowed('offer', 'stimmbildung', null));
    }
}
