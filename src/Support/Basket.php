<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Support\Discount;

/**
 * What somebody actually agreed to buy on an offer page.
 *
 * One offer, the bumps they ticked, and the code they typed. Built entirely on
 * the server from a *list of handles and a string*: the page says which boxes
 * were checked and what was typed in the field, and nothing else about the
 * request is believed. Prices come from the offers table, the discount comes
 * from the coupons table, and the payment addon looks the products up again in
 * the catalogue before charging anything.
 *
 * The reason for the class rather than a few lines in a controller: an offer
 * page in a funnel and an offer page in somebody's own template have to agree
 * about what a ticked box means, down to which bumps are allowed to be ticked.
 */
class Basket
{
    /**
     * @param  list<string>  $bumpHandles  What the browser says was ticked.
     */
    public static function make(Offer $offer, array $bumpHandles = [], ?string $code = null): self
    {
        return new self($offer, self::allowedBumps($offer, $bumpHandles), Coupon::findByCode($code));
    }

    /**
     * @param  list<Offer>  $bumps
     */
    protected function __construct(
        public readonly Offer $offer,
        public readonly array $bumps,
        protected readonly ?Coupon $coupon,
    ) {}

    /**
     * Only bumps this offer actually lists, and only ones that can be sold.
     *
     * Without this, a ticked box is whatever the browser says it is: somebody
     * could add a cheap handle to the form and buy an unrelated product, or add
     * an expensive one to somebody else's basket. The list on the offer is the
     * authority, not the form.
     *
     * @param  list<string>  $wanted
     * @return list<Offer>
     */
    protected static function allowedBumps(Offer $offer, array $wanted): array
    {
        $allowed = array_values(array_filter((array) ($offer->bumps ?? []), 'is_string'));

        if ($allowed === [] || $wanted === []) {
            return [];
        }

        $picked = array_values(array_intersect($allowed, array_filter($wanted, 'is_string')));

        if ($picked === []) {
            return [];
        }

        return Offer::query()
            ->whereIn('handle', $picked)
            ->get()
            ->filter(fn (Offer $bump) => $bump->isSellable() && $bump->handle !== $offer->handle)
            // Shown in the order the offer lists them, not the order the form
            // posted them: the editorial order is the one somebody chose.
            ->sortBy(fn (Offer $bump) => array_search($bump->handle, $allowed, true))
            ->values()
            ->all();
    }

    /**
     * The handles to hand the checkout. The offer first, bumps behind it.
     *
     * @return list<string>
     */
    public function handles(): array
    {
        $prefix = Offer::prefix();

        return array_map(
            fn (Offer $o) => $prefix.$o->handle,
            [$this->offer, ...$this->bumps],
        );
    }

    public function grossCent(): int
    {
        return array_sum(array_map(
            fn (Offer $o) => (int) $o->amountCent(),
            [$this->offer, ...$this->bumps],
        ));
    }

    public function currency(): string
    {
        return $this->offer->currency();
    }

    /** The code as it will be applied, or null if it does not apply here. */
    public function coupon(): ?Coupon
    {
        if (! $this->coupon || ! $this->coupon->isLive() || ! $this->coupon->appliesTo($this->offer)) {
            return null;
        }

        return $this->coupon;
    }

    /**
     * What the payment addon should take off the total.
     *
     * Null when there is nothing to take off, so the ordinary path stays the
     * ordinary path and a payment with no coupon carries no discount columns.
     */
    public function discount(): ?Discount
    {
        $coupon = $this->coupon();

        if (! $coupon) {
            return null;
        }

        $gross = $this->grossCent();
        $off = $gross - $coupon->apply($gross, $this->currency());

        if ($off <= 0) {
            return null;
        }

        // Claimed here, at the moment a basket becomes a payment, not when the
        // code was typed. Somebody who types a code and closes the tab has not
        // used it up. If the last use went to somebody else in between, the
        // sale still happens, at full price.
        if (! $coupon->claim()) {
            return null;
        }

        return new Discount($coupon->code, $off, $coupon->name);
    }

    public function netCent(): int
    {
        $coupon = $this->coupon();
        $gross = $this->grossCent();

        return $coupon ? $coupon->apply($gross, $this->currency()) : $gross;
    }
}
