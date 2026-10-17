<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\StatamicOffers\Contracts\BoxAudience;
use Illuminate\Support\Str;
use Throwable;

/**
 * Die eingebaute Zielgruppenregel eines Inhalts-Kastens.
 *
 * - `only_tags` und `only_groups`: ein Treffer genuegt, ueber beide Listen.
 *   Wer nicht erkannt wird (anonym, kein Kontakt, LeadHub fehlt), sieht den
 *   Kasten nicht: eine Einschraenkung oeffnet sich nie von allein.
 * - `not_tags`: wer eines dieser Merkmale traegt, sieht ihn nicht. Wer nicht
 *   erkannt wird, sieht ihn (es gibt nichts, wovon er auszuschliessen waere).
 *
 * Merkmale werden ohne Beachtung von Gross- und Kleinschreibung und auch in
 * Slug-Form verglichen (`Leitet Chor` trifft `leitet-chor`).
 */
class DefaultBoxAudience implements BoxAudience
{
    public const LEADHUB = 'Goldnead\\Leadhub\\LeadHubManager';

    public function matches(mixed $viewer, array $rule): bool
    {
        $onlyTags = $rule['only_tags'];
        $notTags = $rule['not_tags'];
        $onlyGroups = $rule['only_groups'];

        if ($onlyTags === [] && $notTags === [] && $onlyGroups === []) {
            return true;
        }

        $tags = $this->tags($viewer);

        foreach ($notTags as $tag) {
            if (in_array($this->normalise($tag), $tags, true)) {
                return false;
            }
        }

        if ($onlyTags === [] && $onlyGroups === []) {
            return true;
        }

        foreach ($onlyTags as $tag) {
            if (in_array($this->normalise($tag), $tags, true)) {
                return true;
            }
        }

        $user = is_object($viewer) && method_exists($viewer, 'isInGroup') ? $viewer : null;

        foreach ($onlyGroups as $group) {
            if ($user !== null && $user->isInGroup($group)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    protected function tags(mixed $viewer): array
    {
        $email = match (true) {
            is_string($viewer) => $viewer,
            is_object($viewer) && method_exists($viewer, 'email') => $viewer->email(),
            is_object($viewer) && isset($viewer->email) => $viewer->email,
            default => null,
        };

        if (! is_string($email) || $email === '' || ! class_exists(self::LEADHUB)) {
            return [];
        }

        try {
            $contact = app(self::LEADHUB)->findByEmail($email);
        } catch (Throwable) {
            return [];
        }

        $tags = [];

        foreach (is_array($contact) ? ($contact['tags'] ?? []) : [] as $tag) {
            $tags[] = $this->normalise($tag);
        }

        return $tags;
    }

    protected function normalise(mixed $tag): string
    {
        return Str::slug((string) $tag);
    }
}
