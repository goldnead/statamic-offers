<?php

namespace Goldnead\StatamicOffers\Contracts;

/**
 * Wer einen Inhalts-Kasten sehen darf.
 *
 * Ein Vertrag, damit eine Site die Regel selbst stellen kann (ein Merkmal aus
 * einem signierten Klick-Link, ein Cookie, eine eigene Segmentierung), ohne
 * dass der Kasten davon abhaengt. Die eingebaute Regel liest Benutzergruppen
 * und die Merkmale des LeadHub-Kontakts, wenn LeadHub installiert ist.
 */
interface BoxAudience
{
    /**
     * @param  mixed  $viewer  Ein Statamic-Benutzer, eine E-Mail-Adresse oder null (anonym).
     * @param  array{only_tags: list<string>, not_tags: list<string>, only_groups: list<string>}  $rule
     */
    public function matches(mixed $viewer, array $rule): bool;
}
