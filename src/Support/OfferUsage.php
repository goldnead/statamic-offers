<?php

namespace Goldnead\StatamicOffers\Support;

use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicFunnels\Models\FunnelStep;

/**
 * Was an einem Angebot haengt: Funnels und die Automationen daran.
 *
 * **Warum es das gibt.** Jede Verdrahtung in dieser Suite war unsichtbar.
 * Genau deshalb fiel einen Monat lang niemandem auf, dass nach einem Kauf
 * keine Bestaetigung rausging — man haette es nur gesehen, wenn man in vier
 * Tabellen nachgesehen haette. Kajabi zeigt am Angebot ein Kaestchen mit den
 * verknuepften Ablaeufen und schreibt in den Leerzustand ausdruecklich hinein,
 * dass es keine gibt. Der Leerzustand ist der wichtige Teil: „nichts
 * verdrahtet" muss dastehen, nicht fehlen.
 *
 * **Zwei Stufen, weil die Daten zwei Stufen haben.** Ein Angebot kennt keine
 * Automation; es kennt Funnel-Schritte, und die Automationen haengen am
 * Funnel. Der Weg ist Angebot → Schritte → Funnel → Automationen.
 *
 * **Einmal je Anfrage.** Eine Liste mit dreissig Angeboten wuerde sonst
 * sechzig Abfragen ausloesen. Der Speicher gilt fuer die Dauer einer Anfrage —
 * laenger waere er ein Cache, und ein Cache ueber Verdrahtungen ist genau das,
 * was jemandem eine Verbindung zeigt, die er gerade geloescht hat.
 *
 * Beide Nachbarn sind optional und bleiben es: fehlt einer, faellt seine
 * Haelfte weg, und das Kaestchen zeigt, was es weiss.
 */
class OfferUsage
{
    /** @var array<string, array{funnels: list<array{handle: string, title: string}>, automations: list<array{name: string, enabled: bool}>}>|null */
    private static ?array $karte = null;

    /**
     * @return array{funnels: list<array{handle: string, title: string}>, automations: list<array{name: string, enabled: bool}>}
     */
    public static function forHandle(string $handle): array
    {
        self::$karte ??= self::bauen();

        return self::$karte[$handle] ?? ['funnels' => [], 'automations' => []];
    }

    /**
     * Nur fuer Tests: den Speicher dieser Anfrage vergessen.
     *
     * Ein Testlauf ist ein einziger Prozess mit vielen „Anfragen" darin; ohne
     * das saehe der zweite Test die Verdrahtung des ersten.
     */
    public static function forget(): void
    {
        self::$karte = null;
    }

    /**
     * @return array<string, array{funnels: list<array{handle: string, title: string}>, automations: list<array{name: string, enabled: bool}>}>
     */
    private static function bauen(): array
    {
        $funnelsJeAngebot = [];
        $angeboteJeFunnel = [];

        if (class_exists('Goldnead\\StatamicFunnels\\Models\\FunnelStep')) {
            $schritte = FunnelStep::query()
                ->where('type', 'offer')
                ->with('funnel')
                ->get();

            foreach ($schritte as $schritt) {
                // In PHP gefiltert und nicht per JSON-Abfrage: `config` ist eine
                // JSON-Spalte, und die Syntax dafuer ist auf SQLite, MySQL und
                // Postgres verschieden genug, dass eine davon still nichts
                // faende — und „nichts verdrahtet" ist hier die gefaehrlichste
                // falsche Antwort, weil sie beruhigt.
                $angebot = (string) data_get($schritt->config ?? [], 'offer');
                $funnel = $schritt->funnel;

                if ($angebot === '' || $funnel === null) {
                    continue;
                }

                $funnelsJeAngebot[$angebot][(string) $funnel->handle] = [
                    'handle' => (string) $funnel->handle,
                    'title' => (string) ($funnel->title ?: $funnel->handle),
                ];

                $angeboteJeFunnel[(string) $funnel->handle][$angebot] = true;
            }
        }

        $automationenJeFunnel = [];

        if ($angeboteJeFunnel !== [] && class_exists('Goldnead\\StatamicAutomations\\Models\\AutomationNode')) {
            $knoten = AutomationNode::query()
                ->with('automation')
                ->get();

            foreach ($knoten as $node) {
                $funnel = (string) data_get($node->config ?? [], 'funnel');
                $automation = $node->automation;

                if ($funnel === '' || $automation === null || ! isset($angeboteJeFunnel[$funnel])) {
                    continue;
                }

                $automationenJeFunnel[$funnel][(string) $automation->getKey()] = [
                    'name' => (string) ($automation->name ?: $automation->handle),
                    // Eine abgeschaltete Automation ist verdrahtet und tut
                    // nichts. Sie zu verschweigen waere dieselbe Sorte
                    // Beruhigung wie ein leeres Kaestchen.
                    'enabled' => (bool) $automation->enabled,
                ];
            }
        }

        $karte = [];

        foreach ($funnelsJeAngebot as $angebot => $funnels) {
            $automationen = [];

            foreach (array_keys($funnels) as $funnelHandle) {
                foreach ($automationenJeFunnel[$funnelHandle] ?? [] as $id => $zeile) {
                    $automationen[$id] = $zeile;
                }
            }

            $karte[$angebot] = [
                'funnels' => array_values($funnels),
                'automations' => array_values($automationen),
            ];
        }

        return $karte;
    }
}
