# Daily arrival statistics on /settings/about (#1783)

## Goal

/settings/about shows how many new articles arrive per day: one number, the average of the last 7 completed
days, and a 30-day chart. Entries are pruned (90 days, social feeds 7 days, plus a per-feed cap), so the history is
stored per subscription per day outside the entry table. Where no stored history exists, it is computed on the fly as
far back as the entries allow. The same rows also store articles read per day, and the existing reading chart reads
from them, so social reads stop vanishing after 7 days.

## Decisions

| Question | Decision |
|---|---|
| Which day an article arrives on | The fetch day, `Entry.createdAt` (the day the reader first stored it) |
| The first fetch after subscribing | Arrivals count from the day **after** the subscription's `createdAt`; the subscribe day counts as 0 arrivals |
| Day boundary | UTC calendar days, for stored and live counts alike |
| Days that cannot be known | `null`, drawn as a gap; see "Known days" |
| Extra statistic | `read`: the subscription owner's opens (`EntryState.viewedAt`) of the feed's entries that day, counted from the subscribe day itself |
| Reading chart | Switches from viewer-local days to UTC days and reads the same history; the `tz` query parameter goes away |
| History lifetime | Kept until the subscription is deleted (cascade); no pruning of history rows |
| Unsubscribing | Deletes that subscription's history; past totals drop accordingly |

## Storage

Entity `App\Entity\SubscriptionDay`, table `subscription_day`:

| Column | Type | Notes |
|---|---|---|
| `id` | int, generated | |
| `subscription_id` | FK → `subscription`, `onDelete: CASCADE` | |
| `day` | `DATE_IMMUTABLE` | UTC calendar day |
| `arrived` | int | entries of the feed with `createdAt` on `day`; 0 on the subscribe day |
| `read_count` | int | the owner's `viewedAt` on `day` for the feed's entries |

Unique constraint `uniq_subscription_day` on `(subscription_id, day)`. A row is final: a stored zero means "nothing
happened", never "unknown". Rows are written only for completed days and never rewritten.

## Retention horizon

For a subscription whose feed is social (`Feed.network IS NOT NULL`, the age pass's own test) the horizon is `socialDays`, otherwise
`entryDays`, both from `RetentionSettings::current()`. A completed UTC day `D` can be counted live only if
`D > today − horizon`, i.e. the whole day lies after the age pass's cutoff (`now − horizon days`). Older days may
already be partly pruned (the age pass keeps the newest 20 per feed, which does not make an old day complete).

## Recording (snapshot)

`App\Service\History\SubscriptionHistoryRecorder::recordCompletedDays()`, called by
`RefreshHousekeeping` on a pruning refresh **immediately before** `EntryPruner::prune()`. Because both run in the
same pass and recording goes first, no entry is deleted before its day has been recorded.

For every subscription, it writes one row per UTC day `D` that has no row yet and satisfies all of:

- `D ≤ yesterday` (completed),
- `D ≥ today − 30` (the chart window; older history is not backfilled),
- `D ≥ the subscription's createdAt day`,
- `D > today − horizon` (see above).

The first run after deploy therefore backfills as far as the entries allow; afterwards each run adds the days
completed since. The counts are grouped queries in `src/Repository` (entries per feed per day, opens per user per
feed per day), bounded by the oldest missing day, never one query per subscription and day.

## Reading (on the fly)

`App\Service\History\SubscriptionActivityCounter::lastDays(User)` returns a `SubscriptionActivityModel`: the 30 UTC
dates (oldest first, today last), and per date an `arrived` and a `read` that are each an int or `null`.

For each of the user's subscriptions and each date `D` in the window:

1. `D` before the subscription's `createdAt` day: the subscription contributes nothing (not unknown).
2. A stored row for `D`: use it.
3. Otherwise, `D` is today or within the horizon: count live from entries / entry states, with the subscribe-day
   rule for arrivals.
4. Otherwise: unknown.

### Known days

A date's total is known only if no contributing subscription is unknown for it; otherwise the total is `null`.
`arrived` and `read` are known or unknown together (same horizon, same stored row).

### Average

`arrivedDailyAverage` = mean of the known `arrived` totals among the 7 completed days before today, or `null` when
none of them is known. Today is excluded because it is partial.

## API

`GET /api/reading/activity` (no `tz` any more) returns:

```json
{
  "days": [{ "date": "2026-10-25", "arrived": 12, "read": 4 }, { "date": "…", "arrived": null, "read": null }],
  "total": 118,
  "arrivedDailyAverage": 41.6,
  "topFeedsByRead": [{ "feedId": 3, "readCount": 50 }]
}
```

`total` is the sum of the known `read` values (as today: reads in the window). `topFeedsByRead` is unchanged. The
response is shaped by `App\Http\ReadingActivityJson`. Bearer auth, JSON only, no browser-only input: the
architecture §6 checklist holds.

`ReadingActivityCounter` stays in `Service/Reading` for the top-feeds ranking and delegates the daily series to the
History module (`Reading → History`; History depends on neither Reading nor Refresh). `ReadingWindowModel` and
`ViewerTimeZoneModel` lose this caller; whichever becomes unused is deleted.

## Frontend

- `ReadingDay` becomes `{ date: string; arrived: number | null; read: number | null }`;
  `ReadingActivity` gains `arrivedDailyAverage: number | null`. The API call drops `tz`.
- `buildReadingChart` takes a list of `{ date, count: number | null }`. A `null` day breaks the line and the area
  into separate segments and produces a column whose tooltip says the day is not known. `hasData` means at least
  one non-zero known day.
- A new "New articles" block above the reading chart: the average as a large figure (one decimal, e.g. "≈ 41.6 per
  day", "—" when `null`), then the 30-day chart built from `arrived`. The reading chart is built from `read`.
- New keys in both Transloco files. Styles in the component's `.scss`, tokens only.

## Testing

- Recorder (integration, SQLite + MySQL): subscribe-day arrivals are 0; days beyond the social and the normal
  horizon are not written; an existing row is not rewritten; completed days only; reads attributed to the right
  user and day.
- Housekeeping (functional): a pruning refresh records a day whose entries the same pass then prunes.
- Counter: stored and live days mixed; an unknown subscription makes the day `null`; a subscription created
  mid-window contributes only from its day; the average over a partially known week; today live.
- Migration: the CI migrate-from-empty leg on SQLite and MySQL plus `doctrine:schema:validate`.
- Frontend (Jest): chart segments around `null` days, the average figure and its `—` state; live check on :4200.
