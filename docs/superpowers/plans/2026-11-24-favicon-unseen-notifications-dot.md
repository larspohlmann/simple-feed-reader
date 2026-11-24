# Favicon Dot for Unseen Social Notifications Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** While any social network has an unseen notification, the reader's favicon carries a red dot. A background tab keeps that dot current.

**Architecture:** The backend moves the existing global change marker (`public/state/change-marker.json`) whenever an account's number of unseen notification rows changes: in the poll, the manual refresh and the seen endpoint. Each count covers the one account being changed. The reader's `SidebarCountsPoll` keeps a slow 5-minute marker tick while the tab is hidden, where it stopped before. A new reader-provided `FaviconUnseenDot` swaps the `<link rel="icon">` hrefs to a static badged icon set while `SubscriptionsStore.unseenNotificationsTotal()` is above zero.

**Tech Stack:** Symfony 8.1 / PHP 8.5 / Doctrine (backend), Angular 20 signals + Jest (frontend), `rsvg-convert` for the PNG.

**Spec:** GitHub issue #2515 (`gh issue view 2515`). Every decision in it is settled; this plan does not reopen them.

## Global Constraints

- Every rule in `CLAUDE.md` applies: Clean Code naming (verb-first methods, no abbreviations), `final readonly` services, no comments unless a future reader would get the code wrong without one, PHPMD-clean touched `src` files.
- Commit format: `type(#2515): lower-case summary`, no attribution lines.
- Native PHP test runs set a unique token: `TEST_TOKEN=<task-name> php bin/phpunit …`. Foreground commands only; no background shells, no `sleep`/`until` loops.
- Jest runs only inside Docker: `docker compose exec -T frontend npx jest <path>` from the repo root. Never two Jest runs at once.
- Dot colours: danger red `#b3403a` (light) / `#d98a86` (dark), outline in surface-0 `#f5f5f4` (light) / `#161616` (dark). These are the graphite theme's values; the favicon is not themeable.
- The favicon set is static files. No canvas drawing and no fallback for browsers that ignore a runtime favicon change.
- The dot is reader-only. Leaving the reader restores the plain icons.

## Review Focus

1. **Leaving the reader with an unseen notification** (opening Settings): the plain favicon must come back. Task 3's destroy test pins it.
2. **Logout**: `SubscriptionsStore` resets its counts to empty, so the dot must clear. Task 3's drop-to-zero test covers the mechanism.
3. **A tab left hidden for longer than the 2-hour idle limit** must still pick up a moved marker. Task 2's idle-limit test pins it.
4. **A page the idle limit already wrote off, then hidden**: the slow hidden tick must start anyway. Task 2 pins it.
5. **Notifications seen on another device or in the network's own app** (the poll syncs `seenUntil`) must move the marker so the dot clears. Task 1's `seenUntil` test pins it.

Known and accepted, both caught by the poll's existing 5-minute floor tick: a single poll of one account in which exactly as many rows turn seen as arrive leaves that account's count unchanged; and a 30-day-old unseen row removed by `prune()` doesn't move the marker. `prune()` deletes across all accounts, and ageing out isn't news.

---

### Task 1: Backend — unseen-count changes move the change marker

**Files:**
- Create: `backend/src/Service/Social/Notification/UnseenCountWatch.php`
- Modify: `backend/src/Repository/SocialNotificationRepository.php` (add `countUnseen()`)
- Modify: `backend/src/Service/Social/Notification/NotificationStore.php` (`record()` runs inside the watch; `prune()` stays as it is)
- Modify: `backend/src/Service/Social/Notification/NotificationSeenMarker.php` (`markAccountSeen()` runs inside the watch)
- Modify: `backend/src/Service/Ingestion/Refresh/ContentChangeMarker/ContentChangeMarkerInterface.php`: the docblock says a moved marker means an import stored new content *or* the unseen notification count changed
- Modify every place that constructs `NotificationStore` or `NotificationSeenMarker` in tests: `backend/tests/Support/NotificationPollWiring.php`, `backend/tests/Service/Social/Notification/NotificationContextLookupTest.php`, `NotificationStoreTest.php`, `NotificationSeenMarkerTest.php`, plus any other hit of `grep -rn "new NotificationStore(\|new NotificationSeenMarker(" backend/tests`
- Test: `backend/tests/Service/Social/Notification/UnseenCountWatchTest.php` (new), additions to `NotificationStoreTest.php` and `NotificationSeenMarkerTest.php`

