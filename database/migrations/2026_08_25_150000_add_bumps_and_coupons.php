<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            // Which offers ride along with this one as checkboxes at checkout.
            // A list of handles rather than a join table: the order they were
            // put in is the order they are shown, and that ordering is the
            // whole editorial decision. A pivot with a position column is the
            // same thing with more moving parts.
            $table->json('bumps')->nullable()->after('slot');
        });

        Schema::create('offer_coupons', function (Blueprint $table) {
            $table->id();

            // Stored as typed, compared case-insensitively. People type coupon
            // codes off a slide.
            $table->string('code', 64)->unique();
            $table->string('name', 191)->nullable();

            // Exactly one of these is set. Checked in the model rather than by
            // the database, because SQLite cannot express it and a check
            // constraint that exists on one engine is worse than none.
            $table->unsignedSmallInteger('percent')->nullable();
            $table->unsignedInteger('amount_cent')->nullable();
            $table->string('currency', 3)->nullable();

            // Empty means every offer. A coupon that applies to nothing would
            // be the more surprising default.
            $table->json('offers')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Null means no limit. `used_count` is incremented atomically when
            // a payment starts, not when a code is typed.
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);

            $table->boolean('active')->default(true)->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_coupons');

        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('bumps');
        });
    }
};
