<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plaetze fuer Gruppen.
     *
     * Ein Angebot mit `seats` verkauft in einem Kauf mehrere Zugaenge. Die
     * Kaeuferin bekommt keinen davon automatisch, sondern ein Kontingent
     * (`offer_seat_pools`), aus dem sie per E-Mail einlaedt (`offer_seats`).
     *
     * `payment_id` + `offer` ist eindeutig: der Webhook kommt mehrfach, und aus
     * einer bezahlten Zeile darf genau ein Kontingent werden. Der Index
     * ist die Wache, nicht eine Abfrage davor (`lockForUpdate` ist auf SQLite
     * nichts).
     *
     * Die Tokens sind die einzige Berechtigung auf den Seiten der Kaeuferin und
     * der Eingeladenen, also zufaellig, lang und eindeutig.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('offers', 'seats')) {
            Schema::table('offers', function (Blueprint $table) {
                $table->unsignedSmallInteger('seats')->nullable();
            });
        }

        if (! Schema::hasTable('offer_seat_pools')) {
            Schema::create('offer_seat_pools', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('brand_id')->default(0)->index();
                $table->unsignedBigInteger('payment_id');
                $table->string('offer', 191)->index();
                $table->string('product', 191);
                $table->string('owner_email', 191)->index();
                $table->string('owner_name', 191)->nullable();
                $table->unsignedInteger('seats');
                $table->json('grants')->nullable();
                $table->json('access')->nullable();
                $table->string('manage_token', 64)->unique();
                $table->timestamps();

                $table->unique(['payment_id', 'offer']);
            });
        }

        if (! Schema::hasTable('offer_seats')) {
            Schema::create('offer_seats', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pool_id')->constrained('offer_seat_pools')->cascadeOnDelete();
                $table->string('email', 191);
                $table->string('name', 191)->nullable();
                $table->string('token', 64)->unique();
                $table->string('status', 16)->default('invited');
                $table->timestamp('invited_at')->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->index(['pool_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_seats');
        Schema::dropIfExists('offer_seat_pools');

        if (Schema::hasColumn('offers', 'seats')) {
            Schema::table('offers', function (Blueprint $table) {
                $table->dropColumn('seats');
            });
        }
    }
};
