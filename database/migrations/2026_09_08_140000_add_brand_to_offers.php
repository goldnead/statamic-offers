<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wem ein Angebot gehoert.
     *
     * Dieselbe Form wie in `products`, `payments` und `subscriptions`: eine
     * Spalte mit Vorgabe **Null**, was auf einem Betrieb ohne Mandanten jede
     * Zeile ist und wo `Brands::only()` gar nicht erst filtert. Eine Agentur
     * mit drei Marken bekommt damit drei Angebotslisten in einem Control
     * Panel.
     *
     * **Bestehende Zeilen bleiben auf Null, und das ist eine Entscheidung.**
     * Es gibt in dieser Tabelle nichts, woraus sich die Marke einer alten
     * Zeile ableiten liesse — ein Handle, das mit `cw-` anfaengt, ist eine
     * Namenskonvention und keine Angabe. Sie zu raten hiesse, Angebote der
     * einen Marke in der Liste einer anderen erscheinen zu lassen, und das ist
     * genau der Fehler, den diese Spalte behebt. Auf einem Betrieb mit einer
     * Marke ist Null richtig; auf einem mit mehreren tragen die alten Zeilen
     * die Marke nach, sobald sie jemand einmal speichert.
     */
    public function up(): void
    {
        if (! Schema::hasTable('offers') || Schema::hasColumn('offers', 'brand_id')) {
            return;
        }

        Schema::table('offers', function (Blueprint $table) {
            $table->unsignedBigInteger('brand_id')->default(0)->index()->after('id');
        });
    }

    /**
     * Ohne Tabelle nichts zu tun — dieselbe Wache wie in den Geschwistern:
     * eine fehlende Tabelle darf beim Aufraeumen nicht zerbrechen.
     */
    public function down(): void
    {
        if (! Schema::hasTable('offers') || ! Schema::hasColumn('offers', 'brand_id')) {
            return;
        }

        Schema::table('offers', function (Blueprint $table) {
            // Der Index zuerst. SQLite laesst eine Spalte nicht fallen, auf die
            // noch ein Index zeigt, und meldet das als „no such column" —
            // eine Fehlermeldung, die in die falsche Richtung zeigt.
            $table->dropIndex(['brand_id']);
            $table->dropColumn('brand_id');
        });
    }
};
