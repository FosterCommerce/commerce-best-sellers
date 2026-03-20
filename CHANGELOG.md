# Release Notes for Best Sellers

## 1.1.0 - 2026-03-23

### Added
- Full reporting UI with Overview, Orders, Products, Customers, and Operations pages
- KPI cards with sparklines and period-over-period comparison
- Revenue, orders, and AOV charts with previous period overlay
- New vs returning customer tracking (by email, across all time)
- Credentialed vs guest customer comparison with LTV metrics
- Products and variants report with drill-down to per-product order history
- Operations report with items-per-order distribution, shipping methods, coupon usage, and discount trends
- Cart abandonment widget with age breakdown and anonymous cart tracking
- CSV export on all data tables (orders, products, customers)
- Search, sorting, filtering, and pagination across all reports
- Global date range picker with presets and session persistence
- `productTotalRevenue()` and `variantTotalRevenue()` methods
- Daily stats aggregation table for fast reporting queries
- `backfill/daily-stats` console command to rebuild aggregated stats
- `RegisterReportPagesEvent`, `RegisterKpiCardsEvent`, and `ModifyReportDataEvent` for extensibility
- Revenue columns (`lineItemPrice`, `lineItemTotal`, `discount`) on variant sales records
- Developer documentation at `docs/usage.md`

### Changed
- Customer tracking now uses email instead of customerId for more accurate counts across guest and credentialed orders
- Rebuilt the control panel navigation with subnav (Overview, Orders, Products, Customers, Operations)

## 1.0.2

- Fix best sellers query to use dateOrdered


## 1.0.1

- Fix db query error


## 1.0.0

- Initial release
