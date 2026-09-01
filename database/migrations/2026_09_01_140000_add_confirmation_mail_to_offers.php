<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The mail a buyer gets for their money.
     *
     * **Why it hangs on the offer and not on the funnel step.** The same offer
     * is sold through several funnels and through a direct link. A mail kept at
     * the step would have to be maintained once per way in, and the buyer who
     * came through the fourth way would get nothing — which is exactly the
     * defect this column closes. What the mail says is a property of the
     * purchase, so it belongs where the purchase is defined.
     *
     * **Why a mode and not just a nullable template.** "No template selected"
     * has two meanings that must not be confused: *send the standard one* and
     * *send nothing at all*. A shop that deliberately sends no confirmation —
     * because a sibling automation already does, or because the product is
     * handed over in person — must be able to say so, and a null column cannot
     * tell that apart from an offer whose author simply has not got round to
     * it yet. So the mode is the answer and the template is the detail:
     * `custom` without a template falls back to `default` rather than sending
     * nothing, because silence is the one outcome nobody chose.
     *
     * The default is `default`: an offer created before this migration, and an
     * offer created by someone who never opens the field, sends a confirmation.
     * The old behaviour — no mail — is available, but only on purpose.
     */
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->string('confirmation_mode')->default('default')->after('button_label');
            $table->string('confirmation_template')->nullable()->after('confirmation_mode');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn(['confirmation_mode', 'confirmation_template']);
        });
    }
};
