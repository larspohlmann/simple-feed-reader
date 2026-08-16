# Month-paged run history, and cost as a dollar figure (#409, second pass)

Supersedes §7 and §8 of
[2026-08-16-run-cost-history-design.md](2026-08-16-run-cost-history-design.md).
Everything that spec says about capture, banking, the run columns and the
provider stamp is unchanged. This lands on the same branch, behind PR #427.

## Problem

The history endpoint answers with the newest 50 runs and an all-time `SUM`, and
the card renders them as one flat list. At the observed cadence — several runs a
day — fifty rows is one to four weeks, after which the account's older spending
is unreachable. A flat "load more" would reach it but would still read as a log,
and the question a spending record has to answer is "what did this cost me last
month", not "what were rows 51 to 100".

Separately, the card labels its figures "credits" and renders `0.0412`. The
provider's own logs write the same number as `$ 0.00137`, and matching that is
the difference between a number a reader recognises and one they have to
translate.

## Design

### 1. Months are the unit

Runs group under collapsible month sections. Each header carries that month's
own run count and total spend, so the summary answers the question without an
expansion. The newest month opens by default; older ones load their rows when
opened.

An expanded month renders its newest 50 runs and a "show more" control when it
holds more. The cap never makes a number wrong for the same reason the all-time
total already sits above a capped list: the header's count and total are
computed over the whole month, not over the rows on screen.

A month whose runs all went unpriced shows `—` for its total, matching how a row
and the all-time total already distinguish "unpriced" from "free".

### 2. Two routes, one round trip on first paint

The first paint needs both the month summaries and the newest month's rows. On
the production host each request pays a full PHP boot, so the overview carries
both rather than making the card make two calls.

**`GET /api/recommendations/runs/history?tz=Europe/Berlin`**

```json
{
  "totalCostNanoCredits": 918200000,
  "months": [
    { "month": "2026-08", "runCount": 47,  "costNanoCredits": 2431200000 },
    { "month": "2026-07", "runCount": 122, "costNanoCredits": 5880400000 }
  ],
  "latest": { "month": "2026-08", "runs": [ "…up to 50 rows, newest first…" ], "nextCursor": 361 }
}
```

**`GET /api/recommendations/runs/history/{month}?tz=…&before=…`**

```json
{ "month": "2026-07", "runs": [ "…" ], "nextCursor": null }
```

- `months` is newest first and covers every month the account has a run in.
  Months with no runs are absent, not zero-filled — a gap in a history is not an
  event.
- `latest` is `null` when the account has never run.
- `nextCursor` is the id to pass as `before` for the next page within that
  month, or `null` when the month is exhausted.
- `{month}` matches `\d{4}-\d{2}`; anything else is a 404 from the route
  requirement, not a hand-written guard.
- The row shape is unchanged from the first pass — all twelve fields.

The cursor is a plain `before=<runId>` integer, deliberately not an opaque
base64 pair like `RecommendationCursor`. That class encodes a `(runId, position)`
composite because the for-you feed's order needs both; ordering within a month is
`id DESC` on one column, and wrapping one integer in base64 would buy a decoder
and nothing else.

### 3. Month buckets are computed in PHP, and that is a considered trade

Two problems, with different answers.

**Selecting one month is a range query.** `month` plus `tz` resolve to a UTC
half-open interval `[start, end)`, and the query is
`createdAt >= :start AND createdAt < :end` — exact, portable, and it reads the
same index the existing ordering does. A new `MonthWindow` value object owns that
conversion and the parsing of both inputs.

**Summarising every month cannot be done in portable SQL.** DQL has no month
extraction, and the stored value is naive UTC while the buckets have to be in the
viewer's timezone — so even `SUBSTRING(createdAt, 1, 7)`, which happens to work
on both MySQL and SQLite, would bucket in the wrong zone, and there is no
portable way to shift a timestamp before grouping.

So the summary groups in PHP over a projection of exactly two scalars per run,
`createdAt` and `costNanoCredits`, across every run the account owns.

