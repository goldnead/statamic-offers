# Changelog

## 1.11.2 — 2026-09-09

### Fixed: the catalogue entry names the offer's brand

`statamic-payments` 1.24.1 stamps a follow-up charge with the `brand_id` of the catalogue entry
instead of inheriting the brand of the payment it follows. The catalogue entry is the only seam
between the two packages — payments does not know `Offer` and must not, because offers depends on
payments and not the other way round — and this resolver did not put the key in it.

Two things were wrong at once. The fix over there was dead code for every real upsell, since every
one of them is an `offer:` handle and landed in the inherit branch. And what the entry *did* carry
was the `brand_id` of the product underneath, inherited through the array merge: an answer the offer
never gave, and on a multi-brand install the wrong one.

The entry now carries `(int) $offer->brand_id` — the offer's own, like its name and its price. Zero
stays zero and is not filled in from the product; over there that reads as "names no brand" and
inherits, with a line in the log.

The required `statamic-payments` version stays at `^1.15` on purpose. Nothing here needs 1.24.1: an
older sibling passes the extra key through `Catalogue::find()` and ignores it, so raising the floor
would block installs that work, in exchange for nothing.

### Changed: a bundle whose parts belong to different brands is not sellable

The same strictness `digital` has had since 1.6.0, and for the same reason. A bundle is one line at
one price; a line belongs to one brand, with that brand's invoice series, sender and revenue.
Picking one of two answers would be guessing whose money it is, so the catalogue answers "no such
thing" and `Checkout::start()` refuses before any money moves. The log line names the offer, its
parts and the brand each part claimed.

Parts that name no brand contradict nobody — on a single-brand install that is every part, and a
bundle must not hang on a question nobody asked. A part that names something that is not a brand id
at all is treated as silence too, but says so with a warning rather than passing for it.

## 1.11.1 — 2026-09-08

### Fixed: an offer made outside the Control Panel gets a brand too

1.11.0 stamped `brand_id` in `OffersController::store()` alone, so every other way of making an offer
— a console command, a seeder, an import — left it unset. On a multi-brand install those rows then
appeared in **no** listing at all: created, invisible, nothing said. The playground's own seeder did
exactly that until it set the column by hand.

The model stamps it now, in `creating`, the same place and the same way `statamic-products` has done
since 1.0.0. The explicit stamp in the controller is gone with it: two places for one rule are the
one that later learns something and the one that does not.

`Brands::stampId()`, not `readerId()`. "Whose row is this about to be" may answer zero — that is what
every row on a single-brand install carries — while "whose rows may this reader see" may not.

## 1.11.0 — 2026-09-08

### Added: the offers screen shows one brand at a time

An agency with several brands saw every brand's offers in one table — on the public demo, thirteen
rows from five brands, while the products screen of the same install separated them cleanly. Anyone
working as one brand read another brand's prices and could put another brand's offer into their own
coupon.

Offers now carry a `brand_id` and the Control Panel narrows to it: the listing, the "is there
anything yet" check, the bump picker, the offer picker on the coupons screen, and loading a row to
edit or delete it. Same seam as `statamic-products`, which has had it since 1.0.0.

**The public website narrows too**, and that one is a closed hole rather than a precaution. Unlike a
webhook, a public request *does* have a brand: `brand-context` wires `SetBrandForSite` into the `web`
group and resolves it from site, host or path, for anonymous visitors as well. Without narrowing,
`{{ offers:slot }}` on brand A's website handed out brand B's names, prices, discounts and buyable
handle — and a visitor could have bought through it.

**And the bump list is validated against the brand, not just the picker.** The picker only shows your
own, but a `PATCH` with a foreign handle in the body goes straight past it. That matters beyond the
Control Panel: `Basket::allowedBumps()` is allowed to stay unnarrowed *because* the list on an offer
is already brand-clean. Without the rule that reasoning was false, and brand A's checkout could carry
brand B's bump — wrong revenue, wrong invoice, wrong access.

**Explicitly at those five places, never as a global scope**, and that is the whole care in this
change. A global scope would also lie on the path the catalogue resolver takes, and a webhook has no
brand. `Brands::only()` closes when the question cannot be answered, so fulfilling a paid order
would stop finding its offer: money in, nothing delivered, nothing said. `brand-context` 1.11.0
already had exactly this shape once, on the public website. There is a test that buys through the
catalogue with no current brand and asserts it still resolves.

