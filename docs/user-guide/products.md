# Products

**Best Sellers -> Products.** What sold in the date range, by product or by variant. Audience: merchandisers and store admins.

Pagination, totals, and CSV export behave as described in [filters and report pages](./filters-and-report-pages.md).

## Products or variants

The **Show products or variants** toggle switches the table between one row per product, with every variant's units combined, and one row per variant.

## Columns

Product, SKU, Type, Units Sold, Item Subtotal, Item Sales (Net), and Avg Price. The product title links to its edit page, and Units Sold carries an "in N orders" count linking to those orders. Type reads "Unknown" when the product type has since been deleted.

In the **Profit** view, Cost, Gross Profit, and Gross Margin columns follow Avg Price, and the table holds only line items with a recorded unit cost. See [unit costs and profit](./unit-costs-and-profit.md#the-profit-view).

Two markers can appear on a row: a bundle marker, meaning some units were sold as part of a bundle, and a warning marker on the units count, meaning at least one contributing order is only partially paid.

## Filters

Beyond the three global controls, this page adds a search box matching product title, variant title, SKU, and product type. A store with more than one product type also gets a **Product Type** multi-select.

### Field filters

Each product type can offer filters on its own variant fields, chosen at **Best Sellers -> Settings -> Product Types** under **Products report filters**. They support these field types:

- Relation fields, such as Entries, Categories, or a custom element field
- Dropdown
- Radio Buttons
- Checkboxes
- Multi-select
- Lightswitch

A field filter appears when the store has only one product type, or when exactly one product type is selected in **Product Type**. Field filters clear when they are hidden, including when you select a second product type. Several field filters apply together: a variant has to match each one.

A field filter matches on each variant's current value, not its value when the order was placed. If a variant moves to another value, its past sales move with it.

Ticking several values in one filter matches any of them. Sales of a deleted variant do not match a field filter.

A relation filter lists the elements that the product type's variants relate to through that field. A Lightswitch filter offers the field's on and off labels, or Enabled and Disabled when the field has none, and ticking both matches every variant.

## Drilling into orders

The "in N orders" count opens the [Orders](./orders.md) report filtered to the orders containing that product or variant, including those where it arrived inside a bundle.

The product title opens a per-product order list: each order's reference, date, email, the quantity of this item, its line revenue, and the order total.

## How the numbers are calculated

**Item Subtotal** is quantity times sale price across the contributing line items. Sale-price promotions are baked into that price. Coupon and manual discounts are not subtracted, so this column reconciles with the Orders report's Item Subtotal over the same orders.

**Item Sales (Net)** subtracts the Discount adjustments attributed to those lines. Order-level discounts are included, because Commerce attaches Discount adjustments to specific line items even when the rule is configured order-wide.

**Avg Price** averages the line item's catalog price, before promotions, so it does not divide cleanly into either revenue column.

Tax and shipping are in neither column.

### Which orders count

Orders with a full balance owed, meaning `totalPaid` is zero or less while `totalPrice` is above zero, are excluded from every figure on this page. That drops orders authorized but never captured, fully refunded, or whose payment failed after the order completed.

Partially paid orders are counted in full, and are what the warning marker flags. For money received, use the Orders report's Total Paid column, or [Transactions](./transactions.md).

Partial refunds are not netted out. A line refunded by half still reports its full original sale amount.

### Bundles

When the [webdna Commerce Bundles](https://plugins.craftcms.com/commerce-bundles) plugin is installed, a bundle line item is recorded as one row per constituent variant rather than as the bundle. The bundle's subtotal and discounts are distributed across those variants in proportion to their catalog prices, so the parts sum back to the bundle's total.

### Deleted products

A product deleted after it sold keeps its sales history. The row reports the title and SKU frozen on the order at the time of sale, and the title is not a link.

### Freshness

Sales figures re-sync whenever an order is saved: the initial completion, and any later admin edit, line item change, or status change. The partial-payment marker reads the order's current state, not its state at completion.
