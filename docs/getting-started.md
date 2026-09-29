# Getting started

From `composer require` to a dashboard showing your real sales history, in about fifteen minutes plus the time your queue takes to chew through existing orders.

By the end you will know why a fresh install reports zero, which two tables the plugin fills in, and where the global filters live.

## 1. Install

```sh
composer require fostercommerce/commerce-best-sellers
./craft plugin/install best-sellers
```

A **Best Sellers** item appears in the control panel nav. Open it. Every report reads zero, and the dashboard shows a notice linking to the backfill utility.

That is expected: the plugin records orders from the moment it is installed, and knows nothing about the ones already in your store.

## 2. Backfill your order history

```sh
./craft best-sellers/backfill
./craft best-sellers/backfill/daily-stats
```

The first queues your completed orders in batches of 25. The second builds the per-day table the dashboard charts read from.

Each command prints how much it queued. Neither has done any work yet.

## 3. Run the queue

```sh
./craft queue/listen
```

Leave it running until it goes quiet. On a store with tens of thousands of orders this is the slow part.

Craft also runs pending jobs from control panel requests, so the backfill progresses as you click around, more slowly.

## 4. Read the dashboard

Reload **Best Sellers -> Dashboard**. The notice is gone and the KPI cards carry numbers.

Set the date range at the top to **All Time** to confirm the backfill reached your oldest orders. If the earliest months look thin, see [troubleshooting](./user-guide/troubleshooting.md).

Change the range back to **Past 30 Days**. Every report page shares this control, along with an order status filter and a shipping locations filter, and your selection follows you from page to page. See [filters and report pages](./user-guide/filters-and-report-pages.md).

## 5. Set the defaults for your team

At **Best Sellers -> Settings**, pick the order statuses reports should start on for a user's first visit in a session. A store that treats only shipped orders as real revenue sets those here.

To report gross profit, add a Money field to your variants, then choose it for each product type on the **Product Types** tab. See [unit costs and profit](./user-guide/unit-costs-and-profit.md).

Then grant the permissions your team needs, under a user group's **Best Sellers** heading:

- `best-sellers:viewReports` for anyone who needs the reports.
- `best-sellers:backfill` for whoever maintains the data.
- `best-sellers:manageSettings` for whoever owns that Settings page.

## Where to go next

- [Dashboard](./user-guide/dashboard.md), what each KPI card measures and which filters reach it
- [Products](./user-guide/products.md), how the sales figures are calculated
- [Data and backfill](./user-guide/data-and-backfill.md), how the tables stay current and how to rebuild them
- [Templating](./dev-guide/templating.md), reading sales data from Twig
