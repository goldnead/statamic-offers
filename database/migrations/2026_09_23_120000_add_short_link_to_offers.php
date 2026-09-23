<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ein kurzer Link je Angebot, der sein Ziel wechselt.
     *
     * Bis zum Stichtag (oder bis das Kontingent verkauft ist) fuehrt
     * `/go/<link_slug>` nach `link_target`, danach nach `link_fallback`. Die
     * beiden Zaehler sind die Aufrufe je Ziel, hochgezaehlt in der Datenbank
     * (`increment`), damit zwei gleichzeitige Aufrufe nicht einer werden.
     *
     * Der Slug ist eindeutig, aber leer erlaubt: ein Angebot ohne Link ist der
     * Normalfall. Ein eindeutiger Index auf einer nullbaren Spalte laesst
     * beliebig viele NULL zu, auf allen drei Treibern.
     */
    public function up(): void
    {
        if (! Schema::hasTable('offers')) {
            return;
        }

        Schema::table('offers', function (Blueprint $table) {
            if (! Schema::hasColumn('offers', 'link_slug')) {
                $table->string('link_slug', 64)->nullable()->unique();
            }
            if (! Schema::hasColumn('offers', 'link_target')) {
                $table->string('link_target', 2000)->nullable();
            }
            if (! Schema::hasColumn('offers', 'link_fallback')) {
                $table->string('link_fallback', 2000)->nullable();
            }
            if (! Schema::hasColumn('offers', 'link_switch_at')) {
                $table->dateTime('link_switch_at')->nullable();
            }
            if (! Schema::hasColumn('offers', 'link_switch_on_sold_out')) {
                $table->boolean('link_switch_on_sold_out')->default(true);
            }
            if (! Schema::hasColumn('offers', 'link_hits_target')) {
                $table->unsignedInteger('link_hits_target')->default(0);
            }
            if (! Schema::hasColumn('offers', 'link_hits_fallback')) {
                $table->unsignedInteger('link_hits_fallback')->default(0);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('offers')) {
            return;
        }

        Schema::table('offers', function (Blueprint $table) {
            // Der Index zuerst, sonst meldet SQLite „no such column".
            $table->dropUnique(['link_slug']);
            $table->dropColumn([
                'link_slug', 'link_target', 'link_fallback', 'link_switch_at',
                'link_switch_on_sold_out', 'link_hits_target', 'link_hits_fallback',
            ]);
        });
    }
};
