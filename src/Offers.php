<?php

namespace Goldnead\StatamicOffers;

/**
 * The addon's static surface for siblings.
 *
 * A plain class rather than a Laravel facade on purpose: the neighbours call
 * this behind `method_exists()`, and a facade answers that question with
 * `false` for everything it forwards through `__callStatic`.
 */
final class Offers
{
    /** The field types the library understands; anything else is read as text. */
    public const FIELD_TYPES = ['text', 'select', 'checkbox'];

    /**
     * Every field a checkout could ask for, normalised.
     *
     * Read from `config('statamic-offers.checkout_fields')` on every call so a
     * site's own additions appear without a cache to clear. Labels go through
     * `__()`: a translation key becomes the translation, plain text stays as
     * it is.
     *
     * @return array<string, array{key: string, label: string, type: string, required: bool, options: array<string, string>|null, rules: list<string>}>
     */
    public static function fieldLibrary(): array
    {
        $library = [];

        foreach ((array) config('statamic-offers.checkout_fields', []) as $key => $definition) {
            if (! is_string($key) || $key === '' || ! is_array($definition)) {
                continue;
            }

            $type = (string) ($definition['type'] ?? 'text');

            if (! in_array($type, self::FIELD_TYPES, true)) {
                $type = 'text';
            }

            $options = null;

            if ($type === 'select' && is_array($definition['options'] ?? null)) {
                $options = [];

                foreach ($definition['options'] as $value => $label) {
                    $options[(string) $value] = __((string) $label);
                }
            }

            $library[$key] = [
                'key' => $key,
                'label' => __((string) ($definition['label'] ?? $key)),
                'type' => $type,
                'required' => (bool) ($definition['required'] ?? false),
                'options' => $options,
                'rules' => array_values(array_filter((array) ($definition['rules'] ?? []), 'is_string')),
            ];
        }

        return $library;
    }

    /**
     * Just the keys, for a validation rule.
     *
     * @return list<string>
     */
    public static function fieldKeys(): array
    {
        return array_keys(self::fieldLibrary());
    }
}