**Interfaces:**
- Produces: `SocialNotificationRepository::countUnseen(SocialAccount $account): int` counts the account's rows with `seen = false`. A DQL `COUNT` in the repository.
- Produces: `final readonly class UnseenCountWatch` with constructor `(SocialNotificationRepository $notifications, ContentChangeMarkerInterface $changeMarker)` and
  `public function watch(SocialAccount $account, \Closure $change): mixed`, templated `@template T` / `@param \Closure(): T $change` / `@return T`. It counts the account's unseen rows, runs `$change`, counts them again, calls `markChanged()` when the two counts differ, and returns `$change`'s result. If `$change` throws, the exception propagates and nothing is marked.
- `NotificationStore` gains a 4th constructor parameter `UnseenCountWatch $unseenCountWatch`; `NotificationSeenMarker` gains a 6th. Public signatures stay as they are.
- Tests use the existing `App\Tests\Support\RecordingContentChangeMarker` (`public int $marks`).

- [ ] **Step 1: Write the failing `UnseenCountWatchTest`** (a `DbTestCase`, using `SocialNotificationRows` and `SocialAccounts` like `NotificationSeenMarkerTest`)
  - `testMarksWhenTheChangeAddsAnUnseenRow`: inside `watch`, add one unseen row → `$marker->marks === 1`.
  - `testMarksWhenTheChangeSeesARow`: seed one unseen row; inside `watch`, `markSeen()` it and flush → `marks === 1`.
  - `testLeavesTheMarkerAloneWhenTheCountHolds`: seed one unseen row; `watch` a closure that touches nothing → `marks === 0`.
  - `testCountsOnlyTheWatchedAccount`: seed an unseen row on another user's account; watch the first account while that other row is marked seen and a new unseen row is added to the other account → `marks === 0`.
  - `testReturnsWhatTheChangeReturned`: `self::assertSame(7, $watch->watch($account, static fn (): int => 7))`.
- [ ] **Step 2: Run it and watch it fail.** `cd backend && TEST_TOKEN=t1 php bin/phpunit tests/Service/Social/Notification/UnseenCountWatchTest.php` → error: class `UnseenCountWatch` not found.
- [ ] **Step 3: Implement `countUnseen()` and `UnseenCountWatch`.**
- [ ] **Step 4: Run it.** Same command → 4 tests pass.
- [ ] **Step 5: Write the failing store and seen-marker tests.** Build the store and marker with a real `UnseenCountWatch` around a `RecordingContentChangeMarker` the test keeps a handle on.
  - `NotificationStoreTest::testRecordingANewUnseenRowMovesTheMarker` → `marks === 1`.
  - `NotificationStoreTest::testRefetchingUnchangedRowsLeavesTheMarkerAlone`: record the same page twice; after the second → `marks === 1`.
  - `NotificationStoreTest::testSeenUntilMovesTheMarker`: store an unseen row, then record a page whose `seenUntil` covers it → the marker moved on that second record.
  - `NotificationSeenMarkerTest::testMarkingSeenMovesTheMarker` → moved once.
  - `NotificationSeenMarkerTest::testNothingUnseenLeavesTheMarkerAlone` → `marks === 0`.
- [ ] **Step 6: Run them and watch them fail.** `TEST_TOKEN=t1 php bin/phpunit tests/Service/Social/Notification/` → the new tests fail on `marks`.
- [ ] **Step 7: Wire the watch.** `record()` and `markAccountSeen()` each run their existing body inside `$this->unseenCountWatch->watch($account, ...)`. Update every test construction site. Update the `ContentChangeMarkerInterface` docblock.
- [ ] **Step 8: Run the module and the worker tests.** `TEST_TOKEN=t1 php bin/phpunit tests/Service/Social tests/Service/Operations` → all green.
- [ ] **Step 9: Run the gates on the touched files.** `cd backend && bin/console cache:warmup -q && composer stan && composer cs && composer md && composer tramp` → clean, with no PHPMD finding in a touched file. `ServiceModuleCycleRule` must stay quiet: `Social/Notification → Ingestion/Refresh` was checked and creates no cycle.
- [ ] **Step 10: Commit.** `feat(#2515): a change in unseen notifications moves the change marker`

