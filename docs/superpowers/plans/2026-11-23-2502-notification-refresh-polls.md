# Notification Refresh Polls the Network Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Refresh button on a network's notification list polls that network for the signed-in user's connected accounts, then shows the list and the counts again.

**Architecture:** A new `NotificationRefresh` service polls each connected account of the user on that network through the existing `NotificationPoll::poll()`, which already keeps the host throttle, records a missing scope and stamps `checkedAt`, so the background sweep skips the account for its next 5 minutes. `POST /api/me/notifications/refresh {network}` calls it behind its own per-user rate limiter and answers 204. The frontend's `NotificationsStore.refresh()` posts, then loads the first page and the counts whether the post succeeded or not. A failure shows as a toast.

**Tech Stack:** Symfony 8.1 / PHP 8.5, PHPUnit 12; Angular 20 signals, Jest.

**Spec:** GitHub issue #2502 (the body is the spec). Background: the #2240 rulings (memory `social-notifications-2240`). Opening the list never polls; only the explicit Refresh does.

## Global Constraints

- Branch `fix/2502-notification-refresh-polls`, in the worktree `.claude/worktrees/fix-2502-notification-refresh-polls`. Never touch the main checkout, because another session is working in it.
- Commits use `type(#2502): …`, with no Claude attribution.
- Every touched `src` file must be PHPMD-clean, and `composer check` must pass (cs + stan level max + tramp).
- No new PHPStan ignores, no baselines, no `tests/PhpStan` edits.
- HTTP statuses are `Response::HTTP_*` constants in src and tests.
- Bearer-only JSON POST: no CSRF, no cookie, problem+json on failure (native iOS client stays viable).
- No new UI strings. A failure toast uses the problem's own title and detail via `ToastService.showProblem`.
- Comments: default to none.

## Review Focus

1. **The rate limit is hit (429).** The user should see a toast, and the stored list and counts still reload. Pinned in Task 3 (`refresh still reloads the list when the poll fails`) and Task 2 (`testRefreshIsRateLimitedPerUser`).
2. **The network refuses the credentials.** `ActingAccountGuard` marks the account needs-reconnect and the request answers the mapped problem. The reloaded list shows the needs-reconnect hint. Pinned in Task 2 (`testRefreshMapsRejectedCredentialsToItsProblemAndMarksTheAccount`).
3. **The user switches networks while a refresh is in flight.** The late answer must not overwrite the other network's list. Pinned in Task 3 (`a refresh answered after switching networks loads nothing for the old one`).
4. **Accounts needing a reconnect, or another user's accounts.** They are never polled. Pinned in Task 1.
5. **Known race, accepted and not fixed:** a Refresh that lands while the maintenance tick polls the same account can hit `uniq_social_notification_account_remote`, and the user gets a 500 toast. The rows exist anyway, and the reload shows them. Name this in the PR body as a known limit.

---

### Task 1: `NotificationRefresh` service

**Files:**
- Create: `backend/src/Service/Social/Notification/NotificationRefresh.php`
- Test: `backend/tests/Service/Social/Notification/NotificationRefreshTest.php` (a `KernelTestCase` integration test like `NotificationPollSweepTest`; it swaps in `StubNotificationSource` through the container the same way that test does)

**Interfaces:**
- Consumes: `SocialAccountRepository::findConnectedOnNetwork(User, SocialNetwork): list<SocialAccount>`, `NotificationPoll::poll(SocialAccount): int`
- Produces: `final readonly class NotificationRefresh` with `refresh(User $user, SocialNetwork $network): void`

- [ ] **Step 1: Write the failing tests**

```php
public function testRefreshStoresWhatEachConnectedAccountOnTheNetworkFetched(): void
// two connected Bluesky accounts of the user, each answered a page with one row (NotificationRows::fetched);
// after refresh(): both rows are stored, and both accounts' SocialNotificationPoll::checkedAt equals the clock's now

public function testRefreshSkipsAccountsOnOtherNetworksAndOfOtherUsers(): void
// the user's Mastodon account and another user's Bluesky account: StubNotificationSource::$fetchedIdentities
// lists only the user's own Bluesky identity

public function testRefreshSkipsAnAccountThatNeedsReconnecting(): void
// a NeedsReconnect Bluesky account: $fetchedIdentities === []

public function testRefreshLetsANetworkFailureThrough(): void
// the stub answers SocialNetworkUnreachableException; expectException(SocialNetworkUnreachableException::class)
```

