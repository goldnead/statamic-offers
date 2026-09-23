<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ein Kontingent, dessen Kauf zurueckgegangen ist.
     *
     * Volle Erstattung oder Rueckbuchung schliessen es: alle Plaetze werden
     * zurueckgeholt, und es nimmt weder Einladungen noch Annahmen mehr an.
     * Ein Zeitpunkt statt eines Schalters, damit „seit wann" beantwortbar bleibt.
     */
    public function up(): void
    {
        if (! Schema::hasTable('offer_seat_pools') || Schema::hasColumn('offer_seat_pools', 'closed_at')) {
            return;
        }

        Schema::table('offer_seat_pools', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_reason', 191)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('offer_seat_pools') || ! Schema::hasColumn('offer_seat_pools', 'closed_at')) {
            return;
        }

        Schema::table('offer_seat_pools', function (Blueprint $table) {
            $table->dropColumn(['closed_at', 'closed_reason']);
        });
    }
};
