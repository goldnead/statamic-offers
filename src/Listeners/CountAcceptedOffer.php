<?php

namespace Goldnead\StatamicOffers\Listeners;

use Goldnead\StatamicOffers\Support\OfferAcceptance;
use Goldnead\StatamicPayments\Events\PaymentPaid;

/** Autoloaded by core off the first parameter type below. */
class CountAcceptedOffer
{
    public function __construct(protected OfferAcceptance $acceptance) {}

    public function handle(PaymentPaid $event): void
    {
        $this->acceptance->handle($event);
    }
}