Loading a row to edit or delete goes through the same narrowing and answers 404 for another brand's
offer. Without it the listing hides the row while the route still accepts it, and guessing a number
is no art.

The minimum `statamic-payments` moves to 1.15, the version whose `Brands` carries `readerId()`. Same reason the
bundle rules already carry one: a constraint that allows a sibling without the seam is a constraint
that lets somebody install the pair and get a fatal error on the offers screen.

**Existing rows stay on brand zero, and that is a decision.** Nothing in this table says which brand
an old row belongs to — a handle that starts with `cw-` is a naming habit, not a statement — and
guessing would put one brand's offers in another's list, which is the bug this column fixes. On a
single-brand install zero is right and nothing changes at all. On a multi-brand install the old rows
take their brand the first time somebody saves them.

## 1.10.1 — 2026-09-08

### Fixed: a pricing option without a label no longer grows one

`pricingOptions()` filled an empty label with the option's key so the checkout had something to
print. The control panel reads that same list to fill its form, so the key came back as a label and
the next save wrote it into the column — as an entry nobody typed. The label now stays empty here
and the fallback lives where the option is displayed.

### Added: the trial fields per option, in the form

Model, validation, normalisation and resolver have carried `trial_days` and `trial_amount_cent` per
option since 1.10.0; the form did not show them, so the only way to set a trial on one option was
SQL. Two fields per row now, disabled without a rhythm and emptied on save with it — the same rule
the offer's own trial fields follow.

## 1.10.0 — 2026-09-08

### Added: one offer, several ways to pay, chosen at checkout

Until now an offer carried one payment rhythm. "The same thing in three instalments" meant a second
offer beside the first, and a funnel step points at exactly one offer — so the only way to show a
buyer both was to make them decline the first.

An offer now carries a list of `pricing_options`. Each row is the same set of fields the offer has
carried since 1.8.0 — amount, interval, count, trial — only several times over, and each one
resolves through the existing catalogue resolver as `offer:<handle>:<key>`:

```
offer:choiraccelerator            → 1.500 € once
offer:choiraccelerator:raten3     → 3 × 520 €
offer:choiraccelerator:abo        → 90 € a month
```

The checkout, the follow-up charge and every guard around them are untouched, because a handle with
a key is a handle like any other. Three payment types, read off the fields rather than declared
beside them: no interval is a one-off, an interval with a count is instalments, an interval without
one is a subscription.

Three decisions that touch money:

- **An unknown key resolves to nothing, not to the base price.** An old link or a deleted option
  would otherwise become a charge for an amount nobody picked. `Checkout::start()` refuses loudly.
- **A chosen option replaces the offer's own rhythm rather than adding to it.** Otherwise an option
  labelled "once" would inherit the `interval` next to it and be charged monthly.
- **The name stays the offer's.** The invoice line already says "instalment n of m (total X)"; the
  option's label is checkout language and does not belong on a document somebody keeps.

Half-written rows are dropped rather than guessed, and the control panel writes exactly the shape the
resolver reads — an option somebody enters should not go missing at the checkout.

### Added: the basket knows which option was chosen

`Basket::make()` takes the key, appends it to the first handle and applies a coupon to **that**
amount. Without it "10 percent off" would come off the full price while the instalment is what gets
charged. A key the offer does not carry throws rather than falling back.

## 1.9.0 — 2026-09-08

### Changed: both screens show an empty state instead of HTTP 500 when their tables are missing

Both screens of this addon hang off `Utility::register()`, so the nav entries appear the moment
composer put the package there — migrations are a separate, manual step. Between the two,
`/cp/utilities/offers` and `/cp/utilities/coupons` answered HTTP 500, because each one reaches for
its table while the page is being built: the offers listing needs `offers`, the coupons listing
`offer_coupons` and `offers`.

Each screen now checks before its first query and renders a setup page that names the missing tables
and says to run `php artisan migrate`. The reason does not disappear with the 500: a guarded page
writes to the log why it turned somebody away. Otherwise the site would look installed and never
work.

## 1.8.2 — 2026-09-07

### Fixed: without `ext-intl` the price stood in the wrong language

Without the extension `Offer::localise()` fell back to `number_format($cent / 100, 2, '.', '')` —
onto exactly what the comment above `amountLocal()` warns about: "a German page showing 249.00 is
a machine talking". It surfaced on adriangoldner.com on 2026-09-07. In the container `intl` is
not installed, and the checkout read **"520.00 €"** instead of "520,00 €".

