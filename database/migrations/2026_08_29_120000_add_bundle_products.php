<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An offer may sell more than one product.
     *
     * **Why this is a second column and not a replacement.** `product` stays
     * required and keeps meaning what it meant: the thing this offer is
     * *about*. Its name, its tax facts and its handle are what an invoice line
     * and a tax class hang on, and a bundle still has to answer those questions
     * with one voice. `products` is what else comes along.
     *
     * Making it a list and dropping `product` would have been tidier on paper
     * and worse everywhere else: every existing row would need rewriting, every
     * `where('product', …)` in a listing and a search would break, and the
     * question "which of the three does the invoice call this line" would have
     * had no answer at all.
     *
     * **A bundle is one line at one price, not three lines.** The payment addon
     * can do several lines — that is what bumps are — but a bump is something
     * the buyer ticked, priced on its own. A bundle is one decision: three
     * things, one price, one row on the invoice. Splitting it into three lines
     * would mean apportioning the bundle discount across them, and there is no
     * honest way to do that when the parts carry different tax facts.
     */
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->json('products')->nullable()->after('product');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('products');
        });
    }
};
