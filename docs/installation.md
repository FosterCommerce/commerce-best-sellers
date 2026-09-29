# Installation

Sales reporting for Craft Commerce.

Requirements, install, and plugin settings. For a guided first run, see [getting started](./getting-started.md).

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

A fresh install reports zero for every past period until you backfill your existing orders. See [data and backfill](./user-guide/data-and-backfill.md).

## Configure

**Best Sellers -> Settings**, a page inside the plugin's own section rather than an entry under **Settings -> Plugins**. Gated by `best-sellers:manageSettings`.

**General** tab:

- **Default order statuses**: statuses pre-selected in the global order status filter on a user's first visit in a session. No default, which resolves to all statuses. A user's own selection is stored in their session and takes precedence until it ends.

**Product Types** tab, one section per product type:

- **Unit cost field**: the Money field on the product type's variants that holds each variant's unit cost. Default: None, which does not record costs. See [unit costs and profit](./user-guide/unit-costs-and-profit.md).
- **Products report filters**: the variant fields offered as filters on the Products report for this product type. None by default. See [products](./user-guide/products.md#field-filters).

There is no config file. The settings are stored in project config through `savePluginSettings()`.

## Queue

Backfills and full daily stats rebuilds run as queue jobs, so a worker has to be running for them to finish:

```sh
./craft queue/listen
```

Order saves aggregate that order's day inline, so day-to-day tracking does not depend on the queue. Failures during an order backfill or a daily stats rebuild are written to a log table and listed on the [Operations](./user-guide/operations.md) page. A unit cost fill writes its failures to Craft's log instead, in the `best-sellers` category.
