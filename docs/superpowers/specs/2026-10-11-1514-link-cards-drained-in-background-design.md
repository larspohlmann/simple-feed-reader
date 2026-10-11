# #1514 — Link cards drained in the background, not inside a request

Follow-up to #1502. Link cards are built inside `POST /api/entries/complete`. One call can hold a PHP worker for
up to 40 s: `ShareLinkCards` allows 5 fetches (`SharedLinks::MAX`) of up to 8 s each
(`HtmlPageFetcher::HEAD_MAX_DURATION_SECONDS`). Each open tab sends the next call 3 s after the last one ends, for
up to 20 rounds per list load. Strato has few FastCGI workers, each capped near 200 MB, so a few tabs on
link-heavy lists can keep them busy and ordinary API calls queue behind.

## Decisions (agreed 2026-10-11)

| Question | Decision |
|---|---|
| Card latency on Strato | Within the minute of polling: the request keeps a small slice of work |
| Request slice | **1 page fetch per call**, so at most ~8 s held instead of 40 s |
| Multi-link posts | Kept whole: a post's links are fetched in one call even past the budget, so a rare multi-link post can hold one request or tick up to 8 s per link |
| Background | A sweep drains the oldest queued link-card rows across all feeds |
| Worker installs | New scheduler message every minute, 40 s budget (inside the 60 s claim lease) |
| Strato | The sweep runs in the existing maintenance tick, only when one fetch (8 s) still fits its window |
| Fetch timeout | Unchanged. The 8 s head read was raised in #1524 so YouTube pages get their card |
| Frontend | Unchanged. #1516 shows "No preview yet" once the poll gives up |

Rejected:
- **Background only, the endpoint just reports state.** Strato has no worker; its tick runs every 5 min, so most
  fresh link posts would miss the minute the poll lasts.
- **Only cut the in-request budget.** Cards for lists nobody opens would never be built.

## Measured

Production can't size this yet: it runs a release from before link-card completion (no `share_enrichment`
table, no `kind` column). The risk is anticipatory, so the design keeps the request slice small rather than
tuned to observed volume.

Multi-link posts are rare. On the dev database, finished link posts break down as 1,259 with one card, 2 with
two and 136 with none (failed pages). That is about 0.15 % with more than one link, which is why a post stays
whole rather than being carded across calls (that would need a per-row cursor column).

## Design

### 1. A fetch budget passed by the caller

- New `App\Service\LinkCard\Model\LinkCardBudgetModel`: a fetch count and a wall-clock length in seconds.
  - `LinkCardBudgetModel::fetches(int $count)` sizes the length as `count × HEAD_MAX_DURATION_SECONDS`, the rule
    `DEADLINE_SECONDS` encodes today.
  - Named constructors for the two callers are not added; each caller holds its own count as a constant.
- `ShareLinkCards::complete(array $queued, LinkCardBudgetModel $budget)` replaces `FETCHES_PER_CALL` and
  `DEADLINE_SECONDS` with the budget. Everything else stays: oldest first, the first row always runs (so no entry
  is left half-carded, even past the count), claim, lease, release of the rows the deadline cuts off, `stillHeld`
  before writing.

### 2. The request slice

- `EntryCompletion` passes `LinkCardBudgetModel::fetches(1)`. Its AppView pass is unchanged (one batched call).
- `PendingEntriesPoll` keeps polling; each call now cards about one link.

### 3. The background sweep

- New `App\Service\LinkCard\LinkCardSweep::sweep(LinkCardBudgetModel $budget): LinkCardSweepReportModel`.
  - Reads the oldest claimable `link_cards` rows across all feeds through a new
    `ShareEnrichmentRepository::findClaimableLinkCards(\DateTimeImmutable $now, int $limit)` (same `claimable()`
    base as the AppView query, kind `LinkCards`, no feed filter). The limit is the budget's fetch count, since
    each row costs at least one fetch.
  - Hands them to `ShareLinkCards::complete()` with the budget.
  - `LinkCardSweepReportModel` reports rows carded and rows left for later. `ShareLinkCards::complete()` returns
    what it needs for that (it returns nothing today).
- Worker: new message `App\Service\Worker\Message\CompleteQueuedLinkCards` and its
  `CompleteQueuedLinkCardsHandler`, scheduled `every('1 minute')`, **appended last** in `WorkerSchedule` (the
  stateful checkpoint stores a position). Budget: 5 fetches (40 s).
- Strato: `MaintenanceTick::sweep()` runs the link-card sweep before the saved-search membership sweep.
  - Its budget is the whole fetches that fit before the tick's deadline, at most 2. With less than one fetch
    (8 s) left it does not run and reports itself skipped.
  - The membership sweep keeps taking what is left. The cron cadence and the 25 s window do not change.
  - `MaintenanceSweepsModel` gains the report. `skippedAfterAbortedRefresh()` gives an empty one, and
    `MaintenanceTickJson` adds a `linkCards` section in the same shape as the others.

### 4. Errors

- A page that fails is logged and its row is still marked done, as today.
- The sweep runs inside the tick's EntityManager rules: it is skipped after an aborted refresh like the other
  sweeps.
- A worker run that overruns its lease writes nothing for rows another holder took over (`stillHeld`, unchanged).

## Testing

- `ShareLinkCards`: stops after the budget's fetch count; stops before the budget's deadline (injected clock);
  still runs the first row when it alone exceeds the count.
- `LinkCardSweep`: picks the oldest rows across feeds; respects the limit; reports carded and left counts.
- `ShareEnrichmentRepository::findClaimableLinkCards`: kind filter, claim lease, oldest first, limit (SQLite and
  MySQL legs).
- `MaintenanceTick`: runs the sweep when one fetch fits, skips it when it does not, skips it after an aborted
  refresh; the tick JSON carries `linkCards`.
- `WorkerSchedule`: the new message is last.
- Functional: `POST /api/entries/complete` with two queued link posts makes one page fetch.
- Gates: `composer check`, `composer md`, `composer infection:diff`, both suites.