That is not cosmetics. A price is mandatory information (§ 312j Abs. 2 BGB), and in German the
dot separates thousands — "1.560" reads as one thousand five hundred and sixty, "1560.00" at
best as a foreign body.

The fallback now knows the common notations itself. The list names only what is certain;
everything unknown stays with the dot, because a wrongly guessed notation would be worse than a
recognisably foreign one.

**Why no test found it:** on the development machines `intl` is loaded, in the container it is
not. The branch that ran was the only one no test reached — and the existing test for it skipped
itself when `intl` was missing, that is, in exactly the environment at issue. The fallback
therefore now stands as a method of its own (`localiseWithoutIntl()`) and is checked directly.

## 1.8.1 — 2026-09-07

### Fixed: 1.8.0 could be installed with a brand-context under which the withdrawal notice cannot be entered

**Anyone who installed 1.8.0 should update.** 1.8.0 declared
`goldnead/statamic-brand-context: ^1.12`, but on its settings page it uses the fieldtype `text`
in three places, and that type does not exist before 1.13.0: `withdrawal.text`,
`withdrawal.waiver_text` and `withdrawal.b2b_text`.

Under brand-context 1.12 an unknown type falls into the default branch. For these three fields
that means: the input is a single-line box, and validation bites at 255 characters. A withdrawal
notice (Widerrufsbelehrung) is a paragraph of about a thousand characters. So it is refused —
and with it exactly the text the type was built for.

This becomes visible only on an installation whose `composer.lock` pins brand-context to 1.12;
where resolution is free it pulls 1.13 anyway. The fault therefore does not lie in the code but
in the version boundary, and both resolutions looked plausible on their own.

The boundary now stands at `^1.13`. **1.8.0 should not be used.** Nothing else about the addon's
behaviour changes; 1.8.1 carries no further change.

## 1.8.0 — 2026-09-07

### New: an offer can name a payment plan of its own

Four columns on `offers`: `interval`, `times`, `trial_days`, `trial_amount_cent`. All nullable,
`interval` is the switch. Without it an offer stays what it was: one-off.

**With this, "the same thing in three instalments" no longer needs a second product.** Until now
an instalment plan hung on the product, and an instalment variant meant a second product row with
the same grants, the same tax statement and a different amount. Two rows for one thing, every
piece of maintenance twice. Now: one product, two offers ("One-off", "3 instalments").

An offer is "a product, presented" — and payment terms are presentation.

### The bar against inheriting stays, and that is the point

`resolveOffer()` still strips out the product's plan keys. The reasoning from back then holds
unchanged: an offer often has a lower price of its own, and an inherited plan silently turned
that into a standing order at the discount. "An upsell at 12 € for a 29 € product says nothing
about what the second month costs."

This version is the answer to the half-sentence "until somebody decides that": **the offer says
it itself.** If an `interval` stands there, `offers.amount_cent` is the **instalment amount** —
entered by the person who sets the price anyway, rather than guessed from a discount.

An offer with a plan of its own inherits nothing on top of it either: the product's trial does
not travel along, otherwise an instalment purchase would get 14 free days nobody offered.

### Control Panel

Four fields directly below the price, because they change its meaning. Count, trial days and
trial amount are disabled without an interval and are cleared along with it on save — otherwise a
`times = 3` stays hanging on a one-off offer, which nobody sees and which takes effect as soon as
somebody later sets an interval. Where recurring money is involved that is not a blemish.

### Tests

Four new ones in `OfferInheritsProductFactsTest`, right beside the test that forbids inheriting —
the two rules belong side by side.

### Settings in the Control Panel

Seller name, contact, withdrawal period, the withdrawal notice (Widerrufsbelehrung), the waiver
declaration (Verzichtserklärung), the note for business buyers, the mandatory checkbox and the
counting of impressions sit under **Settings → Addon Settings**. Until now they were package
defaults in `config/statamic-offers.php`: the notice ships explicitly as a draft to be reviewed by
a lawyer, and whoever had it reviewed could then change it only with file access.

Screen, storage, validation and permission check are provided by
`goldnead/statamic-brand-context` (a new dependency, from 1.12 on). Only deviations are stored,
everything else still follows the config file, and the values are held per brand. New permission:
`manage offers settings`; the existing utility permissions stay unchanged.

