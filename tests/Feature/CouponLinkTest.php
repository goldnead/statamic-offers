<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Offers;
use Goldnead\StatamicOffers\Support\QrCode;
use Goldnead\StatamicOffers\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * O4: Gutschein-Link mit vorbelegtem Code und QR-Code.
 *
 * Der Link traegt nur den Code, nie einen Betrag. Ob er etwas wert ist,
 * entscheidet die Tabelle beim Einloesen, genau wie beim getippten Code.
 */
class CouponLinkTest extends TestCase
{
    protected function coupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'CHOR20',
            'percent' => 20,
            'active' => true,
        ], $overrides));
    }

    protected function superuser()
    {
        return tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    #[Test]
    public function the_parameter_is_named_once_and_configurable(): void
    {
        $this->assertSame('coupon', Offers::couponParameter());

        config(['statamic-offers.coupon_link.parameter' => 'code']);

        $this->assertSame('code', Offers::couponParameter());
    }

    #[Test]
    public function a_live_code_from_the_url_is_found(): void
    {
        $this->coupon();

        $coupon = Offers::couponFromRequest(Request::create('/kasse?coupon=chor20'));

        $this->assertSame('CHOR20', $coupon?->code);
    }

    #[Test]
    public function expired_exhausted_and_unknown_codes_are_ignored_and_logged(): void
    {
        $this->coupon(['code' => 'ALT', 'ends_at' => Carbon::now()->subDay()]);
        $this->coupon(['code' => 'LEER', 'max_uses' => 1, 'used_count' => 1]);

        Log::spy();

        foreach (['ALT', 'LEER', 'GIBTSNICHT'] as $code) {
            $this->assertNull(Offers::couponFromRequest(Request::create('/kasse?coupon='.$code)), $code);
        }

        // Kein Fehler fuer den Besucher, der einen alten Flyer abfotografiert
        // hat, aber eine Zeile fuer den Betreiber.
        Log::shouldHaveReceived('info')->times(3);
    }

    #[Test]
    public function a_code_limited_to_other_offers_is_ignored_for_this_one(): void
    {
        $this->coupon(['offers' => ['anderes']]);
        $offer = Offer::create([
            'handle' => 'kurs', 'name' => 'Kurs', 'product' => 'noten-paket', 'amount_cent' => 4900,
            'slot' => Offer::SLOT_STANDALONE, 'active' => true,
        ]);

        $this->assertNull(Offers::couponFromRequest(Request::create('/kasse?coupon=CHOR20'), $offer));
        $this->assertNotNull(Offers::couponFromRequest(Request::create('/kasse?coupon=CHOR20')));
    }

    #[Test]
    public function the_link_keeps_the_target_query_and_adds_the_code(): void
    {
        $coupon = $this->coupon(['link_url' => 'https://chor.example/anmeldung?utm_source=flyer']);

        $this->assertSame('https://chor.example/anmeldung?utm_source=flyer&coupon=CHOR20', $coupon->link());
    }

    #[Test]
    public function a_relative_target_is_made_absolute(): void
    {
        config(['app.url' => 'https://chor.example']);

        $this->assertSame('https://chor.example/kurs?coupon=CHOR20', $this->coupon(['link_url' => '/kurs'])->link());
        // Ohne Zielseite die Startseite der Site.
        $this->assertSame('https://chor.example/?coupon=OHNE', $this->coupon(['code' => 'OHNE'])->link());
    }

    #[Test]
    public function every_offer_with_a_short_link_gets_its_own_coupon_link(): void
    {
        config(['app.url' => 'https://chor.example']);

        Offer::create([
            'handle' => 'kurs', 'name' => 'Kurs', 'product' => 'noten-paket', 'amount_cent' => 4900,
            'slot' => Offer::SLOT_STANDALONE, 'active' => true,
            'link_slug' => 'herbst', 'link_target' => '/kurs',
        ]);
        Offer::create([
            'handle' => 'ohne-link', 'name' => 'Ohne Link', 'product' => 'noten-paket', 'amount_cent' => 4900,
            'slot' => Offer::SLOT_STANDALONE, 'active' => true,
        ]);

        $links = $this->coupon(['link_url' => '/kurs'])->links();

        $this->assertSame([
            ['key' => 'page', 'label' => '/kurs', 'url' => 'https://chor.example/kurs?coupon=CHOR20'],
            ['key' => 'offer:kurs', 'label' => 'Kurs', 'url' => 'https://chor.example/go/herbst?coupon=CHOR20'],
        ], $links);
    }

    #[Test]
    public function the_qr_code_is_made_here_as_svg_and_png(): void
    {
        $svg = QrCode::svg('https://chor.example/go/herbst?coupon=CHOR20');
        $png = QrCode::png('https://chor.example/go/herbst?coupon=CHOR20');

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringStartsWith("\x89PNG", $png);
        $this->assertSame('image/png', getimagesizefromstring($png)['mime']);
    }

    #[Test]
    public function the_control_panel_hands_out_the_qr_code_for_download(): void
    {
        $coupon = $this->coupon(['link_url' => 'https://chor.example/kurs']);

        $svg = $this->actingAs($this->superuser())
            ->get(cp_route('utilities.coupons.qr', ['coupon' => $coupon->id, 'format' => 'svg', 'link' => 'page']));

        $svg->assertOk();
        $this->assertStringStartsWith('image/svg+xml', $svg->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $svg->headers->get('Content-Disposition'));

        $png = $this->get(cp_route('utilities.coupons.qr', ['coupon' => $coupon->id, 'format' => 'png', 'link' => 'page']));
        $png->assertOk();
        $this->assertSame('image/png', $png->headers->get('Content-Type'));
    }

    #[Test]
    public function the_qr_code_only_encodes_links_of_this_coupon(): void
    {
        $coupon = $this->coupon(['link_url' => 'https://chor.example/kurs']);

        // Kein freier QR-Erzeuger im Control Panel: nur was der Gutschein selbst
        // als Link fuehrt.
        $this->actingAs($this->superuser())
            ->get(cp_route('utilities.coupons.qr', ['coupon' => $coupon->id, 'format' => 'svg', 'link' => 'offer:fremd']))
            ->assertNotFound();
    }

    #[Test]
    public function the_qr_code_needs_the_coupons_permission(): void
    {
        $coupon = $this->coupon(['link_url' => 'https://chor.example/kurs']);
        $role = tap(Role::make('nur-cp')->addPermission('access cp'))->save();
        $user = tap(User::make()->email('ohne@example.com')->assignRole($role))->save();

        // JSON, weil das CP eine Browser-Anfrage ohne Recht auf die Startseite
        // umleitet; verweigert ist sie so wie so.
        $this->actingAs($user)
            ->getJson(cp_route('utilities.coupons.qr', ['coupon' => $coupon->id, 'format' => 'svg', 'link' => 'page']))
            ->assertForbidden();
    }
}
