<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\StatamicPayments\Support\Money;

/**
 * Ein Preis für die Funnel-Karte, so wie die Sprache des CP ihn schreibt:
 * „199,00 €" auf Deutsch (Symbol hinter der Zahl), „€199.00" auf Englisch.
 * Trenner und Stellung des Symbols stehen in den Sprachdateien, die
 * Nachkommastellen je Währung kommen von payments.
 *
 * Symbole gibt es für EUR, USD und GBP. CHF und jede andere Währung bleiben der
 * Code, mit Leerzeichen zur Zahl.
 */
final class MapPrice
{
    private const SYMBOLS = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'];

    public static function format(int $cent, string $currency): string
    {
        $currency = strtoupper(trim($currency));
        $decimals = Money::decimals($currency);

        $amount = number_format(
            $cent / (10 ** $decimals),
            $decimals,
            __('statamic-offers::messages.map_decimal_point'),
            __('statamic-offers::messages.map_thousands_separator'),
        );

        $symbol = self::SYMBOLS[$currency] ?? null;

        return __($symbol === null ? 'statamic-offers::messages.map_price_code' : 'statamic-offers::messages.map_price_symbol', [
            'amount' => $amount,
            'symbol' => $symbol ?? $currency,
        ]);
    }
}
