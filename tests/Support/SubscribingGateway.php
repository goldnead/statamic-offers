<?php

namespace Goldnead\StatamicOffers\Tests\Support;

use Goldnead\StatamicPayments\Contracts\SubscriptionGateway;
use Goldnead\StatamicPayments\Support\RemotePayment;
use Goldnead\StatamicPayments\Support\RemoteSubscription;
use RuntimeException;

/**
 * Der Anbieter-Ersatz, der auch Abos beginnen kann.
 *
 * Ohne das gibt `Subscriptions::available()` false, und `start()` liefert null:
 * ein Test, der den Weg eines Abos bis zur ersten Zahlung gehen will, kaeme nie
 * dorthin. Nur die erste Zahlung wird hier gebraucht; die Vereinbarung selbst
 * legt erst die Erfuellung an.
 */
class SubscribingGateway extends FakeGateway implements SubscriptionGateway
{
    public function supportsSubscriptions(): bool
    {
        return true;
    }

    public function supportsFollowUp(): bool
    {
        return false;
    }

    public function rememberBuyer(array $buyer): string
    {
        throw new RuntimeException('nicht Teil dieser Tests');
    }

    public function chargeAgain(string $customerReference, array $payload): RemotePayment
    {
        throw new RuntimeException('nicht Teil dieser Tests');
    }

    public function createSubscription(string $customerReference, array $payload): RemoteSubscription
    {
        throw new RuntimeException('nicht Teil dieser Tests');
    }

    public function cancelSubscription(string $customerReference, string $subscriptionId): RemoteSubscription
    {
        throw new RuntimeException('nicht Teil dieser Tests');
    }

    public function fetchSubscription(string $customerReference, string $subscriptionId): RemoteSubscription
    {
        throw new RuntimeException('nicht Teil dieser Tests');
    }
}
