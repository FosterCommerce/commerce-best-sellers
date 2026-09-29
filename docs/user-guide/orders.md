# Orders

**Best Sellers -> Orders.** Every completed order in the date range. Audience: store admins and CS leads.

Carts and soft-deleted orders never appear. Pagination, totals, and CSV export behave as described in [filters and report pages](./filters-and-report-pages.md).

## Columns

Order #, Date Ordered, Status, Item Subtotal, Tax, Discount, Shipping, Total Paid, Items Sold, and Payment, then Date Shipped and your order fields when they are set. Three are not self-evident:

- **Item Subtotal**: quantity times sale price across every line item. Sale-price promotions are priced in; coupon and manual discounts are not subtracted here.
- **Discount**: every Discount adjustment on the order, line-level and order-level. Does not include sale-price promotions.
- **Total Paid**: the live sum of successful gateway transactions minus refunds. The closest figure to what was settled.

Every column sorts. Sorting by Payment sorts by Total Paid. A relation field column sorts by the first related element's title. A Checkboxes or Multi-select column sorts by the stored option values, not their labels.

### Date Shipped

With a **Shipped status** set at **Best Sellers -> Settings -> General**, a Date Shipped column follows Payment. It shows the last time the order entered that status, from the order's status history. The cell is empty for an order that never entered it.

### Order fields

Each order field chosen under **Orders report fields** at **Best Sellers -> Settings -> General** adds a column and a filter. The columns come last, in the table and in the CSV. A column shows the order's current value. Several values in one cell are separated by commas.

Order fields support the field types listed under [custom field filters](./filters-and-report-pages.md#custom-field-filters). A relation filter lists the elements that completed orders relate to through that field.

## Filters

Beyond the three global controls, this page adds:

- **Payment Status**: Paid, Partial, Unpaid, Overpaid. Your first visit in a session starts on Paid, Partial, and Overpaid. Unticking everything shows all orders. The selection is kept in your session.
- **Shipping**: one shipping method, or **None** for orders with no method set.
- **Discount**: Discounted or Full Price, or one specific discount.
- One filter per field chosen under **Orders report fields**, labeled with the field's name. See [order fields](#order-fields).
- **Search**: matches the order reference, the order number, or the email.

Two more filters only arrive from links elsewhere, and show as a chip above the table:

- **Items per order**: the bucket clicked on the dashboard's Items Per Order histogram.
- **Product or variant**: the orders containing one purchasable, reached from a row on the [Products](./products.md) report. Orders with a full balance owed are excluded here, so the count matches the one on the Products row.

## Bulk PDF download

Tick orders on the current page, then use **Download PDF…** to generate the enabled Commerce PDFs for the selection. You choose the PDF and whether to get a ZIP of separate files or one collated PDF.

Selections apply to one page at a time and clear when you paginate.

The action needs Commerce's `commerce-manageOrders` permission on top of `best-sellers:viewReports`. Users without it do not see the control.

## Reconciling with Commerce

Item Subtotal here and on the [Products](./products.md) report sum the same underlying line item values, so they agree across the same set of orders.

Total Paid is the only column that reflects refunds. A fully refunded order still shows its original Item Subtotal, Discount, and Items Sold, with a Total Paid of zero. It is also Unpaid, so the default payment status selection keeps it out of the table and the totals.

For money movement over time rather than per order, use [Transactions](./transactions.md).
