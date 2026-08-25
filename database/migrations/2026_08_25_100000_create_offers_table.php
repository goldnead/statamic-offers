<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An offer is not a product.
     *
     * A product is a thing that exists and costs money. An offer is that thing
     * *presented*: at a place, for a price that may be its own, with words that
     * are about this moment. The same product is a €29 purchase on the sales
     * page and a €12 upsell on the thank-you page, and those are two offers.
     */
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();

            // What a template and a funnel step refer to. Stable, human, and
            // the thing that ends up in a URL and in a report.
            $table->string('handle', 191)->unique();
            $table->string('name', 191);

            // The product in the payment catalogue. Not a foreign key: the
            // catalogue is configuration, not a table, and an offer pointing at
            // a handle nobody configured has to be a visible mistake rather
            // than a broken join.
            $table->string('product', 191)->index();

            // Null means "whatever the catalogue says". A number here is this
            // offer's own price — the upsell discount — and it is the only
            // reason this table has to exist at all.
            $table->unsignedInteger('amount_cent')->nullable();
            $table->string('currency', 3)->nullable();

            // What a buyer is told they would normally pay. Display only, never
            // charged: a struck-through price that could be charged would be
            // the same mistake as a price in a request.
            $table->unsignedInteger('compare_at_cent')->nullable();

            $table->string('headline', 191)->nullable();
            $table->text('body')->nullable();
            $table->string('image', 500)->nullable();
            $table->string('button_label', 191)->nullable();

            // Where it may appear: at checkout as a bump, after a payment, or
            // anywhere a template asks for it. A slot is a promise about
            // context, and a post-purchase offer shown at checkout would charge
            // twice for the same journey.
            $table->string('slot', 32)->default('standalone')->index();

            $table->boolean('active')->default(true)->index();

            // Shown and accepted. Two integers rather than a row per view: an
            // offer on a busy page would write a row per visitor, and nobody
            // ever asks a question that needs them.
            $table->unsignedBigInteger('shown_count')->default(0);
            $table->unsignedBigInteger('accepted_count')->default(0);

            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
