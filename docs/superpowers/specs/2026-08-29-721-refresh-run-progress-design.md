# #721 — The refresh run owns its own progress

## The problem

Refreshing a large list — "All items" especially — shows three defects at once. They
look like three bugs. They are one: **nothing owns the refresh run.**

`POST /api/refresh` is a stateless slice endpoint. Each call does one budgeted slice
of work and returns that slice's tally; the client loops until `remaining` reaches 0.
Nothing on the wire and nothing in the client describes the run as a whole, so every
consumer invents its own arithmetic over the raw slice DTO — and the inventions
disagree.

### 1. Two progress bars

Two independent elements bind to the same service:

- `frontend/src/app/reader/sidebar/sidebar.component.html:30` — `.prog`, sidebar width.
- `frontend/src/app/reader/reader-shell.component.html:18` — `<app-progress-hairline>`
  inside `.under-header`, full page width.

On desktop both show at once, stacked.

### 2. A 2px band left behind by the retracting header

`.under-header` (`reader-shell.component.scss:218`) is absolutely positioned at
`top: var(--app-bar-h)`. The shell publishes `--app-bar-shift` for exactly this case
and the list header consumes it (`entry-list.component.scss:33`), but `.under-header`
does not. On mobile the header slides away and the hairline stays parked at the bar's
old bottom edge, over the content. Starting a refresh is also what makes the chrome
appear 2px taller in the first place.

### 3. Progress that stalls, jumps, and runs backwards

`RefreshService.progress()` computes `(total - remaining) / total`
(`refresh.service.ts:44`), but the two fields count different populations:

- `total` is `count($feeds)` for **this slice only**, capped at
  `RefreshRunner::BATCH_LIMIT` (50).
- `remaining` is `countDue(...)` — an **uncapped, run-wide** count of what is still due.

With more than 50 due feeds:

- early slices have `remaining >= total`, so the fraction is negative and clamps to 0 —
  the bar does not move for minutes;
- the final slice has a small `total` and `remaining === 0`, so the bar snaps to full;
- mid-run the ratio genuinely falls — 50 → 45 is 0.10, then 45 → 42 is 0.067 — so the
  bar moves backwards.

The onboarding banner repeats the same mistake independently:
`reader-shell.component.ts:191` computes `done = report.total - report.remaining`,
which goes negative for the same reason.

## The decision

**The server owns the run.** Progress is computed once, at the source, and every
client renders it without arithmetic. The alternative — accumulating run-wide numbers
in the Angular client — was considered and rejected: it fixes this client only, and
the standing constraint in `docs/architecture.md` §6 is that a native Swift client
stays viable. Client-side accumulation would make every client reimplement it, and
get it wrong in its own way.

The key insight that keeps this cheap: **the server does not need to count the due
feeds up front.** After any slice, `handled + remaining` is exactly the number of
feeds that were due when that slice began — every due feed either reached an outcome
or is still due. The run's denominator therefore falls out of the first slice with no
extra query.

## The wire contract

`POST /api/refresh` returns:

```json
{
  "status": "partial",
  "progress": { "done": 20, "total": 200 },
  "remaining": 180,
  "fetched": 18,
  "notModified": 2,
  "failed": 0,
  "throttled": 0,
  "skippedForBudget": 30,
  "pruned": 0
}
```

- **`progress`** — run-wide, server-owned, monotonic. The one thing a client renders.
- **`remaining`** — unchanged. Still the loop's termination signal, and still the input
  to the client's stall guard, which stays as defence in depth (#302).
- **`total` is deleted.** It was the trap: a slice's server-capped batch size sitting
  next to a run-wide `remaining`, inviting exactly the division that caused this issue.
  Nothing reads it once `progress` exists. The other counters name their own scope
  (`fetched`, `notModified`, …) and stay flat.

This breaks a private API with one shipped client, which is acceptable and is the
point: the ambiguous field is removed rather than documented.

## Backend design