- [ ] **Step 2: Run them to check they fail**

Run: `php bin/phpunit tests/Service/Social/Notification/NotificationRefreshTest.php`
Expected: FAIL, with "Class App\Service\Social\Notification\NotificationRefresh not found"

- [ ] **Step 3: Implement `NotificationRefresh::refresh(User $user, SocialNetwork $network): void`**

Loop over `findConnectedOnNetwork` and call `poll()` on each account. Exceptions are not caught: the controller's problem mapping answers them, and `poll()` itself already handles throttling and a missing scope.

- [ ] **Step 4: Run the tests and check they pass**

Run: `php bin/phpunit tests/Service/Social/Notification/NotificationRefreshTest.php`
Expected: PASS

- [ ] **Step 5: Commit** with `feat(#2502): refresh polls every connected account of a network for notifications`

### Task 2: `POST /api/me/notifications/refresh`

**Files:**
- Create: `backend/src/Dto/Social/RefreshNotificationsRequest.php`, modelled on `MarkNotificationsSeenRequest`: `#[Assert\NotNull] public ?SocialNetwork $network = null` plus `toNetwork(): SocialNetwork`
- Modify: `backend/config/packages/rate_limiter.yaml`. Add `notification_refresh` with `sliding_window`, limit 30, interval `'10 minutes'`, `cache_pool: cache.rate_limiter`, and the one-line comment `# Per user: each notification Refresh polls every connected account on the network, one or two calls each.`
- Modify: `backend/src/Controller/Api/SocialNotificationController.php`. Add the `refresh` action (route `/refresh`, name `api_me_notifications_refresh`, `POST`), and inject `NotificationRefresh $notificationRefresh` and `#[Target] RateLimiterFactoryInterface $notificationRefreshLimiter`.
- Test: `backend/tests/Controller/Api/SocialNotificationControllerTest.php`

**Interfaces:**
- Consumes: `NotificationRefresh::refresh(User, SocialNetwork): void` (Task 1)
- Produces: `POST /api/me/notifications/refresh` with body `{"network": "bluesky"|"mastodon"|"lemmy"}`, which answers `204`; `400`/`422` for a bad body; `429` when rate-limited; the mapped social problem when the network fails

- [ ] **Step 1: Write the failing tests**

```php
public function testRefreshReturns204AndStoresTheFetchedNotifications(): void
// Lemmy account, StubNotificationSource answering one reply row (set on LemmyNotificationSource::class, like useSource());
// POST {network: lemmy} → HTTP_NO_CONTENT; then GET /api/me/notifications?network=lemmy lists one group of kind 'reply'

public function testRefreshMapsRejectedCredentialsToItsProblemAndMarksTheAccount(): void
// the stub throws SocialCredentialsRejectedException → assertRejected with the status SocialProblems maps it to
// (read the arm; don't guess); afterwards the account's status is SocialAccountStatus::NeedsReconnect

public function testRefreshIsRateLimitedPerUser(): void
// 31 POSTs with an empty-page stub; the 31st → HTTP_TOO_MANY_REQUESTS

public function testRefreshWithoutANetworkIs422(): void
// POST {} → assertRejected(HTTP_UNPROCESSABLE_ENTITY)

public function testRefreshNeedsAToken(): void
// unauthenticated POST → HTTP_UNAUTHORIZED (mirror testListNeedsAToken)
```

If the rate-limiter test is slow or the test env overrides limits, check how another limiter's controller test proves its 429 (`grep -rn HTTP_TOO_MANY_REQUESTS tests/Controller`) and follow that pattern instead.

- [ ] **Step 2: Run them to check they fail**

Run: `php bin/phpunit --filter Refresh tests/Controller/Api/SocialNotificationControllerTest.php`
Expected: FAIL with 404/405 for the missing route

- [ ] **Step 3: Implement the DTO, the limiter and the action**

`refresh(#[MapRequestPayload] RefreshNotificationsRequest $request, #[CurrentUser] User $user): Response`. It calls `enforceForUser($this->notificationRefreshLimiter, $user)`, then `$this->notificationRefresh->refresh($user, $request->toNetwork())`, then returns `new Response(status: Response::HTTP_NO_CONTENT)`.

