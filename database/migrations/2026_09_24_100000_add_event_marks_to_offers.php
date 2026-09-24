<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wann ein Angebot ausverkauft war, wann seine Link-Weiche umschlug, und
     * welche Zahlung welchen Gutschein eingeloest hat.
     *
     * Alle drei sind Momente, die genau einmal gemeldet werden sollen, und
     * keiner wird an einer Stelle entschieden, sondern von dem, der ihn als
     * Erster bemerkt: die bezahlte Zahlung, der erste Aufruf nach dem
     * Stichtag. Die Wache ist ein bedingtes UPDATE auf die Spalten
     * (`whereNull`) bzw. der eindeutige Index der Einloesungen, nicht eine
     * Abfrage davor: zwei Zustellungen derselben Sekunde saehen beide „noch
     * nicht gemeldet". payments liefert „bezahlt" erneut, wenn ein Zuhoerer
     * scheitert; die Einloesung zaehlt trotzdem einmal.
     *
     * Geht das Angebot wieder auf (Grenze erhoeht, Stichtag verschoben), wird
     * die Spalte geleert, und der naechste Wechsel ist wieder einer.
     */
    public function up(): void
    {
        if (Schema::hasTable('offers')) {
            Schema::table('offers', function (Blueprint $table) {
                if (! Schema::hasColumn('offers', 'sold_out_at')) {
                    $table->timestamp('sold_out_at')->nullable();
                }
                if (! Schema::hasColumn('offers', 'link_switched_at')) {
                    $table->timestamp('link_switched_at')->nullable();
                }
            });
        }

        if (! Schema::hasTable('offer_coupon_redemptions')) {
            Schema::create('offer_coupon_redemptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('coupon_id')->index();
                $table->unsignedBigInteger('payment_id');
                $table->timestamp('created_at')->nullable();

                $table->unique(['coupon_id', 'payment_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_coupon_redemptions');

        if (! Schema::hasTable('offers')) {
            return;
        }

        $spalten = array_values(array_filter(
            ['sold_out_at', 'link_switched_at'],
            fn (string $spalte) => Schema::hasColumn('offers', $spalte),
        ));

        if ($spalten !== []) {
            Schema::table('offers', fn (Blueprint $table) => $table->dropColumn($spalten));
        }
    }
};
