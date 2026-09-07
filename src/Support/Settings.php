<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;
use Goldnead\StatamicOffers\Models\Offer;

/**
 * Was ein Betreiber an diesem Addon ändern darf, und die einzige Stelle, die
 * das weiß.
 *
 * Diese Klasse ist **nur** die Feldliste. Bildschirm, Formular, Validierung,
 * Speicher, Rechteprüfung und die Markendimension stellt
 * `goldnead/statamic-brand-context` (siehe {@see ProvidesSettings}). Kein
 * eigener Controller, keine eigene Vue-Seite, keine eigene Route, keine
 * `offers_settings`-Tabelle.
 *
 * **Warum dieses Addon eine Seite braucht.** `seller.name` und die
 * Widerrufsbelehrung sind der Text, den ein Käufer vor dem Bezahlen liest. Sie
 * stehen heute als Paket-Vorgabe in `config/statamic-offers.php`, und die
 * Config sagt über den Verkäufernamen selbst, er sei „wrong the moment a legal
 * entity sells here, so fill it in" — eine Aufforderung an jemanden, der keinen
 * Dateizugriff hat. Die Belehrung wird ausdrücklich als anwaltlich zu prüfender
 * Entwurf ausgeliefert; wer sie prüfen lässt, muss sie danach ändern können.
 *
 * **Überschreibungen, keine Kopie.** Gespeichert wird nur, was jemand wirklich
 * geändert hat. Alles andere folgt weiter der Config-Datei, ein Paket-Update
 * verschiebt also die Vorgaben weiterhin.
 *
 * **Was nicht hier steht, und warum.**
 *
 * - `checkout_fields` — eine verschachtelte Feldbibliothek (Beschriftung, Typ,
 *   Pflicht, Optionen, Regeln je Eintrag). Der Vertrag kennt fünf flache Typen
 *   und bewusst keinen sechsten für Abbildungen. Sie bleibt in der Config und
 *   wird auf dem Bildschirm in der Gruppenbeschreibung benannt statt
 *   verschwiegen.
 * - `handle_prefix` — steht in gespeicherten Referenzen (`offer:fruehling`) und
 *   in Templates. Ein Wechsel unter laufendem Betrieb macht jede bestehende
 *   Referenz ungültig, ohne dass das wie eine Verwerfung aussieht.
 */
class Settings implements ProvidesSettings
{
    /**
     * Bleibt für immer so: er steht in `brand_settings.namespace` auf jeder
     * Zeile, ein Umbenennen verwaist also jede gespeicherte Überschreibung.
     */
    public static function settingsNamespace(): string
    {
        return 'offers';
    }

    /**
     * Die Config-Wurzel, der ungesetzte Werte weiter folgen. Sie heißt anders
     * als der Namensraum — `config('statamic-offers.…')` ist, was jede Stelle
     * in diesem Addon liest.
     */
    public static function settingsConfigPath(): string
    {
        return 'statamic-offers';
    }

    /**
     * Das Recht, das diesen Abschnitt bewacht.
     *
     * Neu vergeben, nicht abgeleitet: dieses Addon hatte bisher keine
     * Einstellungen und damit auch kein Recht dafür. Die bestehenden Rechte
     * (`access offers utility`, `access coupons utility`) bleiben unangetastet
     * — sie umzubenennen wäre ein stiller Rechteentzug auf jeder Installation,
     * die sie vergeben hat.
     */
    public static function settingsPermission(): string
    {
        return 'manage offers settings';
    }

    /**
     * Die Felder, in der Reihenfolge und Gruppierung des Bildschirms.
     *
     * Jeder Schlüssel wird zur Laufzeit gelesen — `seller` und `withdrawal` in
     * {@see Offer::withdrawalTerms()},
     * `count_impressions` beim Rendern des Tags. Keiner davon läuft beim
     * Booten, in einer Route oder bei der Nav-Registrierung; sonst wäre er hier
     * eine Lüge: `SettingsManager::apply()` läuft aus `app->booted()`, und was
     * vorher gelesen wird, sieht noch den Paketwert.
     *
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('statamic-offers::settings.groups.seller.title'),
                'description' => __('statamic-offers::settings.groups.seller.description'),
                'fields' => [
                    static::field('seller.name', 'string', ['nullable' => true]),
                    static::field('seller.contact', 'string', ['nullable' => true]),
                ],
            ],
            [
                'title' => __('statamic-offers::settings.groups.withdrawal.title'),
                'description' => __('statamic-offers::settings.groups.withdrawal.description'),
                'fields' => [
                    // Kein Deckel nach oben und keine Null: eine Frist von null
                    // Tagen ist kein Widerrufsrecht, sondern dessen Abschaffung.
                    static::field('withdrawal.days', 'integer', ['min' => 1]),
                    // `text`, nicht `string`. Die Belehrung ist ein Absatz von
                    // rund tausend Zeichen; als `string` würde das Formular eine
                    // einzeilige Box zeigen und die Validierung bei 255 Zeichen
                    // abschneiden.
                    static::field('withdrawal.text', 'text'),
                    static::field('withdrawal.waiver_text', 'text'),
                    static::field('withdrawal.b2b_text', 'text', ['nullable' => true]),
                    static::field('withdrawal.checkbox_required', 'boolean'),
                ],
            ],
            [
                'title' => __('statamic-offers::settings.groups.display.title'),
                'description' => __('statamic-offers::settings.groups.display.description'),
                'fields' => [
                    static::field('count_impressions', 'boolean'),
                ],
            ],
        ];
    }

    /**
     * Ein Feld, mit Beschriftung und Hilfetext aus den Sprachdateien.
     *
     * Der Übersetzungsschlüssel trägt den Config-Pfad mit flachgelegten Punkten:
     * ein Punkt in einem Sprachschlüssel ist für den Übersetzer ein
     * Pfadtrenner, und `settings.fields.seller.name.label` würde als drei
     * verschachtelte Arrays gesucht, die es nicht gibt.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function field(string $key, string $type, array $extra = []): array
    {
        $handle = str_replace('.', '_', $key);

        return array_merge([
            'key' => $key,
            'type' => $type,
            'label' => __("statamic-offers::settings.fields.{$handle}.label"),
            'description' => __("statamic-offers::settings.fields.{$handle}.description"),
            'nullable' => false,
        ], $extra);
    }
}
