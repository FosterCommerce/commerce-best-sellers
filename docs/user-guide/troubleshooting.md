# Troubleshooting

When a number looks wrong. Audience: anyone who has a report in front of them that disagrees with something else.

## Every report shows zero

The plugin's tables have not been filled in. A fresh install only records orders placed from the moment it was installed. Run the [backfill](./data-and-backfill.md), and make sure a queue worker is running.

## The dashboard KPIs don't change when I change the order status filter

Most of the dashboard's cards read a pre-aggregated table covering every completed order, and the order status and shipping locations filters do not reach it. The rest of the page, and every other report, does apply them. For which is which, see [what the filters do not reach](./filters-and-report-pages.md#what-the-filters-do-not-reach).

## The dashboard and the Products report disagree on revenue

They measure different things. The dashboard's Revenue card is order `totalPrice`: merchandise plus tax plus shipping, minus discounts. Product Revenue and the Products report are item sales net of line-level discounts, with no tax or shipping, and they exclude orders with a full balance owed.

An order authorized but never captured, fully refunded, or whose payment failed after completing counts toward Revenue and not toward the Products report. See [products](./products.md#which-orders-count).

## The Products and Orders reports disagree on Item Subtotal

They should not, over the same orders, since both sum the same underlying line item values. Check that both pages have the same date range and order status, and that the Orders page's payment status filter is not narrowing the set. The Products report always drops orders with a full balance owed, whatever that filter says.

## An order is missing

Check, in order:

- The order is completed, and has not been soft-deleted.
- Its status is in the current order status filter.
- Its shipping address matches the shipping locations filter, if one is set.
- On the Orders page, its paid status is in the payment status selection. Fully refunded orders are Unpaid, which the default selection excludes.
- On the Products page, it does not have a full balance owed.
- The [Operations](./operations.md) page has no backfill log entry for it.

## A day looks wrong on the chart

Rebuild that day, or the whole range:

```sh
./craft best-sellers/backfill/daily-stats --date=2026-03-15
./craft best-sellers/backfill/refresh-daily-stats
```

## The date range is off by a day

Dates are bucketed in the site's timezone, shown next to the date picker. An order at 11pm on the 30th in a UTC-5 site counts toward the 30th, and toward the 31st in UTC. If a range's totals changed after an upgrade, rebuild the daily stats.

## The Products or Customers report stops at 10,000 rows

Both load at most 10,000 rows before filtering, searching, and paginating in memory, so a larger result set loses its tail. Narrow the date range, or filter by product type.

## The Transactions totals don't match the gateway

- Only transactions in the store's primary currency are included, in both the table and the totals.
- The header date picker bounds the transaction date, not the order date. A refund lands on the day it was processed.
- Enabling the **Authorize** type makes Captured double-count, since each authorization has a matching capture. See [transactions](./transactions.md#type-and-status-filters).

## A cart restore link does not work

See [cart restore](./cart-restore.md#when-a-link-stops-working). Usually a completed cart, a purged cart, or a registered customer's cart opened by someone not signed in as them.
