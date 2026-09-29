![Best Sellers](resources/img/header.png)

# Best Sellers

Essential **sales insights** and top-performing product data for Craft Commerce stores.

## Overview

- Read revenue, orders, average order value, and repeat rate for any date range, with a written summary comparing it to the previous period, the same period last year, and a 12-month average.
- Filter orders, payment transactions, product sales, and customers by date, order status, and shipping location, and export each report to CSV.
- See where your orders ship, by country, state, and city, with orders, revenue, and customers for each.
- Report cost, gross profit, and gross margin from a unit cost you record on each variant.
- Filter the Products and Orders reports by your own custom fields, such as a supplier on your variants or a sales channel on your orders.
- Build best-seller listings and buy-again pages in Twig, from units sold and a customer's purchase history.
- Send a customer a link that restores their abandoned cart, and download invoices or packing slips for a batch of orders at once.

## Use Best Sellers when

- You answer sales questions by exporting Commerce orders to a spreadsheet, or the export fails on the date ranges you need.
- You need gross profit and margin by product or supplier, and your variants can hold a unit cost.
- You filter sales by fields on your variants or orders, such as a supplier or a sales channel.
- Your product listings need a "most popular" sort, or customer accounts need a buy-again page.
- You follow up on abandoned carts and need a link that restores the customer's cart.
- You print invoices or packing slips for many orders at a time.

## Requirements

- Craft CMS `^5.0.0`
- Craft Commerce `^5.0.0`
- PHP `>=8.2`

## Install

```sh
composer require fostercommerce/commerce-best-sellers
./craft plugin/install best-sellers
```

For the full guide, see [installation](./docs/installation.md).

## Reports

Seven pages under **Best Sellers** in the control panel, for users with the `best-sellers:viewReports` permission. The [dashboard](./docs/user-guide/dashboard.md) summarizes a date range in KPI cards and a written summary. [Orders](./docs/user-guide/orders.md), [Transactions](./docs/user-guide/transactions.md), [Products](./docs/user-guide/products.md), and [Customers](./docs/user-guide/customers.md) are filterable tables that export to CSV. [Locations](./docs/user-guide/locations.md) maps orders by country and state, with city breakdowns, and [Operations](./docs/user-guide/operations.md) lists store configuration, emails, coupon usage, and backfill logs.

A date range, an order status filter, and a shipping locations filter apply across the reports and stay set between pages. The Products and Orders reports can also filter by your own custom fields, such as a supplier or a sales channel. See [filters and report pages](./docs/user-guide/filters-and-report-pages.md).

The Orders report can show your order fields as columns, add a Date Shipped column, and download invoices or packing slips for the orders you select. The download needs Commerce's `commerce-manageOrders` permission.

## Unit costs and profit

The plugin records what each item cost you, from a Money field on your variants that you choose per product type. The Products report shows cost, gross profit, and gross margin for any date range, and the dashboard adds gross profit and margin cards. **Fill Unit Costs** adds costs to orders placed before you chose a unit cost field.

See [unit costs and profit](./docs/user-guide/unit-costs-and-profit.md).

## Templating

Build best-seller listings and buy-again pages in Twig. Product and variant queries gain a `bestSellers()` method that attaches units sold and item sales to each element, so you can sort a listing by what sold. Twig methods return a product's or variant's totals, and a customer's previous purchases.

See [templating](./docs/dev-guide/templating.md).

## Cart restore

A link that restores an abandoned cart to a customer's session and sends them to your cart page. Copy it from the dashboard's **Highest-Value Abandoned Carts** widget. A registered customer's cart restores only for that customer, while logged in.

See [cart restore](./docs/user-guide/cart-restore.md).

## Backfill and console commands

The plugin records orders as they complete. To add the orders already in your store, run a backfill after installing:

```sh
./craft best-sellers/backfill
./craft best-sellers/backfill/daily-stats
```

Other commands fill in unit costs, rebuild the daily stats, and clear the sales data or daily stats. The same operations are at **Utilities -> Best Sellers**, for users with the `best-sellers:backfill` permission.

See [data and backfill](./docs/user-guide/data-and-backfill.md) and [console commands](./docs/reference/console-commands.md).

## Documentation

Full documentation is at [fostercommerce.com](https://www.fostercommerce.com/craft-cms-plugins/best-sellers).

## License

Proprietary.

---

<a href="https://www.fostercommerce.com" target="_blank"><img src="./resources/img/foster-commerce.svg" alt="Foster Commerce" width="160" height="40"></a>
