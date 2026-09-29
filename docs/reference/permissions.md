# Permissions

Registered under the **Best Sellers** heading in a user group's permissions. None implies another.

| Handle | Description |
| --- | --- |
| `best-sellers:viewReports` | The Best Sellers nav item and every report page in it. Without it, the section is hidden and the pages are refused. |
| `best-sellers:backfill` | The **Utilities -> Best Sellers** page: backfill orders, fill unit costs, rebuild daily stats, clear either table. Independent of `viewReports`. |
| `best-sellers:manageSettings` | The plugin's Settings page, and its entry in the subnav. |

Bulk PDF download on the [Orders](../user-guide/orders.md#bulk-pdf-download) report additionally requires Commerce's `commerce-manageOrders`.

The console commands are not permission-gated.
