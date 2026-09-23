<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zwei Preisarten und eine Gebuehr.
     *
     * `price_mode` sagt, woher der Betrag kommt: `fixed` ist alles, was es bis
     * hierher gab (eigener Preis, Katalogpreis, Prozent), `pwyw` heisst „Zahl,
     * was du willst" mit Untergrenze, Vorschlag und optionaler Obergrenze.
     * `pwyw_thanks` sind Danke-Texte je Betragsstufe.
     *
     * `setup_fee_cent` ist ein einmaliger Aufschlag auf die erste Zahlung eines
     * Abos oder einer Ratenzahlung. Er wird als eigene Zeile abgerechnet, damit
     * die Rechnung ihn als eigene Position fuehrt.
     *
     * Bestehende Zeilen landen auf `fixed` und ohne Gebuehr, also genau auf dem,
     * was sie vorher waren.
     */
    public function up(): void
    {
        if (! Schema::hasTable('offers')) {
            return;
        }

        Schema::table('offers', function (Blueprint $table) {
            if (! Schema::hasColumn('offers', 'price_mode')) {
                $table->string('price_mode', 16)->default('fixed');
            }
            if (! Schema::hasColumn('offers', 'pwyw_min_cent')) {
                $table->unsignedInteger('pwyw_min_cent')->nullable();
            }
            if (! Schema::hasColumn('offers', 'pwyw_suggested_cent')) {
                $table->unsignedInteger('pwyw_suggested_cent')->nullable();
            }
            if (! Schema::hasColumn('offers', 'pwyw_max_cent')) {
                $table->unsignedInteger('pwyw_max_cent')->nullable();
            }
            if (! Schema::hasColumn('offers', 'pwyw_thanks')) {
                $table->json('pwyw_thanks')->nullable();
            }
            if (! Schema::hasColumn('offers', 'setup_fee_cent')) {
                $table->unsignedInteger('setup_fee_cent')->nullable();
            }
            if (! Schema::hasColumn('offers', 'setup_fee_label')) {
                $table->string('setup_fee_label', 191)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('offers')) {
            return;
        }

        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn([
                'price_mode', 'pwyw_min_cent', 'pwyw_suggested_cent', 'pwyw_max_cent',
                'pwyw_thanks', 'setup_fee_cent', 'setup_fee_label',
            ]);
        });
    }
};
