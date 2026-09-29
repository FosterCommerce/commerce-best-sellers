# Permissions

Registered under the **Best Sellers** heading in a user group's permissions. No permission includes another.

| Handle | Description |
| --- | --- |
| `best-sellers:viewReports` | The Best Sellers nav item and every report page in it. Without it, the section is hidden and its pages deny access. |
| `best-sellers:backfill` | The **Utilities -> Best Sellers** page: backfill orders, fill unit costs, rebuild daily stats, clear either table. Also **Clear All** for the backfill logs on the Operations page. The page itself needs Craft's **Best Sellers** permission under the **Utilities** heading. Without `best-sellers:backfill`, it shows a notice instead of the actions. Independent of `viewReports`. |
| `best-sellers:manageSettings` | The plugin's Settings page, and its entry in the subnav. |

Bulk PDF download on the [Orders](../user-guide/orders.md#bulk-pdf-download) report also needs Commerce's `commerce-manageOrders`. Without it, the control does not appear.

The console commands do not check permissions.
