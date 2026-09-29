# Best Sellers

Sales **reporting** for Craft Commerce.

## Overview

- Shows what sold, by product or by variant, with units, item sales net of coupons, and gross profit from each variant's unit cost, and can drill from a product into the orders that contain it.
- Lets you build best-seller listings and buy-again pages in Twig, from units sold and a customer's purchase history.
- Reports revenue, orders, average order value, and repeat rate over any date range, against the period before it, the same period last year, and a 12-month average.
- Lists every completed order with filters for status, payment, shipping method, and discount, and downloads invoices or packing slips for a batch of them in one go.
- Reconciles money movement against your payment gateways: captured, refunded, and net, per transaction.
- Reports who bought, how often, and how much, and maps where their orders shipped, down to the city.
- Finds abandoned carts and gives you a link that puts a customer back into theirs.

Every report exports to CSV with the current filters applied.

## Requirements

- Craft CMS `^5.0.0`
- Craft Commerce `^5.0.0`
- PHP `>=8.2`

## Install

```sh
composer require fostercommerce/commerce-best-sellers
./craft plugin/install best-sellers
```

Then backfill your existing orders, which a fresh install does not have:

```sh
./craft best-sellers/backfill
./craft best-sellers/backfill/daily-stats
```

See [`docs/installation.md`](./docs/installation.md) for the full guide.

## Templating

Twig methods for units sold and item sales per product or variant, for a customer's previous purchases, and for building a "buy again" page. Craft's product and variant element queries also gain a `bestSellers()` method that attaches sales figures to the elements they return, so you can rank a product listing by what sold.

See [`docs/dev-guide/templating.md`](./docs/dev-guide/templating.md).

## Reports

Seven pages under a **Best Sellers** section in the control panel: a dashboard, orders, transactions, products, customers, locations, and an operations page covering store configuration. A date range, an order status filter, and a shipping location filter sit above all of them and follow you from page to page. The Products report can also filter by your own variant fields, such as a supplier, chosen per product type.

See the [user guide](./docs/index.md).

## Unit costs and profit

Records what each item cost you, from a Money field on your variants that you choose per product type. The Products report shows cost, gross profit, and gross margin for any date range, and the dashboard adds gross profit and margin cards. A backfill adds costs to orders placed before you set a unit cost field.

See [unit costs and profit](./docs/user-guide/unit-costs-and-profit.md).

## Cart restore

A front-end route that restores an abandoned cart to a customer's session and sends them to your cart page. Carts belonging to registered customers can only be restored by the account holder.

See [`docs/user-guide/cart-restore.md`](./docs/user-guide/cart-restore.md).

## Console commands

Commands for backfilling orders, filling in unit costs, rebuilding the daily stats table, and clearing either table, all also available from **Utilities -> Best Sellers**.

See [`docs/reference/console-commands.md`](./docs/reference/console-commands.md).

## Permissions

- `best-sellers:viewReports`: see the Best Sellers section and every report in it.
- `best-sellers:backfill`: run backfills and rebuilds from the control panel.
- `best-sellers:manageSettings`: see and save the plugin's settings.

See [`docs/reference/permissions.md`](./docs/reference/permissions.md).

## License

Proprietary.

## Credits

Brought to you by [Foster Commerce](https://fostercommerce.com).
