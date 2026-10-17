# qsync Pricing for Paymenter

One place for prices, stock and promotions:

- **Pricing → Prices & stock**: every product and plan with its price and setup fee in each currency (BRL, USD, …) and its stock, in one table. Edit any cell, or use the bulk tools on the selected rows.
- **Pricing → Promotions**: a % off on chosen products for a limited time and/or for the first N orders ("only 10 at this price"), applied in the cart automatically, with sale badges in the qsync Marketplace.

## Install

```bash
cd /opt/paymenter/extensions/Others
git clone https://github.com/sagattiger/paymenter-pricing.git Pricing
git config --global --add safe.directory /opt/paymenter/extensions/Others/Pricing
chown -R www-data:www-data Pricing
cd /opt/paymenter && php artisan optimize:clear && php artisan queue:restart
```

Admin → Extensions → **qsync Pricing** → enable. Tables are created on the first page load. Admins need the permission **"Edit prices, stock and promotions"** (Admin → Roles) unless they have all permissions.

Update: `cd /opt/paymenter/extensions/Others/Pricing && git pull && cd /opt/paymenter && php artisan optimize:clear && php artisan queue:restart`

## Prices & stock

- **Filters**: name, category, Marketplace family, billing period (e.g. only yearly plans), shown/hidden in store, limited or low stock, "missing a price in USD". Choose which currencies are shown as columns.
- **Edit by hand**: type in a cell. Empty price = the plan is not sold in that currency. Empty stock = unlimited. Changed cells turn yellow; nothing is stored until **Save**.
- **Bulk tools** (tick products on the left; ticking a product selects all its plans):
  - Raise / lower by % (−15 = 15 % off) or by an amount
  - Set to a value
  - From another currency × rate (e.g. USD = BRL × 0.20)
  - Longer plans = monthly × months − % (e.g. yearly = monthly × 12 − 15 %)
  - Remove (stop selling in a currency)
  - Rounding: ,90 / ,99 / whole / ,50 / nearest 5 / 2 decimals
  - Stock: add units, set units, unlimited
- **Undo**: the last save can be undone (every change is logged in `ext_pricing_changes`).
- Existing customers keep the price of their current service; new orders get the new price. (This is how Paymenter works: a service stores its own price.)

### Stock

Paymenter's own product stock: each order lowers it, and at 0 the product can't be ordered. Settings: **Low stock warning at** (default 3) marks products on the page, and **Send the low stock e-mail to** sends one e-mail when a product reaches that level and one when it runs out.

## Promotions

A promotion is a Paymenter coupon managed for you, so Paymenter itself applies the discount on the invoice and enforces the dates and limits:

| Field | |
|---|---|
| Name | shown to customers ("Black Friday") |
| Code | made from the name if empty; customers can also type it |
| Discount on | the price, price and setup fee, or a free setup fee |
| % off | a percentage, so it works the same in R$ and US$ |
| For | the first payment, the first 3 / 6 / 12, or every payment |
| Starts / Ends | in the time zone from the settings (São Paulo by default) |
| Only for the first … orders | limited quantity; it stops by itself when sold out |
| Per customer | how many times one customer can get it |
| Apply automatically | put on the cart without a code (a code the customer typed is never replaced; the biggest running discount wins) |
| Sale badge | −20 %, the old price struck through, "ends in 3 days", "Only 4 left" on Marketplace cards and plan cards |

"Create a promotion…" on the Prices page opens the form with the selected products. **End now** stops a promotion; **Delete** removes it, or only ends it when customers already used it (their renewals still need it).

## Settings

Apply promotions automatically · Show sale badges · Time zone · Low stock warning level · Low stock e-mail.

## Tests

```bash
PAYMENTER=/opt/paymenter php tests/test_pricing.php   # rolls back everything it creates
```
