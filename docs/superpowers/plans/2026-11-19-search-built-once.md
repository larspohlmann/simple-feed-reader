# A Network's Search Is Built Once Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mastodon's and Lemmy's search requests and search page are built in their search source only, and a too-broad stored search becomes a feed failure in one place.

**Architecture:** `MastodonSearchSource` and `LemmySearchSource` each gain `buildRequests()` and `buildPage()`; their own `fetchNewest()` and their network's timeline source both call them. The timeline sources stop translating `SearchQueryTooBroadException`; `Pass/TimelineRound` does it once, for every network. No interface in `Social/Social` gains a method.

**Tech Stack:** PHP 8.5, Symfony 8.1, PHPUnit, PHPStan, PHPMD.

**Spec:** GitHub issue #2369, as amended by the decisions below.

## Global Constraints

- No behaviour or API change: the same requests in the same order, the same pages, and a too-broad stored search still settles its feed as unreachable with the exception's own message (`…more than 8…`, `Lemmy needs a word to search for in every alternative.`).
- Every existing test survives; a test whose expected exception class changes (Task 2) keeps its message assertion.
- Per task: `composer cs:fix`, `composer check`, `composer md`, `php bin/phpunit` green before the commit. Before the PR also `docker compose exec php composer test:parallel` and `composer infection:diff`.
- No new `src` class. No `tests/PhpStan` edit, no `@phpstan-ignore`, no PHPMD suppression.
- No `…Of` method is added; existing ones keep their names (#2360).
- Commits `refactor(#2369): …` (`test(#2369): …` for a pin). No attribution lines.

**Out of scope:** Bluesky's search source and timeline source; `SearchAnswers`; `SocialSearchQuery::isSearchable()`; `SearchFeedEditor`; the search path and page helpers in `Support/`.

## Decisions

- **The two methods are not on `SearchSourceInterface`.** Only the same network's timeline source calls them, and it injects the concrete class, as it injects the concrete private feed rule today. An interface method no generic code calls would be a seam with nothing behind it.
- **Bluesky stays as it is.** Its search is one request built by `BlueskySearchPath::buildPath()` and one page from `BlueskySearchPage::of()`; both already exist once, and its interactive search goes through `BlueskySessions::askAs()`, which sends the request itself.
- **`SearchFeedEditor` keeps calling `buildSearchQueries()` for its exception** (the issue said it should ask `allows()`). `allows()` answers yes or no and would lose the reason the reader is shown in the 422 (`Lemmy needs a word to search for in every alternative.`).
- **Two catch sites remain, one per meaning** (the issue said one): `SocialSearchQuery::isSearchable()` is the yes/no form the private feed rules ask on subscribe; `TimelineRound` is where a stored search that can no longer run becomes a feed failure. The two rethrows in the network timeline sources go.
- **`MastodonTimelineSource::pathOf()` lists `Search` with the kinds that have no timeline.** The method builds timeline paths; a search has none. The `LogicException` arm goes.

## Review Focus

- **A stored search that has become too broad, polled.** The feed settles with the same failure outcome and message as before, and the other feeds of the round are read. Task 1 pins it through a real round for Mastodon and Lemmy before anything changes.
- **Mastodon's `min_id` on a polled search.** The poll passes the feed's newest seen id; the interactive search passes null. Both existing request-URL assertions in `MastodonTimelineSourceTest` and `MastodonSearchSourceTest` must stay untouched.
- **Lemmy accepts a polled answer differently.** `LemmyTimelineSource::accepted()` uses `acceptedInRound()`, the search source uses `accepted()`. That difference stays; only request and page building are shared.
- **Container wiring.** `MastodonSearchSource` depends on `SearchAnswers`, which depends on `TimelineRounds`, whose `TimelineSources` picks the timeline source that now depends on the search source. The picker is a locator, so nothing is built in a circle; `EveryApplicationServiceBuildsTest` and a kernel boot prove it. If the container reports a circular reference, stop and report.
- **A too-broad query typed into the interactive search** still answers the 422 with the query's reason; `fetchNewest()` must let the exception out as it does today.

---

### Task 1: Pin the too-broad poll

**Files:**
- Test: `backend/tests/Service/Social/Social/TimelineRoundsTest.php`

- [ ] **Step 1:** Add two cases that run a real round (the real timeline sources from the test wiring, no doubles of them) over two feeds of one account: a search feed whose query is too broad, and a home feed. Mastodon: query `(a || b || c) (d || e || f)`; Lemmy: a query with an alternative that holds no word (take one from `LemmySearchTermsTest`). Assert: the search feed's outcome is the failure outcome an unreachable network gives, carrying the too-broad message; the home feed's page is read; no request went out for the search feed.
- [ ] **Step 2:** Run `php bin/phpunit tests/Service/Social/Social/TimelineRoundsTest.php`. Expected: PASS against unchanged code.
- [ ] **Step 3:** Commit `test(#2369): pin a too-broad stored search in a timeline round`.

### Task 2: The round translates a too-broad search once

**Files:**
- Modify: `backend/src/Service/Social/Social/Pass/TimelineRound.php` (`ticketOf()`)
- Modify: `backend/src/Service/Social/Social/TimelineSource/TimelineSourceInterface.php` (`requests()` docblock: `@throws SearchQueryTooBroadException when a stored search can no longer run`)
- Modify: `backend/src/Service/Social/Mastodon/MastodonTimelineSource.php`, `backend/src/Service/Social/Lemmy/LemmyTimelineSource.php` (drop the two `try`/`catch` rethrows)
- Test: `backend/tests/Service/Social/Mastodon/MastodonTimelineSourceTest.php`, `backend/tests/Service/Social/Lemmy/LemmyTimelineSourceTest.php`

**Interfaces:**
- `TimelineSourceInterface::requests()` may now throw `SearchQueryTooBroadException`.
- `TimelineRound::ticketOf()` catches it and settles `new SocialNetworkUnreachableException($tooBroad->getMessage(), previous: $tooBroad)` through `TimelineAccounts::settleFailure()`, exactly what the sources threw before.

- [ ] **Step 1:** In the two source tests, the too-broad case expects `SearchQueryTooBroadException` instead of `SocialNetworkUnreachableException`; the message assertion stays. Run them. Expected: FAIL.
- [ ] **Step 2:** Remove the rethrows from both sources and add the catch in `ticketOf()`.
- [ ] **Step 3:** Run both source tests and `TimelineRoundsTest`. Expected: PASS, the Task 1 cases untouched.
- [ ] **Step 4:** Run `grep -rn 'catch (SearchQueryTooBroadException' backend/src`. Expected: two lines, `Pass/TimelineRound.php` and `Support/SocialSearchQuery.php`.
- [ ] **Step 5:** `composer cs:fix && composer check && composer md && php bin/phpunit`; commit `refactor(#2369): the round turns a too-broad search into a feed failure`.

### Task 3: Mastodon's search is built in its search source

**Files:**
- Modify: `backend/src/Service/Social/Mastodon/MastodonSearchSource.php`, `backend/src/Service/Social/Mastodon/MastodonTimelineSource.php`
- Test: `backend/tests/Service/Social/Mastodon/MastodonSearchSourceTest.php`, `MastodonTimelineSourceTest.php`, and the test wiring that builds `MastodonTimelineSource`

**Interfaces:**
- Produces on `MastodonSearchSource`:
  - `buildRequests(SocialAccount $account, string $query, ?string $newestSeenId): array` — `@return non-empty-list<ApiRequestModel>` one per alternative, `@throws SearchQueryTooBroadException`.
  - `buildPage(array $accepted, string $query): TimelinePageModel` — `@param list<ApiResponseModel> $accepted` one per request, in order.
- `MastodonTimelineSource::__construct(MastodonAccountApi $mastodon, MastodonSearchSource $search)`; it no longer takes `MastodonPrivateFeedRule`.

- [ ] **Step 1:** Add to `MastodonSearchSourceTest`: `buildRequests()` for `(rss || atom)` with newest seen id `109` yields two requests whose URLs carry `min_id=109`, and with null carry none. Run. Expected: FAIL, method missing.
- [ ] **Step 2:** Write the two methods; `fetchNewest()` calls `buildRequests($account, $query, null)` and `buildPage()`. In the timeline source, `requests()` and `pageOf()` hand the `Search` kind to them, `searchPathsOf()` goes, and `pathOf()` lists `SocialFeedKind::Search` with `Author`, `CustomFeed`, `Local`, `All`.
- [ ] **Step 3:** Run `grep -n 'LogicException\|MastodonSearchPath\|MastodonSearchPage\|buildSearchQueries' backend/src/Service/Social/Mastodon/MastodonTimelineSource.php`. Expected: only the `A Mastodon timeline is read with one request.` line.
- [ ] **Step 4:** `composer cs:fix && composer check && composer md && php bin/phpunit`; commit `refactor(#2369): Mastodon's search is built in its search source`.

### Task 4: Lemmy's search is built in its search source

**Files:**
- Modify: `backend/src/Service/Social/Lemmy/LemmySearchSource.php`, `backend/src/Service/Social/Lemmy/LemmyTimelineSource.php`
- Test: `backend/tests/Service/Social/Lemmy/LemmySearchSourceTest.php`, `LemmyTimelineSourceTest.php`, and the test wiring that builds `LemmyTimelineSource`

**Interfaces:**
- Produces on `LemmySearchSource`:
  - `buildRequests(SocialAccount $account, string $query): array` — `@return non-empty-list<ApiRequestModel>` one per term, `@throws SearchQueryTooBroadException`.
  - `buildPage(SocialAccount $account, array $accepted, string $query): TimelinePageModel`.
- `LemmyTimelineSource::__construct(LemmyAccountApi $lemmy, LemmyPostEntries $entries, LemmySearchSource $search)`; it no longer takes `LemmyPrivateFeedRule`.

- [ ] **Step 1:** Add to `LemmySearchSourceTest`: `buildRequests()` for a two-term query yields two requests in term order. Run. Expected: FAIL.
- [ ] **Step 2:** Write the two methods; `fetchNewest()` uses them; the timeline source hands the `Search` kind to them and `searchTermsOf()` goes. `accepted()` keeps `acceptedInRound()`.
- [ ] **Step 3:** Run `grep -n 'buildSearchQueries\|searchPage\|searchGet' backend/src/Service/Social/Lemmy/LemmyTimelineSource.php`. Expected: no output.
- [ ] **Step 4:** `composer cs:fix && composer check && composer md && php bin/phpunit`; commit `refactor(#2369): Lemmy's search is built in its search source`.

### Task 5: Acceptance and PR

- [ ] **Step 1:** Run `php bin/phpunit tests/DependencyInjection` and `php bin/console lint:container`. Expected: green, no circular reference.
- [ ] **Step 2:** Run `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: green.
- [ ] **Step 3:** Scan today's `backend/var/log/dev-*.log`; run `/simplify` on the branch.
- [ ] **Step 4:** Push, open the PR into `develop` with `Closes #2369`. The body lists the three points where this plan departs from the issue text (Decisions 2 to 4) with their reasons. Merge when CI is green; verify #2369 closed.
