# Release Notes for Best Sellers

## 1.1.2

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

## 1.0.2

- Fix best sellers query to use dateOrdered


## 1.0.1

- Fix db query error


## 1.0.0

- Initial release
