# Dashboard

**Best Sellers -> Dashboard.** The state of the store over a date range.

A top row of sales KPI cards and a chart, then four sections of KPI cards and widgets: Discounts & Order Composition, Customers & Retention, Product Performance, and Carts.

If the plugin has not recorded any orders, the page shows a notice linking to the backfill utility instead. See [data and backfill](./data-and-backfill.md).

## KPI cards

Each card shows a value for the range and a percentage change against the previous period of the same length. The change is blank when the previous period was zero. The top row, Discounts & Order Composition, and Customers & Retention cards also show a sparkline of daily values.

Once a product type has a unit cost field, the Product Performance section adds **Gross Profit** and **Gross Margin** cards. See [unit costs and profit](./unit-costs-and-profit.md#dashboard-cards).

The order status and shipping locations filters do not apply to most cards. See [where the filters do not apply](./filters-and-report-pages.md#where-the-filters-do-not-apply).

Two card definitions are not what their label suggests:

- **Repeat Rate** is the share of the range's customers who had ordered before, not a rate of repeat purchase within the range.
- **Product Revenue** is item sales net of Discount adjustments, excluding tax, shipping, and orders with a full balance owed. The top row's **Revenue** card is order `totalPrice`, which includes tax and shipping and counts orders with a balance owed. See [products](./products.md#how-the-numbers-are-calculated).

## Written summaries

The top row and each section have a sentence or two describing what changed. Each compares against the previous period, the same period one year earlier, and a trailing 12-month average of the 365 days before the range. When the store has no data from a year earlier, the summary leaves out the year-ago baseline and says so.

Summaries also warn when the comparison is weak: the range is unfinished, short enough for one order to move it, long enough to hide short-term change, or the trailing average is built from too few comparable periods.

## Widgets

Discounts & Order Composition: **Discounted vs. Full-Price Orders**, **Most Used Discounts**, **Items Per Order**, and **Shipping Methods**. The last three link through to the [Orders](./orders.md) report, pre-filtered. An order counts as discounted when it has a Discount adjustment; a sale-price promotion does not make it one.

Customers & Retention: **Top Customers by Revenue**, **Credentialed vs. Guest**, and a **New vs. Returning Customers** daily chart. See [customers](./customers.md#credentialed-and-guest) for how each group is defined.

Product Performance: **Best Sellers**, the top ten products by units sold.

## Carts

**Cart Abandonment** reports the rate, the value of abandoned carts, and a breakdown by age, with a tab per band (4-24 hours, 1-7 days, 7+ days).

An abandoned cart is an incomplete order that has at least one line item and has not been updated for 4 hours. The rate is abandoned carts divided by abandoned plus completed orders in the range.

By default the widget counts only carts with a customer or an email address. **Include anonymous carts** counts every cart, in the rate, the value, and the age bars.

**Highest-Value Abandoned Carts** lists the abandoned carts with the highest value, with a **Share** action that copies a restore link. See [cart restore](./cart-restore.md).
