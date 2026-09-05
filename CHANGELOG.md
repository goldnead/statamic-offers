# Changelog

## 1.7.0 — 2026-09-05

Ein Befund aus Adrians Durchgang vom 03.09.2026 (F36), dazu ein Testfehler, der nur auf PHP 8.2
auftrat.

### Angebote und Gutscheine im Verkaufs-Abschnitt

Beide Bildschirme sind als Statamic-Utilities registriert und standen unter „Hilfsmittel", zwischen
Cache und PHP-Info. Jetzt hängen sie im Verkaufs-Abschnitt, den `statamic-payments` mit
`Cp\SuiteNav::section()` benennt: derselbe Abschnitt wie Zahlungen, Produkte und Funnels. Ein
eigener String hier wäre ein zweiter Abschnitt mit fast demselben Namen, denn Statamic übersetzt
Abschnittsnamen nicht.

Route und Recht bleiben. Die Einträge unter „Hilfsmittel" werden ausgehängt, sonst stünde jeder
Bildschirm zweimal da; so war es im ersten Anlauf vom 04.09.

`Cp\SuiteNav` gibt es erst seit `goldnead/statamic-payments` 1.18.0. Der Aufruf steht deshalb
hinter `class_exists()`, wie in `statamic-booking`: mit älterem payments bekommen beide
Bildschirme einen eigenen Abschnitt „Angebote" statt eines `Class not found` beim Aufbau der
ganzen CP-Navigation. Den gemeinsamen Verkaufs-Abschnitt gibt es ab payments 1.18.0.

### Constraint: payments ab 1.9

`goldnead/statamic-payments` verlangt jetzt `^1.9` statt `^1.6`. Das Paket liest seit längerem
`discount_cent` (payments 1.9.0) und `refunded_cent` (1.4.0) aus der Zahlungstabelle; mit
payments 1.6 bis 1.8 lief die Umsatzspalte ins Leere, und das prefer-lowest-Bein der CI war rot.
Der Constraint sagt jetzt, was der Code braucht.

### Testsuite auf PHP 8.2

`ConfirmationMailFieldTest` legte die Fassade des Schwester-Pakets `statamic-email-templates` per
`class_alias()` auf `\stdClass`. Das erlaubt PHP erst ab 8.3; auf 8.2 warf jeder Test mit
Vorlagen einen `ValueError`, das 8.2-Bein der Matrix war seit diesem Test rot. Der Alias zeigt
jetzt auf eine eigene leere Klasse (`Tests\Support\EmailTemplatesFacadeStandIn`). Betroffen war
nur die Suite, nicht das Paket.

## 1.6.0 — 2026-09-02

Sieben Befunde aus dem Suite-Register vom 01.09.2026. Fünf additive Migrationen an `offers`, alle
mit `hasColumn`-Schutz; bestehende Zeilen bleiben, wie sie sind.

### Widerruf als Objekt am Angebot (P·3)

`withdrawal_days`, `withdrawal_text`, `withdrawal_waiver_text`, `withdrawal_checkbox_required`,
`withdrawal_b2b_text`, `withdrawal_pdf`. Leer heißt: der Standard aus
`config('statamic-offers.withdrawal')`, Platzhalter aus `config('statamic-offers.seller')`.
`Offer::withdrawalTerms()` liefert das Array samt `version` (12 Zeichen SHA-1 über Frist, Text und
Einwilligungssatz). Der Wortlaut, dem der Käufer zustimmt, wird vom Funnel an der Zahlung
eingefroren; hier steht nur, was heute gilt. Der mitgelieferte Text ist ein **Entwurf, anwaltlich
zu prüfen**. `withdrawal_pdf` ist nur ein Flag, der Anhang ist noch nicht umgesetzt.

### Feld-Bibliothek auf zwei Ebenen (S·6)

`config('statamic-offers.checkout_fields')` ist die Bibliothek, `checkout_fields` am Angebot die
Auswahl. `Offers::fieldLibrary()` (statisch, `Goldnead\StatamicOffers\Offers`) und
`Offer::checkoutFields()`. Unbekannte Schlüssel weist das Formular ab.

### Zugangsbeginn und -dauer (K·5)

`access_starts_at`, `access_days`, `Offer::accessWindow()`. Geht als `meta['access']` an die
Zahlung; die Zugänge schreibt `statamic-entitlements`.

### Prozentrabatt (K·6)

`discount_percent` (1–99). `effectiveAmountCent()` und `effectiveCompareAtCent()`; `amountCent()`
delegiert. Eigener Preis und Prozent zusammen werden abgelehnt. Katalog-Resolver, Basket, Tag und
Listing lesen die effektiven Werte.

### Mengen- und Zeitlimit (K·7)

