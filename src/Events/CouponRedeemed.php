<?php

namespace Goldnead\StatamicOffers\Events;

use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A coupon was redeemed: a payment that used it is paid.
 *
 * Off the paid event, not off the moment the code was typed or the basket
 * claimed a use. A claimed use is given back when the checkout is refused;
 * a paid one is a redemption.
 */
class CouponRedeemed
{
    use Dispatchable;

    /** The brand of the payment, null on a site without brands. */
    public readonly ?int $brandId;

    public function __construct(
        public readonly Coupon $coupon,
        public readonly Payment $payment,
        ?int $brandId = null,
    ) {
        $this->brandId = $brandId ?? ((int) $payment->brand_id > 0 ? (int) $payment->brand_id : null);
    }
}
