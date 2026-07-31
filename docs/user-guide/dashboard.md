# Dashboard

The landing page at **Best Sellers -> Dashboard**. Audience: anyone wanting the state of the store over a date range on one screen.

Five sections, each a row of KPI cards over one or more widgets: Overview, Discounts & Order Composition, Customers & Retention, Product Performance, and Carts.

If no order data has been recorded, the page shows a notice linking to the backfill utility instead. See [data and backfill](./data-and-backfill.md).

## KPI cards

Each card shows a value for the range, a percentage change against the previous period of the same length, and a sparkline of daily values. The change is blank when the previous period was zero.

Most cards do not respond to the order status or shipping locations filter. See [what the filters do not reach](./filters-and-report-pages.md#what-the-filters-do-not-reach).

Two card definitions are not what their label suggests:

- **Repeat Rate** is the share of the range's customers who had ordered before, not a rate of repeat purchase within the range.
- **Product Revenue** is item sales net of discounts, excluding tax, shipping, and orders with a full balance owed. The Overview **Revenue** card is order `totalPrice`, which includes tax and shipping and excludes nothing. They will not match. See [products](./products.md#how-the-numbers-are-calculated).

## Written summaries

Each section carries a sentence or two describing what changed, comparing against the previous period, the same period one year earlier, and a trailing 12-month average built from the 365 days before the range. The year-ago baseline is omitted, with a note, when the store has no data that far back.

Summaries also warn when the comparison is weak: the range is unfinished, short enough for one order to move it, long enough to hide short-term change, or the trailing average has too few comparable periods behind it.

## Widgets

Discounts and composition: **Discounted vs. Full-Price Orders**, **Most Used Discounts**, **Items Per Order**, and **Shipping Methods**. The last three link through to the [Orders](./orders.md) report, pre-filtered. An order counts as discounted when it carries a Discount adjustment; a sale-price promotion does not make it one.

Customers: **Top Customers by Revenue**, **Credentialed vs Guest**, and a **New vs Returning** daily chart. See [customers](./customers.md) for how the two groups are told apart.

Products: **Best Sellers**, the top ten products by item sales net of discounts.

## Carts

**Cart Abandonment** reports the rate, the value sitting in abandoned carts, and a breakdown by age, with a tab per band (4-24 hours, 1-7 days, 7+ days).

An abandoned cart is any incomplete order with at least one line item that has not been updated for 4 hours. The rate is abandoned carts divided by abandoned plus completed orders in the range. Analytics services that track intent differently will report a different rate.

By default the widget counts only carts carrying a customer or an email address. Tick **Include anonymous carts** to count every one. The rate, the value, and the age bars all follow the toggle.

**Highest-Value Abandoned Carts** lists the largest by value with a **Share** action that copies a restore link. See [cart restore](./cart-restore.md). The widget also reports the store's cart purge duration, after which a restore link stops working.
