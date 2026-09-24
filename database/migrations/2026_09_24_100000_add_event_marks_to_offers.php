<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wann ein Angebot ausverkauft war und wann seine Link-Weiche umschlug.
     *
     * Beides sind Momente, die genau einmal gemeldet werden sollen, und beide
     * werden nicht an einer Stelle entschieden, sondern von dem, der sie als
     * Erster bemerkt: die bezahlte Zahlung, der erste Aufruf nach dem
     * Stichtag. Die Wache ist ein bedingtes UPDATE auf diese Spalten (`whereNull`),
     * nicht eine Abfrage davor: zwei Zustellungen derselben Sekunde saehen
     * beide „noch nicht gemeldet".
     *
     * Geht das Angebot wieder auf (Grenze erhoeht, Stichtag verschoben), wird
     * die Spalte geleert, und der naechste Wechsel ist wieder einer.
     */
    public function up(): void
    {
        if (! Schema::hasTable('offers')) {
            return;
        }

        Schema::table('offers', function (Blueprint $table) {
            if (! Schema::hasColumn('offers', 'sold_out_at')) {
                $table->timestamp('sold_out_at')->nullable();
            }
            if (! Schema::hasColumn('offers', 'link_switched_at')) {
                $table->timestamp('link_switched_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('offers')) {
            return;
        }

        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn(['sold_out_at', 'link_switched_at']);
        });
    }
};
