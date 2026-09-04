<?php

namespace Goldnead\StatamicOffers\Tests\Support;

/**
 * Steht für die Fassade von `goldnead/statamic-email-templates`, wenn das
 * Paket nicht installiert ist.
 *
 * Das Addon erkennt das Schwester-Paket an `class_exists()` auf seiner
 * Fassade; geprüft wird nur die Anwesenheit, nie ein Verhalten. Deshalb reicht
 * eine leere Klasse, die per `class_alias()` unter den echten Namen gelegt
 * wird. Vorher stand hier `\stdClass` — das verbietet `class_alias()` bis
 * PHP 8.2 („must be a user-defined class name"), erst 8.3 lässt interne
 * Klassen zu. Auf dem 8.2-Bein der Matrix warf jeder Test mit Vorlagen
 * deshalb einen `ValueError`.
 */
final class EmailTemplatesFacadeStandIn {}
