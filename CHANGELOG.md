# Release Notes for Best Sellers

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
