<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A discount as a percentage of the catalogue price.
     *
     * `amount_cent` plus `compare_at_cent` is two numbers typed by hand, and
     * the second goes stale the day the catalogue price changes. A percentage
     * keeps the price in one place: the effective price is worked out from
     * the catalogue, and the struck-through price *is* the catalogue price.
     *
     * One or the other. An offer with both an own price and a percentage has
     * two answers to "what does this cost", and the form refuses it.
     */
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            if (! Schema::hasColumn('offers', 'discount_percent')) {
                $table->unsignedTinyInteger('discount_percent')->nullable()->after('compare_at_cent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('discount_percent');
        });
    }
};
