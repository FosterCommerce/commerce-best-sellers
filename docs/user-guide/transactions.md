# Transactions

**Best Sellers -> Transactions.** Gateway transactions in the date range, with captured, refunded, and net totals.

Pagination, totals, and CSV export behave as described in [filters and report pages](./filters-and-report-pages.md).

## Columns

| Column | What it is |
| --- | --- |
| Date | When the transaction was recorded. |
| Type | `purchase`, `capture`, `authorize`, or `refund`. |
| Status | `success`, `pending`, `redirect`, `processing`, or `failed`. |
| Gateway | The gateway that handled it. |
| Amount | The transaction amount. Refunds are shown negative. |
| Order # | The order it belongs to, linking to Commerce. |
| Order Date | When that order was placed. |
| Email | The order email. |
| Reference | The gateway's own reference for the transaction. |

**Captured**, **Refunded**, and **Net** totals appear above the table. Net is Captured minus Refunded.

## The two date filters

The date range filters on the *transaction* date. A refund appears on the day it was processed, not the day the original order was placed.

The **Order date** filter on the page is a second, independent range on the order's date. Set both to find, for example, refunds processed in May for orders placed in March.

## Type and status filters

A fresh browser tab starts with **Type** on Purchase, Capture, and Refund, and **Status** on Success. Your changes are kept for the tab.

With **Authorize** ticked, Captured counts a payment twice: once for its authorization and once for its capture.

## Other filters

The global order status and shipping locations filters apply here through the order each transaction belongs to.

**Search** matches the transaction reference, the transaction code, the order reference, or the order email. **Gateway** filters to one or more gateways.

## Limits

Only transactions in the store's primary currency are included. On a multi-currency store, transactions in other currencies are absent from both the table and the totals.

Transactions belonging to soft-deleted orders are excluded.
