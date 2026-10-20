# Magenx_CheckoutFieldsGraphQl

> **Built with AI assistance.** This module is built with significant use of Claude Code, under active human review and guidance. It is open source and in active development. AI assistance does not lower the engineering bar: every change must meet the same correctness, security, documentation and maintainability requirements as any other contribution.
>
> **No warranty.** We are not responsible for any incompatibility. By installing this software, you accept responsibility for its security, upgrades, patches and monitoring.

Order comment and requested delivery date / time slot for a headless Magento
checkout, exposed entirely over GraphQL. It follows the same "extend with
GraphQL, leave the core untouched" split the other companion modules use
(StorePickupGraphQl, BestSellerGraphQl, …).

## What it adds

* **Order comment.** A free-text note the shopper adds at checkout. Tags are
  stripped, control characters removed, and the length is capped.
* **Delivery date and time slot.** The shopper picks a date from a calendar the
  server computes (lead time, daily cut-off, weekdays without delivery, closed
  dates, a maximum window) and, when the merchant defines time slots, one slot.
  **Never offered for In-Store Pickup** (`magenx_storepickup_*`) or for virtual
  orders.
* **GraphQL surface:**
  * `Mutation.setCheckoutFieldsOnCart(input: { cart_id, order_note, delivery_date, delivery_slot })`
    — a field left out of the input is unchanged; `null` / `""` clears it. Cart
    ownership is enforced by `GetCartForUser`, as for every stock cart mutation.
  * `Cart.checkout_fields` — `order_note_enabled`, `order_note_max_length`, the
    stored `order_note` / `delivery_date` / `delivery_slot`, and
    `delivery_options { required available_dates slots { code label } }`.
    `delivery_options` is `null` when delivery dates are off, the cart is
    virtual, or In-Store Pickup is the selected shipping method; it is only
    computed when the query selects it.
  * `CustomerOrder.checkout_fields` — `order_note`, `delivery_date`,
    `delivery_slot` as recorded on the order. Read from columns on the loaded
    order, so an order list costs no extra queries.
* **Admin:** a "Comment & Delivery" box on the order view (Information tab) and
  an optional **Delivery Date** column in the order grid (hidden by default; turn
  it on from the grid's Columns menu), sortable and filterable.
* **Order email variables:** `{{var magenx_order_note}}`,
  `{{var magenx_delivery_date}}` (long date in the store locale) and
  `{{var magenx_delivery_slot}}` (slot label). See below.

## Configuration

Stores → Configuration → **Magenx → Checkout Fields**. Both features are off by
default.

| Path | Default | Effect |
|---|---|---|
| `magenx_checkout_fields/order_note/enabled` | `0` | Offer the order comment box. |
| `magenx_checkout_fields/order_note/max_length` | `500` | Longest comment accepted (characters). |
| `magenx_checkout_fields/delivery_date/enabled` | `0` | Offer the delivery date picker. |
| `magenx_checkout_fields/delivery_date/required` | `0` | Refuse to place a storefront order without a date. |
| `magenx_checkout_fields/delivery_date/lead_days` | `1` | Earliest date, in days from today. `0` = same day. |
| `magenx_checkout_fields/delivery_date/max_days` | `30` | Latest date, in days from today (capped at 365). |
| `magenx_checkout_fields/delivery_date/cutoff_time` | empty | `HH:MM`; at or after it the earliest date moves back one day. |
| `magenx_checkout_fields/delivery_date/excluded_weekdays` | `0` (Sunday) | Weekdays without delivery. |
| `magenx_checkout_fields/delivery_date/blackout_dates` | empty | Closed dates, one `YYYY-MM-DD` per line. |
| `magenx_checkout_fields/delivery_date/slots` | empty | Repeatable `code` / `label` rows. With any slot defined, a slot is required with the date. |

All dates are calendar dates in the **store time zone**
(General → Locale Options → Timezone). They are stored in `DATE` columns and
never shifted by a time zone conversion.

## How the values are persisted

Three nullable columns — `magenx_order_note` (TEXT), `magenx_delivery_date`
(DATE) and `magenx_delivery_slot` (VARCHAR 32) — are added to `quote` and
`sales_order`, and `magenx_delivery_date` to `sales_order_grid`
(`etc/db_schema.xml`). The mutation writes the quote; the
`sales_model_service_quote_submit_before` observer
(`Observer\CopyCheckoutFieldsToOrder`) copies them onto the order. Only the slot
**code** is stored; its label is resolved from config on read and falls back to
the code when the slot has since been removed.

### Re-validation at placement

The observer re-checks the date and slot against the calendar as the order is
placed. The cut-off can pass between the shopper choosing a date and paying (a
PayPal or 3-D Secure redirect, a tab left open overnight). An outdated date then
aborts placement with *"The delivery date … is no longer available. Please choose
another date."* rather than recording a date the merchant cannot meet.

A date left on the quote while In-Store Pickup is the shipping method is
dropped, not copied: the location and method are set by independent mutations,
so the quote can carry a stale value.

### "Required" and non-storefront orders

"Required" is enforced only for GraphQL orders. Orders created in the admin
(phone orders) have no delivery date field and are never blocked.

**Agent checkouts (UCP / ACP) also run over GraphQL and never send a date.**
With "Required" set to Yes those orders fail. Leave it at No if you sell through
AI agents.

## Order confirmation email

The variables are added through `email_order_set_template_vars_before`. They
are empty strings when unset. `{{var}}` escapes HTML, so the shopper's comment
is safe to print. Add a block like this to your order email template
(Marketing → Email Templates):

```html
{{depend magenx_delivery_date}}
<p><strong>{{trans "Requested delivery"}}:</strong> {{var magenx_delivery_date}}
{{depend magenx_delivery_slot}} — {{var magenx_delivery_slot}}{{/depend}}</p>
{{/depend}}
{{depend magenx_order_note}}
<p><strong>{{trans "Your comment"}}:</strong><br/>{{var magenx_order_note}}</p>
{{/depend}}
```

## Install

```
bin/magento module:enable Magenx_CheckoutFieldsGraphQl
bin/magento setup:upgrade
```

Then enable the fields under Stores → Configuration → Magenx → Checkout Fields.
