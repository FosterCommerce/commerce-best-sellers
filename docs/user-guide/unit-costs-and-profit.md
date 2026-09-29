# Unit costs and profit

How the plugin records what each item cost you, and where it reports cost, gross profit, and gross margin.

## Set a unit cost field

The plugin reads unit costs from a Money field on your variants. Add one to each product type's variant field layout, then choose it at **Best Sellers -> Settings -> Product Types**.

Each product type has its own **Unit cost field** setting, which lists the Money fields in that product type's variant layout. A product type left at None does not record costs.

The setting stores the field's position in the layout, not the field itself, so two product types can use the same Money field under different handles. If you remove the field from the layout and add it back, or change its type, choose it again. Until you do, the product type does not record costs.

## When a cost is recorded

The plugin copies the variant's unit cost onto the line item whenever Commerce fills in the line item from the variant: when it is added to a cart, and each time the cart recalculates. Once the order is complete, the cost stays fixed. Editing a variant's cost later does not change past orders.

Two actions on a completed order record the cost again:

- **Recalculate order** on the order's edit page records each line's current cost, the same way it refreshes the price.
- A line item added to a completed order records the cost at the time it is added.

The plugin records a cost only in the order's currency. A line has no cost when:

- the variant's cost field is empty
- the field's currency differs from the order's
- the product type has no unit cost field

A cost of zero is recorded as zero, not as missing. Rebuilding the sales data keeps recorded costs, because each cost is stored on its line item.

Bundles from the [webdna Commerce Bundles](https://plugins.craftcms.com/commerce-bundles) plugin record each child variant's own cost.

## Orders from before the setting

Orders placed before you chose a unit cost field have no costs. To add them, run **Fill Unit Costs**. See [data and backfill](./data-and-backfill.md#fill-unit-costs).

## The Profit view

On **Best Sellers -> Products**, the **All sales | Profit** switch appears once at least one product type has a unit cost field. **All sales** is the default.

**Profit** keeps only the line items with a recorded unit cost, including for Units Sold and Item Sales (Net), and adds three columns, which also appear in the totals bar and the CSV export:

| Column | Definition |
| --- | --- |
| Cost | Quantity times unit cost. |
| Gross Profit | Item Sales (Net) minus Cost. |
| Gross Margin | Gross Profit divided by Item Sales (Net). Can be negative. Shows `-` when Item Sales (Net) is zero. |

The CSV gives Gross Margin as a percentage, in a Gross Margin (%) column.

A variant with no cost on any of its line items does not appear.

To see what one supplier's items sold and cost over a period, combine the Profit view with a [field filter](./products.md#field-filters) on your supplier field. The Cost total is then that supplier's cost for the range.

Item Sales (Net) is calculated as described in [products](./products.md#how-the-numbers-are-calculated). Partial refunds are not netted out, so a partly refunded line reports a higher margin than the money kept.

## Dashboard cards

Once a product type has a unit cost field, **Gross Profit** and **Gross Margin** cards appear in the dashboard's Product Performance section. They use the same line items and definitions as the Profit view. With no product type, field, or search filter on the Products report, the cards match the Profit view's totals for the same date range.

Gross Margin shows its change against the previous period in percentage points. A change from 44% to 45.3% shows as 1.3 pts. Each card's label links to the Profit view for the same date range, with the product type and field filters cleared.
