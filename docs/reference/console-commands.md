# Console commands

All of these are also available at **Utilities -> Best Sellers**, except the log clear, which is on the [Operations](../user-guide/operations.md) page. None is permission-gated.

| Command | What it does |
| --- | --- |
| `best-sellers/backfill` | Queues every completed order for processing, in batches of 25. Already-recorded orders are skipped, so re-running does not double-count. |
| `best-sellers/backfill/refresh-orders` | Deletes recorded variant sales, then queues the orders again. |
| `best-sellers/backfill/clear-orders` | Truncates the variant sales table and queues nothing. |
| `best-sellers/backfill/daily-stats` | Rebuilds daily stats from Commerce's orders, over the range detected from your earliest and latest completed order. |
| `best-sellers/backfill/refresh-daily-stats` | Clears daily stats before rebuilding. |
| `best-sellers/backfill/clear-logs` | Deletes every backfill log entry. |

## Options

`--start-date` and `--end-date` scope `backfill` and `refresh-orders` to a range. Both are `YYYY-MM-DD`, and both have to be given: one on its own is ignored. On `refresh-orders` they also narrow what is deleted.

`--date` scopes `daily-stats` and `refresh-daily-stats` to one day, and that day is rebuilt immediately rather than queued.

```sh
./craft best-sellers/backfill --start-date=2025-01-01 --end-date=2025-12-31
./craft best-sellers/backfill/daily-stats --date=2026-03-15
```

## Queue

`backfill`, `refresh-orders`, and the two daily stats rebuilds without `--date` push queue jobs, so nothing changes in the reports until a worker runs them. The `clear-` commands and the `--date` rebuilds run in the console process.

```sh
./craft queue/listen
```
