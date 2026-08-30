# Sidebar poll: static change marker + counts-only endpoint

**Goal:** Stop the 30-second sidebar poll from re-fetching the whole 137 KB
subscription bootstrap to read four numbers. Two steps: (1) a static change
marker the tick reads before any API call, so nine ticks in ten do no PHP work;
(2) a counts-only endpoint for the ticks that do fetch, 137 KB → ~5 KB.

**Spec:** [GitHub issue #720](https://github.com/larspohlmann/simple-feed-reader/issues/720)

## Global constraints

- Branch `feature/720-static-change-marker-counts-endpoint`, off `develop`.
- Commits `type(#720): summary`. `composer check` + `composer md` before each
  backend commit; `npm run check` before each frontend commit.
- Keep controllers thin (`ThinControllerRule`); no browser-coupled endpoint.

## Backend

### B1 — `ContentChangeMarker` (new)
- `App\Service\Refresh\ContentChangeMarkerInterface` with `markChanged(): void`.
- `App\Service\Refresh\ContentChangeMarker` (`final readonly`), ctor `string $projectDir`.
- Writes `public/state/counts` with one opaque token (`bin2hex(random_bytes(8))`).
- Atomic: temp file in the same dir + `rename()`. Creates `public/state/` if missing.
- IO failure logs and returns; it never throws into the refresh.
- Wire `$projectDir` in `services.yaml` like `BundledCatalog`.
- Test: writes a token; two calls differ; creates the dir; a bad dir does not throw.

### B2 — `RefreshRunner` moves the marker on real import
- Sum the entries `EntryIngestor::ingest()` creates across the sweep.
- `persistOutcome`/`applyOutcome` return a small `FeedRefreshResult{outcome, entriesCreated}`.
- `RefreshTally` gains `entriesCreated`; `record()` adds it (aborted feed adds 0).
- After a non-aborted run, if `entriesCreated > 0`, call `markChanged()`.
- Inject `ContentChangeMarkerInterface`; update the three runner test constructors
  with a spy.
- Test: marker moves when entries are created, stays put on an all-NotModified run.

### B3 — `GET /api/subscriptions/counts`
- `SubscriptionController::counts(#[CurrentUser] User $user)`.
- Two aggregate queries only: `unreadCountsForUser()` + `stateCountsForUser()`.
  No ORM hydration of feeds/tags, no `SubscriptionJson`.
- Payload built by `App\Http\SubscriptionCountsJson::from($counts, $flags)`:
  `{ subscriptions:[{id,unreadCount}], favoritesCount, keptCount, viewedCount }`.
- Route order safe: `/{id}` requires `\d+`, so `/counts` is not swallowed.
- Test: mapper shape; functional GET returns the shape and is not caught by `/{id}`.

## Frontend

### F1 — counts model + API
- `SubscriptionCountsResponse { subscriptions:{id,unreadCount}[]; favoritesCount; keptCount; viewedCount }`.
- `ReaderApi.subscriptionCounts()` → GET `${base}/api/subscriptions/counts`.

### F2 — `SubscriptionsStore.reloadCountsIfStale()`
- Counts-only quiet reload: same `resolved`/`inFlight`/stale/`localEdits` guards
  as `reloadQuietlyIfStale`.
- Patch `unreadCount` into the held list; keep row identity for unchanged rows;
  call `subscriptions.set` only when at least one count moved.
- Update `favoritesCount`/`keptCount`/`viewedCount` only when the value differs.
- Keep `reloadQuietlyIfStale()` as the full quiet reload (visibility regain).
- Tests: patches counts; no `set` when nothing moved; `localEdits` guard drops a
  stale answer; `resolved`/`inFlight` guards.

### F3 — `SidebarCountsPoll` marker gate + full-load on regain
- Inject `API_BASE_URL`; marker URL `${apiBaseUrl}/state/counts`.
- Read the marker with native `fetch(url,{cache:'no-cache'})` — NOT `HttpClient`
  (the bearer interceptor would attach the token). Network error / non-ok → treat
  as "unknown" and fetch (today's behaviour).
- Steady tick: read marker; token equal to last seen AND not a floor tick → do
  nothing; else `reloadCountsIfStale()` + `savedSearches.reloadIfStale()`.
- Floor: force a fetch every 10th tick (5 min).
- Visibility regain / idle-resume: full reload (`reloadQuietlyIfStale()` +
  saved searches) to catch a feed added/renamed/retagged elsewhere; refresh the
  marker baseline afterwards.
- Tests: unchanged marker skips; changed marker fetches; missing marker fetches;
  floor forces a fetch; regain does a full reload. Mock global `fetch`.

### F4 — dev proxy + gitignore
- `frontend/proxy.conf.json`: forward `/state` like `/api` so the marker is
  reachable in dev.
- `backend/.gitignore`: ignore `/public/state/`.

## Out of scope (own issues)
- #584 saved-searches LIKE scan — the marker gate already covers its request.
- nginx JSON gzip in the Docker configs.
