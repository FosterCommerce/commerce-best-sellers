# Customers

**Best Sellers -> Customers.** Who bought in the date range, how often, and how much.

Pagination, totals, and CSV export behave as described in [filters and report pages](./filters-and-report-pages.md).

## Columns

Email, Status, # Orders, Total Spent, AOV, and Last Purchase. Total Spent sums order `totalPrice`, including tax and shipping, net of discounts; AOV divides it by # Orders. The email links to the user's profile where there is one.

## Credentialed and guest

A customer is **credentialed** when the order is attached to an active Craft user account, and a **guest** otherwise. Commerce creates an inactive user record behind a guest checkout, so "guest" means the shopper never activated an account, not that no record exists.

Each row is one email and customer record pair. Two customer records with the same email show as two rows.

## Filters

Beyond the three global controls, this page adds a **Customer Type** filter (Credentialed, Guest) and a search box matching the email address.

## What the figures include

Only completed, non-deleted orders are counted.

Total Spent is not net of refunds. A refunded order still contributes its full `totalPrice`.

Every figure is scoped to the date range, including the Avg Customer LTV card and the Credentialed vs. Guest widget on the [dashboard](./dashboard.md). To read those as lifetime figures, set the range to All Time.
