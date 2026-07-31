# Operations

**Best Sellers -> Operations.** Store configuration, email notifications, coupon usage, and the plugin's own backfill logs. Audience: store admins auditing how the store is set up.

This page is not scoped by the date picker. Everything on it is all-time or current configuration.

## Commerce settings

Quick links into Commerce's General Settings and Store Settings.

## Email notifications

**Order Status Emails** lists every order status and the emails that fire when an order moves into it, with each email's recipient type and whether it is enabled. A status with no emails is still listed.

**All Configured Emails** lists every email in the store with its name, subject, recipient, template path, and enabled state, whether or not a status triggers it.

## Coupon usage

**Coupon Usage (All Time)** ranks every coupon code that has been used on a completed order, with the number of uses and the total discount given. This ignores the date picker.

## Backfill logs

Failures recorded while backfilling orders or rebuilding daily stats are listed here with their level, type, the order reference they relate to, and the message. Each entry links to the order where there is one.

**Clear All** empties the table. Craft's garbage collection also prunes the table down to the most recent 500 entries.

An empty list means no failures have been recorded. See [data and backfill](./data-and-backfill.md) for what to do about entries that are there.
