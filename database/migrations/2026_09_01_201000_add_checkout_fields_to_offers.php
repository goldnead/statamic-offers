<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which fields the checkout asks for, per offer.
     *
     * A list of keys into the library in `config('statamic-offers.checkout_fields')`,
     * not the fields themselves: the library says what "street" *is* (label,
     * type, rules), the offer only says "ask for it". Defined once, read by the
     * capture step, the checkout and the invoice alike.
     *
     * `null` means the offer has no opinion and the funnel step decides, which
     * is what every row from before this column did.
     */
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            if (! Schema::hasColumn('offers', 'checkout_fields')) {
                $table->json('checkout_fields')->nullable()->after('withdrawal_pdf');
            }
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('checkout_fields');
        });
    }
};
