# Operations

**Best Sellers -> Operations.** Store configuration, email notifications, coupon usage, and the plugin's own backfill logs.

The date range does not apply to this page. Each section shows all-time figures or the store's configuration.

## Commerce settings

Links to Commerce's General Settings and Store Settings.

## Email notifications

**Order Status Emails** lists every order status and the emails Commerce sends when an order enters that status, with each email's recipient type and whether it is enabled. A status with no emails is still listed.

**All Configured Emails** lists every email in the store with its name, subject, recipient, template path, and enabled state, whether or not a status triggers it.

## Coupon usage

**Coupon Usage (All Time)** ranks every coupon code that has been used on a completed order, with the number of uses and the total discount given.

## Backfill logs

Failures recorded while backfilling orders or rebuilding daily stats are listed here with their level, type, the order reference they relate to, and the message. Each entry links to the order where there is one.

**Clear All** empties the table. It needs the `best-sellers:backfill` permission. Craft's garbage collection also prunes the table down to the most recent 500 entries.

An empty list means no failures have been recorded. A unit cost fill logs to Craft's log instead. To act on an entry, see [data and backfill](./data-and-backfill.md#when-a-backfill-fails-on-an-order).
