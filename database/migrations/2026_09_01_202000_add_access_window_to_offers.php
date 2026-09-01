<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When access starts and how long it lasts.
     *
     * The entitlement addon has had `starts_at` and `expires_at` all along; the
     * offer simply could not set them. These two columns are the offer's
     * answer, handed to the payment as `meta['access']` by whoever starts the
     * checkout, and read by the entitlements bridge on the other side. This
     * addon writes no entitlement itself.
     *
     * Both nullable: empty means immediately and for good, as before.
     */
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            if (! Schema::hasColumn('offers', 'access_starts_at')) {
                $table->date('access_starts_at')->nullable()->after('checkout_fields');
            }
            if (! Schema::hasColumn('offers', 'access_days')) {
                $table->unsignedSmallInteger('access_days')->nullable()->after('access_starts_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn(['access_starts_at', 'access_days']);
        });
    }
};
