<?php

namespace Goldnead\StatamicOffers\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\ByteMatrix;
use BaconQrCode\Encoder\Encoder;

/**
 * Ein QR-Code, hier erzeugt, ohne fremden Dienst.
 *
 * Der Kodierer ist `bacon/bacon-qr-code`, den Statamic selbst fuer die
 * Zwei-Faktor-Anmeldung mitbringt; es kommt also keine Abhaengigkeit dazu, die
 * nicht schon auf jeder Installation liegt. Gezeichnet wird hier, aus der
 * Punktmatrix: SVG als ein einziger Pfad, PNG von Hand geschrieben.
 *
 * **PNG ohne GD und ohne Imagick.** Beides sind Erweiterungen, die auf einem
 * Shared Hosting fehlen koennen, und ein Download-Knopf, der dort still einen
 * Fehler liefert, ist genau die Sorte Ausfall, die erst beim Druck auffaellt.
 * Ein schwarz-weisses PNG ist ein Kopf, ein zlib-Strom und eine Pruefsumme;
 * das schreibt PHP selbst.
 *
 * Fehlerkorrektur `M` (15 %): genug fuer einen Flyer, der gefaltet oder
 * leicht verschmutzt ist, ohne das Muster fuer einen langen Link zu dicht zu
 * machen. Der Rand ist die Ruhezone von vier Modulen, die die Norm verlangt.
 */
final class QrCode
{
    public const QUIET_ZONE = 4;

    public static function svg(string $text, int $size = 512): string
    {
        $matrix = self::matrix($text);
        $breite = $matrix->getWidth() + 2 * self::QUIET_ZONE;
        $pfad = '';

        for ($y = 0; $y < $matrix->getHeight(); $y++) {
            for ($x = 0; $x < $matrix->getWidth(); $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $pfad .= 'M'.($x + self::QUIET_ZONE).' '.($y + self::QUIET_ZONE).'h1v1h-1z';
                }
            }
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" viewBox="0 0 '.$breite.' '.$breite.'" shape-rendering="crispEdges">'
            .'<rect width="'.$breite.'" height="'.$breite.'" fill="#ffffff"/>'
            .'<path fill="#000000" d="'.$pfad.'"/>'
            .'</svg>'."\n";
    }

    /**
     * @param  int  $size  Kantenlaenge in Pixeln, ungefaehr: gerundet auf ganze
     *                     Pixel je Modul, damit keine Kante verschwimmt.
     */
    public static function png(string $text, int $size = 1024): string
    {
        $matrix = self::matrix($text);
        $module = $matrix->getWidth() + 2 * self::QUIET_ZONE;
        $skala = max(1, intdiv($size, $module));
        $pixel = $module * $skala;

        // Eine Zeile je Pixelreihe, 1 Bit je Pixel, vorn das Filterbyte 0.
        // Gesetztes Bit ist weiss (Graustufe 1), damit die Ruhezone ohne
        // Sonderfall entsteht.
        $roh = '';

        for ($py = 0; $py < $pixel; $py++) {
            $my = intdiv($py, $skala) - self::QUIET_ZONE;
            $bits = '';

            for ($px = 0; $px < $pixel; $px++) {
                $mx = intdiv($px, $skala) - self::QUIET_ZONE;
                $dunkel = $mx >= 0 && $my >= 0 && $mx < $matrix->getWidth() && $my < $matrix->getHeight()
                    && $matrix->get($mx, $my) === 1;
                $bits .= $dunkel ? '0' : '1';
            }

            $bits = str_pad($bits, (int) ceil($pixel / 8) * 8, '1');
            $roh .= "\0";

            foreach (str_split($bits, 8) as $byte) {
                $roh .= chr((int) bindec($byte));
            }
        }

        $kopf = pack('NNCCCCC', $pixel, $pixel, 1, 0, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            .self::chunk('IHDR', $kopf)
            .self::chunk('IDAT', (string) gzcompress($roh, 9))
            .self::chunk('IEND', '');
    }

    private static function chunk(string $typ, string $daten): string
    {
        return pack('N', strlen($daten)).$typ.$daten.pack('N', crc32($typ.$daten));
    }

    private static function matrix(string $text): ByteMatrix
    {
        return Encoder::encode($text, ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ENCODING)->getMatrix();
    }
}
