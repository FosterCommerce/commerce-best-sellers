# Filters and report pages

The controls above every report, and the behaviour every report table shares. Audience: anyone reading a Best Sellers report.

## Global controls

A date range, an order status filter, and a shipping locations filter sit above every report. Set one on the Orders page and it is still set when you open Products.

Each writes to your session, so it survives a reload and follows you between pages, and it is yours alone. All three clear when your session ends.

### Date range

| Preset | Covers |
| --- | --- |
| Today | Today only. |
| This Week | Monday to Sunday of the current week. |
| This Month | The 1st to the last day of the current month. |
| This Year | 1 January to 31 December of the current year. |
| Past 7 Days | The 7 days ending yesterday. |
| Past 30 Days | The 30 days ending yesterday. Default. |
| Past 90 Days | The 90 days ending yesterday. |
| Past Year | The year ending yesterday. |
| All Time | 1 January 2000 to today. |
| Custom | The two dates you pick, inclusive of both. |

The "Past" presets end yesterday and exclude today. The "This" presets run to the end of the calendar period, so they include today and the days after it.

Dates bucket in the site's timezone, shown next to the picker. An order placed at 11pm on the 30th in a UTC-5 site counts toward the 30th, not the 31st.

Percentage changes compare against the period of the same length immediately before the current one. Where the range extends past today, only the elapsed part is compared: This Month on the 10th is measured against the first 10 days of last month. The dashboard's written summaries add a year-ago and a trailing 12-month baseline. See [dashboard](./dashboard.md).

### Order statuses

Ticking statuses restricts reports to orders currently in those statuses. Leave everything unticked for all statuses. Your first visit in a session starts from the **Default order statuses** setting; after that it is whatever you last chose. See [installation](../installation.md#configure).

The filter reads an order's status now, not the status it held when placed. An order that shipped last month and was refunded since reports under the refunded status.

### Shipping locations

Search for a country, state or province, or city, and tick as many as you want. The list only holds locations that have completed orders.

A selection matches at the level you picked: `United States` matches every US order, `Texas, United States` only Texas, `Houston, Texas, United States` only Houston. Several selections are an OR.

Matching is on the order's shipping address.

### What the filters do not reach

The dashboard's KPI cards, sparklines, and overview chart read a pre-aggregated daily table covering every completed order, so the order status and shipping locations filters do not apply to them. The dashboard's Avg Customer LTV, Unique Products Sold, and Product Revenue cards do apply both, as does every widget, table, and report page elsewhere.

Abandoned cart figures ignore both filters too: an incomplete cart has no order status, and often no shipping address.

## Shared table behaviour

Every report table works the same way:

- Pages hold 100 rows.
- The totals row spans every row matching the filters, not the page on screen.
- **Export CSV** downloads the same full filtered set, with a totals row appended, named `<site-handle>-<report>-<date>.csv`. Currency columns export as plain decimals with no symbol.
- Column headers sort.

## Filter chips

Some pages carry a chip ("Filtered to: …") for a filter that arrived from a link, such as a Products row linking to the orders containing it. Chips have their own **Clear filter** action, separate from the three global controls, which clear by unticking.
