# Release Notes for Best Sellers

## 1.4.2 - 2026-10-02

### Fixed
- Fixed a bug where filling unit costs logged an error for each line item whose variant has no unit cost.

## 1.4.1 - 2026-10-02

### Changed
- The Orders report's Date Ordered and Date Shipped columns no longer pad the month and day with a leading zero.

### Fixed
- Fixed a bug where a shopper logged in to another account couldn't continue from a cart restore link.
- Fixed a bug where a cart restore link opened on another site restored the cart on the wrong site.
- Fixed a bug where a shopper who logged in from a cart restore link wasn't returned to the cart.
- Fixed a bug where the cart restore page linked to the homepage when front-end login was disabled.

## 1.4.0 - 2026-09-29

> [!NOTE]
> Updating queues a rebuild of the sales data for every completed order, to record each line's catalog price. Keep a queue worker running until the rebuild finishes.

### Added
- Added a Transactions report.
- Added a Locations report.
- Added a shipping locations filter to the reports.
- Added bulk PDF download to the Orders report.
- Added a “Unit cost field” setting for each product type, for recording each line item's unit cost.
- Added a “Profit” view to the Products report, with Cost, Gross Profit, and Gross Margin columns.
- Added Gross Profit and Gross Margin cards to the dashboard.
- Added Products report filters on variant fields, chosen per product type.
- Added the ability to fill in unit costs on existing orders, and the `best-sellers/backfill/fill-unit-costs` command.
- Added the `catalogPrice` and `unitCost` columns to `best_sellers_variant_sales`.
- Added order field columns and filters to the Orders report, chosen in an “Orders report fields” setting.
- Added a Date Shipped column to the Orders report, from a “Shipped status” setting.

### Changed
- The Products report's Avg Price column now averages each unit's catalog price, before promotions.
- The Orders report's payment status filter now starts on Paid, Partial, and Overpaid.
- The date picker now shows the site's timezone.
- `bestSellers()` now sets `totalQtySold`, `totalItemSalesNet`, and `totalRevenue` to zero rather than `null` for elements with no sales in the range.
- Plugin settings are now split into “General” and “Product Types” tabs.
- The Product Type filter is now first on the Products report.
- The Orders report's Items Sold column now sorts.
- Improved the performance of the Orders report's CSV export for large date ranges.
- The Best Sellers utility's clear actions, and “Clear All” on the Operations page, now ask for confirmation.
- The Best Sellers utility's “Clear Order Data” button is now “Clear sales data”.
- Clearing the backfill logs on the Operations page now requires the “Backfill order data” permission (`best-sellers:backfill`).

### Fixed
- Fixed errors that occurred on PostgreSQL when viewing the Customers report or calling `bestSellers()`, `productTotalRevenue()`, or `variantTotalRevenue()`.
- Fixed a bug where a backfill date range skipped orders placed on its end date.
- Fixed a bug where a backfill date range could skip or include orders placed near midnight.
- Fixed a bug where the Products report showed “1 in 1 orders”.
- Fixed a bug where `craft.bestsellers` date ranges and the `bestSellers()` query method compared dates in UTC instead of the site's timezone.
- Fixed a bug where a `YYYY-MM-DD` end date passed to `craft.bestsellers` methods or `bestSellers()` left out orders placed on that date.
- Fixed a bug where `bestSellers()` ignored `DateTime` arguments.
- Fixed a bug where sorting `bestSellers()` results by sales on PostgreSQL listed elements with no sales first.
- Fixed a bug where abandoned cart “Share” links used a control panel URL instead of the cart's site.
- Fixed a bug where abandoned cart ages, and the four-hour abandonment cutoff, were off by the site's UTC offset.
- Fixed a bug where rebuilding daily stats could skip the store's first or last day of orders.
- Fixed a bug where the Best Sellers utility showed its actions to users without the backfill permission.

## 1.3.0 - 2026-05-20

### Added
- "Item Sales (Net)" column on the Products report. Value is Item Subtotal minus any coupon or manual discount attributed to the line item.
- lineDiscount column on best_sellers_variant_sales. Bundle children receive a proportional share.
- Default selection of Paid and Partial in the Orders page payment status filter.

### Changed
- Date filtering and Daily Stats aggregation now bucket dates in the Craft app timezone.
- Products report excludes orders with a full balance owed (totalPaid <= 0 AND totalPrice > 0).

### Fixed
- Divergence between Products and Orders reports caused by inconsistent timezone handling in raw queries versus element queries.
- Fully refunded orders no longer appear in the Orders report summary totals.
- Orders report summary total derives from the same element query filter set as the row data.

## 1.2.0 - 2026-05-06

### Added
- Settings page under the Best Sellers control panel nav for configuring plugin defaults
- Default order statuses setting that pre-selects the global order status filter on first visit per session
- `best-sellers:manageSettings` permission gating access to the settings page

### Fixed
- Report and backfill queries now exclude soft-deleted orders

## 1.1.4 - 2026-04-20

### Changed
- Use `moneyphp/money` for bundle allocation and stats averages to prevent float drift

## 1.1.3 - 2026-04-18

### Added
- Support for the webdna Commerce Bundles plugin: bundle line items are expanded into their child variants and revenue is allocated across them by price weight
- `fromBundle` marker on product and variant rows to indicate units that were sold as part of a bundle
- Snapshot fallback so line items whose purchasable has been deleted still contribute revenue and identifiers to reports
- `productTypeId` column on variant sales, denormalized from the product at the time of sale

### Changed
- Removed `productId`/`variantId` foreign keys on variant sales so rows survive purchasable deletion
- Product type joins now use the denormalized `productTypeId` and show "Unknown" when the product type is missing
- Backfill order queries no longer pre-fetch processed order IDs; duplicate processing is short-circuited inside the sales logger
- Customer report links now use `UrlHelper::cpUrl()` to produce control panel URLs

## 1.1.2 - 2026-03-23

### Changed
- Migration `safeDown()` methods follow Commerce convention (non-reversible)

## 1.1.1 - 2026-03-23

### Added
- Backfill logs table for tracking order processing failures during backfill and daily stats jobs
- Backfill logs displayed on the Operations page with clear button
- Dashboard empty state notice with link to backfill utility when no data exists
- Console commands for clearing and refreshing individual data tables: `clear-orders`, `clear-daily-stats`, `clear-logs`, `refresh-orders`, `refresh-daily-stats`
- Console `--start-date`/`--end-date` options for scoping backfill to a date range
- Console `--date` option for rebuilding daily stats for a single day
- Auto-pruning of backfill logs via Craft garbage collection (keeps 500 most recent)

### Changed
- Backfill utility UI now explains that empty date fields will process all orders
- All backfill utility strings are translatable
- Backfill and daily stats jobs now catch errors per-item and continue processing instead of failing the entire batch

## 1.1.0 - 2026-03-23

### Added
- All new dashboard organized the overview into focused sections: Overview, Discounts & Order Composition, Customers & Retention, Product Performance, and Carts.
- Ability to scope relevant dashboard data to specific order statuses
- Orders report page
- Products report page
- Customers report page
- Operations page
- Cart restore feature
- Front-end text string are translatable
- Postgres compatible

## 1.0.2 - 2025-03-29

- Fix best sellers query to use dateOrdered


## 1.0.1 - 2025-03-28

- Fix db query error


## 1.0.0 - 2025-03-28

- Initial release