Five small pieces, each with one job. **`RefreshRunner` is not touched** — it already
carries thirteen collaborators under a PHPMD suppression, and the CLI and maintenance
sweeps must not pay for a feature only the polling client needs.

### `Service/Refresh/RefreshRunProgress`

`final readonly class`, two ints.

```php
public static function start(): self;                                  // 0, 0
public function advancedBy(int $handled, int $remaining): self;
public function toArray(): array;                                      // {done, total}
```

`advancedBy()` returns a new instance with `done + $handled` and
`total = max($this->total, $done + $remaining)`.

The `max` is the honesty rule. Feeds that fall due while a run is in flight would
otherwise push `done` past `total`; instead the denominator grows and the bar stays
below full. Combined with the loop's own guarantee that `remaining` strictly falls,
progress is monotonic.

Starting from `(0, 0)`, the first `advancedBy()` sets `total = handled + remaining`,
which is the run-start due count. No query, no repository dependency.

### `Service/Refresh/RefreshRunStore`

Reads and writes one `RefreshRunProgress` per (user, scope) in a new
`refresh.run.cache` filesystem pool. Ten-minute expiry, following
`oauth.state.cache`'s pattern. A run opens when no entry exists and is forgotten when
`remaining` reaches 0 or the run ends.

No table, no entity, no reaper: the TTL is the reaper. If an entry evaporates, the
next slice re-derives a denominator and the bar jumps once — a graceful degradation.
Two integers with a two-minute lifetime do not earn a migration, a CI migration leg,
and an abandoned-run sweeper.

The scope is part of the key: a sweep of all feeds, a `feedId` refresh and a `tagId`
refresh are three different runs. The key is `<user id>.<all|feed-N|tag-N>`, built by a
private method on the store. It has exactly one call site, so it does not get a value
object of its own.

`RefreshRequest::$userId` is nullable, because `allDue()` and `forFeed()` serve the CLI
and maintenance sweeps. Those paths call `RefreshRunner` directly and are never tracked;
a request reaching `TrackedRefreshRunner` without a user id is a programming error and
throws `\LogicException`. This is a precondition, not a failure mode, which is why it
does not get a typed exception of its own next to the service.

`RefreshRunProgress` is a `final readonly` class of two ints, so the filesystem adapter
serialises it without help.

### `Service/Refresh/TrackedRefreshRunner`

The only place run-wide accounting lives.

1. Load the open run for (user, scope), or start a fresh one.
2. Delegate to `RefreshRunner::run($request)`.
3. On `busy`, return with the store untouched — no slice ran, and folding a zeroed
   busy tally in would drop the denominator and slam the bar to full.
4. Otherwise fold `fetched + notModified + failed + throttled` in as `handled`.
5. Forget the run when `remaining === 0` or the status is `aborted` — the two ways a
   run ends. Otherwise save it. (`completed` always carries `remaining === 0`, so the
   first condition covers it; `busy` already returned at step 3.)

`RefreshRunner` is `final`, so there is no double to give this class in a unit test.
It therefore depends on a new one-method `RefreshRunnerInterface`, aliased to
`RefreshRunner` in `services.yaml` the way `BatchFeedFetcherInterface` and
`FeedBodyParserInterface` already are. The existing callers keep type-hinting the
concrete class and are untouched.

### `Service/Refresh/TrackedRefreshReport`

The pair it returns: the slice's `RefreshReport` plus the run's `RefreshRunProgress`.

### `Http/RefreshJson`

Assembles the response array — the house pattern for response assembly, and what keeps
`RefreshController` a single expression under `ThinControllerRule`.
`RefreshReport::toArray()` stays as it is for the CLI and worker logging paths.

## Frontend design

### The hairline belongs to the app bar

Translating `.under-header` by `--app-bar-shift`, the way `.list-header` does, would
move the hairline from `y = bar height` to `y = 0`. It would still be on screen and
still a band over the content, only higher.

The hairline instead renders inside `app-reader-header`, absolutely positioned on the
bar's bottom edge. It then:

