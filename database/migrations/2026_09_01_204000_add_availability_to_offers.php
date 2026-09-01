<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many, and until when.
     *
     * Both limits belong to the offer and not to the funnel step it is sold
     * through: the same offer sold through two funnels is one scarcity, not
     * two, and a countdown kept at the step would have to be maintained once
     * per way in.
     *
     * `quantity_limit` is compared against what was *paid*, counted in the
     * payment tables — never against a counter of its own, which would drift
     * from the truth the first time a payment is refunded or a row deleted.
     */
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            if (! Schema::hasColumn('offers', 'quantity_limit')) {
                $table->unsignedInteger('quantity_limit')->nullable()->after('active');
            }
            if (! Schema::hasColumn('offers', 'available_from')) {
                $table->dateTime('available_from')->nullable()->after('quantity_limit');
            }
            if (! Schema::hasColumn('offers', 'available_until')) {
                $table->dateTime('available_until')->nullable()->after('available_from');
            }
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn(['quantity_limit', 'available_from', 'available_until']);
        });
    }
};
