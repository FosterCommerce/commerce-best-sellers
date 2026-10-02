# Getting started

This page walks you from `composer require` to a dashboard showing your sales history.

## 1. Install

```sh
composer require fostercommerce/commerce-best-sellers
./craft plugin/install best-sellers
```

A **Best Sellers** item appears in the control panel nav. Open it. The dashboard shows a notice linking to the backfill utility, and the Products report shows zero.

The plugin records orders placed after it is installed, into its own sales data and daily stats. Your existing orders need a backfill. For which reports depend on it, see [data and backfill](./user-guide/data-and-backfill.md#the-two-tables-reports-read-from).

## 2. Backfill your order history

```sh
./craft best-sellers/backfill
./craft best-sellers/backfill/daily-stats
```

The first command queues your completed orders. The second builds the daily stats that the dashboard's KPI cards and charts read.

The first command prints the number of orders it queued, and the second the date range.

## 3. Run the queue

```sh
./craft queue/listen
```

Leave it running until the queue is empty. On a store with tens of thousands of orders, this step takes longest.

## 4. Read the dashboard

Reload **Best Sellers -> Dashboard**. The notice is gone and the KPI cards show numbers.

Set the date range to **All Time** to confirm the backfill included your oldest orders. If the earliest months show fewer orders than expected, see [troubleshooting](./user-guide/troubleshooting.md).

Change the range back to **Past 30 Days**. Every report page except Operations shares this control, along with an order status filter and a shipping locations filter, and your selection stays set from page to page. See [filters and report pages](./user-guide/filters-and-report-pages.md).

## 5. Set the defaults for your team

At **Best Sellers -> Settings**, set **Default order statuses** to the statuses reports start on for a user's first visit in a session.

To report gross profit, add a Money field to your variants, then choose it for each product type on the **Product Types** tab. See [unit costs and profit](./user-guide/unit-costs-and-profit.md).

Then grant your team the permissions under a user group's **Best Sellers** heading. See [permissions](./reference/permissions.md).

## Where to go next

- [Dashboard](./user-guide/dashboard.md), what each KPI card measures and which filters apply to it
- [Products](./user-guide/products.md), how the sales figures are calculated
- [Data and backfill](./user-guide/data-and-backfill.md), how the tables stay current and how to rebuild them
- [Templating](./dev-guide/templating.md), reading sales data from Twig
