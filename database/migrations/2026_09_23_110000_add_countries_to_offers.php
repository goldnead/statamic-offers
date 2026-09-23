<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wo ein Angebot gekauft werden darf.
     *
     * `country_mode`: `all` (weltweit, die Vorgabe und der Stand jeder
     * bestehenden Zeile), `only` (nur die Laender in `countries`), `except`
     * (ueberall ausser diesen). `countries` ist eine Liste zweistelliger
     * ISO-Codes in Grossbuchstaben.
     */
    public function up(): void
    {
        if (! Schema::hasTable('offers')) {
            return;
        }

        Schema::table('offers', function (Blueprint $table) {
            if (! Schema::hasColumn('offers', 'country_mode')) {
                $table->string('country_mode', 16)->default('all');
            }
            if (! Schema::hasColumn('offers', 'countries')) {
                $table->json('countries')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('offers')) {
            return;
        }

        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn(['country_mode', 'countries']);
        });
    }
};
