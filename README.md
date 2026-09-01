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

### Bundles

An offer usually sells one product. Pick more under **Also included** and it sells all of them:
one line, one price, everything handed over together.

| | |
|---|---|
| **Price** | The offer's own, if it has one. Without one, the **sum of the parts** — never the first part's price, which is how three things get sold for the price of one. |
| **Granted** | Everything every part grants, deduplicated. |
| **On the invoice** | One line, filed under the **lead product** — the one in the Product field. Its handle is what a tax class hangs on. |

**A bundle whose parts disagree about `digital` cannot be sold.** That key is not a description of
the medium, it decides the place of supply and with it which of four mandatory notes the invoice
carries (§ 3a UStG). Half a line delivered electronically has no right note, and picking one would
be guessing at a tax question on a document that cannot be corrected afterwards. So the catalogue
answers "no such thing", `Checkout::start()` refuses the whole order, and it does so before any
money moves.

The same goes for a part the catalogue no longer sells: the bundle stops being offered rather than
quietly delivering less than was bought.

**Bundles that grant more than one thing need `statamic-payments` 1.14 or newer.** Before that,
`grants` had to be a single string and a list fell out of an `is_string()` check — granting nothing
at all rather than the first item. Rather than sell into that, such a bundle refuses to resolve and
says why in the log. The check asks the installed class, not a version number in a file.

Consumers that need to know what was actually delivered read `products` off the resolved catalogue
entry; `product` names only the lead.

```php
$eintrag = app(Catalogue::class)->find('offer:fruehlings-buendel');

$eintrag['product'];  // 'noten-paket', the lead
$eintrag['products']; // ['noten-paket', 'playback-paket', 'mitschnitt']
```

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

### Coupon batches

**Codes erzeugen** on the Coupons screen makes up to 100 codes at once: a prefix, a random part of
6 to 12 characters from an alphabet without `0`/`O`/`1`/`I`/`l`, one use each unless you say
otherwise, and the same discount, window and offer list a single coupon has. All of them are made in
one transaction: a code that collides is retried, and ten misses in a row abort the whole batch
rather than leaving ninety-three. The dates are in the application's timezone, and the form says so.

From the terminal, with the same options:

```bash
php artisan offers:coupons:generate --count=50 --prefix=CHOR- --percent=15 --until=2027-03-31
```

### Percentage discount

Instead of typing an own price and a struck-through price by hand, an offer may carry
`discount_percent` (1–99). The effective price is then the catalogue price minus that share, rounded
to the cent, and the struck-through price *is* the catalogue price — so it can no longer go stale
when the catalogue changes. An own price and a percentage together are refused.

`Offer::effectiveAmountCent()` and `Offer::effectiveCompareAtCent()` are the two numbers;
`amountCent()` delegates to the first and stays what it was for every caller.

### Limits

`quantity_limit`, `available_from` and `available_until` live on the offer, not on the funnel step:
the same offer sold through two funnels is one scarcity. Sold means **paid** — the limit is compared
against paid payment lines, never against a counter of its own. `remainingQuantity()` is `null`
without a limit; `isSellable()` is false outside the window or at zero remaining, so the catalogue
stops answering and no checkout can start. The listing shows the state in a column of its own.

### Access window

`access_starts_at` and `access_days` say when access begins and how long it lasts.
`accessWindow()` returns `['starts_at' => 'Y-m-d'|null, 'days' => int|null]` or `null`. The funnel
hands it to the payment as `meta['access']`; the entitlements bridge in `statamic-payments` turns it
into `starts_at` and `expires_at`. This addon writes no entitlement itself.

### Checkout fields

`config('statamic-offers.checkout_fields')` is the **library**: every field a checkout could ask
for, defined once (label, type `text|select|checkbox`, required, extra rules). The default library
carries `name`, `street`, `postal_code`, `city`, `country` (two letters, `size:2`), `phone`,
`company` and `vat_id`; add your own. The offer then **picks** from it — `checkout_fields` is a
list of keys — and `Offer::checkoutFields()` returns only keys the library still knows. Nothing
picked means the funnel step decides. `Goldnead\StatamicOffers\Offers::fieldLibrary()` is the
normalised library for anyone rendering a form.

### Withdrawal terms

Six columns on the offer — `withdrawal_days`, `withdrawal_text`, `withdrawal_waiver_text`,
`withdrawal_checkbox_required`, `withdrawal_b2b_text`, `withdrawal_pdf` — and a site-wide default
in `config('statamic-offers.withdrawal')`, with `{days}`, `{seller_name}` and `{seller_contact}`
filled from `config('statamic-offers.seller')`. `Offer::withdrawalTerms()` layers offer over config
over default and returns:

```php
['days' => 14, 'text' => '…', 'waiver_text' => '…', 'checkbox_required' => true, 'b2b_text' => null, 'version' => 'a1b2c3d4e5f6']
```

**`version` is the contract with the payment.** It is a hash over period, text and waiver, and the
checkout freezes `waiver_text` plus this version on the payment as `consent_text` and the whole
array as `meta['withdrawal']` — so a text edited next month never rewrites what somebody consented
to last month. That freezing is the funnel's job; this addon only says what the terms are today.

The shipped wording is a **draft, to be checked by a lawyer**; it follows §§ 355, 356 Abs. 5 and
356a BGB but no config file is legal advice. `withdrawal_pdf` is a stored flag only — **attaching
the notice as a PDF is not implemented yet.**

### Counting

Two numbers per offer: how often it was **shown**, and how often it was **accepted**.

Accepted means **paid**, not clicked. An offer whose conversion rate counts clicks flatters itself
every time a card is declined, and the number nobody can trust is worse than no number.

## Configuration

| Key | Default | What happens when it is wrong |
|---|---|---|
| `handle_prefix` | `offer:` | Change it and every template referring to an offer changes too. Empty would let an offer reprice a product of the same name. |
| `count_impressions` | `true` | Off means the shown count stays at zero and the ratio becomes meaningless. Useful on a heavily cached page, where it was meaningless anyway. |
| `seller.name` · `seller.contact` | `null` | Fill the placeholders in the withdrawal text. Empty falls back to `app.name` and `mail.from.address`, which is wrong the moment a legal entity sells here. |
| `withdrawal.*` | 14 days, German draft wording | The site-wide terms every offer inherits. A draft, to be checked by a lawyer. |
| `checkout_fields` | eight fields incl. the invoice address | The library the offer form picks from. Removing a key silently drops it from every offer's picks. |

The listing also shows **revenue** per offer — paid lines with the offer's handle, in the offer's
currency — and a **slot filter**, which is what makes the Offers screen an upsell overview: filter
to *after the purchase* and read shown, accepted, conversion and revenue side by side. The column
only exists when the payment tables do.

## Multi-site

Offers are not site-scoped. An offer is a commercial decision, not content.

## Support

Only the latest version. <https://github.com/goldnead/statamic-offers/issues>

## Changelog · License

[CHANGELOG.md](CHANGELOG.md) · [LICENSE.md](LICENSE.md)
