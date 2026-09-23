<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Contracts\SeatAccess;
use Goldnead\StatamicOffers\Mail\SeatInvitationMail;
use Goldnead\StatamicOffers\Mail\SeatPoolMail;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Models\Seat;
use Goldnead\StatamicOffers\Models\SeatPool;
use Goldnead\StatamicOffers\Support\SeatPools;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Chargebacks;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Goldnead\StatamicPayments\Support\Refunds;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * O7: Plaetze fuer Gruppen.
 *
 * Eine Chorleiterin kauft zehn Plaetze und verteilt sie an ihre Stimmgruppe.
 * Die Kaeuferin bekommt dabei **keinen** Zugang von selbst: sie ist oft gar
 * nicht Teilnehmerin, und ein elfter Zugang aus zehn bezahlten waere ein
 * geschenkter. Wer einen Platz will, bekommt ihn ueber eine Einladung, auch sie
 * selbst.
 */
class SeatsTest extends TestCase
{
    protected FakeSeatAccess $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->access = new FakeSeatAccess;
        $this->app->instance(SeatAccess::class, $this->access);

        Mail::fake();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products.workshop', [
            'name' => 'Workshop',
            'amount_cent' => 4900,
            'grants' => 'workshop-zugang',
        ]);
    }

    protected function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'stimmgruppe',
            'name' => 'Workshop für die Stimmgruppe',
            'product' => 'workshop',
            'amount_cent' => 39000,
            'seats' => 10,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ], $overrides));
    }

    protected function buy(string|array $handles = 'offer:stimmgruppe'): Payment
    {
        $payment = app(Checkout::class)->start($handles, ['email' => 'Leitung@Chor.example', 'name' => 'Anna Leitung'])->payment;
        $this->gateway->markPaid($payment->provider_id);

        // Der Anbieter liefert mehrfach, mit Absicht.
        app(Fulfilment::class)->handle($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);

        return $payment->fresh();
    }

    #[Test]
    public function the_buyer_is_not_granted_the_access_herself(): void
    {
        $this->offer();

        $entry = app(Catalogue::class)->find('offer:stimmgruppe');

        // Ohne `grants` vergibt payments nichts an die Kaeuferin.
        $this->assertArrayNotHasKey('grants', $entry);
        $this->assertSame(['workshop-zugang'], $entry['seat_grants']);
        $this->assertSame(10, $entry['seats']);
    }

    #[Test]
    public function a_paid_purchase_opens_exactly_one_pool_and_tells_the_buyer(): void
    {
        $this->offer();

        $payment = $this->buy();

        $this->assertSame(1, SeatPool::query()->count());

        $pool = SeatPool::query()->first();
        $this->assertSame($payment->id, $pool->payment_id);
        $this->assertSame(10, $pool->seats);
        $this->assertSame('leitung@chor.example', $pool->owner_email);
        $this->assertSame(['workshop-zugang'], $pool->grants);

        Mail::assertSent(SeatPoolMail::class, fn (SeatPoolMail $mail) => $mail->hasTo('leitung@chor.example'));
        Mail::assertSent(SeatPoolMail::class, 1);
    }

    #[Test]
    public function two_units_are_twice_the_seats(): void
    {
        $this->offer();

        $this->buy(['offer:stimmgruppe' => 2]);

        $this->assertSame(20, SeatPool::query()->first()->seats);
    }

    #[Test]
    public function an_ordinary_offer_opens_no_pool(): void
    {
        $this->offer(['seats' => null]);

        $this->buy();

        $this->assertSame(0, SeatPool::query()->count());
        Mail::assertNothingSent();
    }

    #[Test]
    public function the_manage_page_opens_with_its_token_and_only_with_it(): void
    {
        $this->offer();
        $this->buy();
        $pool = SeatPool::query()->first();

        $this->get(route('statamic-offers.seats.manage', $pool->manage_token))
            ->assertOk()
            ->assertSee('Workshop für die Stimmgruppe')
            ->assertSee('0 / 10');

        $this->get(route('statamic-offers.seats.manage', str_repeat('x', 40)))->assertNotFound();
    }

    #[Test]
    public function an_invitation_takes_a_seat_and_sends_a_mail(): void
    {
        $this->offer();
        $this->buy();
        $pool = SeatPool::query()->first();

        $this->post(route('statamic-offers.seats.invite', $pool->manage_token), ['email' => 'Sopran@Chor.example', 'name' => 'Sofie'])
            ->assertRedirect(route('statamic-offers.seats.manage', $pool->manage_token));

        $seat = Seat::query()->first();
        $this->assertSame('sopran@chor.example', $seat->email);
        $this->assertSame(Seat::STATUS_INVITED, $seat->status);
        $this->assertSame(1, $pool->fresh()->takenCount());

        Mail::assertSent(SeatInvitationMail::class, fn (SeatInvitationMail $mail) => $mail->hasTo('sopran@chor.example'));

        // Noch nichts vergeben: erst die Annahme macht den Zugang.
        $this->assertSame([], $this->access->granted);
    }

    #[Test]
    public function the_same_address_cannot_hold_two_seats(): void
    {
        $this->offer();
        $this->buy();
        $pool = SeatPool::query()->first();

        $this->post(route('statamic-offers.seats.invite', $pool->manage_token), ['email' => 'alt@chor.example']);
        $this->post(route('statamic-offers.seats.invite', $pool->manage_token), ['email' => 'ALT@chor.example'])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, Seat::query()->count());
    }

    #[Test]
    public function a_full_pool_takes_no_more_invitations(): void
    {
        $this->offer(['seats' => 2]);
        $this->buy();
        $pool = SeatPool::query()->first();

        $this->post(route('statamic-offers.seats.invite', $pool->manage_token), ['email' => 'a@chor.example']);
        $this->post(route('statamic-offers.seats.invite', $pool->manage_token), ['email' => 'b@chor.example']);
        $this->post(route('statamic-offers.seats.invite', $pool->manage_token), ['email' => 'c@chor.example'])
            ->assertSessionHasErrors('email');

        $this->assertSame(2, Seat::query()->count());
    }

    #[Test]
    public function accepting_the_invitation_grants_the_access(): void
    {
        $this->offer();
        $this->buy();
        $pool = SeatPool::query()->first();
        $this->post(route('statamic-offers.seats.invite', $pool->manage_token), ['email' => 'sopran@chor.example']);
        $seat = Seat::query()->first();

        $this->get(route('statamic-offers.seats.claim', $seat->token))->assertOk()->assertSee('Workshop für die Stimmgruppe');
        $this->post(route('statamic-offers.seats.accept', $seat->token))->assertOk();
        // Zweimal geklickt ist einmal angenommen.
        $this->post(route('statamic-offers.seats.accept', $seat->token))->assertOk();

        $this->assertSame(Seat::STATUS_CLAIMED, $seat->fresh()->status);
        $this->assertSame([['sopran@chor.example', ['workshop-zugang'], 'seat:'.$seat->id]], $this->access->granted);
    }

    #[Test]
    public function a_seat_taken_back_loses_its_access_and_frees_the_place(): void
    {
        $this->offer(['seats' => 2]);
        $this->buy();
        $pool = SeatPool::query()->first();
        $this->post(route('statamic-offers.seats.invite', $pool->manage_token), ['email' => 'alt@chor.example']);
        $this->post(route('statamic-offers.seats.invite', $pool->manage_token), ['email' => 'zwei@chor.example']);
        $seat = Seat::query()->where('email', 'alt@chor.example')->first();
        $this->post(route('statamic-offers.seats.accept', $seat->token));

        $this->post(route('statamic-offers.seats.revoke', [$pool->manage_token, $seat->id]))
            ->assertRedirect(route('statamic-offers.seats.manage', $pool->manage_token));

        $this->assertSame(Seat::STATUS_REVOKED, $seat->fresh()->status);
        $this->assertSame([['alt@chor.example', ['workshop-zugang'], 'seat:'.$seat->id]], $this->access->revoked);

        // Der Platz ist frei und neu vergeben, obwohl das Kontingent vorher
        // voll war.
        $this->assertSame(1, $pool->fresh()->takenCount());
        $this->post(route('statamic-offers.seats.invite', $pool->manage_token), ['email' => 'neu@chor.example'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $pool->fresh()->takenCount());

        // Die alte Einladung laesst sich nicht wieder annehmen.
        $this->post(route('statamic-offers.seats.accept', $seat->token))->assertNotFound();
    }

    #[Test]
    public function a_seat_of_another_pool_cannot_be_taken_back(): void
    {
        $this->offer();
        $this->buy();
        $this->buy();
        [$eins, $zwei] = SeatPool::query()->orderBy('id')->get()->all();
        $this->post(route('statamic-offers.seats.invite', $zwei->manage_token), ['email' => 'fremd@chor.example']);
        $seat = Seat::query()->first();

        $this->post(route('statamic-offers.seats.revoke', [$eins->manage_token, $seat->id]))->assertNotFound();
        $this->assertSame(Seat::STATUS_INVITED, $seat->fresh()->status);
    }

    /** Kauf, zwei Einladungen, eine davon angenommen. */
    protected function poolWithOneAccepted(): array
    {
        $this->offer();
        $payment = $this->buy();
        $pool = SeatPool::query()->first();
        $this->post(route('statamic-offers.seats.invite', $pool->manage_token), ['email' => 'sopran@chor.example']);
        $this->post(route('statamic-offers.seats.invite', $pool->manage_token), ['email' => 'alt@chor.example']);
        $angenommen = Seat::query()->where('email', 'sopran@chor.example')->first();
        $this->post(route('statamic-offers.seats.accept', $angenommen->token));

        return [$payment, $pool, $angenommen];
    }

    #[Test]
    public function a_full_refund_closes_the_pool_and_takes_every_access_back(): void
    {
        [$payment, $pool, $angenommen] = $this->poolWithOneAccepted();

        // Der echte Weg: payments bucht die Erstattung und feuert das Ereignis.
        app(Refunds::class)->record($payment->fresh(), $payment->amount_cent, 're_1');

        $this->assertNotNull($pool->fresh()->closed_at);
        $this->assertSame([['sopran@chor.example', ['workshop-zugang'], 'seat:'.$angenommen->id]], $this->access->revoked);
        $this->assertSame(0, Seat::query()->where('status', '!=', Seat::STATUS_REVOKED)->count());

        // Geschlossen heisst: keine neue Einladung, keine Annahme.
        $this->post(route('statamic-offers.seats.invite', $pool->manage_token), ['email' => 'neu@chor.example'])
            ->assertSessionHasErrors('email');
        $this->assertSame(2, Seat::query()->count());
    }

    #[Test]
    public function a_partial_refund_leaves_the_pool_open(): void
    {
        [$payment, $pool] = $this->poolWithOneAccepted();

        app(Refunds::class)->record($payment->fresh(), 1000, 're_teil');

        $this->assertNull($pool->fresh()->closed_at);
        $this->assertSame([], $this->access->revoked);
    }

    #[Test]
    public function a_chargeback_closes_the_pool(): void
    {
        if (! class_exists(Chargebacks::class)) {
            $this->markTestSkipped('statamic-payments ohne Rueckbuchungen (vor 1.23).');
        }

        [$payment, $pool, $angenommen] = $this->poolWithOneAccepted();

        app(Chargebacks::class)->record($payment->fresh(), 'chb_1', $payment->amount_cent);

        $this->assertNotNull($pool->fresh()->closed_at);
        $this->assertCount(1, $this->access->revoked);
        $this->assertSame(Seat::STATUS_REVOKED, $angenommen->fresh()->status);
    }

    #[Test]
    public function an_invitation_of_a_closed_pool_cannot_be_accepted(): void
    {
        [$payment, $pool] = $this->poolWithOneAccepted();
        $offen = Seat::query()->where('email', 'alt@chor.example')->first();

        app(SeatPools::class)->close($pool, 'Test');

        $this->post(route('statamic-offers.seats.accept', $offen->token))->assertNotFound();
        $this->assertCount(0, array_filter($this->access->granted, fn ($g) => $g[0] === 'alt@chor.example'));
    }

    #[Test]
    public function a_seat_taken_back_twice_revokes_once(): void
    {
        [, , $angenommen] = $this->poolWithOneAccepted();

        // Zwei Anfragen mit demselben, noch „angenommenen" Stand. Die zweite
        // darf nicht erneut entziehen und nicht so tun, als haette sie es getan.
        $alt = Seat::query()->find($angenommen->id);
        $this->assertTrue(app(SeatPools::class)->revoke($angenommen));
        $this->assertFalse(app(SeatPools::class)->revoke($alt));

        $this->assertCount(1, $this->access->revoked);
    }

    #[Test]
    public function the_name_on_an_invitation_is_bounded(): void
    {
        $this->offer();
        $this->buy();
        $pool = SeatPool::query()->first();

        // Ein Name ist die Anrede in einer Mail, die unter fremdem Absender
        // rausgeht. Ein Link oder ein Absatz darin waere eine Phishing-Vorlage.
        foreach (['Klick hier: https://boese.example', str_repeat('A', 81), "Anna\nZeile"] as $name) {
            $this->post(route('statamic-offers.seats.invite', $pool->manage_token), ['email' => 'x@chor.example', 'name' => $name])
                ->assertSessionHasErrors('name');
        }

        $this->post(route('statamic-offers.seats.invite', $pool->manage_token), ['email' => 'x@chor.example', 'name' => "Anna-Lena O'Neill"])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_control_panel_lists_the_pools_and_can_resend_and_take_back(): void
    {
        [, $pool, $angenommen] = $this->poolWithOneAccepted();
        $user = tap(User::make()->email('studio@example.com')->makeSuper())->save();
        Mail::fake();

        $row = collect($this->actingAs($user)->getJson(cp_route('utilities.offers'))->json('data'))
            ->firstWhere('handle', 'stimmgruppe');

        $this->assertSame('leitung@chor.example', $row['seat_pools'][0]['owner_email']);
        $this->assertSame(2, $row['seat_pools'][0]['taken']);
        $this->assertSame(10, $row['seat_pools'][0]['seats']);
        $this->assertCount(2, $row['seat_pools'][0]['rows']);

        $this->post(cp_route('utilities.offers.seats.resend', $pool->id))->assertRedirect();
        Mail::assertSent(SeatPoolMail::class, fn (SeatPoolMail $m) => $m->hasTo('leitung@chor.example'));

        $this->post(cp_route('utilities.offers.seats.revoke', [$pool->id, $angenommen->id]))->assertRedirect();
        $this->assertSame(Seat::STATUS_REVOKED, $angenommen->fresh()->status);
    }

    #[Test]
    public function the_control_panel_seat_routes_need_the_permission(): void
    {
        [, $pool, $angenommen] = $this->poolWithOneAccepted();
        $role = tap(Role::make('nur-cp')->addPermission('access cp'))->save();
        $user = tap(User::make()->email('ohne@example.com')->assignRole($role))->save();

        $this->actingAs($user)->postJson(cp_route('utilities.offers.seats.resend', $pool->id))->assertForbidden();
        $this->actingAs($user)->postJson(cp_route('utilities.offers.seats.revoke', [$pool->id, $angenommen->id]))->assertForbidden();
        $this->assertSame(Seat::STATUS_CLAIMED, $angenommen->fresh()->status);
    }

    #[Test]
    public function seats_are_not_sold_as_a_subscription(): void
    {
        $user = tap(User::make()->email('studio@example.com')->makeSuper())->save();

        $this->actingAs($user)
            ->post(cp_route('utilities.offers.store'), [
                'name' => 'Gruppe', 'handle' => 'gruppe', 'product' => 'workshop',
                'slot' => Offer::SLOT_STANDALONE, 'active' => true,
                'amount_cent' => 2000, 'interval' => '1 month', 'seats' => 5,
            ])
            ->assertSessionHasErrors('seats');
    }
}

/** Haelt fest, was vergeben und entzogen wurde, statt es zu tun. */
class FakeSeatAccess implements SeatAccess
{
    /** @var list<array{0: string, 1: list<string>, 2: string}> */
    public array $granted = [];

    /** @var list<array{0: string, 1: list<string>, 2: string}> */
    public array $revoked = [];

    public function available(): bool
    {
        return true;
    }

    public function grant(string $email, array $slugs, string $sourceRef, ?array $access = null): void
    {
        $this->granted[] = [$email, $slugs, $sourceRef];
    }

    public function revoke(string $email, array $slugs, string $sourceRef, string $reason): void
    {
        $this->revoked[] = [$email, $slugs, $sourceRef];
    }
}
