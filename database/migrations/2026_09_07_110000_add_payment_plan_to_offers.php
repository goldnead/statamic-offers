<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Der Zahlungsrhythmus eines Angebots — erklaert, nie geerbt.
     *
     * **Warum das Angebot ihn ueberhaupt braucht.** Ein Ratenplan hing bisher
     * am Produkt. „Dasselbe in drei Raten" hiess damit: ein zweites Produkt
     * anlegen, mit denselben Zugaengen, derselben Steuerangabe und einem
     * anderen Betrag. Zwei Zeilen fuer eine Sache, und jede Pflege doppelt.
     *
     * Ein Angebot ist „ein Produkt, praesentiert" — und Zahlungsbedingungen
     * sind Praesentation. Ein Produkt, zwei Angebote („einmalig", „3 Raten")
     * ist die Form, die der Sache entspricht.
     *
     * **Warum die Werte trotzdem nicht geerbt werden.** Der Resolver in
     * `ServiceProvider::resolveOffer()` streicht die Plan-Schluessel des
     * Produkts ausdruecklich heraus, und das bleibt so. Die Begruendung dort
     * gilt unveraendert: ein Angebot hat oft einen eigenen, niedrigeren Preis,
     * und ein geerbter Rhythmus machte daraus stillschweigend einen
     * Dauerauftrag ueber den Rabatt. „Ein Upsell zu 12 EUR fuer ein
     * 29-EUR-Produkt sagt nichts darueber, was der zweite Monat kostet."
     *
     * Diese Spalten sind die Antwort auf genau diesen Satz: das Angebot sagt
     * es selbst. Steht hier ein `interval`, dann ist `offers.amount_cent`
     * **die Ratenhoehe** — nicht abgeleitet, nicht geraten, sondern von der
     * Person eingetragen, die den Preis auch sonst setzt.
     *
     * Alle vier nullable, `interval` ist der Schalter. Ohne ihn bleibt ein
     * Angebot, was es war: einmalig.
     */
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            /*
             * Im Wortlaut des Anbieters: `1 month`, `12 weeks`.
             * Dieselbe Freiheit wie am Produkt — eine engere Regel hier
             * beschnitte, was `statamic-payments` ohnehin annimmt.
             */
            $table->string('interval', 32)->nullable()->after('amount_cent');

            /*
             * Ohne Anzahl ein Abo, mit Anzahl eine Ratenzahlung. Die erste
             * Rate ist der Kauf selbst; `Subscriptions::startFromPayment()`
             * fragt den Anbieter deshalb nur nach `times - 1`.
             */
            $table->unsignedSmallInteger('times')->nullable()->after('interval');

            $table->unsignedSmallInteger('trial_days')->nullable()->after('times');

            $table->integer('trial_amount_cent')->nullable()->after('trial_days');
        });
    }

    /**
     * Ohne Tabelle nichts zu tun — dieselbe Wache wie in den Geschwistern:
     * eine fehlende Tabelle darf beim Aufraeumen nicht zerbrechen.
     */
    public function down(): void
    {
        if (! Schema::hasTable('offers')) {
            return;
        }

        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn(['interval', 'times', 'trial_days', 'trial_amount_cent']);
        });
    }
};
