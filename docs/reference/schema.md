# Schema

The plugin installs three tables. The columns are defined in `src/migrations/Install.php`.

The tables are not part of project config. Uninstalling drops all three; Commerce's own data is untouched.

## best_sellers_variant_sales

One row per variant per completed order. A bundle line item produces one row per child variant rather than one for the bundle. Written when an order is saved, and by the backfill and the unit cost fill.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | integer | Primary key. |
| `productId` | integer | The product at the time of sale. No foreign key, so the row survives the product being deleted. Null when the purchasable was already gone. |
| `productTitle` | string | Frozen at the time of sale, so renaming a product does not rewrite history. |
| `productTypeId` | integer | Denormalized from the product. Reports show "Unknown" when the type no longer exists. Indexed. |
| `variantId` | integer | The variant at the time of sale. No foreign key, for the same reason as `productId`. |
| `variantTitle` | string | Frozen at the time of sale. |
| `variantSku` | string | Frozen at the time of sale. |
| `qty` | integer | Units on the line. For a bundle child, the child quantity times the bundle line quantity. |
| `lineItemPrice` | decimal(14,4) | Per-unit price paid. For a bundle child, its allocated share divided by quantity. |
| `lineItemTotal` | decimal(14,4) | The line subtotal: quantity times sale price. Sale-price promotions are priced in. Summed as Item Subtotal in the reports. |
| `catalogPrice` | decimal(14,4) | The catalog price before promotions, frozen at the time of sale. Averaged as Avg Price in the reports. |
| `unitCost` | decimal(14,4) | The variant's unit cost recorded on the line item, per unit. Null when the line has no recorded cost. See [unit costs and profit](../user-guide/unit-costs-and-profit.md). |
| `discount` | decimal(14,4) | The sale-price promotion amount on the line, stored positive. Default: `0`. |
| `lineDiscount` | decimal(14,4) | Discount adjustments attributed to the line: coupons, manual discounts, and order-level discounts Commerce attached here. Negative, so `lineItemTotal + lineDiscount` is Item Sales (Net). Default: `0`. |
| `sourceBundleId` | integer | The bundle this row was expanded from, or null. Drives the bundle marker on product rows. Indexed. |
| `sourceBundleTitle` | string | The bundle's title at the time of sale. |
| `orderId` | integer | Foreign key to `commerce_orders.id`, `ON DELETE CASCADE`. Indexed. |
| `dateOrdered` | datetime | The order's date. Every report's date filter runs against this column. Indexed. |
| `dateCreated` | datetime | When the row was written. On a backfilled row, this is when the backfill ran, not when the order was placed. |

Composite indexes on `(productId, dateCreated)` and `(variantId, dateCreated)`.

A row rebuilt from an order's snapshot can hold a `productId` or `variantId` that no longer exists.

## best_sellers_daily_stats

One row per calendar day, in the site's timezone. Rewritten for the order's day when an order is saved, and by the daily stats commands. Covers every completed order, with no order status or shipping locations filter applied.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | integer | Primary key. |
| `date` | date | Unique index. The calendar day in the site's timezone. |
| `totalOrders` | integer | Completed orders that day. Default: `0`. |
| `totalRevenue` | decimal(14,4) | Sum of order `totalPrice`. Default: `0`. |
| `totalDiscount` | decimal(14,4) | Sum of order `totalDiscount`. Negative in Commerce; the dashboard shows it positive. Default: `0`. |
| `totalShipping` | decimal(14,4) | Sum of order `totalShippingCost`. Default: `0`. |
| `totalTax` | decimal(14,4) | Sum of order `totalTax`. Default: `0`. |
| `totalItemsSold` | integer | Sum of line item quantities. Default: `0`. |
| `uniqueCustomers` | integer | Distinct order emails. Default: `0`. |
| `newCustomers` | integer | Customers whose first ever completed order was that day. Default: `0`. |
| `returningCustomers` | integer | `uniqueCustomers` minus `newCustomers`. Default: `0`. |
| `averageOrderValue` | decimal(14,4) | `totalRevenue` divided by `totalOrders`. Default: `0`. |
| `averageItemsPerOrder` | decimal(8,2) | `totalItemsSold` divided by `totalOrders`. Default: `0`. |

## best_sellers_backfill_logs

Failures recorded during a backfill or a daily stats rebuild, so one bad order does not fail the job. Not written by a unit cost fill. Listed on the [Operations](../user-guide/operations.md) page. Craft's garbage collection prunes the table to the most recent 500 rows.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | integer | Primary key. |
| `level` | string | Default: `error`. Indexed. |
| `type` | string | What was running: order backfill or daily stats. Indexed. |
| `reference` | string | The order or date the failure relates to. |
| `message` | text | The failure message. |
| `dateCreated` | datetime | |
| `dateUpdated` | datetime | |
| `uid` | uid | |
