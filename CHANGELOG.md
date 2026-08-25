# Changelog

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