The withdrawal notice uses the layer's multi-line fieldtype `text`. **It is not yet contained in
brand-context 1.12.0** — until the next release over there the field falls back to `string` and
validation cuts off at 255 characters.

`checkout_fields` stays in the config: a field library with a label, a type and rules per entry
fits into no single form field. The settings page says so in the group description instead of
keeping quiet about it.

## 1.7.0 — 2026-09-05

One finding from Adrian's pass of 2026-09-03 (F36), plus a test failure that only occurred on
PHP 8.2.

### Offers and coupons in the sales section

Both screens are registered as Statamic utilities and sat under "Utilities", between Cache and
PHP Info. They now hang in the sales section that `statamic-payments` names with
`Cp\SuiteNav::section()`: the same section as Payments, Products and Funnels. A string of our own
here would be a second section with almost the same name, because Statamic does not translate
section names.

Route and permission stay. The entries under "Utilities" are unhooked, otherwise each screen
would stand there twice; that is how it was in the first attempt on 09-04.

`Cp\SuiteNav` only exists from `goldnead/statamic-payments` 1.18.0 on. The call therefore sits
behind `class_exists()`, as in `statamic-booking`: with an older payments both screens get a
section "Offers" of their own instead of a `Class not found` while the whole CP navigation is
being built. The shared sales section exists from payments 1.18.0 on.

### Constraint: payments from 1.10

`goldnead/statamic-payments` now requires `^1.10` instead of `^1.6`. The turnover column
calculates net through `payment_items.discount_cent` (payments 1.4.0) and
`payments.refunded_cent`, and the latter, together with `Support\Refunds`, exists only from
payments 1.10.0 on. With 1.6 to 1.9 the column stayed gross and the prefer-lowest leg of the CI
was red. The constraint now says what the code needs.

### No more `Request::get()` in the listings

`OffersController` and `CouponsController` read search, page size and sorting through
`$request->get()`. Symfony http-foundation 7.4 has marked the method deprecated, and with the
oldest permitted Laravel 12 the warning lands in the log; in the suite, which checks the log by
proxy, that counted as a failure. Now `$request->input()`, same source, no notice.

### Test suite on PHP 8.2

`ConfirmationMailFieldTest` aliased the facade of the sister package `statamic-email-templates`
onto `\stdClass` with `class_alias()`. PHP only allows that from 8.3 on; on 8.2 every test
involving templates threw a `ValueError`, and the 8.2 leg of the matrix had been red since that
test. The alias now points at an empty class of its own
(`Tests\Support\EmailTemplatesFacadeStandIn`). Only the suite was affected, not the package.

## 1.6.0 — 2026-09-02

Seven findings from the suite register of 2026-09-01. Five additive migrations on `offers`, all
guarded with `hasColumn`; existing rows stay as they are.

### Withdrawal as an object on the offer (P·3)

`withdrawal_days`, `withdrawal_text`, `withdrawal_waiver_text`, `withdrawal_checkbox_required`,
`withdrawal_b2b_text`, `withdrawal_pdf`. Empty means the default from
`config('statamic-offers.withdrawal')`, with placeholders from
`config('statamic-offers.seller')`. `Offer::withdrawalTerms()` delivers the array including
`version` (12 characters of SHA-1 over the period, the text and the consent sentence). The wording
the buyer agrees to is frozen by the funnel at the payment; what stands here is only what applies
today. The shipped text is a **draft, to be reviewed by a lawyer**. `withdrawal_pdf` is only a
flag, the attachment is not implemented yet.

### Field library on two levels (S·6)

`config('statamic-offers.checkout_fields')` is the library, `checkout_fields` on the offer is the
selection. `Offers::fieldLibrary()` (static, `Goldnead\StatamicOffers\Offers`) and
`Offer::checkoutFields()`. The form refuses unknown keys.

### Access start and duration (K·5)

`access_starts_at`, `access_days`, `Offer::accessWindow()`. Goes to the payment as
`meta['access']`; the grants are written by `statamic-entitlements`.

### Percentage discount (K·6)

`discount_percent` (1–99). `effectiveAmountCent()` and `effectiveCompareAtCent()`; `amountCent()`
delegates. An own price and a percentage together are refused. Catalogue resolver, basket, tag and
listing read the effective values.

### Quantity and time limit (K·7)

