# Customers

**Best Sellers -> Customers.** Who bought in the date range, how often, and how much. Audience: store admins and CS leads.

Pagination, totals, and CSV export behave as described in [filters and report pages](./filters-and-report-pages.md).

## Columns

Email, Status, # Orders, Total Spent, AOV, and Last Purchase. Total Spent sums order `totalPrice`, after tax, shipping, and discount; AOV divides it by # Orders. The email links to the user's profile where there is one.

## Credentialed and guest

A customer is **credentialed** when the order is attached to an active Craft user account, and a **guest** otherwise. Commerce creates an inactive user record behind a guest checkout, so "guest" means the shopper never activated an account, not that no record exists.

Rows group by email and customer record together, so one person's orders roll up into a single row.

## Filters

Beyond the three global controls, this page adds a **Customer Type** filter (Credentialed, Guest) and a search box matching the email address.

## What the figures include

Only completed, non-deleted orders are counted.

Total Spent is not net of refunds. A refunded order still contributes its full `totalPrice`.

Every figure is scoped to the date range, including the Avg Customer LTV card and the Credentialed vs Guest widget on the [dashboard](./dashboard.md). To read those as lifetime figures, set the range to All Time.
