# Locations

**Best Sellers -> Locations.** Where orders shipped to, from world level down to individual cities.

Every figure on this page uses the order's shipping address. Orders without one do not appear.

## The three views

The page starts on the **world** view: a map shaded by revenue, a **Top countries** table, and a **Top cities** table spanning every country.

The **country** view shows a map of one country's states or provinces where one is available, plus a breakdown per state.

The **region** view shows the cities within one state.

Every location table reports Orders, Revenue, Customers, and AOV. Revenue is order `totalPrice`, including tax and shipping, as on the dashboard's Revenue card.

The country and region views also show a **Top products** table, counting only orders shipped to that country or state. For products across every location, use the [Products](./products.md) report.

## City names

Postal codes are ignored, and case variants of the same city are merged into one row. A spelling that is not all capitals replaces one that is, so "Friendswood" shows rather than "FRIENDSWOOD". Between other variants, such as "sonoma" and "Sonoma", the row shows whichever one it found first. Where one customer's orders used more than one spelling, the city's customer count can include that customer more than once.

State and country names come from Craft's address repository, in the control panel language.

## Filters

The three global controls apply. See [filters and report pages](./filters-and-report-pages.md).

Drilling into the map is not the same as the shipping locations filter. The filter restricts every report page, while drilling in changes only this page.