This is deliberately the shape the first pass removed from this endpoint, and the
difference is the point: that read pulled twelve fields per row *plus* the frozen
candidate pool, every batch winner with its free-text reason, the last rejected
provider reply and the error text — JSON and TEXT columns decoded and put under
the EntityManager. This one pulls a datetime and a nullable integer. At a few
runs a day it is a few thousand small rows a year.

The alternative is a platform-branched native `GROUP BY` with a timezone shift,
which this codebase confines to migrations for a reason. If the row count ever
justifies it, the seam is one repository method.

**Timezone is a display preference, so it fails soft.** An absent, unknown or
malformed `tz` falls back to UTC rather than answering 400. A client shipping a
stale timezone database should see its history in the wrong month, not lose
access to it. `tz` is a plain IANA string, so a native iOS client sends it as
readily as a browser does.

### 4. History reads get their own repository

`RecommendationRunRepository` sits at exactly ten public methods, PHPMD's
ceiling, and this adds three more queries.

`RecommendationRunHistoryRepository` takes `totalCostNanoCredits()` and
`HISTORY_LIMIT` across, and adds the month summary projection
(`spendTimeline()`) and the month page query (`pageForMonth()`, which replaces
the old `historyForUser()` — the flat newest-50 read has no caller once the
card pages by month). This is not the class the first pass's review rejected:
that one existed to hold a verbatim copy of `findNewestForUser()`, and the
count that justified it was inflated by that duplicate. This one holds three
distinct queries plus the private row projection behind them, duplicates
nothing, and the move returns `RecommendationRunRepository` to eight methods —
headroom it no longer has.

An assembler service builds the two payloads; the controller's two actions read
the request, delegate and return, holding no private method that carries
responsibility.

### 5. The card

Month sections use `app-disclosure` with `appearance: 'row'` — the shared
component whose docblock records that it was extracted after this exact shape had
been hand-rolled three times, and whose `'row'` appearance exists for "one per
item in a list".

`<details>`'s `toggle` event does not bubble, so lazy-loading an older month needs
an `opened` output on that component. That is the one change outside this
feature's own files, and adding it is the alternative to hand-rolling a fourth
collapsible.

Month header labels render through `Intl.DateTimeFormat` on the active UI
language, the same rule the row dates already follow — `DatePipe` renders
`en-US` whatever the language is.

On a completed run the overview refetches and replaces the newest month's rows.
Already-expanded older months keep their loaded rows and stay open: a run that
completes can only ever land in the current month, so their data cannot have
changed.

### 6. Cost renders as a dollar figure

`$ 0.00137`, the way the provider's own logs write it. Five decimals, which is
the granularity a single run is worth reading at.

- The `historyCostUnit` caption is deleted and "credits" leaves the
  `historyTotal` label, in both dictionaries.
- The symbol always leads. The **number** stays localized —
  `Intl.NumberFormat(activeLanguage, { minimumFractionDigits: 5, maximumFractionDigits: 5 })`
  — so German reads `$ 0,00137`. `toFixed` always writes a `.`, and a card
  showing `22. Juli 2026` beside `0.00137` is two locales in one line.
- An unpriced run and an unpriced month still render `—`, not `$ —`: the
  provider reported no price, which is not a price of zero.

The storage and wire naming stays `costNanoCredits` — nano-credits is what
OpenRouter reports and what the column holds. The dollar sign is a rendering of
that unit, not a redefinition of it, and renaming a shipped column and wire field
for a display change would be churn.

## Out of scope

Per-month totals are not cached or materialised. Runs are immutable once
terminal, so a cache would be safe, but nothing yet shows the read is slow enough
to justify the invalidation.

## Verification bar

The #409 bar still governs and is still outstanding: with the debug switch off, a
live OpenRouter run must show a non-empty cost and an all-time total that grew by
it, and an LM Studio run must show tokens with `—`. On top of it, this pass ships
only after an account with runs in more than one month shows a section per month
whose header count and total match that month's rows.