`quantity_limit`, `available_from`, `available_until`. Verkauft = bezahlte `payment_items` mit dem
Kaufhandle des Angebots, jedes Mal frisch gezählt; dazu zählen offene Checkouts jünger als eine
Stunde als reserviert (`OfferSales::RESERVATION_MINUTES`), damit das Fenster zwischen Start und
Bezahlung praktisch zu ist. Das Limit bleibt weich, keine Reservierung im Datenbanksinn.
`remainingQuantity()`, `isWithinWindow()`; `isSellable()` berücksichtigt beides. Spalte
**Verfügbar** im Listing.

### Massen-Gutscheincodes (K·12)

Zweite Handlung **Codes erzeugen** auf dem Gutschein-Screen und `php artisan
offers:coupons:generate`. Bis zu 100 Codes in einer Transaktion, Alphabet ohne 0/O/1/I/l, Retry je
Code, Abbruch nach zehn Kollisionen statt Teilmenge. Zeitzone steht am Formular.

### Upsell-Übersicht (K·15)

Filter **Ort** am Angebots-Listing und Spalte **Umsatz**: netto, also bezahlte Zeilen in der
Angebotswährung abzüglich des Gutscheinanteils der Zeile (`payment_items.discount_cent`) und ihres
Anteils an Erstattungen (`payments.refunded_cent`, anteilig am Zahlungsbetrag). Fehlt ohne
Zahlungstabellen; auf `statamic-payments` vor 1.8 ohne die beiden Spalten bleibt sie brutto, mit
Hinweis im Log.

## 1.5.0 — 2026-09-01

Nachgetragen: die Fassung 1.5.0 ging ohne Eintrag raus. Sie brachte die **Kaufbestätigung als
Feld am Angebot** (`confirmation_mode` Standard / eigene Vorlage / keine, `confirmation_template`
aus `et_templates`, nur veröffentlichte Vorlagen wählbar) und den Kasten **„Wo dieses Angebot
verwendet wird"** (Funnels und Automationen, `OfferUsage`) im Bearbeiten-Formular.

## 1.4.0

### Neu: ein Angebot darf ein Bündel sein

Bisher verkaufte ein Angebot genau ein Produkt. Ein Bündel — drei Dinge, ein Preis — ließ sich
damit nirgends ausdrücken: Bumps sind Häkchen, die der Käufer einzeln entscheidet und die einzeln
kosten, und ein eigenes Katalogprodukt dafür anzulegen heißt, den Preis wieder in eine Datei zu
schreiben.

Neues Feld **Enthält außerdem** am Angebot. Bleibt es leer, ändert sich nichts.

- **Preis:** der eigene, sonst die **Summe der Teile**. Der alte Rückfall auf das Leitprodukt wäre
  hier zum Fehler geworden: drei Dinge zum Preis von einem, still, bis es jemand nachrechnet.
- **Freischaltung:** die Vereinigung dessen, was alle Teile gewähren, ohne Dopplungen.
- **Rechnung:** eine Zeile, geführt unter dem Leitprodukt. An dessen Handle hängt die Steuerklasse,
  und die braucht genau eine Antwort.

**Ein Bündel, dessen Teile sich bei `digital` widersprechen, ist nicht verkaufbar.** Der Schlüssel
beschreibt nicht das Medium, er entscheidet über den Leistungsort und damit über einen von vier
Pflichthinweisen (§ 3a UStG). Eine Zeile, die zur Hälfte elektronisch erbracht ist, hat keinen
richtigen — und einen zu wählen hieße, eine Steuerfrage auf einem Dokument zu raten, das sich nicht
mehr korrigieren lässt. Der Katalog antwortet dann „gibt es nicht", und `Checkout::start()` bricht
den ganzen Vorgang ab, bevor Geld fließt. Dasselbe, wenn ein Teil aus dem Katalog gefallen ist.

**Bündel mit mehr als einer Freischaltung brauchen `statamic-payments` 1.14 oder neuer.** Davor
nahm `grants` nur eine Zeichenkette; eine Liste fiel dort an `is_string()` heraus und vergab
**gar nichts** statt des ersten Stücks. Ein solches Bündel verweigert deshalb die Auflösung und
schreibt den Grund ins Log, statt sich verkaufen zu lassen und nichts zu liefern. Geprüft wird die
installierte Klasse, nicht eine Zahl in einer Datei.

Der aufgelöste Katalogeintrag trägt neben `product` (dem Leitprodukt) jetzt `products` mit allen
Teilen — damit ein Geschwister, das die Auslieferung macht, nicht die Angebotstabelle selbst
abfragen muss.

Migration: `products` (json, nullable) an `offers`. Bestehende Zeilen bleiben, wie sie sind.

## 1.3.0

### Fixed — ein Angebot war nur ein Preis, und das riss die Familie auseinander

