<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Contracts\SeatAccess;
use Goldnead\StatamicOffers\Mail\SeatInvitationMail;
use Goldnead\StatamicOffers\Mail\SeatPoolMail;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Models\Seat;
use Goldnead\StatamicOffers\Models\SeatPool;
use Goldnead\StatamicOffers\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
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
