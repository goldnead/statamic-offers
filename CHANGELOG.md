# Changelog

## 1.0.0

Initial release. An offer is a product from the payment catalogue plus a price of its own, words,
a slot and two counters. It resolves through `Catalogue::extend()`, so the price stays server-side
and every guard the payment addon has applies to it unchanged.
