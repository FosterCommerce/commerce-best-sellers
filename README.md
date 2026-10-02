![Best Sellers](resources/img/header.png)

# Best Sellers

Essential sales insights and top-performing product data for Craft Commerce stores.

## Overview

- Read revenue, orders, average order value, and repeat rate for any date range, with a written summary comparing it to the previous period, the same period last year, and a 12-month average.
- Filter orders, payment transactions, product sales, and customers by date, order status, and shipping location, narrow products and orders by your own custom fields, and export each report to CSV.
- See where your orders ship, by country, state, and city, with orders, revenue, and customers for each.
- See order status emails, coupon usage, and backfill failures in one Operations view.
- Report cost, gross profit, and gross margin from a unit cost you record on each variant.
- Build best-seller listings and buy-again pages in Twig, from units sold and a customer's purchase history.
- Send a customer a link that restores their abandoned cart, and download invoices or packing slips for a batch of orders at once.

## How it works

Install the plugin, then run the backfill to add the orders already in your store. From then on, the plugin records each completed order into its own sales data and daily stats tables. The Products report, the dashboard's KPI cards, and the Twig units and item sales methods read those tables, so they show zero for earlier periods until the backfill's queue jobs finish. The Orders, Transactions, and Customers reports read Commerce's own tables directly.

## Requirements

- Craft CMS `^5.0.0`
- Craft Commerce `^5.0.0`
- PHP `>=8.2`

## Install

```sh
composer require fostercommerce/commerce-best-sellers
./craft plugin/install best-sellers
```

## Documentation

- [Getting started](https://www.fostercommerce.com/craft-cms-plugins/best-sellers/docs/getting-started), from install to a dashboard showing your sales history
- [Dashboard](https://www.fostercommerce.com/craft-cms-plugins/best-sellers/docs/user-guide/dashboard), KPI cards, written summaries, and abandoned carts
- [Unit costs and profit](https://www.fostercommerce.com/craft-cms-plugins/best-sellers/docs/user-guide/unit-costs-and-profit), recording what items cost and reporting gross margin
- [Templating](https://www.fostercommerce.com/craft-cms-plugins/best-sellers/docs/dev-guide/templating), best-seller listings and buy-again pages in Twig
- [Console commands](https://www.fostercommerce.com/craft-cms-plugins/best-sellers/docs/reference/console-commands), every backfill command and its options

## License

Proprietary.

---

<a href="https://www.fostercommerce.com" target="_blank"><img src="./resources/img/foster-commerce.svg" alt="Foster Commerce" width="160" height="40"></a>