### Task 2: Frontend — the counts poll keeps a slow tick while the tab is hidden

**Files:**
- Modify: `frontend/src/app/reader/shell/sidebar-counts-poll.service.ts`
- Test: `frontend/src/app/reader/shell/sidebar-counts-poll.service.spec.ts`

**Interfaces:**
- Produces: `export const SIDEBAR_HIDDEN_POLL_INTERVAL_MS = 5 * 60 * 1000;` from `sidebar-counts-poll.service.ts`.
- Behaviour: while `isTabHidden()`, the poll ticks every `SIDEBAR_HIDDEN_POLL_INTERVAL_MS` instead of `SIDEBAR_RELOAD_INTERVAL_MS`. A hidden tick is the same marker-gated `tick()`: it reads the marker, and when it moved, or on the floor tick, it runs `refreshCounts()`. The idle limit never stops a hidden tab: `givenUp()` becomes "visible and idle". Hiding the tab starts the hidden cadence even on a page the idle limit had written off. Showing it again keeps today's `regainTab()` (full reload, back to 30 s). The tick also checks the cadence against `isTabHidden()`, because `visibilitychange` doesn't fire on every path; the existing comment in `tick()` says so.
- Rewrite the class docblock's "a hidden tab doesn't poll" rule so it describes the slow hidden tick and why it exists (the favicon dot, #2515).

- [ ] **Step 1: Write the failing specs.** Replace `'sends nothing at all while the tab is hidden'` with these:
  - `'keeps a slow marker tick while the tab is hidden'`: `start(null)`, hide, `elapse(SIDEBAR_RELOAD_INTERVAL_MS * 9)` → `refreshes() === 0`. Then `elapse(SIDEBAR_HIDDEN_POLL_INTERVAL_MS - SIDEBAR_RELOAD_INTERVAL_MS * 9)` → `reloadCounts` called once and `reloadFull` never.
  - `'a hidden tick fetches only when the marker moved'`: `start('a')`, hide, `elapse(SIDEBAR_HIDDEN_POLL_INTERVAL_MS)` → `refreshes() === 0`. Then `markerToken = 'b'`, `elapse(SIDEBAR_HIDDEN_POLL_INTERVAL_MS)` → `reloadCounts` called once.
  - `'the idle limit never stops a hidden tab'`: `start('a')`, hide, `elapse(SIDEBAR_POLL_IDLE_LIMIT_MS + SIDEBAR_HIDDEN_POLL_INTERVAL_MS)`, `markerToken = 'b'`, `elapse(SIDEBAR_HIDDEN_POLL_INTERVAL_MS)` → `reloadCounts` called once.
  - `'hiding a page the idle limit wrote off starts the slow tick'`: `start('a')`, `elapse(SIDEBAR_POLL_IDLE_LIMIT_MS)`, record `refreshes()`, `markerToken = 'b'`, hide, `elapse(SIDEBAR_HIDDEN_POLL_INTERVAL_MS)` → `refreshes()` is one more than recorded.

  Shorten the hidden stretch in `'does a full reload the moment a hidden tab is shown again'` from `SIDEBAR_RELOAD_INTERVAL_MS * 10` to `SIDEBAR_RELOAD_INTERVAL_MS * 5`. That keeps it under one hidden interval, so its `refreshes() === 1` still asserts the regain alone.
- [ ] **Step 2: Run them and watch them fail.** `docker compose exec -T frontend npx jest src/app/reader/shell/sidebar-counts-poll.service.spec.ts` → the four new specs fail; the rest pass.
- [ ] **Step 3: Implement the hidden cadence** in `SidebarCountsPoll`.
- [ ] **Step 4: Run the spec.** Same command → all pass.
- [ ] **Step 5: Commit.** `feat(#2515): a hidden reader tab keeps a slow change-marker tick`

### Task 3: Frontend — the badged favicon and the service that swaps it in

