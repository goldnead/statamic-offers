<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mehrere Zahlweisen an einem Angebot.
     *
     * Die Spalten `interval`/`times`/`trial_*` daneben bleiben, was sie sind:
     * der eine Rhythmus des Angebots selbst. Diese Liste ergaenzt sie um den
     * Fall „dasselbe, aber der Kaeufer waehlt" — und jede Option traegt
     * denselben Feldsatz noch einmal, damit sich der Resolver nicht zwei
     * Formen merken muss.
     */
    public function up(): void
    {
        if (! Schema::hasTable('offers') || Schema::hasColumn('offers', 'pricing_options')) {
            return;
        }

        Schema::table('offers', function (Blueprint $table) {
            /*
             * `json`, weil eine Option aus sechs Feldern besteht und eine
             * eigene Tabelle dafuer nichts kann, was diese Spalte nicht kann:
             * es wird nie einzeln danach gesucht, immer nur das ganze Angebot
             * geladen. Leer heisst „ein Preis", also der Zustand von gestern.
             */
            $table->json('pricing_options')->nullable()->after('trial_amount_cent');
        });
    }

    /**
     * Ohne Tabelle nichts zu tun — dieselbe Wache wie in den Geschwistern:
     * eine fehlende Tabelle darf beim Aufraeumen nicht zerbrechen.
     */
    public function down(): void
    {
        if (! Schema::hasTable('offers') || ! Schema::hasColumn('offers', 'pricing_options')) {
            return;
        }

        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('pricing_options');
        });
    }
};
