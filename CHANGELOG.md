# Changelog

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
