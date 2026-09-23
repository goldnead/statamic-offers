<?php

namespace Goldnead\StatamicOffers\Http\Resources\Cp;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Models\Seat;
use Goldnead\StatamicOffers\Models\SeatPool;
use Goldnead\StatamicOffers\Support\CpNumber;
use Goldnead\StatamicOffers\Support\OfferSales;
use Goldnead\StatamicOffers\Support\OfferUsage;
use Goldnead\StatamicOffers\Support\SeatPools;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * One row.
 *
 * @mixin Offer
 */
class ListedOffer extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'handle' => $this->handle,
            'name' => $this->name,
            'product' => $this->product,
            // Wie viele Stuecke insgesamt, damit ein Buendel in der Liste als
            // Buendel zu erkennen ist und nicht als ein Produkt mit einem
            // seltsam hohen Preis. Null statt 1, aus demselben Grund wie bei
            // `bumps_count`: eine Spalte voller Einsen liest sich wie ein
            // Fehler, eine leere Zelle wie „kein Buendel".
            'products_count' => $this->isBundle() ? count($this->productHandles()) : null,
            // Formatted here rather than by the model, so this listing and the
            // coupons listing next door write a price the same way. The model's
            // `amount()` is a machine-readable decimal and stays that way.
            //
            // Bei „Zahl, was du willst" ist das die Untergrenze, und die Zelle
            // sagt es: „ab 10,00". Nackt stuende da ein Preis, den niemand
            // zahlen muss.
            'amount' => $this->resource->isPayWhatYouWant()
                ? __('statamic-offers::messages.price_from', ['amount' => $this->money($this->effectiveAmountCent())])
                : $this->money($this->effectiveAmountCent()),
            'pay_what_you_want' => $this->resource->isPayWhatYouWant(),
            // Der Kurzlink, fertig gebaut, mit den Zaehlern und dem Ziel, zu
            // dem er gerade fuehrt. Null ohne Link.
            'short_link' => $this->shortLink(),
            // Die Kontingente dieses Angebots, fuer den Abschnitt „Plaetze" im
            // Formular. Nur bei einem Angebot mit Plaetzen, und die neuesten 50:
            // ein Angebot mit tausend Gruppenkaeufen gehoert in einen eigenen
            // Bildschirm, nicht in eine Zeile der Liste.
            'seat_pools' => $this->seatPools(),
            // Plaetze fuer ein Produkt ohne Zugang: angenommen, und niemand
            // kommt irgendwo hinein. Das Formular sagt es, bevor verkauft wird.
            'seats_grant_nothing' => $this->resource->seatCount() !== null && SeatPools::grantsOf($this->resource) === [],
            'currency' => $this->currency(),
            'compare_at' => $this->money($this->effectiveCompareAtCent()),
            'own_price' => $this->amount_cent !== null,
            'discount_percent' => $this->discount_percent,
            // Scarcity, worked out here so the column and any report agree.
            // `remaining` is null for "no limit"; the label says which of the
            // four states the row is in, the flag colours it.
            'availability' => $this->availability(),
            'sold' => OfferSales::soldForListing($this->resource),
            // Paid revenue in the offer's currency, or null when the payment
            // tables are not there to be asked. The column itself is dropped
            // in that case, so null never reaches the screen as "0".
            'revenue' => OfferSales::available() ? $this->money(OfferSales::revenueCent($this->resource) ?? 0) : null,
            // Shown in the form so a change in the wording is visibly a new
            // version. The hash, not the text: the text is what the form edits.
            'withdrawal_version' => $this->withdrawalTerms()['version'],
            'slot' => $this->slot,
            'slot_label' => __('statamic-offers::messages.slot_'.$this->slot),
            // A count, not a list: the column has one line and a row carrying
            // four bumps would push the price out of view. Null rather than 0,
            // because a column full of zeroes reads as a broken feature while
            // an empty cell reads as "none".
            'bumps_count' => count((array) ($this->bumps ?? [])) ?: null,
            'active' => $this->active,
            // Two values rather than one: the label is what the column reads,
            // the flag is what lets the screen mark "none" as the exception it
            // is. Working that out in the template would put the vocabulary of
            // the column in two places.
            'confirmation' => __('statamic-offers::messages.confirmation_'.($this->confirmation_mode ?: Offer::CONFIRMATION_DEFAULT)),
            'confirmation_silent' => ! $this->sendsConfirmation(),
            'sellable' => $this->isSellable(),
            'shown_count' => $this->shown_count,
            'accepted_count' => $this->accepted_count,
            // Worked out here rather than in the template so the screen and any
            // report agree, and so nobody divides by zero in Antlers.
            'conversion' => $this->shown_count > 0
                ? CpNumber::decimal($this->accepted_count / $this->shown_count * 100, 1)
                : null,
            // Was an diesem Angebot haengt. Am Zeilen-Objekt und nicht als
            // eigener Abruf, damit das Formular es beim Aufklappen schon hat —
            // ein Kaestchen, das erst nachlaedt, ist eins, das im Zweifel leer
            // aussieht.
            'usage' => OfferUsage::forHandle($this->handle),
            'edit_values' => [
                'name' => $this->name,
                'handle' => $this->handle,
                'product' => $this->product,
                'products' => array_values((array) ($this->products ?? [])),
                'amount_cent' => $this->amount_cent,

                // Der Zahlungsrhythmus des Angebots. `null` heisst einmalig —
                // das Formular zeigt dann leere Felder. Ohne diese Zeilen
                // liesse sich ein bestehender Plan im Control Panel nicht
                // sehen und beim naechsten Speichern still ueberschreiben.
                'interval' => $this->interval,
                'times' => $this->times,
                'trial_days' => $this->trial_days,
                'trial_amount_cent' => $this->trial_amount_cent,
                // Normalisiert, nicht roh: das Formular soll dieselben Zeilen
                // zurueckbekommen, die die Kasse zeigt. Eine halbe Zeile, die
                // der Resolver ohnehin wegwirft, im Formular stehen zu lassen
                // hiesse, sie bei jedem Speichern erneut zu bestaetigen.
                'pricing_options' => $this->resource->pricingOptions(),
                'compare_at_cent' => $this->compare_at_cent,
                'discount_percent' => $this->discount_percent,
                'quantity_limit' => $this->quantity_limit,
                // `datetime-local` wants exactly this shape and no zone; the
                // zone is the app's, and the screen says so beside the field.
                'available_from' => $this->available_from?->format('Y-m-d\TH:i'),
                'available_until' => $this->available_until?->format('Y-m-d\TH:i'),
                'access_starts_at' => $this->access_starts_at?->format('Y-m-d'),
                'access_days' => $this->access_days,
                'checkout_fields' => $this->checkout_fields ?? [],
                'withdrawal_days' => $this->withdrawal_days,
                'withdrawal_text' => $this->withdrawal_text,
                'withdrawal_waiver_text' => $this->withdrawal_waiver_text,
                'withdrawal_checkbox_required' => (bool) ($this->withdrawal_checkbox_required ?? true),
                'withdrawal_b2b_text' => $this->withdrawal_b2b_text,
                'withdrawal_pdf' => (bool) $this->withdrawal_pdf,
                'currency' => $this->currency,
                'headline' => $this->headline,
                'body' => $this->body,
                'button_label' => $this->button_label,
                'image' => $this->image,
                'slot' => $this->slot,
                'bumps' => (array) ($this->bumps ?? []),
                'active' => $this->active,
                // Falls back rather than handing over an empty string: a row
                // written before this column existed has `null` here, and the
                // form would otherwise open with no mode selected and save the
                // offer into silence on the next click.
                'confirmation_mode' => $this->confirmation_mode ?: Offer::CONFIRMATION_DEFAULT,
                'confirmation_template' => $this->confirmation_template,
                'price_mode' => $this->resource->isPayWhatYouWant() ? Offer::PRICE_PWYW : Offer::PRICE_FIXED,
                'pwyw_min_cent' => $this->pwyw_min_cent,
                'pwyw_suggested_cent' => $this->pwyw_suggested_cent,
                'pwyw_max_cent' => $this->pwyw_max_cent,
                // Gesaeubert, wie die Zahlweisen: das Formular bekommt, was
                // wirkt, nicht was irgendwann in der Spalte stand.
                'pwyw_thanks' => $this->resource->thankYouTiers(),
                'setup_fee_cent' => $this->setup_fee_cent,
                'setup_fee_label' => $this->setup_fee_label,
                'country_mode' => $this->resource->countryMode(),
                'countries' => $this->resource->countryList(),
                'link_slug' => $this->link_slug,
                'link_target' => $this->link_target,
                'link_fallback' => $this->link_fallback,
                'link_switch_at' => $this->link_switch_at?->format('Y-m-d\TH:i'),
                'link_switch_on_sold_out' => (bool) ($this->link_switch_on_sold_out ?? true),
                'seats' => $this->seats,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function seatPools(): array
    {
        // Auch ohne `seats` am Angebot: wer die Plaetze spaeter abschaltet,
        // hat die verkauften Kontingente damit nicht aus der Welt.
        if (! $this->hasPoolsTable()) {
            return [];
        }

        return SeatPool::query()
            ->where('offer', $this->handle)
            ->with(['seatRows' => fn ($q) => $q->where('status', '!=', Seat::STATUS_REVOKED)->orderBy('id')])
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (SeatPool $pool) => [
                'id' => $pool->id,
                'owner_email' => $pool->owner_email,
                'owner_name' => $pool->owner_name,
                'seats' => $pool->seats,
                'taken' => $pool->seatRows->count(),
                'closed' => $pool->isClosed(),
                'closed_at' => $pool->closed_at?->locale(app()->getLocale())->isoFormat('L LT'),
                'closed_reason' => $pool->closed_reason,
                'payment_id' => $pool->payment_id,
                'created_at' => $pool->created_at?->locale(app()->getLocale())->isoFormat('L'),
                'manage_url' => route('statamic-offers.seats.manage', $pool->manage_token),
                'resend_url' => cp_route('utilities.offers.seats.resend', $pool->id),
                'rows' => $pool->seatRows->map(fn (Seat $seat) => [
                    'id' => $seat->id,
                    'email' => $seat->email,
                    'name' => $seat->name,
                    'claimed' => $seat->status === Seat::STATUS_CLAIMED,
                    'revoke_url' => cp_route('utilities.offers.seats.revoke', [$pool->id, $seat->id]),
                ])->values()->all(),
            ])
            ->all();
    }

    protected function hasPoolsTable(): bool
    {
        // Nur das Ja wird gemerkt: eine fehlende Tabelle fragt beim naechsten
        // Mal wieder, statt nach der Migration weiter „nein" zu sagen.
        static $da = false;

        return $da = $da || (Schema::hasTable('offer_seat_pools') && Schema::hasColumn('offer_seat_pools', 'closed_at'));
    }

    /**
     * @return array{url: string, destination: string, hits_target: int, hits_fallback: int, qr_svg: string, qr_png: string}|null
     */
    protected function shortLink(): ?array
    {
        $url = $this->resource->shortLinkUrl();

        if ($url === null) {
            return null;
        }

        return [
            'url' => $url,
            'destination' => $this->resource->linkDestination(),
            'hits_target' => (int) $this->link_hits_target,
            'hits_fallback' => (int) $this->link_hits_fallback,
            'qr_svg' => cp_route('utilities.offers.qr', ['offer' => $this->id, 'format' => 'svg']),
            'qr_png' => cp_route('utilities.offers.qr', ['offer' => $this->id, 'format' => 'png']),
        ];
    }

    protected function money(?int $cent): ?string
    {
        return $cent === null ? null : CpNumber::decimal($cent / 100, 2);
    }

    /**
     * One of four states, in words the column can print as they are.
     *
     * @return array{label: string, state: string, remaining: int|null}
     */
    protected function availability(): array
    {
        $remaining = $this->remainingForListing();
        $now = Carbon::now();

        if ($this->available_from !== null && $now->lt($this->available_from)) {
            return [
                'label' => __('statamic-offers::messages.availability_not_yet', ['date' => $this->moment($this->available_from)]),
                'state' => 'not_yet',
                'remaining' => $remaining,
            ];
        }

        if ($this->available_until !== null && $now->gt($this->available_until)) {
            return [
                'label' => __('statamic-offers::messages.availability_ended', ['date' => $this->moment($this->available_until)]),
                'state' => 'ended',
                'remaining' => $remaining,
            ];
        }

        if ($remaining === 0) {
            return ['label' => __('statamic-offers::messages.availability_sold_out'), 'state' => 'sold_out', 'remaining' => 0];
        }

        $parts = [];

        if ($remaining !== null) {
            $parts[] = __('statamic-offers::messages.availability_remaining', ['count' => $remaining, 'limit' => $this->quantity_limit]);
        }

        if ($this->available_until !== null) {
            $parts[] = __('statamic-offers::messages.availability_until', ['date' => $this->moment($this->available_until)]);
        }

        return [
            'label' => $parts === [] ? __('statamic-offers::messages.availability_unlimited') : implode(' · ', $parts),
            'state' => $parts === [] ? 'unlimited' : 'limited',
            'remaining' => $remaining,
        ];
    }

    /**
     * What is left, from the per-request map rather than a fresh query.
     *
     * One query for the page instead of one per row; the map counts paid plus
     * reserved units exactly as the checkout does, so the column and the
     * checkout agree. `null` is "no limit", and also "nothing to count
     * against" when the payment tables are missing.
     */
    protected function remainingForListing(): ?int
    {
        if ($this->quantity_limit === null) {
            return null;
        }

        $committed = OfferSales::committedForListing($this->resource);

        return $committed === null ? null : max(0, $this->quantity_limit - $committed);
    }

    /** The locale's own short date and time, like the coupons screen. */
    protected function moment(Carbon $moment): string
    {
        return $moment->locale(app()->getLocale())->isoFormat('L LT');
    }
}
