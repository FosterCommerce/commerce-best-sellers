# Console commands

All of these are also available at **Utilities -> Best Sellers**, except `clear-logs`, which is on the [Operations](../user-guide/operations.md) page. The console commands do not check permissions.

| Command | What it does |
| --- | --- |
| `best-sellers/backfill` | Queues every completed order for processing, in batches of 25. Already-recorded orders are skipped, so re-running does not double-count. |
| `best-sellers/backfill/refresh-orders` | Deletes the recorded sales data, then queues the orders again. |
| `best-sellers/backfill/clear-orders` | Clears the sales data without queueing a rebuild. |
| `best-sellers/backfill/fill-unit-costs` | Queues completed orders to add unit costs to lines with no unit cost, from each variant's current cost. See [data and backfill](../user-guide/data-and-backfill.md#fill-unit-costs). |
| `best-sellers/backfill/daily-stats` | Rebuilds daily stats from Commerce's orders, over the range detected from your earliest and latest completed order. |
| `best-sellers/backfill/refresh-daily-stats` | Clears daily stats before rebuilding. |
| `best-sellers/backfill/clear-daily-stats` | Clears the daily stats without queueing a rebuild. |
| `best-sellers/backfill/clear-logs` | Deletes every backfill log entry. |

## Options

`--start-date` and `--end-date` scope `backfill`, `refresh-orders`, and `fill-unit-costs` to a range. Both are `YYYY-MM-DD` in the site's timezone, and the range includes both days. Give both: one on its own is ignored. On `refresh-orders` they also narrow what is deleted.

`--product-types` limits `fill-unit-costs` to product types by handle, comma-separated. Without it, the command fills every product type with a unit cost field. It exits with an error when none of the named product types has a unit cost field.

`--date` scopes `daily-stats` and `refresh-daily-stats` to one day, and that day is rebuilt immediately rather than queued.

```sh
./craft best-sellers/backfill --start-date=2025-01-01 --end-date=2025-12-31
./craft best-sellers/backfill/daily-stats --date=2026-03-15
./craft best-sellers/backfill/fill-unit-costs --product-types=apparel,accessories
```

## Queue

`backfill`, `refresh-orders`, `fill-unit-costs`, and the two daily stats rebuilds without `--date` push queue jobs. The reports do not change until a queue worker runs those jobs. The `clear-` commands and the `--date` rebuilds run in the console process.

```sh
./craft queue/listen
```
