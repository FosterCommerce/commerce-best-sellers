# Data and backfill

How sales data gets into the plugin, and how to rebuild it. Audience: store admins and developers maintaining a store.

## The two tables reports read from

The plugin keeps its own copies of order data rather than querying Commerce live on every page.

**Variant sales** holds one row per variant per completed order: quantity, line totals, the discount attributed to the line, and the product and variant titles and SKU as they were at the time of sale. The [Products](./products.md) and [Locations](./locations.md) reports, the dashboard's product widgets, and the Twig variables read this table.

**Daily stats** holds one row per calendar day: that day's orders, revenue, discounts, shipping, tax, items sold, customer counts, AOV, and average items per order. The dashboard's KPI cards, sparklines, and overview chart read this table.

The [Orders](./orders.md), [Transactions](./transactions.md), and [Customers](./customers.md) reports read Commerce's own tables and need neither. See the [schema reference](../reference/schema.md) for the columns.

## How data stays current

Both tables update whenever a completed order is saved: the initial completion, and every later save such as an admin editing line items, adding a manual discount, or changing the status. The order's rows are deleted and re-inserted, and its day is re-aggregated.

This runs on the request that saved the order, not through the queue.

Historical rows keep the product title and SKU frozen at the time of sale, so renaming a product does not rewrite history, and a product deleted after it sold keeps its sales.

## Backfilling

A fresh install has neither table populated, so every past period reports zero. Backfilling reads your existing completed orders and fills them in. Run it after installing, and after clearing either table.

Already-recorded orders are skipped, so running a backfill twice is safe. Daily stats rebuild from Commerce's orders rather than from the variant sales table, so the two are independent; running the order backfill first fills the product reports and the dashboard together.

The `refresh-` variants clear the table before rebuilding, leaving the reports empty until the queue finishes. See [console commands](../reference/console-commands.md) for every command, its options, and which ones queue.

The same operations are at **Utilities -> Best Sellers**, which needs the `best-sellers:backfill` permission and adds an optional date range on the order backfill.

A date range covers both dates in full, in the site's timezone. Give both dates or neither: with neither, the backfill covers every completed order.

## Fill unit costs

**Fill Unit Costs** at **Utilities -> Best Sellers** adds [unit costs](./unit-costs-and-profit.md) to orders placed before a product type had a unit cost field, from each variant's current unit cost. The section appears once at least one product type has a unit cost field.

- **Product Types** lists the product types with a unit cost field. All are ticked by default.
- **Start Date** and **End Date** limit the orders, as for the order backfill.

The fill only adds a cost to a line with no recorded cost. It does not change a recorded cost, and it does not save the order, so the order and its line items keep their dates and totals. The plugin then rebuilds the variant sales rows of each order that gained a cost.

A line can still end up without a cost: the variant's cost field is empty, the field's currency differs from the order's, or the variant or bundle has been deleted. Each of those lines is written to Craft's log as an error in the `best-sellers` category, with the order and line item. If the fill fails on an order, it logs the error there too, leaves that order unchanged, and moves on to the next.

The fill runs through the queue. From the console, run `best-sellers/backfill/fill-unit-costs`. See [console commands](../reference/console-commands.md).

## When a backfill hits a problem

An order that cannot be processed is written to the backfill log instead of failing the job. Entries are listed on the [Operations](./operations.md) page with the order reference and the message.

Fix the underlying problem, then re-run the backfill.

## Uninstalling

Uninstalling drops all three of the plugin's tables. Commerce's own order data is untouched, so reinstalling and backfilling reproduces the reports.
