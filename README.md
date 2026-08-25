<!-- statamic:hide -->
# Statamic Offers
> A product, a price of its own, the words that sell it, and where it appears.
<!-- /statamic:hide -->

## Requirements

Statamic 6 · PHP 8.2+ · a database · [`goldnead/statamic-payments`](https://github.com/goldnead/statamic-payments).

## What an offer is, and why it is not a product

A **product** is a thing that exists and costs money. An **offer** is that thing *presented*: at a
place, for a price that may be its own, with words that are about this moment.

The same product is a €29 purchase on the sales page and a €12 upsell on the thank-you page. Those
are two offers, one product — and the second one is the reason this addon exists at all.

## Installation

```bash
composer require goldnead/statamic-offers
php artisan migrate
```

Offers appear under **Utilities → Offers**.

## The price rule

The payment addon is built on one rule: **an amount never comes from a request.** A checkout that
accepted a posted price would sell a €29 thing for a cent.

An offer's own price bends that rule in the only safe direction: it lives in a table, on the server,
decided by whoever runs the site. Nothing about it is reachable from a browser.

That is wired through a seam in the payment addon:

```php
Catalogue::extend(fn (string $handle) => /* … */);
```

An offer therefore resolves like any other product, and every guard the payment addon already has
applies to it unchanged.

## Usage

### Buying an offer

Refer to it with the prefix:

```php
app(Checkout::class)->start('offer:fruehling-upsell', $buyer);
```

The prefix keeps offers and products apart. Without it, an offer named after a product could quietly
reprice it — and the checkout would charge the wrong amount with no sign that anything was wrong.

### In a template

```antlers
{{ offers:show handle="fruehling-upsell" }}
    {{ if no_results }}
        {{# Nothing to offer: inactive, or its product is gone. #}}
    {{ else }}
        <h2>{{ headline }}</h2>
        <p>{{ amount }} {{ currency }} {{ if compare_at }}<s>{{ compare_at }}</s>{{ /if }}</p>
        {{# `buy_handle` already carries the prefix. #}}
        <input type="hidden" name="product" value="{{ buy_handle }}">
    {{ /if }}
{{ /offers:show }}
```

`{{ offers:slot slot="bump" }}` yields every active offer for a slot.

### Bumps

An offer can carry other offers as checkboxes at checkout. Pick them in the **Bumps** field on the
offer form; only offers placed **At checkout** can be picked, and the order you pick them in is the
order they appear.

The list on the offer is the authority, not the form the buyer sees. A ticked box that is not on
that list is ignored, so nobody can add a cheap handle to the page and buy an unrelated product.

```php
$basket = Basket::make($offer, $request->input('bumps', []), $request->input('coupon'));
```

### Coupons

**Utilities → Coupons** is where a code and what it is worth are decided. A coupon takes either a
percentage or a fixed amount off, may be limited to certain offers, to a date range, and to a number
of redemptions.

This is the one place that looks like an exception to the price rule and is not: what arrives from
the browser is a **code**, and what the code is worth is looked up in the table. A request that says
"20 % off" is ignored; a request that says `FRUEHLING` is a question this table answers.

Codes are matched however they are typed, so `FRUEHLING` and `fruehling` are the same coupon. A
redemption is counted when a payment starts, not when a code is typed, and the last one cannot go to
two people at once.


**The `{{ if no_results }} … {{ else }}` is not optional.** Like every Statamic tag pair, this one
parses its block once even when there is nothing to yield, so markup outside that branch prints an
empty offer.

### Counting

Two numbers per offer: how often it was **shown**, and how often it was **accepted**.

Accepted means **paid**, not clicked. An offer whose conversion rate counts clicks flatters itself
every time a card is declined, and the number nobody can trust is worse than no number.

## Configuration

| Key | Default | What happens when it is wrong |
|---|---|---|
| `handle_prefix` | `offer:` | Change it and every template referring to an offer changes too. Empty would let an offer reprice a product of the same name. |
| `count_impressions` | `true` | Off means the shown count stays at zero and the ratio becomes meaningless. Useful on a heavily cached page, where it was meaningless anyway. |

## Multi-site

Offers are not site-scoped. An offer is a commercial decision, not content.

## Support

Only the latest version. <https://github.com/goldnead/statamic-offers/issues>

## Changelog · License

[CHANGELOG.md](CHANGELOG.md) · [LICENSE.md](LICENSE.md)
