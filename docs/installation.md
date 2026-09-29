# Installation

Essential **sales insights** and top-performing product data for Craft Commerce stores.

For a guided first run, see [getting started](./getting-started.md).

## Requirements

- Craft CMS `^5.0.0`
- Craft Commerce `^5.0.0`
- PHP `>=8.2`

## Install

```sh
composer require fostercommerce/commerce-best-sellers
./craft plugin/install best-sellers
```

The plugin is also listed in the Craft Plugin Store as "Best Sellers".

Installing creates three tables and adds a **Best Sellers** item to the control panel nav, visible to users with the `best-sellers:viewReports` permission. See [permissions](./reference/permissions.md).

The Products report and the dashboard's KPI cards read zero for past periods until you backfill your existing orders. See [data and backfill](./user-guide/data-and-backfill.md).

## Configure

Settings are at **Best Sellers -> Settings**, not **Settings -> Plugins**, and need the `best-sellers:manageSettings` permission.

**General** tab:

- **Default order statuses**: statuses pre-selected in the global order status filter on a user's first visit in a session. Default: None, which means all statuses. See [order statuses](./user-guide/filters-and-report-pages.md#order-statuses).
- **Orders report fields**: the order fields shown as columns and filters on the Orders report. Lists only fields of the [supported types](./user-guide/filters-and-report-pages.md#custom-field-filters), and does not appear when the order field layout has no field of those types. Default: None. See [orders](./user-guide/orders.md#order-fields).
- **Shipped status**: the order status that means an order has shipped. Adds a Date Shipped column to the Orders report. Default: None. See [orders](./user-guide/orders.md#date-shipped).

**Product Types** tab, one section per product type:

- **Unit cost field**: the Money field on the product type's variants that holds each variant's unit cost. Default: None. See [unit costs and profit](./user-guide/unit-costs-and-profit.md).
- **Products report filters**: the variant fields offered as filters on the Products report for this product type. Default: None. See [products](./user-guide/products.md#field-filters).

Craft stores the settings in project config.

## Queue

Backfills and full daily stats rebuilds run as queue jobs. They finish only while a queue worker runs:

```sh
./craft queue/listen
```

Saving an order updates that order's day on the same request, so day-to-day tracking does not depend on the queue. Backfill and daily stats rebuild failures go to the backfill log, listed on the [Operations](./user-guide/operations.md) page.