- travels with the bar and leaves the viewport with it, so the band cannot survive
  the retraction;
- adds no height to the bar — an absolutely positioned child does not enter its
  parent's bounding rect, so the `ResizeObserver` publishing `--app-bar-h` never sees
  it, and starting a refresh no longer thickens the chrome;
- needs no new dependency: `ReaderHeaderComponent` already injects `RefreshService`
  for its spinning icon.

`.under-header` survives for the two banners it still holds and gains the
`--app-bar-shift` transform, so a banner never floats over a gap either. Staying
visible at the top is correct for an alert, which is why `.list-header` does the same.

### `RefreshService`

`report()` becomes private. The service publishes `progress()` — the server's numbers,
unmodified — beside the existing `running()`, `failure()` and `slice()`. The
`previousRemaining` stall guard stays. The only arithmetic left is rendering
`done / total` as a fraction, in one place.

### The remaining consumers

- `reader-shell` — the `fetchProgress()` computed is deleted. The onboarding banner
  reads the same `progress()` the bar does. It was the second consumer doing its own
  maths, and the reason its count could go negative.
- `sidebar` — `.prog` markup and styles deleted.
- `progress-hairline` — gains an activity sheen on the filled segment while a slice is
  in flight, suppressed under `prefers-reduced-motion`. The width stays honest: a slice
  is budgeted at 25 s, so a large sweep steps roughly every 25 s, and the sheen says
  "working" without claiming progress the server has not reported.

## Testing

Backend:

- `RefreshRunProgressTest` — pure. The first slice sets the denominator; `done`
  accumulates across slices; `max` grows the total when feeds fall due mid-run; the
  fraction never exceeds full.
- `RefreshRunStoreTest` — round trip, per-user and per-scope keying, forget.
- `TrackedRefreshRunnerTest` — the #721 scenario itself: a 50-feed slice of a 200-feed
  sweep reports 20/200, not 0. Plus `busy` leaving the store untouched, and a finished
  run forgetting itself.
- `RefreshControllerTest` — the three `total` assertions move to `progress`.
- No schema change, so no migration leg. `composer infection:diff` gates the changed
  files as usual.

Frontend:

- `refresh.service.spec.ts` — progress comes from the wire; it resets between runs; the
  stall guard still fires.
- `sidebar.component.spec.ts` — the existing `.prog` assertion is replaced by one
  proving the sidebar now has no progress bar.
- `reader-shell.component.spec.ts` — exactly one bar in the DOM; the banner's counts
  come from the service.
- The sheen is a pure CSS decoration with no DOM hook. Adding a class solely to assert
  it would be testing the test, so it is verified on the real render instead.
- The retraction is a visual behaviour Jest cannot see. It is verified on the real
  render in the Docker stack, in both themes.

## Dead code

The change is not finished while a replaced mechanism is still in the tree. Each of
these must be gone, and each is proven gone by grepping the symbol rather than by
assuming:

- `RefreshReport::$total` and its constructor and factory arguments — **only if**
  nothing still reads it. `RefreshReport::toArray()` stays for the worker's logging, so
  this has to be checked, not assumed.
- `frontend` `RefreshReport.total` in `models.ts`.
- `RefreshService.progress()`'s old arithmetic and the `report()` signal's public
  visibility.
- `reader-shell.component.ts`'s `fetchProgress()` computed.
- `sidebar.component.html`'s `.prog` element, and the `.prog` and `.prog i` rules in
  `sidebar.component.scss`.
- Any import, translation key or spec assertion left without a subject by the above.

PHPStan at level max reports unused private members, and ESLint reports unused imports.
Stylelint reports neither an unused class nor an orphaned rule, so the `.scss` removals
are verified by grepping the class names across `src/`.

## Out of scope

- The For You run pill keeps its own progress mechanism. It has phases, an ETA and a
  stop button that a feed refresh has no equivalent for.
- The CLI and maintenance sweeps get no run-wide progress. Nothing polls them.
