# Filters and report pages

The controls on every report page, and the features the report tables share.

## Global controls

A date range, an order status filter, and a shipping locations filter appear on every report page except Operations. Set one on the Orders page and it is still set when you open Products.

The plugin stores your selections in your session. They stay set across reloads and report pages, apply only to you, and clear when your session ends.

### Date range

| Preset | Covers |
| --- | --- |
| Today | Today only. |
| This Week | Monday to Sunday of the current week. |
| This Month | The 1st to the last day of the current month. |
| This Year | January 1 to December 31 of the current year. |
| Past 7 Days | The 7 days ending yesterday. |
| Past 30 Days | The 30 days ending yesterday. Default. |
| Past 90 Days | The 90 days ending yesterday. |
| Past Year | The year ending yesterday. |
| All Time | January 1, 2000 to today. |
| Custom | The two dates you pick, inclusive of both. |

The "Past" presets end yesterday and exclude today. The "This" presets run to the end of the calendar period, so they include today and the days after it.

Dates bucket in the site's timezone, shown beside the date range. An order placed at 11pm on the 30th in a UTC-5 site counts toward the 30th, not the 31st.

Percentage changes compare against the period of the same length immediately before the current one. Where the range extends past today, only the elapsed part is compared: This Month on the 10th is measured against the first 10 days of last month. The dashboard's written summaries add a year-ago and a trailing 12-month baseline. See [dashboard](./dashboard.md).

### Order statuses

Reports show only orders in the ticked statuses, or every status when none is ticked. Your first visit in a session starts from the **Default order statuses** setting; after that, the filter keeps your last selection. See [installation](../installation.md#configure).

The filter matches an order's present status, not its status when placed. An order that shipped last month and was refunded since reports under the refunded status.

### Shipping locations

The filter lists countries, states or provinces, and cities with completed orders.

A selection matches at the level you picked: `United States` matches every US order, `Texas, United States` only Texas, `Houston, Texas, United States` only Houston. Several selections match any of them.

Matching is on the order's shipping address.

### Where the filters do not apply

The dashboard's KPI cards, sparklines, and overview chart read the daily stats, which cover every completed order, so the order status and shipping locations filters do not apply to them. The dashboard's Avg Customer LTV, Unique Products Sold, Product Revenue, Gross Profit, and Gross Margin cards do apply both, as does every other report page, widget, and table, except Operations and the abandoned cart figures.

Abandoned cart figures ignore both filters too: an incomplete cart has no order status, and often no shipping address.

## Shared table features

The Orders, Transactions, Products, and Customers tables work the same way:

- Pages hold 100 rows.
- The totals row spans every row matching the filters, not the page on screen.
- **Export CSV** downloads the same full filtered set, with a totals row appended, named `<site-handle>-<report>-<date>.csv`. Currency columns export as plain decimals with no symbol.
- Column headers sort.

## Custom field filters

The Products and Orders reports can filter by your own custom fields, chosen at **Best Sellers -> Settings**. Both support these field types:

- Relation fields, such as Entries, Categories, or a custom element field
- Dropdown
- Radio Buttons
- Checkboxes
- Multi-select
- Lightswitch

Each filter is labeled with the field's name and matches each element's current value. Ticking several values in one filter matches any of them, and several filters apply together. A Lightswitch filter offers the field's on and off labels, or Enabled and Disabled when the field has none, and ticking both is the same as ticking neither.

For each report's own rules, see [products](./products.md#field-filters) and [orders](./orders.md#order-fields).

## Filter chips

A filter set by a link, such as a Products row linking to the orders containing it, shows as a chip ("Filtered to: …").
