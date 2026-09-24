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

**And the same goes for the brand.** A bundle whose parts belong to different brands cannot be sold
either: one invoice line belongs to one brand, with that brand's invoice series, sender and revenue,
and choosing one of two answers would be guessing whose money it is. Parts that name no brand say
nothing and contradict nobody — on a single-brand install that is every part. The refusal is logged
with the offer handle, the parts and the brands they named.

The resolved catalogue entry carries `brand_id`: the **offer's** brand, not the brand of the product
underneath, the same rule that already gives the offer its own name and its own price.
`statamic-payments` 1.24.1 and newer stamps a follow-up charge with it instead of inheriting the
brand of the payment it follows. An offer without a brand sends `0`, which that version reads as
"names no brand" and inherits, saying so in the log.

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

**The limit is soft.** It is checked when a checkout starts and a sale is counted when it is paid,
and nothing in between holds a unit the way a stock table would. To close that hole in practice,
unpaid checkouts younger than an hour count as taken (`OfferSales::RESERVATION_MINUTES`); an
abandoned checkout gives its unit back after that. It is still not a reservation: two people who
start a checkout for the last unit within the same second can both pay. Where one unit too many
is a real problem, set the limit with a reserve.

**Revenue is net.** The listing's revenue column is gross line value minus the line's share of the
payment's coupon (`payment_items.discount_cent`) minus its share of any refund
(`payments.refunded_cent`, apportioned by the line's part of the payment total). On a
`statamic-payments` older than 1.8 those columns do not exist, revenue stays gross, and the log
says so once per request.

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
list of keys — and `Offer::checkoutFields()` returns only keys the library still knows.
`Goldnead\StatamicOffers\Offers::fieldLibrary()` is the normalised library for anyone rendering a
form.

**Decision: nothing picked means the funnel step decides,** not "ask for nothing". An offer
created before this column existed, and an offer whose author never opened the section, must not
silently strip the address fields a funnel step already asks for. An offer that wants a checkout
with no extra fields at all says so on the step, where the fields were configured before this
column arrived. `checkout_fields` is therefore stored as `null` when empty, and `checkoutFields()`
returns `[]` for it — the caller reads `[]` as "no opinion".

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

### Pay what you want

An offer with **Pricing → Pay what you want** has a minimum (0 allowed), a suggestion and an
optional maximum instead of a price. Without an own maximum, `pay_what_you_want.max_cent` caps it
(default 5,000.00) so a typo with three zeros too many is not a charge.

The amount is the one number that comes from the buyer, and it is only believed inside those
bounds. It travels in the catalogue handle, `offer:workshop:=2500`, and the catalogue refuses any
amount outside the bounds, any malformed one (`=01500`, `=10.50`, `=-1`) and any amount at all on a
fixed-price offer. So `statamic-payments` needs no change: the checkout looks the handle up like any
other and charges what the catalogue says.

```php
$basket = Basket::make($offer, amountCent: (int) $request->input('amount_cent'));
// throws AmountNotAccepted (an InvalidArgumentException with buyerMessage()) outside the bounds;
// without an amount, the suggestion is used
```

**The minimum is a floor after discounts too.** A coupon takes at most the part of the chosen
amount above the minimum (plus the bumps, for a coupon on the whole basket).

A subscription at a chosen amount charges that amount every cycle, because the renewals read the
same handle. Pay what you want does not combine with several payment options (the form refuses it).

**Thank-you tiers** are optional: text per threshold, and the highest tier the paid amount reaches
wins. `Offer::thankYouFor(int $cent)`, `Offers::thankYouFor('offer:workshop:=6000')`, or in a
template `{{ offers:thanks handle="workshop" amount_cent="6000" }}`.

### Setup fee

On an offer with a rhythm, **Setup fee** is charged once with the first payment, as its own line
(`offer:<handle>:+setup`) and therefore its own invoice position. The line inherits the product's
tax facts, carries no rhythm and grants nothing. Coupons never take anything off it. With several
payment options, the fee applies only when the chosen option has a rhythm. A *lower* first payment
is the existing paid trial (`trial_days` + `trial_amount_cent`). `Offer::firstPaymentCent()` is the
number to show as "due today".

### Availability by country

**Available in**: worldwide (default), only these countries, or everywhere except these. Enforced in
`Basket::make(..., country: 'DE')`, which throws `OfferNotAvailable` (with `buyerMessage()`) for a
country outside the rule, **and when no country is given while a rule exists**: a rule that only
holds for buyers who volunteer their country is not a rule. A bump restricted elsewhere quietly
drops out of the basket. Siblings ask `Offers::availableIn($catalogueHandle, $country)`.

### Coupon links and QR codes

Every coupon has links with the code prefilled: its target page (`link_url`, default the home page)
plus the short link of every offer it applies to. The parameter is `?coupon=CODE`
(`coupon_link.parameter`, read everywhere through `Offers::couponParameter()`). The coupon panel
shows each link with a QR code and SVG/PNG downloads, generated on the server (the QR encoder is
`bacon/bacon-qr-code`, which Statamic already ships; PNG is written without GD or Imagick).

A link only prefills. The code is redeemed in the basket against the same table as a typed one:
`Offers::couponFromRequest($request, $offer)` returns the live coupon or `null`, and logs why an
expired, exhausted, unknown or foreign code was ignored instead of failing the page.

A redemption is counted in `Basket::discount()`, at the moment the basket becomes a payment, so two
people cannot both get the last use. When the checkout then refuses (`Checkout::start()` returns
`null`, for example over the buyer's country), call `$basket->releaseCoupon()`; it gives the
redemption back once.

### Coupon duration and scope

- **Applies to** (`duration`): the first payment (default, and what every coupon did before), the
  first *n* payments, or every payment. For subscriptions, `Basket::paymentMeta()` returns
  `['coupon' => terms]` to attach to the payment; `Offers::recurringDiscountCent($terms, $number,
  $amount)` is the arithmetic for payment number *n*. Lowering the renewals themselves is done by
  `statamic-payments`; until a version that reads `meta.coupon` is installed, every coupon behaves
  as "the first payment".
- **Takes off** (`applies_to`): offer and bumps (default), the offer only, or the bumps only.
- **Also for later offers in the same funnel** (`funnel_wide`): `Coupon::coversFollowUps()`; carrying
  the code from step to step is the funnel's job.

### Short link

An offer may have a short link, `/go/<slug>` (`links.prefix`), that leads to **Target** while the
offer runs and to **Target afterwards** (a waiting list, the full price) once the switch date passes
(own date, else `available_until`) or, if enabled, once the contingent is sold out. The query string
travels along, so a coupon link through the short link keeps its code. Visits are counted per target.
302, never 301: a browser that cached a permanent redirect would keep sending people to the old
target. The offer panel shows the link, the current target, the visits and the QR code.

### Seats for groups

An offer with **Seats per purchase** (2 or more) sells several accesses in one purchase. The buyer
does **not** get the access herself: the catalogue entry carries the grants under `seat_grants`
instead of `grants`, so `statamic-payments` grants nothing to her. On the paid event a seat pool is
opened (once per payment line, `seats × quantity`), and she gets a mail with a link to a page where
she invites people by email, sees who accepted, takes seats back and gives them again. An invited
person accepts on their own page; only then is the access granted, through
`statamic-entitlements` when it is installed (source `statamic-offers`, reference `seat:<id>`). A seat
taken back revokes that access with a reason and frees the place.

Both pages are authorised by a 48-character token and nothing else, because the people who open
them have no account here. Seats are one-off purchases only; the form refuses seats with a rhythm.
Bind your own `Contracts\SeatAccess` to grant access some other way. Access is written under the
brand of the pool, not the brand of the request that opened the page.

**Money back, seats back.** A full refund or a chargeback (payments 1.23+) closes every pool of the
payment: all seats are taken back, accepted ones lose their access, and the pool takes no further
invitation or acceptance. A partial refund closes nothing; the buyer decides which seat goes.

A seat is marked as taken back only **after** its access was revoked. If entitlements cannot be
reached at that moment, the seat stays accepted, the log says so, and
`php artisan offers:seats-reconcile` (safe to run as often as you like; exits non-zero while
something is still open) catches up. Schedule it:

```php
Schedule::command('offers:seats-reconcile')->hourly();
```

In the Control Panel the offer panel lists the pools sold (buyer, seats given, closed or open), with
**Resend link** (to the buyer's address only) and **Take back** per seat, which asks first for an
accepted seat.

### Counting

Two numbers per offer: how often it was **shown**, and how often it was **accepted**.

Accepted means **paid**, not clicked. An offer whose conversion rate counts clicks flatters itself
every time a card is declined, and the number nobody can trust is worse than no number.

### Events

Every event carries `brandId` (the brand of the pool, offer or payment; `null` without brands), so a
listener started from the provider's webhook or a seat page opened from a mail runs in the right
brand. Each fires once per moment, also on a redelivered webhook or a double click.

| Event | When | Properties |
|---|---|---|
| `SeatPoolOpened` | a paid purchase of seats opened its pool | `pool` |
| `SeatInvited` | a seat was given to an address | `seat`, `pool` |
| `SeatAccepted` | the invited person accepted, access granted | `seat`, `pool` |
| `SeatRevoked` | a seat was taken back (after its access was revoked) | `seat`, `pool`, `previousStatus` (`claimed`/`invited`), `reason` |
| `SeatPoolClosed` | full refund or chargeback closed the pool, after its seats | `pool`, `reason` |
| `OfferSoldOut` | a paid purchase took the last unit of a limited offer | `offer`, `sold` |
| `CouponRedeemed` | a payment that used a coupon is paid | `coupon`, `payment` |
| `ShortLinkSwitched` | the short link leads to its second target for the first time | `offer`, `reason` (`date`/`sold_out`) |

Sold out and the switch are marked on the offer (`sold_out_at`, `link_switched_at`) and cleared when
it opens again (limit raised, date moved), so the next change fires again. The switch is noticed by
the purchase that sells out, or by the first visit after the date.

### Webhooks

With [statamic-webhook-manager](https://github.com/goldnead/statamic-webhook-manager) installed,
every event above is a trigger (source type `offers`). Without it nothing is loaded;
`webhook_manager.enabled` (env `STATAMIC_OFFERS_WEBHOOK_MANAGER`, default `true`) switches the
bridge off. A hook fires in the event's brand.

An event whose brand no longer exists sends nothing (logged), rather than reaching the hooks of
whichever brand is current. A moment is sent after its database transaction commits, and not at all
if it is rolled back. **Order is not guaranteed** (retries, queues): sort by `occurred_at` and
deduplicate on `event_id`.

Every payload starts with the frame the suite addons share: `event` (the handle), `event_id`
(`<handle>:<subject_id>:<time of the moment>`, for a coupon `…:payment-<id>`, the same for the same
moment however often it is sent, a redelivered payment included), `occurred_at` (when the moment
happened, ISO 8601 with offset), `brand` (`{id, handle}` or `null`), `subject_type` and `subject_id` (the
seat for seat events, `seat_pool` for the pool events, the coupon, else the offer).
**Never a token**: neither a seat's nor the pool's `manage_token`, since each opens a seat page as
that person.

- `offer`: `{id, handle, name}`
- `pool`: `{id, product, seats, taken, owner {email, name}, payment_id, closed_at}`
- `seat`: `{id, email, name, status, invited_at, claimed_at, revoked_at}`
- `payment`: the same block statamic-payments sends in its own webhooks (`id`, `provider`,
  `provider_id`, `status`, `product`, `amount_cent`, `currency`, discount and refund in cent, buyer
  `email` and `name`, `country`, `items[]`, `attribution{utm_*}`, timestamps). No card, mandate,
  customer reference or meta.

| Trigger | Fields after the frame |
|---|---|
| `offers.seat_pool_opened` | `offer`, `pool` |
| `offers.seat_invited` | `offer`, `pool`, `seat` |
| `offers.seat_accepted` | `offer`, `pool`, `seat` |
| `offers.seat_revoked` | `offer`, `pool`, `seat`, `previous_status`, `reason` |
| `offers.seat_pool_closed` | `offer`, `pool`, `reason` |
| `offers.sold_out` | `offer`, `quantity_limit`, `sold` |
| `offers.coupon_redeemed` | `coupon {id, code, name, percent, amount_cent, currency}`, `discount_cent`, `currency`, `buyer {email, name}`, `payment` |
| `offers.link_switched` | `offer`, `reason`, `link {slug, target, fallback, switch_at}` |

## Configuration

| Key | Default | What happens when it is wrong |
|---|---|---|
| `handle_prefix` | `offer:` | Change it and every template referring to an offer changes too. Empty would let an offer reprice a product of the same name. |
| `count_impressions` | `true` | Off means the shown count stays at zero and the ratio becomes meaningless. Useful on a heavily cached page, where it was meaningless anyway. |
| `seller.name` · `seller.contact` | `null` | Fill the placeholders in the withdrawal text. Empty falls back to `app.name` and `mail.from.address`, which is wrong the moment a legal entity sells here. |
| `withdrawal.*` | 14 days, German draft wording | The site-wide terms every offer inherits. A draft, to be checked by a lawyer. |
| `checkout_fields` | eight fields incl. the invoice address | The library the offer form picks from. Removing a key silently drops it from every offer's picks. |
| `pay_what_you_want.max_cent` | `500000` | The ceiling for a chosen amount when the offer names none. |
| `coupon_link.parameter` | `coupon` | Renaming it breaks every printed link with the old name: the page opens, without the discount. |
| `links.prefix` · `links.base_url` | `go` · `null` | The short link path, and the address printed in links and QR codes (`null` means `app.url`). The prefix must not equal a page path of the site. |
| `seats.prefix` · `seats.after_claim_url` | `!/statamic-offers/plaetze` · `null` | Where the seat pages live, and where the button after accepting a seat leads. |

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
