<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wie lange und worauf ein Gutschein wirkt, und wohin sein Link fuehrt.
     *
     * `duration`: `once` (nur die erste Zahlung — genau das, was jeder
     * Gutschein bis hierher tat, deshalb die Vorgabe), `repeating` (die ersten
     * `duration_cycles` Zahlungen), `forever` (jede Zahlung eines Abos).
     *
     * `applies_to`: `order` (Hauptangebot und Bumps, der bisherige Stand),
     * `main` (nur das Hauptangebot), `bumps` (nur die Bumps). `funnel_wide`
     * heisst: der Code gilt auch fuer spaetere Angebote im selben Funnel-Lauf.
     *
     * `link_url` ist die Zielseite fuer den Gutschein-Link (`?coupon=CODE`).
     */
    public function up(): void
    {
        if (! Schema::hasTable('offer_coupons')) {
            return;
        }

        Schema::table('offer_coupons', function (Blueprint $table) {
            if (! Schema::hasColumn('offer_coupons', 'duration')) {
                $table->string('duration', 16)->default('once');
            }
            if (! Schema::hasColumn('offer_coupons', 'duration_cycles')) {
                $table->unsignedSmallInteger('duration_cycles')->nullable();
            }
            if (! Schema::hasColumn('offer_coupons', 'applies_to')) {
                $table->string('applies_to', 16)->default('order');
            }
            if (! Schema::hasColumn('offer_coupons', 'funnel_wide')) {
                $table->boolean('funnel_wide')->default(false);
            }
            if (! Schema::hasColumn('offer_coupons', 'link_url')) {
                $table->string('link_url', 2000)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('offer_coupons')) {
            return;
        }

        Schema::table('offer_coupons', function (Blueprint $table) {
            $table->dropColumn(['duration', 'duration_cycles', 'applies_to', 'funnel_wide', 'link_url']);
        });
    }
};