Der Katalog-Resolver gab `name`, `amount_cent`, `currency` und `offer` zurück. Alles andere über
das verkaufte Ding steht am Produkt, und ein Angebot ist laut eigener Beschreibung „ein Produkt,
dargestellt". Zwei Folgen, beide still:

- **`digital` und die Steuerklasse fehlten** → `statamic-invoices` konnte für eine über ein Angebot
  bezahlte Bestellung **gar keine Rechnung** schreiben. Die beworbene Kette Funnel → Angebot →
  Zahlung → Rechnung riss am letzten Glied, auf jeder Installation, die die Familie so einsetzt,
  wie die Doku sie beschreibt.
- **`grants` fehlte** → wer über ein Angebot kaufte, bekam **keinen Zugang**. Die Zahlung ging
  durch, das Geld kam an, der Zugang erschien nie. Ohne Fehler: „dieses Produkt gewährt nichts" und
  „dieses Produkt kenne ich nicht" kamen beide als dasselbe `null` zurück.

Der Resolver liefert jetzt das Produkt-Array mit den Überschreibungen des Angebots darüber. Der
Angebotspreis und -name gewinnen, alles Übrige wird geerbt. **Erfunden wird nichts:** ein Angebot
für ein Produkt, das `digital` nicht angibt, gibt es ebenfalls nicht an — und das Rechnungs-Addon
verweigert dann weiterhin, statt zu raten.

Dazu neu im Ergebnis: `product`, der Handle des Dings darunter. Steuerklassen werden je
Produkt-Handle konfiguriert, und ein Angebot hat einen eigenen — ohne diesen Schlüssel wäre ein
Angebot für ein ermäßigtes Produkt still auf die Standardklasse gefallen und hätte den falschen
Satz auf ein Steuerdokument gedruckt.

Drei ausgelieferte Addons, drei grüne Suiten, und der Fehler lag in der Lücke dazwischen.

## 1.2.0

### What's new

- **`amountLocal()` and `compareAtLocal()`** — a price as the reader's language writes it. `amount()`
  is unchanged and stays the machine-readable form: always a dot, always two decimals, whatever the
  site's locale. Templates should use the local pair; anything that parses should keep using
  `amount()`.

  This existed because both readers were served by one method and the machine won: a German page
  printed `249.00 EUR`, where the dot is not a decimal separator at all but a thousands group — so
  the number was not merely styled oddly, it read as a different number.

  Without `ext-intl` the local pair falls back to the dot rather than guessing.

## 1.1.0 — 2026-08-25

### What's new

- **Bumps.** An offer can carry other offers as checkboxes at checkout, picked in the offer form
  and shown in the order they were picked. Only offers placed at checkout can be picked, never the
  offer itself, and the server refuses anything else — a select is a text field with a nice hat on.
- **Coupons.** A second utility screen for the codes people type to pay less: a percentage or a
  fixed amount, optionally limited to certain offers, a date range and a number of redemptions.
  Filters for "active" and "valid right now", the latter as a query scope so the pager counts the
  rows the filter left.

### What's fixed

- **A saved row appeared only after a reload.** The listing fetches its own rows and an Inertia
  redirect never touches them, so saving an offer looked like it had failed. It now refreshes.
- Prices and rates follow the Control Panel's language: a German CP writes `5,00 EUR` and `23,1 %`,
  and the two screens of this addon agree with each other.
- `Offer::currency()` read `$this->currency`, and because a method of that name exists, Eloquent
  fell through to relation resolution whenever the column was not among the loaded attributes and
  threw. It hit every offer built in memory rather than read back from the table.

### Requires

- `goldnead/statamic-payments` ^1.4, for `Discount` and zero-priced products.
- Every label on both screens is translated on the server and handed to the page, so no screen can
  end up showing a raw translation key.

## 1.0.1

### What's fixed

- **An offer whose product pointed at another offer took the process down.** The pair asked each
  other what they cost until memory ran out — and the listing you would have deleted one from died
  with it, because every row asks whether it is sellable. Now the product must be something the
  catalogue actually sells, and the resolver refuses to re-enter itself.
- **Saving an offer without a currency answered 500.** `validate()` omits a nullable key that was
  never sent, so reading it directly broke every client that is not this addon's own form.
- Deleting asks first. The modal is bound with `:open`, not `v-if` — mounted conditionally it never
  opens, which looks exactly like a Delete button that does nothing.
- Surfaces and borders use core's colour tokens, so the screen follows a re-themed Control Panel.
- An empty `handle_prefix` falls back to the default instead of quietly letting an offer and a
  product of the same name be mistaken for one another.
- The `image` field is editable, having previously existed everywhere except the form.

## 1.0.0

Initial release. An offer is a product from the payment catalogue plus a price of its own, words,
a slot and two counters. It resolves through `Catalogue::extend()`, so the price stays server-side
and every guard the payment addon has applies to it unchanged.
