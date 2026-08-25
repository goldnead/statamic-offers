<?php

namespace Goldnead\StatamicOffers\Support;

use NumberFormatter;

/**
 * Numbers, written the way the Control Panel's language writes them.
 *
 * One place rather than two, because the alternative showed itself on screen:
 * the offers listing said "5.00 EUR" and the coupons listing next door said
 * "5,00 EUR" in the same German Control Panel, which reads as two different
 * products rather than two screens of one addon.
 *
 * Formatting lives on the server because that is where the locale is already
 * settled — core's `Localize` middleware has run by the time a resource is
 * built, while a number assembled in Javascript would follow the browser.
 */
class CpNumber
{
    /**
     * `ext-intl` is not required by this package, so its absence has to mean a
     * plainer number rather than a 500.
     */
    public static function decimal(float|int $value, int $decimals): string
    {
        if (! class_exists(NumberFormatter::class)) {
            return number_format($value, $decimals, '.', '');
        }

        $formatter = new NumberFormatter(app()->getLocale(), NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        return (string) $formatter->format($value);
    }
}