`quantity_limit`, `available_from`, `available_until`. Sold = paid `payment_items` carrying the
offer's purchase handle, counted fresh every time; on top of that, open checkouts younger than an
hour count as reserved (`OfferSales::RESERVATION_MINUTES`), so that the window between start and
payment is practically closed. The limit stays soft, no reservation in the database sense.
`remainingQuantity()`, `isWithinWindow()`; `isSellable()` takes both into account. Column
**Available** in the listing.

### Bulk coupon codes (K·12)

A second action **Generate codes** on the coupon screen and `php artisan
offers:coupons:generate`. Up to 100 codes in one transaction, an alphabet without 0/O/1/I/l, a
retry per code, an abort after ten collisions rather than a partial set. The time zone is stated
on the form.

### Upsell overview (K·15)

Filter **Slot** on the offer listing and column **Turnover**: net, that is, paid rows in the
offer's currency less the row's coupon share (`payment_items.discount_cent`) and its share of
refunds (`payments.refunded_cent`, in proportion to the payment amount). Absent without the
payment tables; on `statamic-payments` before 1.8, which lacks the two columns, it stays gross,
with a note in the log.

## 1.5.0 — 2026-09-01

Added afterwards: version 1.5.0 went out without an entry. It brought the **purchase confirmation
as a field on the offer** (`confirmation_mode` default / own template / none,
`confirmation_template` from `et_templates`, only published templates selectable) and the box
**"Where this offer is used"** (funnels and automations, `OfferUsage`) in the edit form.

## 1.4.0

### New: an offer may be a bundle

Until now an offer sold exactly one product. A bundle — three things, one price — could not be
expressed anywhere: bumps are checkboxes the buyer decides on one by one and which cost one by
one, and creating a catalogue product of its own for it means writing the price back into a file.

New field **Also contains** on the offer. If it stays empty, nothing changes.

- **Price:** its own, otherwise the **sum of the parts**. The old fallback to the lead product
  would have become a bug here: three things for the price of one, silently, until somebody does
  the arithmetic.
- **Grants:** the union of what all the parts grant, without duplicates.
- **Invoice:** one row, booked under the lead product. The tax class hangs on its handle, and that
  needs exactly one answer.

**A bundle whose parts contradict each other on `digital` is not sellable.** The key does not
describe the medium, it decides the place of supply and thereby one of four mandatory notes
(§ 3a UStG). A row that is half rendered electronically has no right one — and to choose one would
mean guessing a tax question on a document that can no longer be corrected. The catalogue then
answers "does not exist", and `Checkout::start()` aborts the whole process before money moves. The
same if a part has dropped out of the catalogue.

**Bundles with more than one grant need `statamic-payments` 1.14 or newer.** Before that `grants`
took only a string; a list fell out there at `is_string()` and granted **nothing at all** instead
of the first item. Such a bundle therefore refuses resolution and writes the reason to the log,
instead of letting itself be sold and delivering nothing. What is checked is the installed class,
not a number in a file.

Beside `product` (the lead product) the resolved catalogue entry now carries `products` with all
the parts — so that a sibling doing the delivery does not have to query the offers table itself.

Migration: `products` (json, nullable) on `offers`. Existing rows stay as they are.

## 1.3.0

### Fixed — an offer was only a price, and that tore the family apart

The catalogue resolver returned `name`, `amount_cent`, `currency` and `offer`. Everything else
about the thing being sold lives on the product, and an offer is, by its own description, "a
product, presented". Two consequences, both silent:

- **`digital` and the tax class were missing** → for an order paid through an offer
  `statamic-invoices` could write **no invoice at all**. The advertised chain funnel → offer →
  payment → invoice broke at the last link, on every installation that uses the family the way the
  documentation describes it.
- **`grants` was missing** → anyone who bought through an offer got **no access**. The payment
  went through, the money arrived, the access never appeared. Without an error: "this product
  grants nothing" and "I do not know this product" both came back as the same `null`.

The resolver now delivers the product array with the offer's overrides on top. The offer's price
and name win, everything else is inherited. **Nothing is invented:** an offer for a product that
does not state `digital` does not state it either — and the invoice addon then still refuses
instead of guessing.

New in the result as well: `product`, the handle of the thing underneath. Tax classes are
configured per product handle, and an offer has one of its own — without this key an offer for a
reduced-rate product would silently have fallen back to the standard class and printed the wrong
rate on a tax document.

Three shipped addons, three green suites, and the bug lay in the gap between them.

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
