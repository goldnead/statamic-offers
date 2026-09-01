<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The terms of withdrawal, as an object on the offer.
     *
     * **Why on the offer.** A course sold as a one-off and the same course sold
     * as a subscription have different periods and a different waiver; a
     * physical workbook has no waiver at all. The period and the words are a
     * property of *what is sold*, so they live where that is defined, and a
     * site-wide default in the config covers every offer that says nothing.
     *
     * **What is not here.** The wording a buyer actually agreed to. That is
     * frozen on the payment at the moment of consent (`consent_text`,
     * `meta['withdrawal']` in `statamic-payments`), because a text edited next
     * month must never rewrite what somebody consented to last month. This
     * table holds the *current* terms; the payment holds the *agreed* ones.
     *
     * Every column is nullable and means "the config's default" when empty.
     * `withdrawal_pdf` is a flag only — the renderer does not exist yet.
     */
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            if (! Schema::hasColumn('offers', 'withdrawal_days')) {
                $table->unsignedSmallInteger('withdrawal_days')->nullable()->after('confirmation_template');
            }
            if (! Schema::hasColumn('offers', 'withdrawal_text')) {
                $table->text('withdrawal_text')->nullable()->after('withdrawal_days');
            }
            if (! Schema::hasColumn('offers', 'withdrawal_waiver_text')) {
                $table->text('withdrawal_waiver_text')->nullable()->after('withdrawal_text');
            }
            if (! Schema::hasColumn('offers', 'withdrawal_checkbox_required')) {
                $table->boolean('withdrawal_checkbox_required')->default(true)->after('withdrawal_waiver_text');
            }
            if (! Schema::hasColumn('offers', 'withdrawal_b2b_text')) {
                $table->text('withdrawal_b2b_text')->nullable()->after('withdrawal_checkbox_required');
            }
            if (! Schema::hasColumn('offers', 'withdrawal_pdf')) {
                $table->boolean('withdrawal_pdf')->default(false)->after('withdrawal_b2b_text');
            }
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn([
                'withdrawal_days', 'withdrawal_text', 'withdrawal_waiver_text',
                'withdrawal_checkbox_required', 'withdrawal_b2b_text', 'withdrawal_pdf',
            ]);
        });
    }
};
