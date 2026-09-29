# Troubleshooting

When a report number looks wrong, or disagrees with another report.

## The Products report and dashboard cards show zero

The plugin has not recorded your existing orders. A fresh install only records orders placed after it was installed. Run the [backfill](./data-and-backfill.md#backfilling), and make sure a queue worker is running.

## The dashboard KPI cards do not change with the order status filter

Most of the dashboard's cards read the daily stats, which cover every completed order, and the order status and shipping locations filters do not apply to them. The rest of the page, and every other report, does apply them. For which is which, see [where the filters do not apply](./filters-and-report-pages.md#where-the-filters-do-not-apply).

## The dashboard and the Products report disagree on revenue

The dashboard's Revenue card and Product Revenue measure different amounts. See [KPI cards](./dashboard.md#kpi-cards) and [which orders count](./products.md#which-orders-count).

## The Products and Orders reports disagree on Item Subtotal

They match over the same orders. Check that both pages have the same date range and order status, and that the Orders page's payment status filter is not narrowing the set. The Products report always drops orders with a full balance owed, regardless of that filter.

## An order is missing

Check, in order:

- The order is completed, and has not been soft-deleted.
- Its status is in the current order status filter.
- Its shipping address matches the shipping locations filter, if one is set.
- On the Orders page, its payment status is in the payment status selection. Fully refunded orders are Unpaid, which the default selection excludes.
- On the Orders page, it matches every order field filter that is set.
- On the Products page, it does not have a full balance owed.
- The [Operations](./operations.md) page has no backfill log entry for it.

## A product is missing from the Profit view

The Profit view counts only line items with a recorded unit cost. A line has no recorded cost when its order predates the unit cost field, the variant's cost field was empty, or the field's currency differs from the order's. For older orders, run [Fill Unit Costs](./data-and-backfill.md#fill-unit-costs). For lines it could not fill, see [fill unit costs](./data-and-backfill.md#fill-unit-costs).

## The Products report field filters do not appear

Field filters show when the store has one product type, or when exactly one is selected in **Product Type**. See [field filters](./products.md#field-filters).

## A day looks wrong on the chart

Rebuild that day, or the whole range:

```sh
./craft best-sellers/backfill/daily-stats --date=2026-03-15
./craft best-sellers/backfill/refresh-daily-stats
```

## The date range is off by a day

Dates bucket in the site's timezone. See [date range](./filters-and-report-pages.md#date-range). If a range's totals changed after an upgrade, rebuild the daily stats.

## The Products or Customers report stops at 10,000 rows

Both load at most 10,000 rows, then search, sort, and paginate them in memory. On a larger result set, rows past the first 10,000 are missing. Narrow the date range, or filter by product type.

## The Transactions totals do not match the gateway

- Only transactions in the store's primary currency are included, in both the table and the totals.
- The date range filters on the transaction date, not the order date. A refund counts on the day it was processed.
- Ticking the **Authorize** type makes Captured double-count, because each authorization has a matching capture. See [transactions](./transactions.md#type-and-status-filters).

## A cart restore link does not work

See [cart restore](./cart-restore.md#when-a-link-stops-working). The common causes are a completed cart, a purged cart, a registered customer's cart opened while logged out, or a customer's cart opened by a different logged-in user.