**Files:**
- Create: `frontend/public/favicon-unseen.svg` (exact content below)
- Create: `frontend/public/favicon-unseen-32.png`, generated from the SVG: `rsvg-convert -w 32 -h 32 frontend/public/favicon-unseen.svg -o frontend/public/favicon-unseen-32.png`
- Create: `frontend/src/app/reader/shell/favicon-unseen-dot.service.ts`
- Modify: `frontend/src/app/reader/shell/reader-shell.component.ts`: add `FaviconUnseenDot` to `providers` next to `ReaderTabTitle`, and inject it for its effect the same way (`private readonly faviconDot = inject(FaviconUnseenDot);`)
- Test: `frontend/src/app/reader/shell/favicon-unseen-dot.service.spec.ts`

`frontend/public/favicon-unseen.svg`: the current `favicon.svg` path unchanged, plus the dot in the icon's empty top-right corner:

```svg
<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 -960 960 960">
  <style>
    path { fill: #3f8676; }
    circle { fill: #b3403a; stroke: #f5f5f4; }
    @media (prefers-color-scheme: dark) {
      path { fill: #5aa694; }
      circle { fill: #d98a86; stroke: #161616; }
    }
  </style>
  <path d="<copy the d attribute of frontend/public/favicon.svg verbatim>"/>
  <circle cx="740" cy="-740" r="180" stroke-width="40"/>
</svg>
```

**Interfaces:**
- Consumes: `SubscriptionsStore.unseenNotificationsTotal: Signal<number>`.
- Produces: `@Injectable() export class FaviconUnseenDot`, reader-provided and injected for its effect. At construction it captures each `link[rel="icon"]` in `DOCUMENT.head` with its original `href` attribute. An effect sets the badged hrefs while the total is above 0 and restores the originals otherwise. `DestroyRef.onDestroy` restores the originals. Badged hrefs: the `type="image/svg+xml"` link gets `favicon-unseen.svg?v=1`; every other icon link (the `.ico` and the PNG) gets `favicon-unseen-32.png?v=1`. Set them with `setAttribute('href', …)` so they stay relative, like the originals in `index.html`.
- No new translation keys and no SCSS.

- [ ] **Step 1: Write the failing spec.** In `beforeEach`, put the three `<link rel="icon">` elements from `frontend/src/index.html` into `document.head` (same `type`, `sizes` and `href` attributes), and remove them in `afterEach`. Provide `{ provide: SubscriptionsStore, useValue: { unseenNotificationsTotal: total } }`, where `total = signal(0)`. Flush effects with `TestBed.tick()`.
  - `'leaves the icons alone while nothing is unseen'` → hrefs are exactly `favicon.ico?v=7`, `favicon-32.png?v=7`, `favicon.svg?v=7`.
  - `'shows the badged icons while a notification is unseen'`: `total.set(2)` → hrefs `favicon-unseen-32.png?v=1`, `favicon-unseen-32.png?v=1`, `favicon-unseen.svg?v=1`, in that link order.
  - `'restores the plain icons once everything is seen'`: set 2, tick, set 0, tick → the original hrefs.
  - `'restores the plain icons when the reader goes away'`: set 2, tick, `TestBed.resetTestingModule()` → the original hrefs.
- [ ] **Step 2: Run it and watch it fail.** `docker compose exec -T frontend npx jest src/app/reader/shell/favicon-unseen-dot.service.spec.ts` → it fails because `favicon-unseen-dot.service` can't be resolved.
- [ ] **Step 3: Create the SVG and PNG, implement `FaviconUnseenDot`, and wire it into `ReaderShellComponent`.**
- [ ] **Step 4: Run the spec.** Same command → 4 pass. Open `frontend/public/favicon-unseen-32.png` with the Read tool: a green RSS mark with a red dot in the top-right corner, not touching the outer arc.
- [ ] **Step 5: Commit.** `feat(#2515): the reader's favicon carries a dot while a notification is unseen`

### Task 4: Whole-branch verification

- [ ] Backend, run in parallel: `cd backend && composer test:parallel` (SQLite) and `docker compose exec -T php composer test:parallel` (MySQL); `composer check`; `composer md`; `composer infection:diff`. All green.
- [ ] Frontend, after the MySQL leg has finished (never alongside it): `docker compose exec -T frontend npm run check`, then `cd frontend && npx ng build` (`npm run check` misses template errors). All green.
- [ ] Live check on http://localhost:4200 with a seeded account that has an unseen notification: the tab shows the dot; marking everything seen clears it within one tick; opening Settings restores the plain icon. Scan today's `backend/var/log/dev-*.log` for errors.