- [ ] **Step 4: Run the tests and check they pass**, using the same command → PASS

- [ ] **Step 5: Run the backend gates**: `composer check`, `composer md` (touched files clean), then `php bin/phpunit` (the full SQLite leg) → all green

- [ ] **Step 6: Commit** with `feat(#2502): POST /api/me/notifications/refresh polls the network`

### Task 3: Frontend Refresh polls first

**Files:**
- Modify: `frontend/src/app/reader/notifications/notifications-api.ts`. Add `refresh(network: SocialNetworkName): Observable<void>`, which POSTs `{ network }` to `${base}/api/me/notifications/refresh`.
- Modify: `frontend/src/app/reader/notifications/notifications.store.ts`. Add `refresh(): void`. It replaces the component's direct `reload()` and counts call.
- Modify: `frontend/src/app/reader/notifications/notification-list/notification-list.component.ts`. `refresh()` becomes `this.store.refresh()` alone, and the `subscriptions` field stays for `showsAccount`.
- Test: `frontend/src/app/reader/notifications/notification-list/notification-list.component.spec.ts` (replace `refresh reads the stored list again`), plus `notifications.store.spec.ts` if one exists. Otherwise the component spec carries all of these.

**Interfaces:**
- Consumes: `POST /api/me/notifications/refresh` (Task 2)
- Produces: `NotificationsStore.refresh(): void`

- [ ] **Step 1: Write the failing specs**

```ts
it('refresh polls the network, then reads the list and the counts', () => {
  // after the first page: click .refresh → expectOne POST `${base}/api/me/notifications/refresh` with body { network: 'bluesky' };
  // header shows refreshing (store.loading() true) and no list GET is out yet (http.expectNone on listUrl);
  // flush the POST with null (204) → expectOne list GET, flush; reloadCountsQuietly called after the POST answered
});

it('refresh still reloads the list when the poll fails', () => {
  // flush the POST with a 429 problem → toast.showProblem called; list GET still goes out; reloadCountsQuietly called
});

it('a refresh answered after switching networks loads nothing for the old one', () => {
  // click refresh on bluesky, switch the selection input to mastodon (flush its list), then flush the bluesky POST
  // → no further list GET for network=bluesky (http.expectNone)
});
```

- [ ] **Step 2: Run the specs to check they fail.** The worktree's `frontend/` is not the Docker container's mount, so run a one-off container on the shared node_modules volume. Run it only while no other Jest run is going (the OOM rule):

```bash
docker run --rm -v "$PWD/frontend:/app" -v simple-feed-reader_frontend-node-modules:/app/node_modules -w /app node:22-slim npx jest src/app/reader/notifications
```

Expected: FAIL, because no POST to `/refresh` was made

- [ ] **Step 3: Implement `NotificationsStore.refresh()`**

Take `network = openNetwork()` and return when it is null. Set `isLoading` to true, clear `failure`, unsubscribe `listRequest`, and set `listRequest = api.refresh(network).subscribe({ next/complete and error → settle })`. Both paths run one private `settle(network)`: return if `openNetwork() !== network`; otherwise `loadFirstPage(network)` and `subscriptions.reloadCountsQuietly()`. Only the error path calls `toast.showProblem(parseProblem(error))` first. Because `listRequest` is the same handle `open()` and the identity change already cancel, a network switch drops the in-flight poll answer.

- [ ] **Step 4: Run the specs and check they pass**, using the same command → PASS

- [ ] **Step 5: Run the frontend gate**, with the same `docker run …` but `npm run check` → green

- [ ] **Step 6: Commit** with `feat(#2502): notification Refresh polls the network before reading the list`

### Task 4: Live check and PR

- [ ] **Step 1:** The Docker stack serves the main checkout, not this worktree. So the live check waits until the branch can be switched in the main checkout (the other session's branch has finished), or it happens on Strato after the deploy. Checklist: get a new Bluesky like or follow, open `/?view=notifications&network=bluesky`, click Refresh, and the row appears with the sidebar count raised.
- [ ] **Step 2:** Run `/simplify`, `composer infection:diff`, and push. Open the PR against `develop` with `Closes #2502`. The PR body names the known race (Review Focus 5) and notes that the MySQL leg runs in CI.
