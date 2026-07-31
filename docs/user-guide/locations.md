# Locations

**Best Sellers -> Locations.** Where orders shipped to, from world level down to individual cities. Audience: store admins planning shipping, tax, or regional campaigns.

Everything on this page is based on the order's shipping address. Orders without one do not appear.

## The three views

The page starts on the **world** view: a map shaded by revenue, a **Top countries** table, and a **Top cities** table spanning every country.

Clicking a country opens the **country** view: a map of that country's states or provinces where one is available, plus a breakdown per state.

Clicking a state opens the **region** view: the cities within that state.

A breadcrumb and a **Zoom out** control take you back up. Countries with no regional map show the breakdown tables without a map.

Every table reports Orders, Revenue, Customers, and AOV. The world and country views also carry a **Top products** table for the current date range.

## City names

Postal codes are ignored, and case variants of the same city are merged into one row, preferring the properly-cased spelling. Where one customer's orders used more than one spelling, the customer count for that city can be slightly high.

State and country names are resolved through Craft's own address repository, so they follow the control panel language.

## Filters

The three global controls apply. See [filters and report pages](./filters-and-report-pages.md).

Applying the shipping locations filter and drilling into the map are different things: the filter restricts every report page in the plugin, while drilling in only changes what this page shows.
