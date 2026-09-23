<?php

namespace Goldnead\StatamicOffers\Support;

use Illuminate\Http\Response;

/**
 * Ein QR-Code als Datei zum Herunterladen, fuer beide Bildschirme im CP.
 *
 * `?inline=1` liefert ihn zum Anzeigen statt zum Speichern: die Vorschau im
 * Formular ist dasselbe Bild wie der Download, nicht ein zweites, das anders
 * gerechnet wurde.
 */
final class QrDownload
{
    public static function response(string $url, string $format, string $filename): Response
    {
        $png = $format === 'png';
        $inhalt = $png ? QrCode::png($url) : QrCode::svg($url);
        $datei = preg_replace('/[^a-z0-9_-]+/i', '-', $filename).($png ? '.png' : '.svg');

        return new Response($inhalt, 200, [
            'Content-Type' => $png ? 'image/png' : 'image/svg+xml; charset=utf-8',
            'Content-Disposition' => (request()->boolean('inline') ? 'inline' : 'attachment').'; filename="'.$datei.'"',
            // Ein Bild aus dem Control Panel, das sich mit dem Link aendert:
            // nicht zwischenspeichern.
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
