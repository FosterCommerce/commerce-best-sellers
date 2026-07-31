# Transactions

**Best Sellers -> Transactions.** Gateway transactions in the date range, with captured, refunded, and net totals. Audience: anyone reconciling the store against a payment gateway.

Where the [Orders](./orders.md) report answers "what was sold", this page answers "what money moved, and when". Pagination, totals, and CSV export behave as described in [filters and report pages](./filters-and-report-pages.md).

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

**Captured**, **Refunded**, and **Net** totals sit above the table. Net is Captured minus Refunded.

## The two date filters

The header date picker bounds the *transaction* date. A refund appears on the day it was processed, not the day the original order was placed.

The **Order date** filter on the page is a second, independent range on the order's date. Setting both answers questions like "refunds processed in May, for orders placed in March". Leave it empty to filter on transaction date alone.

## Type and status filters

A fresh browser tab starts with **Type** on Purchase, Capture, and Refund, and **Status** on Success: the rows where money moved. Your changes are kept for the tab.

Enabling **Authorize** changes what the totals mean. An authorization is not money movement on its own, and its matching capture is already counted, so those payments are counted twice in Captured.

## Other filters

The global order status and shipping location filters apply here through the order each transaction belongs to.

**Search** matches the transaction reference, the transaction code, the order reference, or the order email. **Gateway** filters to one or more gateways.

## Limits

Only transactions in the store's primary currency are included. On a multi-currency store the others are absent from both the table and the totals.

Transactions belonging to soft-deleted orders are excluded.
