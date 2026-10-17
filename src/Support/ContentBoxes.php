<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\StatamicOffers\Contracts\BoxAudience;
use Goldnead\StatamicOffers\Models\Offer;
use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

/**
 * Inhalts-Kaesten lesen.
 *
 * Ein Kasten ist ein Eintrag der Sammlung `content_boxes`: eine kleine
 * Einladung mit Ueberschrift, Text, Knopf und Ziel, die eine Vorlage, eine
 * Lektion oder ein Artikel an einer Stelle zeigt. Er ist bewusst **kein
 * Angebot** (`Models\Offer`): ein Angebot braucht ein Produkt und einen Preis,
 * eine Warteliste hat beides nicht.
 *
 * Aufgeloest wird zu einem schlichten Array oder zu `null`. `null` heisst:
 * zeig nichts. Das gilt fuer einen Kasten, den es nicht gibt, einen
 * unveroeffentlichten, einen ohne Knopf oder ohne brauchbares Ziel, einen,
 * dessen Angebot gerade nicht gilt, und einen, den dieser Besucher nicht sehen
 * soll. Ein halber Kasten (Text ohne Knopf, Knopf ohne Ziel) ist schlechter
 * als keiner.
 */
class ContentBoxes
{
    public function __construct(protected BoxAudience $audience) {}

    public static function collection(): string
    {
        $handle = trim((string) config('statamic-offers.boxes.collection', 'content_boxes'));

        return $handle === '' ? 'content_boxes' : $handle;
    }

    /**
     * @param  mixed  $viewer  Statamic-Benutzer, E-Mail-Adresse oder null
     * @return array<string, mixed>|null
     */
    public function resolve(string $idOrSlug, mixed $viewer = null): ?array
    {
        $entry = $this->find($idOrSlug);

        if ($entry === null || ! $entry->published()) {
            return null;
        }

        $label = trim((string) $entry->get('button_label'));
        $offerHandle = trim((string) $entry->get('offer'));
        $url = trim((string) $entry->get('url'));

        if ($label === '') {
            return null;
        }

        if (! $this->audience->matches($viewer, [
            'only_tags' => $this->list($entry->get('only_tags')),
            'not_tags' => $this->list($entry->get('not_tags')),
            'only_groups' => $this->list($entry->get('only_groups')),
        ])) {
            return null;
        }

        $offer = null;

        if ($offerHandle !== '') {
            $offer = Offer::query()->forBrand()->where('handle', $offerHandle)->first();

            if ($offer === null) {
                Log::warning('statamic-offers: a content box names an offer that does not exist.', [
                    'box' => $entry->slug(),
                    'offer' => $offerHandle,
                ]);

                return null;
            }

            // Ausverkauft, abgelaufen, abgeschaltet: die Einladung zum Kauf
            // verschwindet mit dem Angebot, ohne dass jemand den Kasten sucht.
            if (! $offer->isSellable()) {
                return null;
            }

            // Der Kurzlink kommt aus der eigenen Config, nicht aus einem Feld,
            // das jemand tippt: er braucht die Pruefung unten nicht.
            if ($url === '') {
                $url = (string) $offer->shortLinkUrl();
                $checked = true;
            }
        }

        if ($url === '' || (! ($checked ?? false) && ! self::usableUrl($url))) {
            return null;
        }

        if ($offer !== null && config('statamic-offers.count_impressions', true)) {
            $offer->recordShown();
        }

        return [
            'id' => $entry->id(),
            'handle' => (string) $entry->slug(),
            'kicker' => trim((string) $entry->get('kicker')),
            'headline' => trim((string) ($entry->get('headline') ?: $entry->get('title'))),
            'body' => trim((string) $entry->get('body')),
            'button_label' => $label,
            'url' => $url,
            'offer' => $offer?->handle,
        ];
    }

    /**
     * Mehrere in der Reihenfolge der Liste; was nicht gilt, faellt heraus.
     *
     * @param  iterable<mixed>  $idsOrSlugs
     * @return list<array<string, mixed>>
     */
    public function resolveMany(iterable $idsOrSlugs, mixed $viewer = null): array
    {
        $boxes = [];

        foreach ($idsOrSlugs as $idOrSlug) {
            if (! is_string($idOrSlug) || $idOrSlug === '') {
                continue;
            }

            $box = $this->resolve($idOrSlug, $viewer);

            if ($box !== null) {
                $boxes[] = $box;
            }
        }

        return $boxes;
    }

    /**
     * Nur ein Pfad auf der eigenen Seite oder eine https-Adresse: kein
     * `javascript:`, keine protokollrelative Adresse auf einen fremden Host.
     */
    public static function usableUrl(string $url): bool
    {
        return preg_match('#^(https://|/(?!/))#i', trim($url)) === 1;
    }

    protected function find(string $idOrSlug): ?EntryContract
    {
        $entry = Entry::find($idOrSlug);

        if (! $entry instanceof EntryContract || $entry->collectionHandle() !== self::collection()) {
            // Eine Handvoll Eintraege je Site: lesen und vergleichen genuegt.
            $entry = Entry::whereCollection(self::collection())
                ->first(fn ($candidate) => $candidate->slug() === $idOrSlug);
        }

        return $entry instanceof EntryContract ? $entry : null;
    }

    /**
     * @return list<string>
     */
    protected function list(mixed $value): array
    {
        $items = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);

        return array_values(array_filter(array_map(fn ($item) => trim((string) $item), $items), fn (string $item) => $item !== ''));
    }
}
