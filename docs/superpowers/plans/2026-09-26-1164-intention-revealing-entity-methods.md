# Intention-Revealing Entity Methods (#1164) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close #1164 in two PRs (ruling D2).
- **PR A** (Tasks 0–6, `Refs #1164`): Feed, User and EntryState own their state transitions and invariants through named methods. Services stop writing those transitions as setter sequences, and every setter that loses its last caller is deleted.
- **PR B** (Part B, Tasks B0–B4, `Closes #1164`): the issue's remaining findings in `UserPasskey`, `RecommendationRunLog`, `RecommendationRun` and `EntryMedium`/`EntryAttachment`.

**Architecture (PR A; Part B has its own):**
- **Feed fetch schedule.** `FetchSchedule` (the embeddable) gets the transitions: `recordSuccess`, `recordNewEntries`, `recordFailure`, `recordGone` and `scheduleNextFetchAt`. It also owns the 1000-character cap on the error message. `Feed` exposes them as `recordSuccessfulFetch()`, `recordNewEntries()`, `recordFailedFetch()`, `markGone()` and `scheduleNextFetchAt()`, and sets its own `FeedStatus` in the same call. `FeedScheduler` keeps the *policy*: the adaptive interval, the backoff, the 30-failure threshold and the throttle wait. All 17 schedule and status setters on `Feed` and `FetchSchedule` go.
- **Feed cache validators.** `Feed::recordCacheValidators(?string $etag, ?string $lastModified)` takes the pair and truncates it to the column lengths. The two identical `truncate()` helpers in `RefreshRunner` and `FirstFetchRecorder` go with `setEtag` and `setLastModified`.
- **User status.** `User::approve(\DateTimeImmutable)`, `queueForApproval()`, `reject()` and `suspend()` replace `setStatus` and `setApprovedAt`. "Active ⇒ approvedAt stamped" can no longer be forgotten. `User::isAdmin()` replaces the four `in_array('ROLE_ADMIN', …)` copies.
- **EntryState flags.** `markFavorite()`/`clearFavorite()` and `markKept()`/`clearKept()` join the existing `hide()`/`markUnread()`/`markViewed()`/`clearViewed()`. `setIsHidden`, `setHiddenAt`, `setIsFavorite` and `setIsKept` go. The backup restore writes the read mark byte-faithfully through a restore-only `restoreReadMark(BackedUpReadMark)` (ruling D3), and favourite/kept through the new methods. `EntryStateRepository::ensureRow()` takes one `?\DateTimeImmutable $hiddenSince` instead of a redundant bool and date pair.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM (embeddables, native lazy objects), PHPUnit 12 with DAMA DoctrineTestBundle, PHPStan level max with the custom rules in `backend/tests/PhpStan/`, PHPMD codesize, phptramp, Infection 0.34.

**Base:** every before-block, line number, signature and import was reconciled against 6ca53dd8, the head of #1184 (the last #1158 PR). Develop equals it once #1184 merges.

**Spec:**
- GitHub issue #1164 (`gh issue view 1164`).
- CLAUDE.md, "PHP code style — Clean Code is mandatory": names reveal intent, no boolean flag parameters, immutability by default, errors are exceptions, comments one line.

## Status (PR A)

| Task | State |
|---|---|
| Task 0: Preflight | ⬜ not started |
| Task 1: Feed records its fetch outcomes; the schedule setters go | ⬜ not started |
| Task 2: `Feed::recordCacheValidators()` | ⬜ not started |
| Task 3: `User` status transitions: `approve`, `queueForApproval`, `reject`, `suspend` | ⬜ not started |
| Task 4: `User::isAdmin()` | ⬜ not started |
| Task 5: `EntryState` favourite and kept methods; the raw flag setters go | ⬜ not started |
| Task 6: `ensureRow(…, ?\DateTimeImmutable $hiddenSince)` | ⬜ not started |

## Scope

| Issue bullet | Task |
|---|---|
| `Feed` forwards the 8 `FetchSchedule` fields as get/set pairs; the state machine lives in `FeedScheduler` as setter sequences. Also `BulkSubscriber`, `FirstFetchRecorder` and `CatalogController` write through setters. | 1 (schedule, status), 2 (the `FirstFetchRecorder` validator writes). `CatalogController:59` was `Response::setEtag()`, not `Feed`. It is out of scope, and #1157 PR B moved it into `src/Http/CatalogFaviconResponse.php:18`. |
| `User` approval is `setStatus(Active)` + `setApprovedAt($now)`, written out repeatedly | 3 (8 src sites, not 4: `UserLimits:63-64`, `BootstrapAdminProvisioner:40-41`, `RegistrationService:68-70,127-128` also do it) |
| `User::isAdmin()` missing; `in_array('ROLE_ADMIN', …)` ×4 | 4 |
| `EntryState` has two APIs; `E2eSeedAdminSubscriptionCommand:192` leaves a stale `hiddenAt`; `RestoreEntryLoader:118` uses raw setters | 5 |
| `ensureRow(…, bool $seedHidden, ?\DateTimeImmutable $seedHiddenAt)` | 6 |
| `UserPasskey` 9-parameter constructor | Part B, Task B1 |
| `RecommendationRunLog::finish()` takes 5 parameters | Part B, Task B2 |
| `RecommendationRun::recordTransportFailure(): bool` mutates and answers a query | Part B, Task B3 |
| `EntryMedium`/`EntryAttachment` name their input `$data` and coerce a missing `url`/`kind` to `''` | Part B, Task B4 (D6) |

## Setter inventory and fate

Every public setter on the three entities (and on the two embeddables they forward to), at 6ca53dd8 (develop once #1157 and #1158 have merged; the entity files are unchanged since 883ac759). Call sites are listed in Appendix A.

**Why no setter is kept "for Doctrine or forms":** Doctrine hydrates entities and embeddables through reflection, never through setters, and `backend/src` uses no Symfony Form (`git grep FormBuilderInterface|AbstractType|createForm` finds nothing). So no setter is needed by either. Each setter below is kept only for the domain reason given (ruling D1).

**The rule this plan applies:** a setter is replaced when it
- (a) is one half of fields that must change together,
- (b) writes a status of a state machine,
- (c) takes a boolean flag (#1167), or
- (d) makes every caller repeat a rule the entity can own.

A setter that writes one independent attribute with no invariant is kept.

### Feed and FetchSchedule

| Setter | Fate | Replaced by / reason kept |
|---|---|---|
| `Feed::setStatus` | **deleted** (b) | `recordSuccessfulFetch` → Active, `recordFailedFetch` → Erroring, `markGone` → Gone |
| `Feed::setLastFetchedAt`, `setLastSuccessfulFetchAt`, `setFetchIntervalMinutes`, `setConsecutiveFailures`, `setLastErrorMessage` | **deleted** (a) | `recordSuccessfulFetch(\DateTimeImmutable $fetchedAt, int $intervalMinutes)`, `recordFailedFetch(\DateTimeImmutable $failedAt, string $message, int $backoffMinutes)`, `markGone(\DateTimeImmutable $failedAt, string $message)` |
| `Feed::setLastNewEntryAt` | **deleted** | `recordNewEntries(\DateTimeImmutable $arrivedAt)` |
| `Feed::setNextFetchAt` | **deleted** (a) | `scheduleNextFetchAt(\DateTimeImmutable $nextFetchAt)`. It takes no null: only `markGone()` clears the next fetch. |
| `FetchSchedule::set*` (7) | **deleted** | `FetchSchedule::recordSuccess`, `recordNewEntries`, `recordFailure`, `recordGone`, `scheduleNextFetchAt` |
| `Feed::setEtag`, `setLastModified` | **deleted** (a)(d) | `recordCacheValidators(?string $etag, ?string $lastModified)`, which truncates to 512/255 |
| `Feed::setUrl` | kept | One independent attribute: an admin edit (`CatalogFeedEditor`) or an adopted permanent redirect (`RefreshRunner::applyPermanentRedirect`). The caller owns the uniqueness rule, which needs the repository. |
| `Feed::setSiteUrl`, `setTitle`, `setDescription`, `setFaviconUrl`, `setImageUrl`, `setSourceFormat` | kept | Descriptive metadata, each written field by field and conditionally from four independent sources: the publisher's document (`EntryIngestor`), the catalog (`CatalogImporter`, `CatalogFeedEditor`, `BulkSubscriber`), a backup (`RestoreLoadPass`) and discovery (`SubscriptionCreator`). No field constrains another. A renamed single-field mutator would only move the word "set". |

### User and AccountLimits

| Setter | Fate | Replaced by / reason kept |
|---|---|---|
| `User::setStatus`, `setApprovedAt` | **deleted** (a)(b) | `approve(\DateTimeImmutable $approvedAt)` (Active + stamp), `queueForApproval()`, `reject()`, `suspend()`. A suspension keeps the last grant's stamp. |
| `User::setPasswordHash(?string, \DateTimeImmutable)` | kept | Already intention-bearing: the mandatory `$changedAt` binds the hash to the JWT revocation stamp (its docblock). |
| `User::setRoles(list<string>)` | kept | The counterpart of `UserInterface::getRoles()`. The 39 `UserFactory` fixtures pass `roles: ['ROLE_ADMIN']`, and `UserSecurityTest` pins the list semantics. `isAdmin()` (Task 4) is the query the issue asks for. |
| `User::setLastLoginAt`, `setLocale` | kept | Independent attributes with one writer each in `src`. |
| `User::setTrialEndsAt`, `setMaxSubscriptions` (→ `AccountLimits`) | kept | Admin-set values where null means "none", written only by `UserLimits`. The status side of a trial (reactivate, suspend) moves to `approve()`/`suspend()` in Task 3. |
| `User::setActiveAiProviderSettings(?AiProviderSettings)` | kept | The pointer that `AiProviderConfigurator` alone owns. Its docblock records why. |

### EntryState

| Setter | Fate | Replaced by |
|---|---|---|
| `setIsHidden(bool)`, `setHiddenAt(?\DateTimeImmutable)` | **deleted** (a)(c) | the existing `hide(\DateTimeImmutable $when)` and `markUnread()` everywhere but the restore. `markUnread()` is the "unhide": it also clears "viewed", which `ViewedImpliesHiddenListener` would otherwise re-hide on flush. The backup restore uses `restoreReadMark(BackedUpReadMark $readMark)`, which writes the pair exactly as backed up (D3). |
| `setIsFavorite(bool)` | **deleted** (c) | `markFavorite()`, `clearFavorite()` |
| `setIsKept(bool)` | **deleted** (c) | `markKept()`, `clearKept()` |

## Rulings

The coordinator ruled on the draft's decisions on 2026-09-26. They are settled: do not re-open them during execution.

- **D1, accepted: 14 descriptive setters stay.** Doctrine hydrates by reflection, and the backend has no Symfony Form, so none of them is needed by either. Each stays for this domain reason:

  | Kept setter | Reason |
  |---|---|
  | `Feed::setUrl` | One independent attribute. It changes either through an admin edit (`CatalogFeedEditor`) or through an adopted permanent redirect (`RefreshRunner::applyPermanentRedirect`), and the caller owns the uniqueness check, which needs the repository. |
  | `Feed::setSiteUrl` | Publisher metadata. It is written conditionally, field by field, by `EntryIngestor`, `CatalogImporter`, `CatalogFeedEditor` and `RestoreLoadPass`, and no other field depends on it. |
  | `Feed::setTitle` | Publisher or catalog metadata. It has seven independent writers, and `BulkSubscriber` seeds it only on a new row. No invariant ties it to another field. |
  | `Feed::setDescription` | Publisher metadata with the same four writers as `setSiteUrl`. |
  | `Feed::setFaviconUrl` | Resolved separately from the feed document (`RefreshRunner::resolveMissingFavicons`) or restored from a backup. It is independent of every other field. |
  | `Feed::setImageUrl` | The feed's own logo from the document (`EntryIngestor`) or a backup. It is independent. |
  | `Feed::setSourceFormat` | Chosen once by discovery, the catalog or a backup. It carries no state machine. |
  | `User::setPasswordHash(?string, \DateTimeImmutable)` | Already intention-bearing: the mandatory `$changedAt` binds every hash change to the JWT revocation stamp. |
  | `User::setRoles(list<string>)` | The counterpart of `UserInterface::getRoles()`. The 39 `UserFactory` fixtures pass `roles: ['ROLE_ADMIN']`, and `UserSecurityTest` pins the list semantics. Task 4 adds the `isAdmin()` query. |
  | `User::setLastLoginAt` | One writer (`StampLastLoginOnTokenIssue`) and one independent attribute. |
  | `User::setLocale` | A user preference with three independent writers (registration, preferences, backup). |
  | `User::setTrialEndsAt` | An admin-set date, where null means no trial. It is written only by `UserLimits`. The status side of a trial now goes through `approve()`/`suspend()` (Task 3). |
  | `User::setMaxSubscriptions` | An admin-set cap, where null means unlimited. It is written only by `UserLimits`. |
  | `User::setActiveAiProviderSettings(?AiProviderSettings)` | The active-configuration pointer, which only `AiProviderConfigurator` writes. Its docblock records why callers must also update the loaded instance. |
- **D2, changed: two PRs.**
  - **PR A** is Tasks 0–6. Its body references the issue as `Refs #1164`, and no form of the word "closes" appears next to #1164 anywhere in it.
  - **PR B** is Part B of this file (Tasks B0–B4). It covers the issue's remaining bullets: `UserPasskey`'s constructor, `RecommendationRunLog::finish()`, `RecommendationRun::recordTransportFailure(): bool`, and `EntryMedium`/`EntryAttachment`. Its body says `Closes #1164`.
  - No follow-up issue.
- **D3, changed: the restore stays byte-faithful.** On develop, `RestoreEntryLoader` writes the backed-up `isHidden` and `hiddenAt` verbatim.
  - A line with `isHidden: true` and `hiddenAt: null` becomes a row that is read, with its read instant unknown. The entry stays off the unread list, and `EntryStateJson` reports `"hiddenAt": null`. Such rows go back to the schema's first migration (`Version20260721153011`), where `read_at` was already nullable beside `is_read`.
  - A line with `isHidden: false` and a `hiddenAt` keeps that stale instant.

  Task 5 keeps both cases exactly. It does so through a restore-only path, `EntryState::restoreReadMark(BackedUpReadMark $readMark)`, not through `hide()`/`markUnread()`, and pins them with a legacy-line test.
- **D4, accepted: fixtures become honest.**
  - `UserFactory` and the other fixtures reach "active" through `approve($createdAt)`. `AdminUserControllerTest:775`'s expectation changes only because the fixture now carries `approvedAt`. The endpoint, its serialisation and every production path are unchanged.
  - The E2E fixture feed is seeded as a full successful fetch, with `nextFetchAt` 60 minutes out.
- **D5 (FYI, unchanged).** `ensureRow`'s resolver seeds `hiddenSince = isHidden ? markedReadUntil : null`. A "hidden" row with a null watermark is reachable only if a concurrent request deletes the state row between the list query and the resolve. That row would reseed as unread.

- **D6, ruled: incomplete stored media are dropped (Task B4).** On develop, `EntryMedium::fromArray()`/`EntryAttachment::fromArray()` coerce a stored item without its URL (or a medium without its kind) to `''`, so the API serves `{"url": ""}`. Only a hand-edited backup can store one: `LineField::objectList()` checks shape, not keys, and `EntryBatchInserter` writes the list raw. Task B4 leaves such items out when the list is read. This is a deliberate change, stated in the Part B PR body as "media rows with no url/kind (only possible from hand-edited backups) are no longer served as url:''", and pinned by `EntryPartRestorerTest::testAStoredMediumOrAttachmentWithoutItsKeysIsLeftOutWhenRead`. Every complete item serialises byte-identically.
- **`UserPasskey`, ruled: no exception to the three-parameter rule.** Credential, label and registration time fold into one `PasskeyRegistration`, and the constructor takes `(User $user, PasskeyRegistration $registration)` (Task B1).

## Overlaps

- **#1167 (boolean flag parameters)** overlaps. This plan splits the three `EntryState` flag setters. `EntryStateUpdater` keeps `$request->isFavorite ? markFavorite() : clearFavorite()`: the request DTO's booleans are wire input, not flag parameters. The test helper `SearchMarkReadServiceTest::stateFor(Entry, bool $isHidden)` keeps its flag, and #1167 owns test-helper flags.
- **#1157 PR B (merged, 2b08b681)** added files and changed test code this plan edits. Tasks 3, 5 and B4 carry the blocks as they stand at 6ca53dd8:
  - `tests/Service/Subscription/SubscriptionTallyReaderTest.php:30-31` calls `setIsHidden(true)` and `setIsFavorite(true)`.
  - `tests/Http/AdminUserLimitsJsonTest.php:17` calls `setStatus(UserStatus::Active)`.
  - `EntryListRepository::oneRowForUser()` folded into the throwing `getOneRowForUser()`, so Task 5's new `EntryStateUpdaterTest` case uses the getter and drops its `assertNotNull`.
  - `tests/PhpStan/data/controller-mutates-no-entity-fixtures.php:215` calls `EntryAttachment::fromArray()` as the rule's "unmapped value object under `App\Entity`" negative case. Task B4 renames it with the method.
  - It moved `CatalogController`'s `Response::setEtag()` into `src/Http/CatalogFaviconResponse.php:18`. That call is not a `Feed` setter, and nothing here touches it.
- **#1158 (merged in #1183 and #1184, head 6ca53dd8)** edits no `src` file this plan edits, and no test file except `EntryListTest`, `RecommendationFeedTest`, `EntrySearchTest` and `IndexedEntrySearchTest` (cursor imports). Together with #1157 PR B that drifts lines in three files, which the blocks below follow: `EntryListTest` (+1, and the last site 1019 → 1067), `EntryStateResolverTest` (−1) and `RecommendationRunLogRepositoryTest` (+1). The request DTOs services still take (`EntryStateUpdater`'s `UpdateEntryStateRequest`, `AttestationVerifier`'s `RegisterPasskeyRequest`) are left to #1182, which #1158's Appendix C names. Task 5's two `EntryStateUpdater` lines and Task B1's `passkeyFrom()` edit survive that move.

## Global Constraints (PR A)

- **Paths and commands are relative to `backend/`** unless they start with `docs/` or `CLAUDE.md`.
- **No behaviour or wire-contract change.** D4 changes fixtures only.
  - Every response body, status code and header stays byte-identical.
  - The only changed expectation in an existing controller test is `AdminUserControllerTest:775`. It follows from D4's fixture change alone: the endpoint is unchanged. The mechanical setter replacements under `tests/Controller/` listed in Tasks 1, 3 and 5 are fixture edits, not contract edits.
- **Clean Code (CLAUDE.md) is mandatory.**
  - Names reveal intent.
  - No boolean flag parameters.
  - At most three parameters on a method.
  - Guard clauses over nesting.
  - New entity methods are called from services, commands and tests, never from a controller. `ControllerMutatesNoEntityRule` and its siblings `ControllerMutatesNoEntityThroughMethodCallableRule` and `ControllerMutatesNoEntityThroughStaticCallableRule` let a controller call, or take as a callable, only `get*`/`is*`/`has*`/`requireId()` on a Doctrine-mapped class (`Feed`, `User`, `EntryState` and the `FetchSchedule` embeddable included), and construct none. This plan edits no controller.
  - **Domain code knows no HTTP** (`DomainKnowsNoHttpRule`): the new `App\Entity` value objects and the exception reference no `App\Http\*` and no Symfony HttpFoundation or HTTP-exception class, not even as a class-name string.
  - Queries stay in `src/Repository` (`QueriesLiveInRepositoriesRule`): the `ensureRow` insert and the run-log settlement stay where they are.
  - None of the new value objects (`BackedUpReadMark`, `PasskeyRegistration`, `CallOutcome`) takes a Doctrine-mapped class in its constructor, so none needs #1158's `@noinspection AutowireWrongClass` suppression.
- **Comments:** one line, three at the absolute most, and only where a future reader would otherwise get the code wrong. A docblock or comment on a method or class this plan rewrites is trimmed to that bar. Docblocks on untouched members stay.
- **Tests read persisted ids with `requireId()`** (`EntityIdCoercionRule` covers `tests/`).
- **Every touched `src` file must be PHPMD-clean** under `composer md`. Fix the design, never the threshold.
- **PHPStan at level max:** no new baseline entries, no `@phpstan-ignore`.
- **Gates for every task:**
  - the task's own tests,
  - `composer check`,
  - `composer md`.
- **Branch-wide gates (Finishing):**
  - `composer check`,
  - `composer md`,
  - `php bin/phpunit`,
  - `docker compose exec php composer test`,
  - `composer infection:diff`.
- **Every new test gets a deletion check.** Delete or break the production line the test covers, run the test and see it fail, restore the line by hand with the Edit tool (never `git checkout --`), and paste both outputs into the task report.
- **Commit format:** `refactor(#1164): …`, one commit per task. Never commit to `develop`.
- **Branch:** `refactor/1164-intention-revealing-entities`, cut from `origin/develop`.

---

### Task 0: Preflight

**Files:** none changed.

- [ ] **Step 1: Check that the checkout is free.** Other sessions share this checkout.

Run: `git status --short && git branch --show-current`
Expected: a clean tree. If it is not clean, or another session's branch is checked out, stop and ask Lars. Do not stash, reset or check out over it.

- [ ] **Step 2: Confirm that #1157 PR B and #1158 have merged, and that #1164 is open.**

Run from the repository root:
```bash
git fetch origin develop
gh issue view 1157 --json state
gh issue view 1158 --json state
gh issue view 1164 --json state
git log --oneline origin/develop | grep -E "\(#1157\)|\(#1158\)" | head -5
git merge-base --is-ancestor 6ca53dd8 origin/develop && echo "1158 PR 2 on develop"
```
Expected: #1157 and #1158 `CLOSED`, #1164 `OPEN`, commits of both on `origin/develop`, and `1158 PR 2 on develop` (6ca53dd8 is the head of #1184, the last #1158 PR, which this plan was reconciled against). If either issue is still open or the ancestor check prints nothing, stop and tell Lars: this plan lands after both.

- [ ] **Step 3: Re-run the inventory sweeps.** The counts are develop's at 6ca53dd8, #1157 PR B's two test files included (see Overlaps).

Run from the repository root:
```bash
git grep -nE '\->(setLastFetchedAt|setLastSuccessfulFetchAt|setLastNewEntryAt|setNextFetchAt|setFetchIntervalMinutes|setConsecutiveFailures|setLastErrorMessage)\(' origin/develop -- backend/src backend/tests | wc -l
git grep -nE '\->setStatus\(' origin/develop -- backend/src backend/tests | wc -l
git grep -nE '\->setApprovedAt\(' origin/develop -- backend/src backend/tests | wc -l
git grep -nE '\->set(Etag|LastModified)\(' origin/develop -- backend/src backend/tests
git grep -nE "in_array\('ROLE_ADMIN'" origin/develop -- backend/src
git grep -nE '\->(setIsHidden|setIsFavorite|setIsKept|setHiddenAt)\(' origin/develop -- backend/src backend/tests | wc -l
git grep -nE 'ensureRow\(' origin/develop -- backend/src backend/tests | wc -l
```
Expected:
- 73 schedule-setter lines. 26 are in `src`: the 7 forwarders in `Entity/Feed.php`, 17 in `FeedScheduler`, and 1 each in `BulkSubscriber` and `E2eSeedAdminSubscriptionCommand`.
- 37 `setStatus(` lines: 8 on `Feed` and 29 on `User`, `AdminUserLimitsJsonTest:17` from #1157 PR B included.
- 9 `setApprovedAt(` lines.
- 8 `setEtag`/`setLastModified` lines. The `Response::setEtag()` line is `Http/CatalogFaviconResponse.php:18`, moved there from `Controller/Api/CatalogController.php` by #1157 PR B.
- 4 `in_array('ROLE_ADMIN'` lines: `UserRepository` ×3 and `AccountDeleter` ×1.
- 92 `EntryState` flag-setter lines, the two in `SubscriptionTallyReaderTest:30-31` included.
- 9 `ensureRow(` lines.

If a count differs, find the new or moved site, add its before/after block to the matching task in this plan (commit the plan amendment on the branch), and only then start that task.

- [ ] **Step 4: Re-verify every before-block.** For each file a task names under **Files**, run `git show origin/develop:backend/<path>` and compare it with that task's before-blocks. The blocks and the line numbers in `# line N` and `Before (lines …)` markers were re-verified at 6ca53dd8. If a before-block no longer matches, correct it in the plan first.

- [ ] **Step 5: Record a PHPMD baseline for every touched `src` file.**

Run:
```bash
cd backend && composer md 2>&1 | grep -E 'Entity/(Feed|FetchSchedule|User|EntryState)\.php|Service/FeedScheduler\.php|Refresh/RefreshRunner\.php|Subscription/(BulkSubscriber|FirstFetchRecorder)\.php|Command/E2eSeedAdmin(Subscription)?Command\.php|Admin/(UserStatusChanger|UserLimits)\.php|Auth/(BootstrapAdminProvisioner|RegistrationService)\.php|OAuth/OAuthAccountLinker\.php|Security/TrialExpiryGuard\.php|Repository/(UserRepository|EntryStateRepository)\.php|Account/AccountDeleter\.php|Reader/(EntryStateUpdater|EntryStateResolver)\.php|Backup/RestoreEntryLoader\.php' || echo CLEAN
```
Expected: `CLEAN`. If a touched file already has a finding, stop and ask Lars. The standing rule makes the touching task fix it, and that is a design change this plan does not cover.

- [ ] **Step 6: Create the branch.**

Run: `git switch -c refactor/1164-intention-revealing-entities origin/develop`

---
### Task 1: Feed records its fetch outcomes; the schedule setters go

**Files:**
- Modify: `src/Entity/FetchSchedule.php` (rewritten in full)
- Modify: `src/Entity/Feed.php:141-149, 161-229`
- Modify: `src/Service/FeedScheduler.php` (rewritten in full)
- Modify: `src/Service/Subscription/BulkSubscriber.php:108`
- Modify: `src/Command/E2eSeedAdminSubscriptionCommand.php:127-129`
- Test: `tests/Entity/FeedTest.php` (new tests), `tests/Service/FeedSchedulerTest.php` (rewritten in full), `tests/Service/Subscription/BulkSubscriberTest.php` (one new test)
- Test fixtures (setter replacements): `tests/Command/RefreshFeedsCommandTest.php`, `tests/Controller/Admin/AdminUserControllerTest.php`, `tests/Controller/MaintenanceControllerTest.php`, `tests/Http/SubscriptionJsonTest.php`, `tests/Repository/FeedRepositoryTest.php`, `tests/Repository/FeedRepositoryUserFeedScopeTest.php`, `tests/Service/Admin/UserStatisticsTest.php`, `tests/Service/Backup/AccountRestorerTest.php`, `tests/Service/Maintenance/MaintenanceTickTest.php`, `tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php`, `tests/Service/Refresh/RefreshRunnerTest.php`, `tests/Service/Subscription/SubscriptionServiceTest.php`, `tests/Service/Worker/RefreshDueFeedsHandlerTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces (later tasks and Task 2's `AccountRestorerTest` edit rely on these exact signatures):
  - `Feed::recordSuccessfulFetch(\DateTimeImmutable $fetchedAt, int $intervalMinutes): void` sets status Active, interval, failures 0, error null, lastFetchedAt = lastSuccessfulFetchAt = `$fetchedAt`, and nextFetchAt = `$fetchedAt` + interval.
  - `Feed::recordNewEntries(\DateTimeImmutable $arrivedAt): void` sets lastNewEntryAt only.
  - `Feed::recordFailedFetch(\DateTimeImmutable $failedAt, string $message, int $backoffMinutes): void` sets status Erroring, failures + 1, error capped at 1000 characters, lastFetchedAt = `$failedAt`, and nextFetchAt = `$failedAt` + backoff.
  - `Feed::markGone(\DateTimeImmutable $failedAt, string $message): void` sets status Gone, failures + 1, the capped error, lastFetchedAt = `$failedAt`, and nextFetchAt null.
  - `Feed::scheduleNextFetchAt(\DateTimeImmutable $nextFetchAt): void` sets nextFetchAt only.
  - `FetchSchedule::recordSuccess`, `recordNewEntries`, `recordFailure`, `recordGone` and `scheduleNextFetchAt` are the same transitions without the status. Only `Feed` calls them.

Behaviour stays identical. The one exception is D4's E2E seed: `recordFailure()` on the 30th failure is now exactly `markGone()`, which writes the same five fields the old gone branch wrote.

- [ ] **Step 1: Write the failing entity tests.** Append these methods to `tests/Entity/FeedTest.php`, before the class's closing `}`, and add `use App\Enum\FeedStatus;` after `use App\Entity\Feed;`.

```php
    public function testASuccessfulFetchEndsAFailureStreakAndSchedulesTheNextFetch(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->recordFailedFetch(new \DateTimeImmutable('2026-07-20 08:00:00'), 'HTTP 500', 45);
        $feed->recordFailedFetch(new \DateTimeImmutable('2026-07-20 09:00:00'), 'HTTP 502', 90);

        $feed->recordSuccessfulFetch(new \DateTimeImmutable('2026-07-21 10:00:00'), 35);

        self::assertSame(FeedStatus::Active, $feed->getStatus());
        self::assertSame(0, $feed->getConsecutiveFailures());
        self::assertNull($feed->getLastErrorMessage());
        self::assertSame(35, $feed->getFetchIntervalMinutes());
        self::assertEquals(new \DateTimeImmutable('2026-07-21 10:00:00'), $feed->getLastFetchedAt());
        self::assertEquals(new \DateTimeImmutable('2026-07-21 10:00:00'), $feed->getLastSuccessfulFetchAt());
        self::assertEquals(new \DateTimeImmutable('2026-07-21 10:35:00'), $feed->getNextFetchAt());
    }

    public function testAFailedFetchCountsTheFailureAndBacksOffWithoutClaimingSuccess(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->recordSuccessfulFetch(new \DateTimeImmutable('2026-07-19 07:00:00'), 20);
        $feed->recordFailedFetch(new \DateTimeImmutable('2026-07-20 08:00:00'), 'HTTP 500', 45);

        $feed->recordFailedFetch(new \DateTimeImmutable('2026-07-20 09:00:00'), 'HTTP 502', 90);

        self::assertSame(FeedStatus::Erroring, $feed->getStatus());
        self::assertSame(2, $feed->getConsecutiveFailures());
        self::assertSame('HTTP 502', $feed->getLastErrorMessage());
        self::assertSame(20, $feed->getFetchIntervalMinutes());
        self::assertEquals(new \DateTimeImmutable('2026-07-20 09:00:00'), $feed->getLastFetchedAt());
        self::assertEquals(new \DateTimeImmutable('2026-07-19 07:00:00'), $feed->getLastSuccessfulFetchAt());
        self::assertEquals(new \DateTimeImmutable('2026-07-20 10:30:00'), $feed->getNextFetchAt());
    }

    public function testAGoneFeedIsNeverScheduledAgain(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->recordFailedFetch(new \DateTimeImmutable('2026-07-20 08:00:00'), 'HTTP 500', 45);

        $feed->markGone(new \DateTimeImmutable('2026-07-21 10:00:00'), 'HTTP 410 Gone');

        self::assertSame(FeedStatus::Gone, $feed->getStatus());
        self::assertNull($feed->getNextFetchAt());
        self::assertSame(2, $feed->getConsecutiveFailures());
        self::assertSame('HTTP 410 Gone', $feed->getLastErrorMessage());
        self::assertEquals(new \DateTimeImmutable('2026-07-21 10:00:00'), $feed->getLastFetchedAt());
        self::assertNull($feed->getLastSuccessfulFetchAt());
    }

    public function testAFailureMessageIsCappedAtAThousandCharacters(): void
    {
        $feed = new Feed('https://example.com/feed.xml');

        $feed->recordFailedFetch(new \DateTimeImmutable('2026-07-20 08:00:00'), str_repeat('é', 1001), 45);
        self::assertSame(str_repeat('é', 1000), $feed->getLastErrorMessage());

        $feed->markGone(new \DateTimeImmutable('2026-07-21 10:00:00'), str_repeat('ü', 1001));
        self::assertSame(str_repeat('ü', 1000), $feed->getLastErrorMessage());
    }

    public function testNewEntriesStampOnlyTheirArrival(): void
    {
        $feed = new Feed('https://example.com/feed.xml');

        $feed->recordNewEntries(new \DateTimeImmutable('2026-07-21 10:00:00'));

        self::assertEquals(new \DateTimeImmutable('2026-07-21 10:00:00'), $feed->getLastNewEntryAt());
        self::assertNull($feed->getLastFetchedAt());
        self::assertNull($feed->getNextFetchAt());
    }

    public function testSchedulingTheNextFetchLeavesTheFeedsHealthAlone(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->recordFailedFetch(new \DateTimeImmutable('2026-07-20 08:00:00'), 'HTTP 500', 45);

        $feed->scheduleNextFetchAt(new \DateTimeImmutable('2026-07-20 08:01:30'));

        self::assertEquals(new \DateTimeImmutable('2026-07-20 08:01:30'), $feed->getNextFetchAt());
        self::assertSame(FeedStatus::Erroring, $feed->getStatus());
        self::assertSame(1, $feed->getConsecutiveFailures());
        self::assertSame('HTTP 500', $feed->getLastErrorMessage());
    }
```

- [ ] **Step 2: Run them to verify they fail.**

Run: `php bin/phpunit tests/Entity/FeedTest.php`
Expected: FAIL with `Call to undefined method App\Entity\Feed::recordFailedFetch()` (and the siblings).

- [ ] **Step 3: Rewrite `src/Entity/FetchSchedule.php`.** The class docblock and two field docblocks pointed at `FeedScheduler` methods that no longer write these fields. They are trimmed to one line each (Global Constraints, comments).

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A feed's fetch-schedule state, embedded into Feed with unprefixed columns so the table is unchanged.
 */
#[ORM\Embeddable]
class FetchSchedule
{
    private const int ERROR_MESSAGE_MAX = 1000;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    /** When a fetch last delivered; unlike lastFetchedAt, a failed or gone attempt leaves it alone. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSuccessfulFetchAt = null;

    /** When a fetch last brought new entries, the reader's "last updated"; an empty 200 leaves it alone. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastNewEntryAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextFetchAt = null;

    #[ORM\Column]
    private int $fetchIntervalMinutes = 60;

    #[ORM\Column]
    private int $consecutiveFailures = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastErrorMessage = null;

    public function getLastFetchedAt(): ?\DateTimeImmutable
    {
        return $this->lastFetchedAt;
    }

    public function getLastSuccessfulFetchAt(): ?\DateTimeImmutable
    {
        return $this->lastSuccessfulFetchAt;
    }

    public function getLastNewEntryAt(): ?\DateTimeImmutable
    {
        return $this->lastNewEntryAt;
    }

    public function getNextFetchAt(): ?\DateTimeImmutable
    {
        return $this->nextFetchAt;
    }

    public function getFetchIntervalMinutes(): int
    {
        return $this->fetchIntervalMinutes;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    /**
     * @throws \DateMalformedStringException
     */
    public function recordSuccess(\DateTimeImmutable $fetchedAt, int $intervalMinutes): void
    {
        $this->fetchIntervalMinutes = $intervalMinutes;
        $this->consecutiveFailures = 0;
        $this->lastErrorMessage = null;
        $this->lastFetchedAt = $fetchedAt;
        $this->lastSuccessfulFetchAt = $fetchedAt;
        $this->nextFetchAt = $fetchedAt->modify(sprintf('+%d minutes', $intervalMinutes));
    }

    public function recordNewEntries(\DateTimeImmutable $arrivedAt): void
    {
        $this->lastNewEntryAt = $arrivedAt;
    }

    /**
     * @throws \DateMalformedStringException
     */
    public function recordFailure(\DateTimeImmutable $failedAt, string $message, int $backoffMinutes): void
    {
        $this->countFailedAttempt($failedAt, $message);
        $this->nextFetchAt = $failedAt->modify(sprintf('+%d minutes', $backoffMinutes));
    }

    public function recordGone(\DateTimeImmutable $failedAt, string $message): void
    {
        $this->countFailedAttempt($failedAt, $message);
        $this->nextFetchAt = null;
    }

    public function scheduleNextFetchAt(\DateTimeImmutable $nextFetchAt): void
    {
        $this->nextFetchAt = $nextFetchAt;
    }

    private function countFailedAttempt(\DateTimeImmutable $failedAt, string $message): void
    {
        ++$this->consecutiveFailures;
        $this->lastErrorMessage = mb_substr($message, 0, self::ERROR_MESSAGE_MAX);
        $this->lastFetchedAt = $failedAt;
    }
}
```

- [ ] **Step 4: Replace the status setter and the schedule forwarders in `src/Entity/Feed.php`.**

Before (lines 141-149):
```php
    public function getStatus(): FeedStatus
    {
        return $this->status;
    }

    public function setStatus(FeedStatus $status): void
    {
        $this->status = $status;
    }
```
After:
```php
    public function getStatus(): FeedStatus
    {
        return $this->status;
    }
```

Before (lines 161-229):
```php
    public function getLastFetchedAt(): ?\DateTimeImmutable
    {
        return $this->fetchSchedule->getLastFetchedAt();
    }

    public function setLastFetchedAt(?\DateTimeImmutable $lastFetchedAt): void
    {
        $this->fetchSchedule->setLastFetchedAt($lastFetchedAt);
    }

    public function getLastSuccessfulFetchAt(): ?\DateTimeImmutable
    {
        return $this->fetchSchedule->getLastSuccessfulFetchAt();
    }

    public function setLastSuccessfulFetchAt(?\DateTimeImmutable $lastSuccessfulFetchAt): void
    {
        $this->fetchSchedule->setLastSuccessfulFetchAt($lastSuccessfulFetchAt);
    }

    public function getLastNewEntryAt(): ?\DateTimeImmutable
    {
        return $this->fetchSchedule->getLastNewEntryAt();
    }

    public function setLastNewEntryAt(?\DateTimeImmutable $lastNewEntryAt): void
    {
        $this->fetchSchedule->setLastNewEntryAt($lastNewEntryAt);
    }

    public function getNextFetchAt(): ?\DateTimeImmutable
    {
        return $this->fetchSchedule->getNextFetchAt();
    }

    public function setNextFetchAt(?\DateTimeImmutable $nextFetchAt): void
    {
        $this->fetchSchedule->setNextFetchAt($nextFetchAt);
    }

    public function getFetchIntervalMinutes(): int
    {
        return $this->fetchSchedule->getFetchIntervalMinutes();
    }

    public function setFetchIntervalMinutes(int $minutes): void
    {
        $this->fetchSchedule->setFetchIntervalMinutes($minutes);
    }

    public function getConsecutiveFailures(): int
    {
        return $this->fetchSchedule->getConsecutiveFailures();
    }

    public function setConsecutiveFailures(int $consecutiveFailures): void
    {
        $this->fetchSchedule->setConsecutiveFailures($consecutiveFailures);
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->fetchSchedule->getLastErrorMessage();
    }

    public function setLastErrorMessage(?string $lastErrorMessage): void
    {
        $this->fetchSchedule->setLastErrorMessage($lastErrorMessage);
    }
```
After:
```php
    public function getLastFetchedAt(): ?\DateTimeImmutable
    {
        return $this->fetchSchedule->getLastFetchedAt();
    }

    public function getLastSuccessfulFetchAt(): ?\DateTimeImmutable
    {
        return $this->fetchSchedule->getLastSuccessfulFetchAt();
    }

    public function getLastNewEntryAt(): ?\DateTimeImmutable
    {
        return $this->fetchSchedule->getLastNewEntryAt();
    }

    public function getNextFetchAt(): ?\DateTimeImmutable
    {
        return $this->fetchSchedule->getNextFetchAt();
    }

    public function getFetchIntervalMinutes(): int
    {
        return $this->fetchSchedule->getFetchIntervalMinutes();
    }

    public function getConsecutiveFailures(): int
    {
        return $this->fetchSchedule->getConsecutiveFailures();
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->fetchSchedule->getLastErrorMessage();
    }

    /**
     * @throws \DateMalformedStringException
     */
    public function recordSuccessfulFetch(\DateTimeImmutable $fetchedAt, int $intervalMinutes): void
    {
        $this->status = FeedStatus::Active;
        $this->fetchSchedule->recordSuccess($fetchedAt, $intervalMinutes);
    }

    public function recordNewEntries(\DateTimeImmutable $arrivedAt): void
    {
        $this->fetchSchedule->recordNewEntries($arrivedAt);
    }

    /**
     * @throws \DateMalformedStringException
     */
    public function recordFailedFetch(\DateTimeImmutable $failedAt, string $message, int $backoffMinutes): void
    {
        $this->status = FeedStatus::Erroring;
        $this->fetchSchedule->recordFailure($failedAt, $message, $backoffMinutes);
    }

    public function markGone(\DateTimeImmutable $failedAt, string $message): void
    {
        $this->status = FeedStatus::Gone;
        $this->fetchSchedule->recordGone($failedAt, $message);
    }

    public function scheduleNextFetchAt(\DateTimeImmutable $nextFetchAt): void
    {
        $this->fetchSchedule->scheduleNextFetchAt($nextFetchAt);
    }
```

- [ ] **Step 5: Run the entity tests to verify they pass.**

Run: `php bin/phpunit tests/Entity/FeedTest.php`
Expected: PASS.

- [ ] **Step 6: Rewrite `src/Service/FeedScheduler.php`.** The policy stays: the interval, backoff, gone threshold and throttle arithmetic are unchanged. The writes become entity calls, `ERROR_MESSAGE_MAX` moves to `FetchSchedule`, and the `FeedStatus` import goes. The class docblock said the scheduler "owns all fetch-schedule state transitions on Feed", which is no longer true, so it is reworded. `recordSuccess()` and `recordThrottled()` are rewritten, so their comments (6 and 9 lines) are trimmed to the three-line bar (Global Constraints, comments). Each keeps its hard-won warning: the floor reset and floor guard (#643), and why a 429 moves only `nextFetchAt` (#290, the manual-refresh cooldown).

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Feed;
use App\Service\Fetch\HostThrottle;
use Symfony\Component\Clock\ClockInterface;

/**
 * Decides Feed's fetch schedule: adaptive interval on success, a wait on a rationed request,
 * exponential backoff on failure, and when a failing feed is gone.
 */
final class FeedScheduler
{
    private const int FLOOR_MINUTES = 5;
    private const int CEILING_MINUTES = 120;       // 2 h
    private const int FAILURE_CAP_MINUTES = 10080; // 7 days
    private const int FAILURES_UNTIL_GONE = 30;
    private const int MAX_BACKOFF_EXPONENT = 9;
    private const int SECONDS_PER_MINUTE = 60;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly HostThrottle $hostThrottle,
    ) {
    }

    /**
     * @throws \DateMalformedStringException
     */
    public function recordSuccess(Feed $feed, int $newEntryCount): void
    {
        // New entries reset to the floor at once, not by halving, or a burst blocks the top of All items (#643).
        // The grow branch keeps the floor guard: a stored interval <= 0 would otherwise refetch the feed every run.
        $interval = $newEntryCount > 0
            ? self::FLOOR_MINUTES
            : max(
                self::FLOOR_MINUTES,
                min(self::CEILING_MINUTES, (int) round($feed->getFetchIntervalMinutes() * 1.5)),
            );

        $now = $this->clock->now();
        $feed->recordSuccessfulFetch($now, $interval);
        if ($newEntryCount > 0) {
            $feed->recordNewEntries($now);
        }
    }

    /**
     * A 429 rations, it does not fail: only nextFetchAt moves, or one 429 silences a working feed for hours (#290).
     * lastFetchedAt stays too: the manual refresh's cooldown reads it, and stamping it would block a retry by hand.
     *
     * @throws \DateMalformedStringException
     */
    public function recordThrottled(Feed $feed, ?int $retryAfterSeconds): void
    {
        $hostWait = $this->hostThrottle->record($feed->getUrl(), $retryAfterSeconds);
        // Reddit resets in seconds, so only this feed, not the whole host, waits out its own cadence.
        $wait = $retryAfterSeconds === null ? max($hostWait, $this->cadenceSeconds($feed)) : $hostWait;

        $feed->scheduleNextFetchAt($this->clock->now()->modify(sprintf('+%d seconds', $wait)));
    }

    private function cadenceSeconds(Feed $feed): int
    {
        return min(HostThrottle::MAXIMUM_WAIT_SECONDS, $feed->getFetchIntervalMinutes() * self::SECONDS_PER_MINUTE);
    }

    /**
     * @throws \DateMalformedStringException
     */
    public function recordFailure(Feed $feed, string $message): void
    {
        $failures = $feed->getConsecutiveFailures() + 1;
        $now = $this->clock->now();

        if ($failures >= self::FAILURES_UNTIL_GONE) {
            $feed->markGone($now, $message);

            return;
        }

        $feed->recordFailedFetch($now, $message, $this->backoffMinutes($feed, $failures));
    }

    public function recordGone(Feed $feed, string $message): void
    {
        $feed->markGone($this->clock->now(), $message);
    }

    private function backoffMinutes(Feed $feed, int $failures): int
    {
        return (int) min(
            self::FAILURE_CAP_MINUTES,
            max($feed->getFetchIntervalMinutes(), self::FLOOR_MINUTES)
                * (2 ** min($failures, self::MAX_BACKOFF_EXPONENT)),
        );
    }
}
```

- [ ] **Step 7: Rewrite `tests/Service/FeedSchedulerTest.php`.** Every test keeps its name, its act and its assertions. Besides the arrangement, only the one comment over three lines (the #384 docblock) changes: it is trimmed to two. The arrangement changes like this:
  - An interval is now set up by a successful fetch earlier on the same morning (`EARLIER`).
  - A failure streak is now set up by that many recorded failures (`failedTimes()`).

  The failure-streak tests keep their original counts: 5, 10 and 29. A streak of 1 would let a "decrement instead of reset" mutant pass.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Feed;
use App\Enum\FeedStatus;
use App\Service\Fetch\HostThrottle;
use App\Service\FeedScheduler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class FeedSchedulerTest extends TestCase
{
    private const string EARLIER = '2026-07-21 06:00:00';

    private MockClock $clock;
    private HostThrottle $hostThrottle;
    private FeedScheduler $scheduler;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-07-21 12:00:00', 'UTC');
        $this->hostThrottle = new HostThrottle(new ArrayAdapter(clock: $this->clock), $this->clock);
        $this->scheduler = new FeedScheduler($this->clock, $this->hostThrottle);
    }

    public function testSuccessWithNewEntriesResetsIntervalToFloor(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->recordSuccessfulFetch(new \DateTimeImmutable(self::EARLIER), 120);

        $this->scheduler->recordSuccess($feed, 3);

        // A source that shows life is polled at the floor at once, so the rest
        // of a burst interleaves instead of blocking the top of All items (#643).
        self::assertSame(5, $feed->getFetchIntervalMinutes());
        self::assertSame(0, $feed->getConsecutiveFailures());
        self::assertSame(FeedStatus::Active, $feed->getStatus());
        self::assertSame('2026-07-21 12:00:00', $feed->getLastFetchedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-21 12:00:00', $feed->getLastSuccessfulFetchAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-21 12:05:00', $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));

        $feed->recordSuccessfulFetch(new \DateTimeImmutable(self::EARLIER), 8);
        $this->scheduler->recordSuccess($feed, 1);
        self::assertSame(5, $feed->getFetchIntervalMinutes());
    }

    public function testNewEntriesStampTheLastNewEntryTime(): void
    {
        $feed = new Feed('https://example.com/feed');

        $this->scheduler->recordSuccess($feed, 2);

        self::assertSame('2026-07-21 12:00:00', $feed->getLastNewEntryAt()?->format('Y-m-d H:i:s'));
    }

    public function testASuccessWithNoNewEntriesLeavesTheLastNewEntryTimeUntouched(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->recordNewEntries(new \DateTimeImmutable('2026-07-20 08:00:00'));

        // A 200 that carried nothing new is a successful fetch, but not an
        // update: the "last new content" mark must not advance on it.
        $this->scheduler->recordSuccess($feed, 0);

        self::assertSame('2026-07-20 08:00:00', $feed->getLastNewEntryAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-21 12:00:00', $feed->getLastSuccessfulFetchAt()?->format('Y-m-d H:i:s'));
    }

    public function testAThrottleCostsTheFeedNothingButItsPlaceInTheQueue(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->recordSuccessfulFetch(new \DateTimeImmutable('2026-07-21 11:00:00'), 60);

        $this->scheduler->recordThrottled($feed, 90);

        self::assertSame('2026-07-21 12:01:30', $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));
        // The feed is healthy; we asked too often. Counting this as a failure
        // would set the erroring status and an hours-long backoff.
        self::assertSame(0, $feed->getConsecutiveFailures());
        self::assertSame(FeedStatus::Active, $feed->getStatus());
        self::assertSame(60, $feed->getFetchIntervalMinutes());
        self::assertNull($feed->getLastErrorMessage());
        // Untouched, so the manual refresh's cooldown still measures the last
        // time content actually arrived.
        self::assertSame('2026-07-21 11:00:00', $feed->getLastFetchedAt()?->format('Y-m-d H:i:s'));
    }

    /**
     * @return iterable<string, array{int|null, int, string}>
     */
    public static function throttleWaits(): iterable
    {
        yield 'the delay the site asked for' => [90, 60, '2026-07-21 12:01:30'];
        // Below a minute the retry would only draw a second 429, and a
        // multi-day wait is a feed nobody would see refresh again.
        yield 'a delay too short to help' => [2, 60, '2026-07-21 12:01:00'];
        yield 'a delay longer than a day' => [7 * 24 * 3600, 60, '2026-07-22 12:00:00'];
        yield 'no delay named' => [null, 60, '2026-07-21 13:00:00'];
        // Never sooner than the feed's own cadence: a daily feed that hits one
        // 429 must not be polled every quarter hour until it answers.
        yield 'no delay named, on a daily feed' => [null, 1440, '2026-07-22 12:00:00'];
    }

    #[DataProvider('throttleWaits')]
    public function testAThrottleWaitIsBoundedAtBothEnds(
        ?int $retryAfterSeconds,
        int $intervalMinutes,
        string $expectedNextFetch,
    ): void {
        $feed = new Feed('https://example.com/feed');
        $feed->recordSuccessfulFetch(new \DateTimeImmutable(self::EARLIER), $intervalMinutes);

        $this->scheduler->recordThrottled($feed, $retryAfterSeconds);

        self::assertSame($expectedNextFetch, $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }

    public function testThrottlingRecordsAHostThrottleForTheWholeHost(): void
    {
        $feed = new Feed('https://www.reddit.com/r/PHP/.rss');
        $feed->recordSuccessfulFetch(new \DateTimeImmutable(self::EARLIER), 60);

        $this->scheduler->recordThrottled($feed, 90);

        self::assertSame(90, $this->hostThrottle->remainingSeconds('https://www.reddit.com/r/x/comments/1/.rss'));
    }

    public function testAThrottleWithNoNamedDelayRationsTheHostForTheFloorOnly(): void
    {
        $feed = new Feed('https://www.reddit.com/r/PHP/.rss');
        $feed->recordSuccessfulFetch(new \DateTimeImmutable(self::EARLIER), 120);

        $this->scheduler->recordThrottled($feed, null);

        self::assertSame(
            HostThrottle::MINIMUM_WAIT_SECONDS,
            $this->hostThrottle->remainingSeconds('https://www.reddit.com/r/x/comments/1/.rss'),
        );
        self::assertSame('2026-07-21 14:00:00', $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }

    public function testQuietSuccessGrowsIntervalUpToCeiling(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->recordSuccessfulFetch(new \DateTimeImmutable(self::EARLIER), 60);

        $this->scheduler->recordSuccess($feed, 0);
        self::assertSame(90, $feed->getFetchIntervalMinutes());

        // The grow-on-empty branch is capped at 2 h, so the first fetch after a
        // quiet spell cannot accumulate more than that (#643).
        $feed->recordSuccessfulFetch(new \DateTimeImmutable(self::EARLIER), 300);
        $this->scheduler->recordSuccess($feed, 0);
        self::assertSame(120, $feed->getFetchIntervalMinutes());
    }

    public function testCorruptedIntervalCannotScheduleInThePast(): void
    {
        foreach ([0, -120] as $corrupted) {
            $feed = new Feed('https://example.com/feed');
            $feed->recordSuccessfulFetch(new \DateTimeImmutable(self::EARLIER), $corrupted);

            $this->scheduler->recordSuccess($feed, 0);

            self::assertSame(5, $feed->getFetchIntervalMinutes());
            self::assertGreaterThan($this->clock->now(), $feed->getNextFetchAt());
        }
    }

    public function testSuccessClearsPreviousFailureState(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->failedTimes($feed, 5);

        $this->scheduler->recordSuccess($feed, 0);

        self::assertSame(0, $feed->getConsecutiveFailures());
        self::assertNull($feed->getLastErrorMessage());
        self::assertSame(FeedStatus::Active, $feed->getStatus());
    }

    public function testFailureBacksOffExponentially(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->recordSuccessfulFetch(new \DateTimeImmutable(self::EARLIER), 60);

        $this->scheduler->recordFailure($feed, 'timeout');

        self::assertSame(1, $feed->getConsecutiveFailures());
        self::assertSame(FeedStatus::Erroring, $feed->getStatus());
        self::assertSame('timeout', $feed->getLastErrorMessage());
        // 60 * 2^1 = 120 minutes
        self::assertSame('2026-07-21 14:00:00', $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));

        $this->scheduler->recordFailure($feed, 'timeout again');
        // 60 * 2^2 = 240 minutes
        self::assertSame('2026-07-21 16:00:00', $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }

    public function testBackoffIsCappedAtSevenDays(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->recordSuccessfulFetch(new \DateTimeImmutable(self::EARLIER), 1440);
        $this->failedTimes($feed, 10);

        $this->scheduler->recordFailure($feed, 'still broken');

        $cap = $this->clock->now()->modify('+10080 minutes');
        self::assertSame($cap->format('Y-m-d H:i:s'), $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }

    public function testThirtiethFailureMarksFeedGone(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->failedTimes($feed, 29);

        $this->scheduler->recordFailure($feed, 'the end');

        self::assertSame(FeedStatus::Gone, $feed->getStatus());
        self::assertSame(30, $feed->getConsecutiveFailures());
        self::assertNull($feed->getNextFetchAt());
    }

    public function testLongErrorMessageIsTruncated(): void
    {
        $feed = new Feed('https://example.com/feed');

        $this->scheduler->recordFailure($feed, str_repeat('x', 5000));

        self::assertSame(1000, mb_strlen((string) $feed->getLastErrorMessage()));
    }

    public function testRecordGone(): void
    {
        $feed = new Feed('https://example.com/feed');

        $this->scheduler->recordGone($feed, 'HTTP 410 Gone');

        self::assertSame(FeedStatus::Gone, $feed->getStatus());
        self::assertNull($feed->getNextFetchAt());
        self::assertSame('HTTP 410 Gone', $feed->getLastErrorMessage());
    }

    /**
     * #384: only a delivered fetch advances lastSuccessfulFetchAt. A failed or gone attempt still stamps
     * lastFetchedAt, which the manual-refresh cooldown needs, but says nothing about what the feed served.
     */
    public function testOnlyRecordSuccessAdvancesLastSuccessfulFetchAt(): void
    {
        $failed = new Feed('https://failed.example.com/feed');
        $this->scheduler->recordFailure($failed, 'timeout');
        self::assertNotNull($failed->getLastFetchedAt());
        self::assertNull($failed->getLastSuccessfulFetchAt());

        $gone = new Feed('https://gone.example.com/feed');
        $this->scheduler->recordGone($gone, 'HTTP 410 Gone');
        self::assertNotNull($gone->getLastFetchedAt());
        self::assertNull($gone->getLastSuccessfulFetchAt());
    }

    private function failedTimes(Feed $feed, int $failures): void
    {
        for ($attempt = 0; $attempt < $failures; ++$attempt) {
            $feed->recordFailedFetch(new \DateTimeImmutable(self::EARLIER), 'boom', 60);
        }
    }
}
```

The corrupted-interval arrangement builds `'+-120 minutes'`, which PHP's relative-time parser reads as −120 minutes. If `modify()` rejects it on the runner's PHP, report it and switch that case to `-1`. Do not add a guard to the entity: the scheduler's floor is what the test proves.

- [ ] **Step 8: Replace the schedule setter in `src/Service/Subscription/BulkSubscriber.php`.**

```diff
# line 108
-            $feed->setNextFetchAt($this->clock->now()); // due now → next refresh populates it
+            $feed->scheduleNextFetchAt($this->clock->now()); // due now → next refresh populates it
```

Add this test to `tests/Service/Subscription/BulkSubscriberTest.php`, after `testSeedsTheFeedTitleOnlyWhenTheSharedFeedRowIsNew()`. No other test pins the line, so without it the line's removal is an escaped mutant.

```php
    public function testANewFeedIsScheduledForTheNextRefresh(): void
    {
        $user = $this->user('due@example.com');

        $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItem('https://due.example.com/rss.xml', 'Due Feed', null, null),
        ]);

        $feed = $this->em()->getRepository(Feed::class)->findOneBy(['url' => 'https://due.example.com/rss.xml']);
        self::assertNotNull($feed);
        self::assertNotNull($feed->getNextFetchAt());
    }
```

- [ ] **Step 9: Replace the seed's fetch stamp in `src/Command/E2eSeedAdminSubscriptionCommand.php`** (D4). The stored interval is the feed's default 60, so the next fetch lands an hour out instead of "due now".

Before (lines 127-129):
```php
        // Already fetched, so the reader skips its post-onboarding refresh sweep
        // over a host that never answers.
        $feed->setLastFetchedAt($this->clock->now());
```
After:
```php
        // Already fetched, so the reader skips its post-onboarding refresh sweep
        // over a host that never answers.
        $feed->recordSuccessfulFetch($this->clock->now(), $feed->getFetchIntervalMinutes());
```

`E2eSeedAdminSubscriptionCommandTest:110` (`assertNotNull($feed->getLastFetchedAt())`) keeps covering it.

- [ ] **Step 10: Replace every schedule and status setter in the test fixtures.**

`tests/Command/RefreshFeedsCommandTest.php`:
```diff
# line 37
-        $feed->setNextFetchAt(new \DateTimeImmutable('-1 hour'));
+        $feed->scheduleNextFetchAt(new \DateTimeImmutable('-1 hour'));
```

`tests/Controller/MaintenanceControllerTest.php`:
```diff
# line 30
-        $feed->setNextFetchAt(new \DateTimeImmutable('-1 hour'));
+        $feed->scheduleNextFetchAt(new \DateTimeImmutable('-1 hour'));
```

`tests/Service/Worker/RefreshDueFeedsHandlerTest.php`:
```diff
# line 43
-        $feed->setNextFetchAt(new \DateTimeImmutable('-1 hour'));
+        $feed->scheduleNextFetchAt(new \DateTimeImmutable('-1 hour'));
```

`tests/Service/Maintenance/MaintenanceTickTest.php`:
```diff
# line 129
-        $feed->setNextFetchAt($clock->now()->modify('-1 hour'));
+        $feed->scheduleNextFetchAt($clock->now()->modify('-1 hour'));
```

`tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php`:
```diff
# line 97
-        $feed->setNextFetchAt($this->clock->now()->modify('-1 hour'));
+        $feed->scheduleNextFetchAt($this->clock->now()->modify('-1 hour'));
```

`tests/Controller/Admin/AdminUserControllerTest.php`: the footprint reads `lastFetchedAt`, which a successful fetch stamps identically.
```diff
# line 846
-        $feed->setLastFetchedAt($lastFetch);
+        $feed->recordSuccessfulFetch($lastFetch, 60);
```

`tests/Service/Admin/UserStatisticsTest.php`:

Before (line 41):
```php
            $feed->setLastFetchedAt(null === $stamp ? null : new \DateTimeImmutable($stamp));
```
After:
```php
            if (null !== $stamp) {
                $feed->recordSuccessfulFetch(new \DateTimeImmutable($stamp), 60);
            }
```

`tests/Service/Subscription/SubscriptionServiceTest.php`: `fetchedAt` plus a 60-minute interval is exactly the `+1 hour` the test stored before.

Before (lines 185-186):
```php
        $shared->setLastFetchedAt($fetchedAt);
        $shared->setNextFetchAt($fetchedAt->modify('+1 hour'));
```
After:
```php
        $shared->recordSuccessfulFetch($fetchedAt, 60);
```

`tests/Service/Backup/AccountRestorerTest.php`: more seeded bookkeeping makes "the restore drops it" stronger. Task 2 replaces the `setEtag` line directly above.
```diff
# line 222
-        $feed->setLastFetchedAt(new \DateTimeImmutable('2026-08-10 07:00:00'));
+        $feed->recordSuccessfulFetch(new \DateTimeImmutable('2026-08-10 07:00:00'), 60);
```

`tests/Http/SubscriptionJsonTest.php`:

```diff
# line 159
-        $feed->setLastFetchedAt(new \DateTimeImmutable('2026-02-04T10:11:12Z'));
+        $feed->recordSuccessfulFetch(new \DateTimeImmutable('2026-02-04T10:11:12Z'), 60);
```

Before (lines 234-237):
```php
        $feed->setStatus(\App\Enum\FeedStatus::Erroring);
        $feed->setLastSuccessfulFetchAt(new \DateTimeImmutable('2026-01-28T09:00:00Z'));
        $feed->setConsecutiveFailures(4);
        $feed->setLastErrorMessage('https://example.com/feed.xml: HTTP 500');
```
After:
```php
        $feed->recordSuccessfulFetch(new \DateTimeImmutable('2026-01-28T09:00:00Z'), 60);
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $feed->recordFailedFetch(
                new \DateTimeImmutable('2026-02-03T04:00:00Z'),
                'https://example.com/feed.xml: HTTP 500',
                60,
            );
        }
```

```diff
# line 261
-        $feed->setNextFetchAt(new \DateTimeImmutable('2026-02-04T11:00:00Z'));
+        $feed->scheduleNextFetchAt(new \DateTimeImmutable('2026-02-04T11:00:00Z'));
# line 272
-        $feed->setNextFetchAt(null);
+        $feed->markGone(new \DateTimeImmutable('2026-02-04T10:00:00Z'), 'HTTP 410 Gone');
# line 280
-        $feed->setLastNewEntryAt(new \DateTimeImmutable('2026-02-04T10:00:00Z'));
+        $feed->recordNewEntries(new \DateTimeImmutable('2026-02-04T10:00:00Z'));
```

The `markGone` line matches its test's name, `testAGoneFeedReportsANullNextFetchTime`: before, it built a null next fetch on a feed that was not gone.

`tests/Repository/FeedRepositoryUserFeedScopeTest.php`: remove `use App\Enum\FeedStatus;`, which is now unused, and:
```diff
# line 64
-        $feed->setStatus(FeedStatus::Gone);
+        $feed->markGone(new \DateTimeImmutable('2026-01-01T00:00:00Z'), 'HTTP 410 Gone');
```

`tests/Repository/FeedRepositoryTest.php`: the helper's `FeedStatus` parameter only ever received `Gone`, so a separate helper replaces it. Remove `use App\Enum\FeedStatus;`, which is now unused.

Before (lines 29-37):
```php
    private function feed(string $url, ?\DateTimeImmutable $nextFetchAt, FeedStatus $status = FeedStatus::Active): Feed
    {
        $feed = new Feed($url);
        $feed->setNextFetchAt($nextFetchAt);
        $feed->setStatus($status);
        $this->em->persist($feed);

        return $feed;
    }
```
After:
```php
    private function feed(string $url, ?\DateTimeImmutable $nextFetchAt): Feed
    {
        $feed = new Feed($url);
        if (null !== $nextFetchAt) {
            $feed->scheduleNextFetchAt($nextFetchAt);
        }
        $this->em->persist($feed);

        return $feed;
    }

    private function goneFeed(string $url, ?\DateTimeImmutable $nextFetchAt): Feed
    {
        $feed = new Feed($url);
        $feed->markGone($this->now->modify('-1 day'), 'HTTP 410 Gone');
        if (null !== $nextFetchAt) {
            $feed->scheduleNextFetchAt($nextFetchAt);
        }
        $this->em->persist($feed);

        return $feed;
    }
```

```diff
# line 54
-        $this->feed('https://d.example.com/feed', $this->now->modify('-1 day'), FeedStatus::Gone);
+        $this->goneFeed('https://d.example.com/feed', $this->now->modify('-1 day'));
# line 177
-        $this->feed('https://gone.example.com/feed', null, FeedStatus::Gone);
+        $this->goneFeed('https://gone.example.com/feed', null);
# line 222
-        $gone = $this->feed('https://gone.example.com/feed', null, FeedStatus::Gone);
+        $gone = $this->goneFeed('https://gone.example.com/feed', null);
```

The cooldown tests run with `force: true`, which ignores `nextFetchAt`, so a successful fetch arranges them exactly:
```diff
# line 136
-        $fresh->setLastFetchedAt($this->now->modify('-1 minute'));
+        $fresh->recordSuccessfulFetch($this->now->modify('-1 minute'), 60);
# line 138
-        $stale->setLastFetchedAt($this->now->modify('-10 minutes'));
+        $stale->recordSuccessfulFetch($this->now->modify('-10 minutes'), 60);
# line 156
-        $future->setLastFetchedAt($this->now->modify('+59 minutes'));
+        $future->recordSuccessfulFetch($this->now->modify('+59 minutes'), 60);
# line 194
-        $justFetched->setLastFetchedAt($this->now->modify('-1 minute'));
+        $justFetched->recordSuccessfulFetch($this->now->modify('-1 minute'), 60);
```

`tests/Service/Refresh/RefreshRunnerTest.php` (clock `2026-07-21 12:00:00`; a feed is due when `nextFetchAt <= now`):
```diff
# line 169
-        $feed->setNextFetchAt($this->clock->now()->modify('-1 hour'));
+        $feed->scheduleNextFetchAt($this->clock->now()->modify('-1 hour'));
# line 924
-        $existing->setNextFetchAt($this->clock->now()->modify('+1 day'));
+        $existing->scheduleNextFetchAt($this->clock->now()->modify('+1 day'));
# line 1120
-        $feed->setNextFetchAt($this->clock->now()->modify('-1 hour')); // due again
+        $feed->scheduleNextFetchAt($this->clock->now()->modify('-1 hour')); // due again
```

Before (lines 367-369):
```php
        // A normal previous fetch: it succeeded, so it stamped both fields alike.
        $feed->setLastFetchedAt(new \DateTimeImmutable('2026-07-21 06:00:00'));
        $feed->setLastSuccessfulFetchAt(new \DateTimeImmutable('2026-07-21 06:00:00'));
```
After (next fetch 07:00, still due):
```php
        $feed->recordSuccessfulFetch(new \DateTimeImmutable('2026-07-21 06:00:00'), 60);
```

Before (lines 413-416):
```php
        // Last real success was nine days ago; every attempt since failed but
        // still stamped lastFetchedAt (FeedScheduler::recordFailure()).
        $feed->setLastSuccessfulFetchAt(new \DateTimeImmutable('2026-07-12 06:00:00'));
        $feed->setLastFetchedAt(new \DateTimeImmutable('2026-07-21 11:00:00'));
```
After (next fetch 11:30, still due):
```php
        $feed->recordSuccessfulFetch(new \DateTimeImmutable('2026-07-12 06:00:00'), 60);
        $feed->recordFailedFetch(new \DateTimeImmutable('2026-07-21 11:00:00'), 'HTTP 503', 30);
```

Before (line 521):
```php
        $feed->setLastFetchedAt(new \DateTimeImmutable('2026-07-21 11:00:00'));
```
After (next fetch 11:30, still due; the test's throttle names 90 s, so the interval does not reach its assertion):
```php
        $feed->recordSuccessfulFetch(new \DateTimeImmutable('2026-07-21 11:00:00'), 30);
```

- [ ] **Step 11: Confirm that no schedule or status setter is left.**

Run: `git grep -nE '\->(setLastFetchedAt|setLastSuccessfulFetchAt|setLastNewEntryAt|setNextFetchAt|setFetchIntervalMinutes|setConsecutiveFailures|setLastErrorMessage)\(|FeedStatus::[A-Za-z]+\)' -- src tests | grep -E 'set[A-Z]'`
Expected: no output. A leftover `$feed->setStatus($variable)` does not match the grep, but `composer stan` in Step 14 reports it as a call to an undefined method.

- [ ] **Step 12: Run the affected tests.**

Run: `php bin/phpunit tests/Entity/FeedTest.php tests/Service/FeedSchedulerTest.php tests/Service/Subscription tests/Service/Refresh tests/Repository/FeedRepositoryTest.php tests/Repository/FeedRepositoryUserFeedScopeTest.php tests/Http/SubscriptionJsonTest.php tests/Command tests/Controller/MaintenanceControllerTest.php tests/Controller/Admin/AdminUserControllerTest.php tests/Service/Admin/UserStatisticsTest.php tests/Service/Backup/AccountRestorerTest.php tests/Service/Maintenance tests/Service/Worker`
Expected: PASS.

- [ ] **Step 13: Deletion checks.** For each line below, delete it or break it as stated. Run the named test, see it fail, and restore the line by hand. Paste both outputs.
  - `FetchSchedule::recordSuccess`, `$this->consecutiveFailures = 0;` → `FeedTest::testASuccessfulFetchEndsAFailureStreakAndSchedulesTheNextFetch`.
  - `FetchSchedule::recordGone`, `$this->nextFetchAt = null;` → `FeedTest::testAGoneFeedIsNeverScheduledAgain`.
  - `FetchSchedule::countFailedAttempt`, `mb_substr` → `substr` → `FeedTest::testAFailureMessageIsCappedAtAThousandCharacters`.
  - `Feed::recordFailedFetch`, `$this->status = FeedStatus::Erroring;` → `FeedTest::testAFailedFetchCountsTheFailureAndBacksOffWithoutClaimingSuccess`.
  - `BulkSubscriber`, the `scheduleNextFetchAt` line → `BulkSubscriberTest::testANewFeedIsScheduledForTheNextRefresh`.

- [ ] **Step 14: Run the task gates.**

Run: `composer check && composer md`
Expected: both green. `FeedScheduler`, `Feed` and `FetchSchedule` produce no PHPMD finding. For phptramp, `$message` now passes through `FeedScheduler::recordFailure` → `Feed::recordFailedFetch` → `FetchSchedule::recordFailure`, where it is read: two forwarding hops, below the warning threshold. If phptramp reports it anyway, check `composer show larspohlmann/phptramp` before changing the design.

- [ ] **Step 15: Commit.**

```bash
git add src/Entity/Feed.php src/Entity/FetchSchedule.php src/Service/FeedScheduler.php src/Service/Subscription/BulkSubscriber.php src/Command/E2eSeedAdminSubscriptionCommand.php tests
git commit -m "refactor(#1164): feed records its fetch outcomes; the schedule setters go"
```

---
### Task 2: `Feed::recordCacheValidators()`

**Files:**
- Modify: `src/Entity/Feed.php` (constants after `use PersistedId;`; the `setEtag`/`setLastModified` pair at the end of the class)
- Modify: `src/Service/Refresh/RefreshRunner.php:56-57, 336-337, 419-428`
- Modify: `src/Service/Subscription/FirstFetchRecorder.php:33-35, 78-79, 89-93`
- Test: `tests/Entity/FeedTest.php` (two new tests)
- Test fixtures: `tests/Service/Refresh/BudgetedFeedQueueTest.php:51-52`, `tests/Service/Backup/AccountRestorerTest.php:221`

**Interfaces:**
- Consumes: Task 1's `Feed` (this task edits the same file after it).
- Produces: `Feed::recordCacheValidators(?string $etag, ?string $lastModified): void`. It stores both, truncated to 512 and 255 characters (`mb_substr`), and a null clears the stored value, exactly as the two `truncate()` helpers did.

- [ ] **Step 1: Write the failing tests.** Append to `tests/Entity/FeedTest.php`:

```php
    public function testCacheValidatorsAreCappedToTheirColumns(): void
    {
        $feed = new Feed('https://example.com/feed.xml');

        $feed->recordCacheValidators(str_repeat('é', 513), str_repeat('ü', 256));

        self::assertSame(str_repeat('é', 512), $feed->getEtag());
        self::assertSame(str_repeat('ü', 255), $feed->getLastModified());
    }

    public function testAbsentCacheValidatorsClearTheStoredOnes(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->recordCacheValidators('"v1"', 'Mon, 20 Jul 2026 08:30:00 GMT');

        $feed->recordCacheValidators(null, null);

        self::assertNull($feed->getEtag());
        self::assertNull($feed->getLastModified());
    }
```

- [ ] **Step 2: Run them to verify they fail.**

Run: `php bin/phpunit tests/Entity/FeedTest.php`
Expected: FAIL with `Call to undefined method App\Entity\Feed::recordCacheValidators()`.

- [ ] **Step 3: Implement the method in `src/Entity/Feed.php`.**

Before:
```php
class Feed
{
    use PersistedId;

    #[ORM\Id]
```
After:
```php
class Feed
{
    use PersistedId;

    private const int ETAG_MAX = 512;
    private const int LAST_MODIFIED_MAX = 255;

    #[ORM\Id]
```

Before (the end of the class):
```php
    public function getEtag(): ?string
    {
        return $this->etag;
    }

    public function setEtag(?string $etag): void
    {
        $this->etag = $etag;
    }

    public function getLastModified(): ?string
    {
        return $this->lastModified;
    }

    public function setLastModified(?string $lastModified): void
    {
        $this->lastModified = $lastModified;
    }
}
```
After:
```php
    public function getEtag(): ?string
    {
        return $this->etag;
    }

    public function getLastModified(): ?string
    {
        return $this->lastModified;
    }

    // SQLite ignores the column limit; MySQL strict mode rejects an over-long remote value and fails the flush.
    public function recordCacheValidators(?string $etag, ?string $lastModified): void
    {
        $this->etag = null === $etag ? null : mb_substr($etag, 0, self::ETAG_MAX);
        $this->lastModified = null === $lastModified ? null : mb_substr($lastModified, 0, self::LAST_MODIFIED_MAX);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass.**

Run: `php bin/phpunit tests/Entity/FeedTest.php`
Expected: PASS.

- [ ] **Step 5: Switch `RefreshRunner` to it.**

Before (lines 52-58):
```php
    private const string LOCK_NAME = 'feed-refresh';
    private const float LOCK_TTL_SECONDS = 60.0;
    private const int BATCH_LIMIT = 50;
    private const int COOLDOWN_MINUTES = 5;
    private const int ETAG_MAX = 512;
    private const int LAST_MODIFIED_MAX = 255;
    private const int URL_MAX = 750;
```
After:
```php
    private const string LOCK_NAME = 'feed-refresh';
    private const float LOCK_TTL_SECONDS = 60.0;
    private const int BATCH_LIMIT = 50;
    private const int COOLDOWN_MINUTES = 5;
    private const int URL_MAX = 750;
```

Before (lines 336-337):
```php
            $feed->setEtag($this->truncate($response->etag, self::ETAG_MAX));
            $feed->setLastModified($this->truncate($response->lastModified, self::LAST_MODIFIED_MAX));
```
After:
```php
            $feed->recordCacheValidators($response->etag, $response->lastModified);
```

Before (lines 417-429):
```php
        $feed->setUrl($response->finalUrl);
    }

    /**
     * ETag and Last-Modified are remote-controlled and go into length-limited
     * columns. SQLite ignores the limit, MySQL in strict mode rejects the row —
     * which would fail the flush, abort the run, and skip every queued feed.
     */
    private function truncate(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }
}
```
After:
```php
        $feed->setUrl($response->finalUrl);
    }
}
```

- [ ] **Step 6: Switch `FirstFetchRecorder` to it.**

Before (lines 31-36):
```php
final readonly class FirstFetchRecorder
{
    private const int ETAG_MAX = 512;
    private const int LAST_MODIFIED_MAX = 255;

    /**
```
After:
```php
final readonly class FirstFetchRecorder
{
    /**
```

Before (lines 78-79):
```php
        $feed->setEtag($this->truncate($discovered->etag, self::ETAG_MAX));
        $feed->setLastModified($this->truncate($discovered->lastModified, self::LAST_MODIFIED_MAX));
```
After:
```php
        $feed->recordCacheValidators($discovered->etag, $discovered->lastModified);
```

Before (lines 86-94):
```php
        return \count($createdEntries);
    }

    private function truncate(?string $value, int $max): ?string
    {
        return null === $value ? null : mb_substr($value, 0, $max);
    }

    /**
```
After:
```php
        return \count($createdEntries);
    }

    /**
```

- [ ] **Step 7: Replace the two test fixtures.**

`tests/Service/Refresh/BudgetedFeedQueueTest.php`:

Before (lines 51-52):
```php
        $feed->setEtag('"v1"');
        $feed->setLastModified('Mon, 20 Jul 2026 08:30:00 GMT');
```
After:
```php
        $feed->recordCacheValidators('"v1"', 'Mon, 20 Jul 2026 08:30:00 GMT');
```

`tests/Service/Backup/AccountRestorerTest.php` (the test at line 734 still asserts the preserved `W/"seeded-etag"`):
```diff
# line 221
-        $feed->setEtag('W/"seeded-etag"');
+        $feed->recordCacheValidators('W/"seeded-etag"', null);
```

- [ ] **Step 8: Confirm that no `Feed` validator setter is left.**

Run: `git grep -nE '\->set(Etag|LastModified)\(' -- src tests`
Expected: only `$response->setEtag(` in `src/Http/CatalogFaviconResponse.php` (a Symfony `Response`, from #1157 PR B).

- [ ] **Step 9: Run the affected tests.**

Run: `php bin/phpunit tests/Entity/FeedTest.php tests/Service/Refresh tests/Service/Subscription tests/Service/Backup/AccountRestorerTest.php`
Expected: PASS. `RefreshRunnerTest:878-879` (512/255 after an over-long refresh) now proves the entity's cap end to end.

- [ ] **Step 10: Deletion check.** In `recordCacheValidators`, change `self::ETAG_MAX` to `self::ETAG_MAX + 1`. Run `php bin/phpunit tests/Entity/FeedTest.php --filter Capped` and see it fail, then restore the line by hand. Do the same with `mb_substr($lastModified, 0, self::LAST_MODIFIED_MAX)` → `$lastModified`. Paste the outputs.

- [ ] **Step 11: Run the task gates.**

Run: `composer check && composer md`
Expected: green.

- [ ] **Step 12: Commit.**

```bash
git add src/Entity/Feed.php src/Service/Refresh/RefreshRunner.php src/Service/Subscription/FirstFetchRecorder.php tests/Entity/FeedTest.php tests/Service/Refresh/BudgetedFeedQueueTest.php tests/Service/Backup/AccountRestorerTest.php
git commit -m "refactor(#1164): feed records its cache validators and owns their column caps"
```

---

### Task 3: `User` status transitions: `approve`, `queueForApproval`, `reject`, `suspend`

**Files:**
- Modify: `src/Entity/User.php:208-231`
- Modify: `src/Service/Admin/UserStatusChanger.php:70-71, 83, 91`
- Modify: `src/Service/Admin/UserLimits.php:63-64`
- Modify: `src/Service/Auth/BootstrapAdminProvisioner.php:8, 40-41`
- Modify: `src/Command/E2eSeedAdminCommand.php:8, 74-75`
- Modify: `src/Security/TrialExpiryGuard.php:42`
- Modify: `src/Service/Auth/RegistrationService.php:67-71, 119, 127-128` (+ one private method)
- Modify: `src/Service/OAuth/OAuthAccountLinker.php:175-180, 208-213`
- Create: `tests/Entity/UserStatusTest.php`
- Create: `tests/Support/NewUserStatus.php`
- Test fixtures: `tests/Support/UserFactory.php`, `tests/Command/E2ePurgeUsersCommandTest.php`, `tests/Command/E2eSeedAdminSubscriptionCommandTest.php`, `tests/Command/PurgeUnverifiedUsersCommandTest.php`, `tests/Controller/Api/JwtAccessTest.php`, `tests/Controller/Api/LoginTest.php`, `tests/Controller/Api/PasskeyLoginTest.php`, `tests/Controller/Admin/AdminUserControllerTest.php`, `tests/Repository/UserRepositoryTest.php`, `tests/Security/TrialExpiryGuardTest.php`, `tests/Security/UserCheckerTest.php`, `tests/Service/OAuth/OAuthAccountLinkerTest.php`, `tests/Service/OAuth/OAuthSignInTest.php`, and from #1157 PR B `tests/Http/AdminUserLimitsJsonTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `User::approve(\DateTimeImmutable $approvedAt): void` sets status Active and stamps `approvedAt`, every time: a reinstatement re-stamps, as `UserStatusChanger`'s docblock requires.
  - `User::queueForApproval(): void` sets PendingApproval.
  - `User::reject(): void` sets Rejected.
  - `User::suspend(): void` sets Suspended and keeps `approvedAt`.
  - Test support: `App\Tests\Support\NewUserStatus::apply(User $newUser, UserStatus $status, \DateTimeImmutable $approvedAt): void` walks a freshly constructed user (status PendingVerification) to `$status` through those methods.

The transitions stay unguarded, exactly as the setters were. Today `reject()` and `suspend()` run from any status, so adding a guard would change behaviour.

- [ ] **Step 1: Write the failing entity tests.** Create `tests/Entity/UserStatusTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\Enum\UserStatus;
use PHPUnit\Framework\TestCase;

final class UserStatusTest extends TestCase
{
    public function testApprovingActivatesTheAccountAndStampsTheGrant(): void
    {
        $user = $this->user();

        $user->approve(new \DateTimeImmutable('2026-07-15 08:30:00'));

        self::assertSame(UserStatus::Active, $user->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-07-15 08:30:00'), $user->getApprovedAt());
    }

    public function testAReinstatementMovesTheStampToTheLatestGrant(): void
    {
        $user = $this->user();
        $user->approve(new \DateTimeImmutable('2026-07-15 08:30:00'));
        $user->suspend();

        $user->approve(new \DateTimeImmutable('2026-08-02 17:45:00'));

        self::assertSame(UserStatus::Active, $user->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-08-02 17:45:00'), $user->getApprovedAt());
    }

    public function testSuspendingKeepsTheLastGrant(): void
    {
        $user = $this->user();
        $user->approve(new \DateTimeImmutable('2026-07-15 08:30:00'));

        $user->suspend();

        self::assertSame(UserStatus::Suspended, $user->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-07-15 08:30:00'), $user->getApprovedAt());
    }

    public function testQueueingForApprovalGrantsNothing(): void
    {
        $user = $this->user();

        $user->queueForApproval();

        self::assertSame(UserStatus::PendingApproval, $user->getStatus());
        self::assertNull($user->getApprovedAt());
    }

    public function testRejectingGrantsNothing(): void
    {
        $user = $this->user();
        $user->queueForApproval();

        $user->reject();

        self::assertSame(UserStatus::Rejected, $user->getStatus());
        self::assertNull($user->getApprovedAt());
    }

    private function user(): User
    {
        return new User('status@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));
    }
}
```

- [ ] **Step 2: Run them to verify they fail.**

Run: `php bin/phpunit tests/Entity/UserStatusTest.php`
Expected: FAIL with `Call to undefined method App\Entity\User::approve()`.

- [ ] **Step 3: Replace the two setters in `src/Entity/User.php`.**

Before (lines 208-231):
```php
    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): void
    {
        $this->status = $status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeImmutable $approvedAt): void
    {
        $this->approvedAt = $approvedAt;
    }
```
After:
```php
    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function approve(\DateTimeImmutable $approvedAt): void
    {
        $this->status = UserStatus::Active;
        $this->approvedAt = $approvedAt;
    }

    public function queueForApproval(): void
    {
        $this->status = UserStatus::PendingApproval;
    }

    public function reject(): void
    {
        $this->status = UserStatus::Rejected;
    }

    public function suspend(): void
    {
        $this->status = UserStatus::Suspended;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }
```

- [ ] **Step 4: Run the entity tests to verify they pass.**

Run: `php bin/phpunit tests/Entity/UserStatusTest.php`
Expected: PASS.

- [ ] **Step 5: Switch every `src` caller.**

`src/Service/Admin/UserStatusChanger.php` (`UserStatus` stays imported: `approve()` still reads it):

Before (lines 70-71):
```php
        $user->setStatus(UserStatus::Active);
        $user->setApprovedAt($this->clock->now());
```
After:
```php
        $user->approve($this->clock->now());
```
```diff
# line 83
-        $user->setStatus(UserStatus::Rejected);
+        $user->reject();
# line 91
-        $user->setStatus(UserStatus::Suspended);
+        $user->suspend();
```

`src/Service/Admin/UserLimits.php` (`UserStatus` stays imported: line 59 reads it):

Before (lines 63-64):
```php
        $user->setStatus(UserStatus::Active);
        $user->setApprovedAt($this->clock->now());
```
After:
```php
        $user->approve($this->clock->now());
```

`src/Security/TrialExpiryGuard.php` (`UserStatus` stays imported):
```diff
# line 42
-            $user->setStatus(UserStatus::Suspended);
+            $user->suspend();
```

`src/Service/Auth/BootstrapAdminProvisioner.php`: remove `use App\Enum\UserStatus;` (line 8), which is now unused, and:

Before (lines 39-42):
```php
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setStatus(UserStatus::Active);
        $admin->setApprovedAt($now);
        $admin->setPasswordHash($this->hasher->hashPassword($admin, $password), $now);
```
After:
```php
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->approve($now);
        $admin->setPasswordHash($this->hasher->hashPassword($admin, $password), $now);
```

`src/Command/E2eSeedAdminCommand.php`: remove `use App\Enum\UserStatus;` (line 8), which is now unused, and:

Before (lines 73-76):
```php
        $user->setRoles(['ROLE_ADMIN']);
        $user->setStatus(UserStatus::Active);
        $user->setApprovedAt($now);
        $user->setPasswordHash($this->hasher->hashPassword($user, $password), $now);
```
After:
```php
        $user->setRoles(['ROLE_ADMIN']);
        $user->approve($now);
        $user->setPasswordHash($this->hasher->hashPassword($user, $password), $now);
```

`src/Service/OAuth/OAuthAccountLinker.php`: the same five lines appear twice, in `claimIfUnverified()` (lines 175-180) and `createUser()` (lines 208-213), at the same indentation. Replace both, using Edit with replace_all.

Before:
```php
        if ($this->policy->approvalRequired()) {
            $user->setStatus(UserStatus::PendingApproval);
        } else {
            $user->setStatus(UserStatus::Active);
            $user->setApprovedAt($now);
        }
```
After:
```php
        if ($this->policy->approvalRequired()) {
            $user->queueForApproval();
        } else {
            $user->approve($now);
        }
```

`src/Service/Auth/RegistrationService.php`: `register()` receives the status from `RegistrationPolicy::prospectiveStatusForEmailSignup()`, which returns PendingVerification, PendingApproval or Active. A new `User` already starts as PendingVerification.

Before (lines 67-71):
```php
        $status = $this->policy->prospectiveStatusForEmailSignup();
        $user->setStatus($status);
        if (UserStatus::Active === $status) {
            $user->setApprovedAt($now);
        }
```
After:
```php
        $status = $this->policy->prospectiveStatusForEmailSignup();
        $this->enterSignupStatus($user, $status, $now);
```

Insert this private method directly before the `completeRegistration()` docblock (`/**` above `The one post-flush side effect…`):
```php
    private function enterSignupStatus(User $user, UserStatus $status, \DateTimeImmutable $now): void
    {
        if (UserStatus::Active === $status) {
            $user->approve($now);

            return;
        }
        if (UserStatus::PendingApproval === $status) {
            $user->queueForApproval();
        }
    }

```

Before (line 119):
```php
                $user->setStatus(UserStatus::PendingApproval);
```
After:
```php
                $user->queueForApproval();
```

Before (lines 127-128):
```php
                $user->setStatus(UserStatus::Active);
                $user->setApprovedAt($now);
```
After:
```php
                $user->approve($now);
```

- [ ] **Step 6: Create the fixture helper `tests/Support/NewUserStatus.php`.** The seven fixtures below take an arbitrary `UserStatus` from a data provider or a parameter, and each needs one way to reach it through the domain methods.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Enum\UserStatus;

final class NewUserStatus
{
    public static function apply(User $newUser, UserStatus $status, \DateTimeImmutable $approvedAt): void
    {
        if (UserStatus::Active === $status) {
            $newUser->approve($approvedAt);

            return;
        }
        if (UserStatus::PendingApproval === $status) {
            $newUser->queueForApproval();

            return;
        }
        if (UserStatus::Rejected === $status) {
            $newUser->reject();

            return;
        }
        if (UserStatus::Suspended === $status) {
            $newUser->suspend();
        }
    }
}
```

- [ ] **Step 7: Replace every `setStatus`/`setApprovedAt` in the tests.**

`tests/Support/UserFactory.php` (same namespace as the helper, so no import):
```diff
# line 41
-        $user->setStatus($status);
+        NewUserStatus::apply($user, $status, $createdAt);
```

`tests/Command/PurgeUnverifiedUsersCommandTest.php`: add `use App\Tests\Support\NewUserStatus;` after `use App\Tests\DbTestCase;`, and:
```diff
# line 33
-        $user->setStatus($status);
+        NewUserStatus::apply($user, $status, new \DateTimeImmutable($createdAt));
```

`tests/Repository/UserRepositoryTest.php`: add `use App\Tests\Support\NewUserStatus;` after `use App\Tests\DbTestCase;`, and:
```diff
# line 25
-        $user->setStatus($status);
+        NewUserStatus::apply($user, $status, new \DateTimeImmutable('2026-07-01 10:00:00'));
```

`tests/Security/TrialExpiryGuardTest.php`: add `use App\Tests\Support\NewUserStatus;` after `use App\Security\TrialExpiryGuard;`, and:
```diff
# line 20
-        $user->setStatus($status);
+        NewUserStatus::apply($user, $status, new \DateTimeImmutable('2026-07-01 10:00:00'));
```

`tests/Security/UserCheckerTest.php`: add `use App\Tests\Support\NewUserStatus;` after `use App\Security\UserChecker;`, and:
```diff
# line 23
-        $user->setStatus($status);
+        NewUserStatus::apply($user, $status, new \DateTimeImmutable('2026-07-21 09:00:00'));
```

`tests/Service/OAuth/OAuthAccountLinkerTest.php`: add `use App\Tests\Support\NewUserStatus;` after `use App\Tests\DbTestCase;`, and:
```diff
# line 428
-        $user->setStatus($status);
+        NewUserStatus::apply($user, $status, $this->now());
```
Lines 344 and 362 assert `approvedAt == now()` on users persisted as `PendingVerification` (lines 297, 350). The helper leaves those untouched, so the linker's own stamp is still what they see.

`tests/Service/OAuth/OAuthSignInTest.php`: add `use App\Tests\Support\NewUserStatus;` after `use App\Tests\DbTestCase;`, and:
```diff
# line 197
-        $user->setStatus($status);
+        NewUserStatus::apply($user, $status, new \DateTimeImmutable('2026-07-21 12:00:00'));
```

`tests/Command/E2ePurgeUsersCommandTest.php`: remove `use App\Enum\UserStatus;`, which is now unused, and:
```diff
# line 33
-        $user->setStatus(UserStatus::Active);
+        $user->approve(new \DateTimeImmutable('-1 day'));
```

`tests/Command/E2eSeedAdminSubscriptionCommandTest.php`: remove `use App\Enum\UserStatus;`, which is now unused, and:
```diff
# line 34
-        $admin->setStatus(UserStatus::Active);
+        $admin->approve(new \DateTimeImmutable('-1 day'));
```

`tests/Controller/Api/LoginTest.php` (`UserStatus` stays imported):
```diff
# line 377
-        $user->setStatus(UserStatus::Active);
+        $user->approve(new \DateTimeImmutable('2026-07-01 10:00:00'));
# line 419
-        $oauthOnly->setStatus(UserStatus::Active);
+        $oauthOnly->approve(new \DateTimeImmutable('2026-07-01 10:00:00'));
```

`tests/Controller/Api/JwtAccessTest.php` (`UserStatus` stays imported):
```diff
# lines 134, 166 (identical; Edit with replace_all)
-        $user->setStatus(UserStatus::Suspended);
+        $user->suspend();
```

`tests/Controller/Api/PasskeyLoginTest.php`: remove `use App\Enum\UserStatus;`, which is now unused, and:
```diff
# line 447
-        $user->setStatus(UserStatus::Suspended);
+        $user->suspend();
```

`tests/Controller/Admin/AdminUserControllerTest.php`. The reinstatement test needs a suspended account that was approved in 2020. It now builds exactly that history:
```diff
# line 396
-        $target->setApprovedAt($original);
+        $target->approve($original);
+        $target->suspend();
```
D4, fixture consequence only: the admin detail endpoint and `AdminUserJson` are untouched and still serialise whatever `approvedAt` the account holds. What changed is the account. The factory's default active user is now approved at its `createdAt` instead of being active with no approval stamp, so the serialised value changes with it. No production path produces a different `approvedAt` than before.

Before (line 775):
```php
        self::assertNull($account['approvedAt']);
```
After:
```php
        self::assertIsString($account['approvedAt']);
        self::assertStringStartsWith('2026-07-01T10:00:00', $account['approvedAt']);
```

`tests/Http/AdminUserLimitsJsonTest.php` (added by #1157 PR B): remove `use App\Enum\UserStatus;` (line 8), which is now unused, and:

Before (line 17):
```php
        $user->setStatus(UserStatus::Active);
```
After:
```php
        $user->approve(new \DateTimeImmutable('2026-08-01T00:00:00Z'));
```
`AdminUserLimitsJson::trial()` serialises only `status` and `trialEndsAt`, so the test's expected `['status' => 'active', …]` is unchanged.

- [ ] **Step 8: Confirm that no status or approval setter is left.**

Run: `git grep -nE '\->(setStatus|setApprovedAt)\(' -- src tests`
Expected: no output.

- [ ] **Step 9: Run the affected tests.**

Run: `php bin/phpunit tests/Entity tests/Service/Admin tests/Service/Auth tests/Service/OAuth tests/Security tests/Repository/UserRepositoryTest.php tests/Command tests/Controller/Api/LoginTest.php tests/Controller/Api/JwtAccessTest.php tests/Controller/Api/PasskeyLoginTest.php tests/Controller/Admin/AdminUserControllerTest.php tests/Http/AdminUserLimitsJsonTest.php`
Expected: PASS.

Then run the whole suite once (`php bin/phpunit`), since `UserFactory` backs most of it. Expected: PASS. If a test now fails on an active fixture's non-null `approvedAt`, it is a D4 case: report it before changing it.

- [ ] **Step 10: Deletion checks.** Paste the fail/restore outputs for each:
  - `User::approve`, `$this->approvedAt = $approvedAt;` → `UserStatusTest::testApprovingActivatesTheAccountAndStampsTheGrant`.
  - `User::suspend`, `UserStatus::Suspended` → `UserStatus::Rejected` → `UserStatusTest::testSuspendingKeepsTheLastGrant`.
  - `RegistrationService::enterSignupStatus`, the `queueForApproval()` line → `tests/Service/Auth/RegistrationServiceTest.php` (its approval-gate case fails).

- [ ] **Step 11: Run the task gates.**

Run: `composer check && composer md`
Expected: green.

- [ ] **Step 12: Commit.**

```bash
git add src/Entity/User.php src/Service/Admin src/Service/Auth src/Service/OAuth/OAuthAccountLinker.php src/Security/TrialExpiryGuard.php src/Command/E2eSeedAdminCommand.php tests
git commit -m "refactor(#1164): user status moves through approve, queueForApproval, reject and suspend"
```

---

### Task 4: `User::isAdmin()`

**Files:**
- Modify: `src/Entity/User.php` (one method after `setRoles()`)
- Modify: `src/Repository/UserRepository.php:163, 186, 235`
- Modify: `src/Service/Account/AccountDeleter.php:69`
- Test: `tests/Entity/UserSecurityTest.php` (three new tests)

**Interfaces:**
- Consumes: Task 3's `User` (same file).
- Produces: `User::isAdmin(): bool`, true only when the stored roles contain exactly `ROLE_ADMIN`. `ROLE_ADMINISTRATOR` does not count, and that is the substring hazard `UserRepository`'s docblocks describe.

- [ ] **Step 1: Write the failing tests.** Append to `tests/Entity/UserSecurityTest.php`, after `testExplicitRoleUserKeepsListSequential()`:

```php
    public function testAnAccountWithoutTheAdminRoleIsNotAnAdmin(): void
    {
        self::assertFalse($this->user()->isAdmin());
    }

    public function testTheAdminRoleMakesAnAdmin(): void
    {
        $user = $this->user();
        $user->setRoles(['ROLE_ADMIN']);

        self::assertTrue($user->isAdmin());
    }

    public function testALookalikeRoleIsNotTheAdminRole(): void
    {
        $user = $this->user();
        $user->setRoles(['ROLE_ADMINISTRATOR']);

        self::assertFalse($user->isAdmin());
    }
```

- [ ] **Step 2: Run them to verify they fail.**

Run: `php bin/phpunit tests/Entity/UserSecurityTest.php`
Expected: FAIL with `Call to undefined method App\Entity\User::isAdmin()`.

- [ ] **Step 3: Implement.** In `src/Entity/User.php`:

Before:
```php
    /** @param list<string> $roles */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }
```
After:
```php
    /** @param list<string> $roles */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    public function isAdmin(): bool
    {
        return \in_array('ROLE_ADMIN', $this->roles, true);
    }
```

- [ ] **Step 4: Run the tests to verify they pass.**

Run: `php bin/phpunit tests/Entity/UserSecurityTest.php`
Expected: PASS.

- [ ] **Step 5: Replace the four copies.**

`src/Repository/UserRepository.php`:
```diff
# line 163
-            static fn (User $user): bool => \in_array('ROLE_ADMIN', $user->getRoles(), true),
+            static fn (User $user): bool => $user->isAdmin(),
# line 186
-            if (\in_array('ROLE_ADMIN', $candidate->getRoles(), true)) {
+            if ($candidate->isAdmin()) {
# line 235
-            static fn (User $candidate): bool => \in_array('ROLE_ADMIN', $candidate->getRoles(), true),
+            static fn (User $candidate): bool => $candidate->isAdmin(),
```

`src/Service/Account/AccountDeleter.php`:
```diff
# line 69
-        if (!\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
+        if (!$user->isAdmin()) {
```

- [ ] **Step 6: Confirm that no copy is left.**

Run: `git grep -n "'ROLE_ADMIN', \\$" -- src`
Expected: only `src/Entity/User.php` (`isAdmin()`).

- [ ] **Step 7: Run the affected tests.**

Run: `php bin/phpunit tests/Entity/UserSecurityTest.php tests/Repository/UserRepositoryTest.php tests/Service/Account/AccountDeleterTest.php`
Expected: PASS. `UserRepositoryTest` still proves the `ROLE_ADMINISTRATOR` lookalike is rejected by all three queries.

- [ ] **Step 8: Deletion check.** In `isAdmin()`, change `'ROLE_ADMIN'` to `'ROLE_ADMINISTRATOR'`. Run `php bin/phpunit tests/Entity/UserSecurityTest.php` and see it fail, then restore by hand. Paste the outputs.

- [ ] **Step 9: Run the task gates.**

Run: `composer check && composer md`
Expected: green. If `composer infection:diff` later reports an escaped `TrueValue` mutant on `isAdmin()`'s strict flag, it is equivalent: the roles list holds strings only. Report it, and do not add an ignore without Lars.

- [ ] **Step 10: Commit.**

```bash
git add src/Entity/User.php src/Repository/UserRepository.php src/Service/Account/AccountDeleter.php tests/Entity/UserSecurityTest.php
git commit -m "refactor(#1164): user answers isAdmin() instead of four role-list scans"
```

---
### Task 5: `EntryState` favourite and kept methods; the raw flag setters go

**Files:**
- Modify: `src/Entity/EntryState.php:63-101`
- Modify: `src/Service/Reader/EntryStateUpdater.php:47-52`
- Create: `src/Entity/BackedUpReadMark.php`
- Modify: `src/Service/Backup/RestoreEntryLoader.php:7-8, 114-132`
- Modify: `src/Command/E2eSeedAdminSubscriptionCommand.php:192`
- Test: `tests/Entity/EntryStateTest.php` (seven new tests), `tests/Service/Reader/EntryStateUpdaterTest.php` (one new test), `tests/Service/Backup/EntryPartRestorerTest.php` (one new test and one helper), `tests/Command/E2eSeedAdminSubscriptionCommandTest.php` (one added assertion)
- Test fixtures: the 26 files in Step 8, which are every test file with a flag setter at 6ca53dd8. `tests/Service/Subscription/SubscriptionTallyReaderTest.php` (from #1157 PR B) is one of them; `E2eSeedAdminSubscriptionCommandTest` and `EntryPartRestorerTest` also appear under **Test** above.

**Interfaces:**
- Consumes: nothing from earlier tasks. The existing `EntryState::hide(\DateTimeImmutable $when)`, `markUnread()` and `markViewed(\DateTimeImmutable $when)` are unchanged.
- Produces:
  - `EntryState::markFavorite(): void`, `clearFavorite(): void`, `markKept(): void`, `clearKept(): void`.
  - `EntryState::restoreReadMark(BackedUpReadMark $readMark): void`, restore only.
  - `App\Entity\BackedUpReadMark::__construct(bool $isHidden, ?\DateTimeImmutable $hiddenAt)`.

  `setIsHidden`, `setHiddenAt`, `setIsFavorite` and `setIsKept` no longer exist. Task 6's tests call `markFavorite()`.

- [ ] **Step 1: Write the failing entity tests.** Append to `tests/Entity/EntryStateTest.php`, before `makeState()`:

```php
    public function testMarkingAFavoriteLeavesTheOtherFlagsAlone(): void
    {
        $state = $this->makeState();

        $state->markFavorite();

        self::assertTrue($state->isFavorite());
        self::assertFalse($state->isKept());
        self::assertFalse($state->isHidden());
    }

    public function testClearingAFavoriteUndoesIt(): void
    {
        $state = $this->makeState();
        $state->markFavorite();

        $state->clearFavorite();

        self::assertFalse($state->isFavorite());
    }

    public function testMarkingKeptLeavesTheOtherFlagsAlone(): void
    {
        $state = $this->makeState();

        $state->markKept();

        self::assertTrue($state->isKept());
        self::assertFalse($state->isFavorite());
        self::assertFalse($state->isHidden());
    }

    public function testClearingKeptUndoesIt(): void
    {
        $state = $this->makeState();
        $state->markKept();

        $state->clearKept();

        self::assertFalse($state->isKept());
    }
```

- [ ] **Step 2: Run them to verify they fail.**

Run: `php bin/phpunit tests/Entity/EntryStateTest.php`
Expected: FAIL with `Call to undefined method App\Entity\EntryState::markFavorite()`.

- [ ] **Step 3: Replace the four setters in `src/Entity/EntryState.php`.**

Before (lines 63-101):
```php
    public function isHidden(): bool
    {
        return $this->isHidden;
    }

    public function setIsHidden(bool $isHidden): void
    {
        $this->isHidden = $isHidden;
    }

    public function isFavorite(): bool
    {
        return $this->isFavorite;
    }

    public function setIsFavorite(bool $isFavorite): void
    {
        $this->isFavorite = $isFavorite;
    }

    public function isKept(): bool
    {
        return $this->isKept;
    }

    public function setIsKept(bool $isKept): void
    {
        $this->isKept = $isKept;
    }

    public function getHiddenAt(): ?\DateTimeImmutable
    {
        return $this->hiddenAt;
    }

    public function setHiddenAt(?\DateTimeImmutable $hiddenAt): void
    {
        $this->hiddenAt = $hiddenAt;
    }
```
After:
```php
    public function isHidden(): bool
    {
        return $this->isHidden;
    }

    public function isFavorite(): bool
    {
        return $this->isFavorite;
    }

    public function markFavorite(): void
    {
        $this->isFavorite = true;
    }

    public function clearFavorite(): void
    {
        $this->isFavorite = false;
    }

    public function isKept(): bool
    {
        return $this->isKept;
    }

    public function markKept(): void
    {
        $this->isKept = true;
    }

    public function clearKept(): void
    {
        $this->isKept = false;
    }

    public function getHiddenAt(): ?\DateTimeImmutable
    {
        return $this->hiddenAt;
    }
```

- [ ] **Step 4: Run the entity tests to verify they pass.**

Run: `php bin/phpunit tests/Entity/EntryStateTest.php`
Expected: PASS.

- [ ] **Step 5: Pin the clearing path, then switch `EntryStateUpdater`.** `EntryStateUpdaterTest` covers only `isFavorite: true` and `isKept: true`, so first add this test after `testKeptDoesNotMirror()` in `tests/Service/Reader/EntryStateUpdaterTest.php`. It passes on the current code, and it is the net for the switch.

```php
    public function testUnfavouritingAndUnkeepingClearBothFlags(): void
    {
        [$user, $target] = $this->seedGroup();
        $row = $this->rows()->getOneRowForUser($target->requireId(), $user->requireId());
        $this->updater()->apply($user, $row, $this->request(isFavorite: true, isKept: true));

        $this->updater()->apply($user, $row, $this->request(isFavorite: false, isKept: false));

        self::assertFalse($this->stateOf($user, $target)->isFavorite());
        self::assertFalse($this->stateOf($user, $target)->isKept());
    }
```

Run: `php bin/phpunit tests/Service/Reader/EntryStateUpdaterTest.php`
Expected: PASS (before the switch).

Then switch `src/Service/Reader/EntryStateUpdater.php::applyTo()`. The switch uses the same ternary-statement style as the `isHidden`/`isViewed` branches beside it.

Before (lines 47-52):
```php
        if ($request->isFavorite !== null) {
            $state->setIsFavorite($request->isFavorite);
        }
        if ($request->isKept !== null) {
            $state->setIsKept($request->isKept);
        }
```
After:
```php
        if ($request->isFavorite !== null) {
            $request->isFavorite ? $state->markFavorite() : $state->clearFavorite();
        }
        if ($request->isKept !== null) {
            $request->isKept ? $state->markKept() : $state->clearKept();
        }
```

- [ ] **Step 6: Give the restore its own byte-faithful path (D3), test first.**

On develop, `RestoreEntryLoader::stateFor()` writes a backup line's `isHidden` and `hiddenAt` verbatim. Two pairs matter here:
- `isHidden: true` with `hiddenAt: null` is a legacy "read, instant unknown".
- `isHidden: false` with a stale `hiddenAt` keeps the stale instant.

Both must come back exactly. `hide()` would stamp an instant, and `markUnread()` would drop one, so the restore gets its own method, with a value object that carries the pair as the file does.

**6a. Pin today's behaviour through the real restore.** In `tests/Service/Backup/EntryPartRestorerTest.php`, add this test directly after `testItCreatesTheEntriesAndTheirStates()`:

```php
    public function testReadMarksAreRestoredExactlyAsBackedUpIncludingALegacyUndatedOne(): void
    {
        $user = $this->subscribedUser(self::FEED_URL);
        $gzip = $this->entryPart([
            $this->entryLine('a'),
            $this->entryLine('b'),
            $this->entryLine('c'),
            array_replace($this->entryStateLine('a'), ['isHidden' => true, 'hiddenAt' => '2026-08-02T00:00:00+00:00']),
            array_replace($this->entryStateLine('b'), ['isHidden' => true, 'isKept' => true]),
            array_replace($this->entryStateLine('c'), ['hiddenAt' => '2026-08-03T00:00:00+00:00']),
        ]);

        $this->restorer()->load($user, $gzip);

        $this->em->clear();
        $dated = $this->restoredStateOf($user, 'a');
        self::assertTrue($dated->isHidden());
        self::assertEquals(new \DateTimeImmutable('2026-08-02 00:00:00'), $dated->getHiddenAt());
        self::assertFalse($dated->isKept());
        $legacy = $this->restoredStateOf($user, 'b');
        self::assertTrue($legacy->isHidden());
        self::assertNull($legacy->getHiddenAt());
        self::assertTrue($legacy->isKept());
        self::assertFalse($legacy->isFavorite());
        $staleUnread = $this->restoredStateOf($user, 'c');
        self::assertFalse($staleUnread->isHidden());
        self::assertEquals(new \DateTimeImmutable('2026-08-03 00:00:00'), $staleUnread->getHiddenAt());
    }
```

Add this helper directly after the existing private `stateFor()`:

```php
    private function restoredStateOf(User $user, string $token): EntryState
    {
        $entry = $this->findEntry($token);
        self::assertNotNull($entry);
        $state = $this->stateFor($user, $entry);
        self::assertNotNull($state);

        return $state;
    }
```

Run: `php bin/phpunit tests/Service/Backup/EntryPartRestorerTest.php --filter LegacyUndated`
Expected: PASS on the unchanged loader. This test is the net for the switch below, and it must still pass after it.

**6b. Write the failing entity tests.** Append to `tests/Entity/EntryStateTest.php`, before `makeState()`, and add `use App\Entity\BackedUpReadMark;` before `use App\Entity\Entry;`:

```php
    public function testARestoredLegacyReadMarkKeepsItsMissingInstant(): void
    {
        $state = $this->makeState();

        $state->restoreReadMark(new BackedUpReadMark(true, null));

        self::assertTrue($state->isHidden());
        self::assertNull($state->getHiddenAt());
    }

    public function testARestoredUnreadMarkKeepsAStaleInstantVerbatim(): void
    {
        $state = $this->makeState();
        $staleInstant = new \DateTimeImmutable('2026-08-03T00:00:00Z');

        $state->restoreReadMark(new BackedUpReadMark(false, $staleInstant));

        self::assertFalse($state->isHidden());
        self::assertSame($staleInstant, $state->getHiddenAt());
    }

    public function testRestoringAReadMarkLeavesTheOtherFlagsAlone(): void
    {
        $state = $this->makeState();
        $state->markFavorite();

        $state->restoreReadMark(new BackedUpReadMark(true, new \DateTimeImmutable('2026-08-02T00:00:00Z')));

        self::assertTrue($state->isFavorite());
        self::assertFalse($state->isViewed());
    }
```

Run: `php bin/phpunit tests/Entity/EntryStateTest.php`
Expected: FAIL with `Class "App\Entity\BackedUpReadMark" not found`.

**6c. Create `src/Entity/BackedUpReadMark.php`.** It is a readonly value object with no Doctrine mapping, like `EntryAttachment`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

/** An entry's read flag and read instant exactly as a backup file carries them, legacy pairs included. */
final readonly class BackedUpReadMark
{
    public function __construct(
        public bool $isHidden,
        public ?\DateTimeImmutable $hiddenAt,
    ) {
    }
}
```

**6d. Add the restore path to `src/Entity/EntryState.php`**, directly after `getHiddenAt()` (as it stands after Step 3).

Before:
```php
    public function getHiddenAt(): ?\DateTimeImmutable
    {
        return $this->hiddenAt;
    }

    public function isViewed(): bool
```
After:
```php
    public function getHiddenAt(): ?\DateTimeImmutable
    {
        return $this->hiddenAt;
    }

    // Restore only: a legacy "read, instant unknown" (null hiddenAt) must survive, which hide() cannot express.
    public function restoreReadMark(BackedUpReadMark $readMark): void
    {
        $this->isHidden = $readMark->isHidden;
        $this->hiddenAt = $readMark->hiddenAt;
    }

    public function isViewed(): bool
```

Run: `php bin/phpunit tests/Entity/EntryStateTest.php`
Expected: PASS.

**6e. Switch `src/Service/Backup/RestoreEntryLoader.php`.** Add `use App\Entity\BackedUpReadMark;` before `use App\Entity\Entry;`. The viewed comment is trimmed to one line, because its method is rewritten.

Before (lines 114-132):
```php
    private function stateFor(EntryStateLine $line, int $entryId): EntryState
    {
        $entry = $this->em->getReference(Entry::class, $entryId)
            ?? throw new \LogicException('An entry this restore just wrote has no reference.');
        $state = new EntryState($this->userReference(), $entry);
        $state->setIsHidden($line->isHidden);
        $state->setIsFavorite($line->isFavorite);
        $state->setIsKept($line->isKept);
        $state->setHiddenAt($line->hiddenAt);
        if ($line->isViewed) {
            // markViewed() is the only way in (#307, one-way by design) and it
            // needs an instant. A file that says "viewed" without a timestamp
            // keeps the flag — the fact that matters to the recommendation
            // history — and is stamped with the restore's own time.
            $state->markViewed($line->viewedAt ?? $this->clock->now());
        }

        return $state;
    }
```
After:
```php
    private function stateFor(EntryStateLine $line, int $entryId): EntryState
    {
        $entry = $this->em->getReference(Entry::class, $entryId)
            ?? throw new \LogicException('An entry this restore just wrote has no reference.');
        $state = new EntryState($this->userReference(), $entry);
        $state->restoreReadMark(new BackedUpReadMark($line->isHidden, $line->hiddenAt));
        if ($line->isFavorite) {
            $state->markFavorite();
        }
        if ($line->isKept) {
            $state->markKept();
        }
        if ($line->isViewed) {
            // A "viewed" line without its timestamp keeps the flag and takes the restore's own time (#307).
            $state->markViewed($line->viewedAt ?? $this->clock->now());
        }

        return $state;
    }
```

A fresh `EntryState` starts with favourite and kept false. So `markFavorite()`/`markKept()` behind a true flag writes exactly what `setIsFavorite($line->isFavorite)`/`setIsKept($line->isKept)` wrote.

Run: `php bin/phpunit tests/Service/Backup`
Expected: PASS, including 6a's test unchanged.

- [ ] **Step 7: Fix the E2E seed's stale `hiddenAt`, test first.** In `tests/Command/E2eSeedAdminSubscriptionCommandTest.php`, `testUnreadsTheEntryWhenAnEntryStateMarkedItRead()`:

Before (lines 258-259):
```php
        self::assertInstanceOf(EntryState::class, $reloaded);
        self::assertFalse($reloaded->isHidden());
```
After:
```php
        self::assertInstanceOf(EntryState::class, $reloaded);
        self::assertFalse($reloaded->isHidden());
        self::assertNull($reloaded->getHiddenAt());
```
Apply that test's arrangement line from Step 8 (`setIsHidden(true)` → `hide(…)`) in the same edit, then run `php bin/phpunit tests/Command/E2eSeedAdminSubscriptionCommandTest.php`. Expected: FAIL on `assertNull`, because `setIsHidden(false)` leaves `hiddenAt` behind. This is the #1164 bug.

Then, in `src/Command/E2eSeedAdminSubscriptionCommand.php`:
```diff
# line 192
-            $state->setIsHidden(false);
+            $state->markUnread();
```
`markUnread()` also clears "viewed". A viewed-but-unhidden row would be re-hidden on flush by `ViewedImpliesHiddenListener`, so the old setter could not unread a viewed fixture entry at all.

Run the same test again. Expected: PASS.

- [ ] **Step 8: Replace every flag setter in the tests.** A read mark gets a fixed instant. No query reads `hiddenAt`: only `EntryStateJson` and the backup do, and none of these tests asserts it. An explicit unread becomes `markUnread()`, which is a no-op on a fresh state, exactly as `setIsHidden(false)` was.

These four sites are not one-line substitutions:

`tests/Entity/SubscriptionTest.php`:

Before (lines 100-101):
```php
        $state->setIsHidden(true);
        $state->setHiddenAt(new \DateTimeImmutable());
```
After:
```php
        $state->hide(new \DateTimeImmutable());
```

`tests/Service/Backup/AccountRestorerTest.php`:

Before (lines 292-294):
```php
        $read->setIsHidden(true);
        $read->setIsFavorite(true);
        $read->setHiddenAt(new \DateTimeImmutable('2026-08-05 10:00:00'));
```
After:
```php
        $read->hide(new \DateTimeImmutable('2026-08-05 10:00:00'));
        $read->markFavorite();
```
```diff
# line 297
-        $viewed->setIsKept(true);
+        $viewed->markKept();
```

`tests/Service/Reader/SearchMarkReadServiceTest.php`. The helper's `bool $isHidden` parameter is a flag, and #1167 owns it.

Before (line 67):
```php
        $state->setIsHidden($isHidden);
```
After:
```php
        $isHidden ? $state->hide(new \DateTimeImmutable('2026-07-05T00:00:00Z')) : $state->markUnread();
```

`tests/Service/Subscription/SubscriptionTallyReaderTest.php` (added by #1157 PR B; `$when` is the test's own `2026-07-01T00:00:00Z` from line 20):

Before (lines 30-31):
```php
        $state->setIsHidden(true);
        $state->setIsFavorite(true);
```
After:
```php
        $state->hide($when);
        $state->markFavorite();
```

The remaining one-line substitutions, per file. Where one line's text repeats in a file, every occurrence is a site: use Edit with replace_all.

`tests/Command/E2eSeedAdminSubscriptionCommandTest.php`:
```diff
# line 248
-        $state->setIsHidden(true);
+        $state->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
```

`tests/Controller/Api/AccountBackupControllerTest.php`:
```diff
# line 64
-        $state->setIsFavorite(true);
+        $state->markFavorite();
```

`tests/Controller/Api/EntrySearchControllerTest.php`:
```diff
# line 206
-        $explicitUnread->setIsHidden(false);
+        $explicitUnread->markUnread();
# line 208
-        $explicitRead->setIsHidden(true);
+        $explicitRead->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
```

`tests/Entity/SubscriptionTest.php`:
```diff
# line 147
-        $state->setIsFavorite(true);
+        $state->markFavorite();
# line 148
-        $state->setIsKept(true);
+        $state->markKept();
```

`tests/Repository/DuplicateCollapseTest.php`:
```diff
# lines 75, 187 (identical; Edit with replace_all)
-        $read->setIsHidden(true);
+        $read->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
```

`tests/Repository/EntryListTest.php`:
```diff
# line 385
-        $state->setIsHidden(false);
+        $state->markUnread();
# line 401
-        $s1->setIsFavorite(true);
+        $s1->markFavorite();
# line 403
-        $s2->setIsKept(true);
+        $s2->markKept();
# line 611
-        $favoriteState->setIsFavorite(true);
+        $favoriteState->markFavorite();
# line 644
-        $strangerState->setIsHidden(true);
+        $strangerState->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
# line 645
-        $strangerState->setIsFavorite(true);
+        $strangerState->markFavorite();
# line 753
-        $state->setIsFavorite(true);
+        $state->markFavorite();
# line 1067
-        $state->setIsHidden(true);
+        $state->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
```

`tests/Repository/EntrySearchTest.php`:
```diff
# line 239
-        $read->setIsHidden(true);
+        $read->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
```

`tests/Repository/EntryStateRepositoryTest.php`:
```diff
# lines 67, 141 (identical; Edit with replace_all)
-        $state->setIsFavorite(true);
+        $state->markFavorite();
# line 101
-        $mineState->setIsFavorite(true);
+        $mineState->markFavorite();
# line 105
-        $theirState->setIsKept(true);
+        $theirState->markKept();
```

`tests/Repository/RecommendationFeedTest.php`:
```diff
# line 175
-        $favState->setIsFavorite(true);
+        $favState->markFavorite();
# line 177
-        $keptState->setIsKept(true);
+        $keptState->markKept();
```

`tests/Repository/StateCountsTest.php`:
```diff
# line 44
-        $fav->setIsFavorite(true);
+        $fav->markFavorite();
# line 48
-        $kept->setIsKept(true);
+        $kept->markKept();
# line 52
-        $both->setIsFavorite(true);
+        $both->markFavorite();
# line 53
-        $both->setIsKept(true);
+        $both->markKept();
# line 109
-        $orphan->setIsFavorite(true);
+        $orphan->markFavorite();
# line 110
-        $orphan->setIsKept(true);
+        $orphan->markKept();
# line 135
-        $theirs->setIsFavorite(true);
+        $theirs->markFavorite();
```

`tests/Repository/SubscriptionEntryCountsTest.php`:
```diff
# line 28
-        $state->setIsHidden(true);
+        $state->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
```

`tests/Repository/UnreadCountsTest.php`:
```diff
# line 43
-                $st->setIsHidden(true);
+                $st->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
# line 157
-        $state->setIsHidden(true);
+        $state->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
```

`tests/Repository/UnreadMatchingEntryIdsForUserTest.php`:
```diff
# line 91
-        $state->setIsHidden(true);
+        $state->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
```

`tests/Service/Backup/AccountBackupExporterTest.php`:
```diff
# line 130
-        $state->setIsFavorite(true);
+        $state->markFavorite();
# line 229
-        $subscribedState->setIsFavorite(true);
+        $subscribedState->markFavorite();
# line 248
-        $orphanState->setIsFavorite(true);
+        $orphanState->markFavorite();
```

`tests/Service/Backup/AccountRestorerTest.php`:
```diff
# line 844
-            $state->setIsFavorite(true);
+            $state->markFavorite();
```

`tests/Service/Backup/EntryPartRestorerTest.php`:
```diff
# line 126
-        $state->setIsFavorite(false);
+        $state->clearFavorite();
# line 314
-        $existingState->setIsFavorite(false);
+        $existingState->clearFavorite();
```

`tests/Service/Reader/EntryStateResolverTest.php`:
```diff
# line 110
-        $state->setIsFavorite(true);
+        $state->markFavorite();
# line 142
-        $existing->setIsKept(true);
+        $existing->markKept();
```

`tests/Service/Reader/MarkReadServiceTest.php`:
```diff
# lines 56, 126, 227 (identical; Edit with replace_all)
-        $state->setIsHidden(false);
+        $state->markUnread();
# line 127
-        $state->setIsFavorite(true);
+        $state->markFavorite();
# line 128
-        $state->setIsKept(true);
+        $state->markKept();
# line 239
-        $includedState->setIsHidden(false);
+        $includedState->markUnread();
```

`tests/Service/Recommendation/RecommendationCandidateLoaderTest.php`:
```diff
# line 51
-        $state->setIsHidden(true);
+        $state->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
# line 85
-        $state->setIsFavorite(true);
+        $state->markFavorite();
# line 98
-        $state->setIsKept(true);
+        $state->markKept();
# line 133
-        $state->setIsFavorite(false);
+        $state->clearFavorite();
# line 134
-        $state->setIsKept(false);
+        $state->clearKept();
```

`tests/Service/Recommendation/RecommendationHistoryLoaderTest.php`:
```diff
# line 54
-        $stateA->setIsFavorite(true);
+        $stateA->markFavorite();
# line 55
-        $stateA->setIsKept(true);
+        $stateA->markKept();
# lines 60, 93 (identical; Edit with replace_all)
-        $stateB->setIsKept(true);
+        $stateB->markKept();
# line 69
-        $stateE->setIsFavorite(true);
+        $stateE->markFavorite();
# lines 134, 149, 162, 176 (identical; Edit with replace_all)
-        $state->setIsFavorite(true);
+        $state->markFavorite();
```

`tests/Service/Retention/EntryPrunerTest.php`:
```diff
# lines 303, 480 (identical; Edit with replace_all)
-        $favoriteState->setIsFavorite(true);
+        $favoriteState->markFavorite();
# lines 305, 416 (identical; Edit with replace_all)
-        $keptState->setIsKept(true);
+        $keptState->markKept();
# lines 307, 446 (identical; Edit with replace_all)
-        $readState->setIsHidden(true);
+        $readState->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
# line 340
-        $aliceRead->setIsHidden(true);
+        $aliceRead->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
# line 342
-        $bobKept->setIsKept(true);
+        $bobKept->markKept();
# line 361
-        $state->setIsHidden(true);
+        $state->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
```

`tests/Service/Search/IndexedEntrySearchTest.php`:
```diff
# line 71
-        $state->setIsHidden(true);
+        $state->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
```

`tests/Service/Search/LikeEntrySearchTest.php`:
```diff
# line 88
-        $unreadState->setIsHidden(false);
+        $unreadState->markUnread();
# line 90
-        $readState->setIsHidden(true);
+        $readState->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
```

`tests/Support/FullyPopulatedAccount.php`:
```diff
# line 153
-        $state->setIsFavorite(true);
+        $state->markFavorite();
# line 154
-        $state->setIsKept(true);
+        $state->markKept();
```

- [ ] **Step 9: Confirm that no flag setter is left.**

Run: `git grep -nE '\->(setIsHidden|setIsFavorite|setIsKept|setHiddenAt)\(' -- src tests`
Expected: no output.

- [ ] **Step 10: Run the affected tests.**

Run: `php bin/phpunit tests/Entity tests/Repository tests/Service tests/Command tests/Controller/Api/AccountBackupControllerTest.php tests/Controller/Api/EntrySearchControllerTest.php`
Expected: PASS.

- [ ] **Step 11: Deletion checks.** Paste the fail/restore outputs for each:
  - `EntryState::clearFavorite`, `false` → `true` → `EntryStateTest::testClearingAFavoriteUndoesIt`.
  - `EntryState::markKept`, `true` → `false` → `EntryStateTest::testMarkingKeptLeavesTheOtherFlagsAlone`.
  - `EntryState::restoreReadMark`, `$this->hiddenAt = $readMark->hiddenAt;` → `$this->hiddenAt = $readMark->hiddenAt ?? new \DateTimeImmutable('2000-01-01');` → `EntryStateTest::testARestoredLegacyReadMarkKeepsItsMissingInstant` and `EntryPartRestorerTest::testReadMarksAreRestoredExactlyAsBackedUpIncludingALegacyUndatedOne` fail.
  - `EntryState::restoreReadMark`, `$this->isHidden = $readMark->isHidden;` → `$this->isHidden = true;` → `EntryStateTest::testARestoredUnreadMarkKeepsAStaleInstantVerbatim` fails.
  - `RestoreEntryLoader::stateFor`, `if ($line->isKept)` → `if (true)` → the restorer test fails on `$dated->isKept()`.
  - `EntryStateUpdater::applyTo`, `clearFavorite()` → `markFavorite()` in the ternary → `EntryStateUpdaterTest::testUnfavouritingAndUnkeepingClearBothFlags`.
  - `EntryStateUpdater::applyTo`, `clearKept()` → `markKept()` in the ternary → the same test.

- [ ] **Step 12: Run the task gates.**

Run: `composer check && composer md`
Expected: green. `EntryStateUpdater::applyTo` stays under PHPMD's thresholds (cyclomatic 9, NPath 81).

- [ ] **Step 13: Commit.**

```bash
git add src/Entity/EntryState.php src/Entity/BackedUpReadMark.php src/Service/Reader/EntryStateUpdater.php src/Service/Backup/RestoreEntryLoader.php src/Command/E2eSeedAdminSubscriptionCommand.php tests
git commit -m "refactor(#1164): entry state keeps one API; the raw flag setters go"
```

---

### Task 6: `ensureRow(…, ?\DateTimeImmutable $hiddenSince)`

**Files:**
- Modify: `src/Repository/EntryStateRepository.php:42-72`
- Modify: `src/Service/Reader/EntryStateResolver.php:49`
- Test: `tests/Repository/EntryStateRepositoryTest.php` (call sites and one new test), `tests/Service/Reader/EntryStateResolverTest.php:112`

**Interfaces:**
- Consumes: Task 5 (`EntryStateRepositoryTest:141` and `EntryStateResolverTest:110` already call `markFavorite()`).
- Produces: `EntryStateRepository::ensureRow(int $userId, int $entryId, ?\DateTimeImmutable $hiddenSince): void`. A non-null `$hiddenSince` seeds the row read since that instant. Null seeds it unread. The bool that could contradict the date is gone. See D5 for the one unreachable edge.

- [ ] **Step 1: Write the failing test and move the existing calls to the new signature.** In `tests/Repository/EntryStateRepositoryTest.php`, replace the five identical calls with Edit replace_all:
```diff
# lines 125, 126, 137, 144, 174 (identical; Edit with replace_all)
-        $this->repo()->ensureRow($userId, $entryId, false, null);
+        $this->repo()->ensureRow($userId, $entryId, null);
# line 159
-        $this->repo()->ensureRow($userId, $entryId, true, $watermark);
+        $this->repo()->ensureRow($userId, $entryId, $watermark);
```
Then append this test before the class's closing `}`:
```php
    public function testEnsureRowWithoutAHiddenSinceSeedsAnUnreadRow(): void
    {
        $entry = $this->entry('ensure-unread');
        $userId = $this->user->requireId();
        $entryId = $entry->requireId();

        $this->repo()->ensureRow($userId, $entryId, null);

        $this->em->clear();
        $state = $this->repo()->findOneForUserEntry($userId, $entryId);
        self::assertNotNull($state);
        self::assertFalse($state->isHidden());
        self::assertNull($state->getHiddenAt());
    }
```

In `tests/Service/Reader/EntryStateResolverTest.php`:
```diff
# line 112
-        $this->repo()->ensureRow($this->user->requireId(), $entry->requireId(), false, null);
+        $this->repo()->ensureRow($this->user->requireId(), $entry->requireId(), null);
```

- [ ] **Step 2: Run them to verify they fail.**

Run: `php bin/phpunit tests/Repository/EntryStateRepositoryTest.php tests/Service/Reader/EntryStateResolverTest.php`
Expected: FAIL with an `ArgumentCountError` from `EntryStateRepository::ensureRow()`: 3 arguments passed, 4 expected.

- [ ] **Step 3: Implement.** In `src/Repository/EntryStateRepository.php`:

Before (lines 42-60):
```php
    /**
     * Idempotent insert of the (user, entry) state row, seeded for read state:
     * one racing writer wins, the other's INSERT is ignored rather than dying on
     * the duplicate primary key, and an existing row keeps its flags.
     */
    public function ensureRow(int $userId, int $entryId, bool $seedHidden, ?\DateTimeImmutable $seedHiddenAt): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $isMysql = DatabasePlatform::isMySql($connection);
        $conflictClause = $isMysql ? 'IGNORE' : 'OR IGNORE';

        $connection->executeStatement(
            sprintf(
                'INSERT %s INTO entry_state'
                . ' (user_id, entry_id, is_hidden, hidden_at, is_favorite, is_kept, is_viewed, viewed_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                $conflictClause,
            ),
            [$userId, $entryId, $seedHidden, $seedHiddenAt, false, false, false, null],
```
After:
```php
    /**
     * Idempotent insert of the (user, entry) state row, read since $hiddenSince or unread when it is null:
     * one racing writer wins, the other's INSERT is ignored, and an existing row keeps its flags.
     */
    public function ensureRow(int $userId, int $entryId, ?\DateTimeImmutable $hiddenSince): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $isMysql = DatabasePlatform::isMySql($connection);
        $conflictClause = $isMysql ? 'IGNORE' : 'OR IGNORE';

        $connection->executeStatement(
            sprintf(
                'INSERT %s INTO entry_state'
                . ' (user_id, entry_id, is_hidden, hidden_at, is_favorite, is_kept, is_viewed, viewed_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                $conflictClause,
            ),
            [$userId, $entryId, null !== $hiddenSince, $hiddenSince, false, false, false, null],
```

In `src/Service/Reader/EntryStateResolver.php`:
```diff
# line 49
-        $this->states->ensureRow($userId, $entryId, $row->isHidden, $row->isHidden ? $row->markedReadUntil : null);
+        $this->states->ensureRow($userId, $entryId, $row->isHidden ? $row->markedReadUntil : null);
```

- [ ] **Step 4: Run the tests to verify they pass.**

Run: `php bin/phpunit tests/Repository/EntryStateRepositoryTest.php tests/Service/Reader`
Expected: PASS. `EntryStateResolverTest::testResolveSeedsAnEffectivelyReadRowHiddenFromTheWatermark` still proves that a watermark-read row is seeded hidden at the watermark.

- [ ] **Step 5: Run the MySQL leg for the raw insert.** The `IGNORE` versus `OR IGNORE` statement is dialect-specific, and the bool is now computed.

Run: `docker compose exec php composer test -- --filter 'EntryStateRepositoryTest|EntryStateResolverTest'`. First confirm that the php container runs this checkout's code (the "check the container is current" rule).
Expected: PASS.

- [ ] **Step 6: Deletion check.** Change `null !== $hiddenSince` to `true`. Run `php bin/phpunit tests/Repository/EntryStateRepositoryTest.php --filter WithoutAHiddenSince` and see it fail, then restore by hand. Paste the outputs.

- [ ] **Step 7: Run the task gates.**

Run: `composer check && composer md`
Expected: green.

- [ ] **Step 8: Commit.**

```bash
git add src/Repository/EntryStateRepository.php src/Service/Reader/EntryStateResolver.php tests/Repository/EntryStateRepositoryTest.php tests/Service/Reader/EntryStateResolverTest.php
git commit -m "refactor(#1164): ensureRow seeds read state from one nullable instant"
```

---

## Finishing (PR A)

1. **Run the branch-wide sweeps.** Each line has its expected output.
   ```bash
   git grep -nE '\->(setLastFetchedAt|setLastSuccessfulFetchAt|setLastNewEntryAt|setNextFetchAt|setFetchIntervalMinutes|setConsecutiveFailures|setLastErrorMessage)\(' -- src tests   # nothing
   git grep -nE 'function set' -- src/Entity/FetchSchedule.php src/Entity/EntryState.php                            # nothing
   git grep -nE '\->(setStatus|setApprovedAt|setIsHidden|setIsFavorite|setIsKept|setHiddenAt)\(' -- src tests      # nothing
   git grep -nE '\->set(Etag|LastModified)\(' -- src tests            # only src/Http/CatalogFaviconResponse.php (a Response)
   git grep -n "'ROLE_ADMIN', \\$" -- src                              # only src/Entity/User.php
   git grep -n 'restoreReadMark(' -- src                              # EntryState (definition) and RestoreEntryLoader only
   ```
2. **Run the gates, all green:**
   - `composer check`
   - `composer md`
   - `php bin/phpunit` (SQLite)
   - `docker compose exec php composer test` (MySQL). First confirm that the php container runs this checkout's code.
   - `composer infection:diff`. `tests/Support/NewUserStatus.php`, `tests/Entity/UserStatusTest.php` and `src/Entity/BackedUpReadMark.php` are new, so run it after the last commit (infection:diff ignores untracked files).

   Then scan today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`. It must show no new deprecations or errors.
3. **Run the SDD final whole-branch review.** It is not optional. Ask the reviewer to attack these points:
   - **Did any transition change?**
     - `FeedScheduler::recordFailure` on the 30th failure versus `markGone`: the same five fields.
     - `recordThrottled` still writes `nextFetchAt` alone.
     - `approve()` re-stamps on every call, and `UserLimits` still calls it only when the account is not active.
     - `suspend()` keeps `approvedAt`.
     - `markUnread()` versus the old `setIsHidden(false)`: the only difference is the stale `hiddenAt` and the viewed flag (Task 5 Step 7).
   - **Is the restore byte-faithful (D3)?** Every `(isHidden, hiddenAt, isFavorite, isKept)` combination a backup line can carry must be written exactly as before. The viewed rule is unchanged. `restoreReadMark()` must have no caller outside `RestoreEntryLoader`.
   - **Did any wire contract change?** `git diff origin/develop --stat -- src/Http src/Controller` must be empty. `tests/Controller` may differ only by fixture substitutions and D4's `AdminUserControllerTest:775`, which follows from the fixture alone.
   - **D4's fixture consequences are the only intended differences.** Anything else is a bug.
   - **Did a setter survive without its ruled reason?** Compare against the D1 table.
   - **Is any new entity method called from a controller?** `composer stan` with `ControllerMutatesNoEntityRule` and its two callable siblings must be green, and `git diff origin/develop --stat -- src/Controller` must be empty.
   - **Does any new `App\Entity` class know HTTP?** `DomainKnowsNoHttpRule` (in `composer stan`) must be green for `BackedUpReadMark`.
   - **Is every comment the plan wrote or touched needed, one line where possible and three at most?**
4. **Run `/simplify`** over the branch diff. If it changed anything, re-run the gates from step 2.
5. **Open the PR against `develop`** titled `refactor(#1164): intention-revealing methods for Feed, User and EntryState (part A)`, with this body. It references #1164 and leaves the issue open; only Part B's PR ends it.

   ```markdown
   Refs #1164 (part A of two)

   Feed, User and EntryState now own their state transitions. The services that used to write them as setter sequences call named methods instead.

   - **Feed fetch schedule.** `recordSuccessfulFetch()`, `recordNewEntries()`, `recordFailedFetch()`, `markGone()` and
     `scheduleNextFetchAt()` replace 17 get/set forwarders and the status setter. `FetchSchedule` owns the fields and
     the 1000-character error cap. `FeedScheduler` keeps only the policy: interval, backoff, the gone threshold and
     the throttle wait.
   - **Feed cache validators.** `recordCacheValidators()` stores ETag and Last-Modified and caps them to their columns,
     so the two identical `truncate()` helpers go.
   - **User status.** `approve()`, `queueForApproval()`, `reject()` and `suspend()` replace `setStatus()` and
     `setApprovedAt()`, so an active account can no longer miss its approval stamp. `isAdmin()` replaces four role-list scans.
   - **EntryState.** `markFavorite()`/`clearFavorite()` and `markKept()`/`clearKept()` join `hide()`/`markUnread()`,
     and the raw flag setters are gone. The backup restore writes a read mark through `restoreReadMark()`, byte for
     byte as before, including a legacy mark without an instant. `ensureRow()` takes one nullable `$hiddenSince`.

   Setters that write one independent attribute (feed metadata, locale, trial end, subscription cap, role list,
   password hash, the active AI pointer) stay. No other setter is needed by Doctrine, which hydrates by reflection,
   or by forms, which the backend has none of.

   **Behaviour notes:**
   - The e2e seed now unreads its fixture entry with `markUnread()`, which also clears the stale `hiddenAt` it used
     to leave behind.
   - The e2e fixture feed is seeded as a successful fetch, so it is not due for an hour.
   - Test fixtures reach "active" through `approve()`, so an active fixture carries `approvedAt`. One admin-detail
     assertion follows from that fixture change.
   - No response body, status code or header changed.

   Part B (`UserPasskey`, `RecommendationRunLog`, `RecommendationRun`, `EntryMedium`/`EntryAttachment`) follows in a
   separate PR.
   ```
   Before posting, check the body for a closing keyword: `grep -niE '(close[sd]?|fix(e[sd])?|resolve[sd]?) #' body.md` must print nothing. The body mentions no other issue number.
6. **Merge when CI is green.**
   - Arm a Monitor that polls `gh pr checks <pr>` until every check has passed. Then run `gh pr merge <pr> --merge`.
   - Never use `gh pr merge --auto`: it merges immediately on this repository.
   - If a check fails, stop and report. If phptramp fails, look at `composer show larspohlmann/phptramp` before you blame the diff.
   - After the merge, confirm with `gh issue view 1164 --json state` that #1164 is still `OPEN`. If it closed, reopen it and tell Lars.
7. **Start Part B** from Task B0.

---

# Part B: the remaining #1164 findings (PR B, closes #1164)

**Goal:** Close #1164's remaining bullets (rulings D2, D6):
- `UserPasskey`'s 9-parameter constructor becomes `(owner, PasskeyRegistration)`.
- `RecommendationRunLog::finish()` takes an outcome value instead of five loose arguments.
- `RecommendationRun::recordTransportFailure()` stops answering a query while it mutates.
- `EntryMedium`/`EntryAttachment` stop naming their input `$data` and stop coercing a missing URL or kind to `''`.

**Architecture:**
- **`PasskeyRegistration`** (`src/Entity`, final readonly, unmapped) is one completed registration: what the authenticator minted (credential id, user handle, public key, signature counter, AAGUID, transports), the user's label, and the registration time. `UserPasskey::__construct(User $user, PasskeyRegistration $registration)` copies it into the unchanged columns, so there is no mapping or schema change.
- **`CallOutcome`** (`src/Entity`, final readonly, unmapped) is how a provider call settled: verdict, wire bytes, finished-at and finish reason.
  - `RecommendationRunLog::finish(string $responseText, CallOutcome $outcome)` takes it.
  - `App\Repository\CallSettlement` becomes `(int $logId, CallOutcome $outcome)` instead of repeating the four fields.
  - `RecordedCall` builds it.
  - `RecommendationCallRepository` reads it.
- **`RecommendationRun::recordTransportFailure(): void`** plus the query `hasExhaustedTransportRetries(): bool`. `RunCallAttempts::recordTransportFailure()` also becomes `void`: it returned the new count while mutating, the same smell. `RecommendationTransportFailureRecorder` records, then asks.
- **`EntryMedium::fromStored()` / `EntryAttachment::fromStored()`** replace `fromArray($data)`.
  - `isComplete()` says whether a stored item has its keys.
  - `EntryMedia` reads only complete items (D6).
  - `fromStored()` on an incomplete item throws `App\Entity\Exception\IncompleteStoredMediaException`. That is unreachable through `EntryMedia`, and it replaces the `''` magic value.

**Spec:** #1164's bullets on `Entity/UserPasskey.php:77`, `RecommendationRunLog::finish()`, `RecommendationRun::recordTransportFailure(): bool` and `EntryMedium`/`EntryAttachment`; rulings D2 and D6 above.

## Execution rulings (PR A)

Recorded while executing Tasks 0–6. Each ruling amends the text above.

- **F1 (Task 5 step order):** Step 3 only adds the new `EntryState` methods. The four raw setters go after Step 8, because their callers and the Step 5/6a/7 runs need them until then.
- **F2:** `FirstFetchRecorderTest` asserts the validators that the first fetch stores.
- **F3:** Cap tests use position-sensitive input (a distinct first character, then filler), so an `mb_substr` start-offset mutant dies.
- **F4:** `RegistrationService::enterSignupStatus` is a `match`, like `completeRegistration()`.
- **F5:** Explicit deletion checks run only where a task lists them. `infection:diff` covers the rest.
- **F6:** Commits stage explicit files, never directories.
- **F8:** The PR body says "15 setters" and states that the e2e seed's `markUnread()` now also clears viewed.
- **N3:** `E2eSeedAdminSubscriptionCommand::ensureFixtureFeed()` and `execute()` declare `@throws \DateMalformedStringException`.
- **N6:** No class docblocks on `FetchSchedule` or `FeedScheduler`, and no trailing comment on the touched `BulkSubscriber` line.
- **N7:** `FeedScheduler` is `final readonly class`.
- **N9:** The new-feed test in `BulkSubscriberTest` asserts `nextFetchAt` equals the clock's instant.
- **N10:** Tests build `BackedUpReadMark` with named arguments.
- **Infection:** Two mutants escaped at `UserRepository:161` (`array_values`) and `:233` (the lookalike filter). Tests now pin both: the list shape and the exact role.
- **Final review:**
  - Applied M1 and M3–M8, plus two /simplify items: `NewUserStatus` is now a `match`, and `FeedRepositoryTest` shares one persist helper.
  - M2 skipped: `infection:diff` did not report the `FeedScheduler` CastInt mutant.
- **Skipped /simplify findings:**
  - `User::enterStatus(UserStatus)` and `EntryState::applyFavorite(bool)` were proposed. Both would bring back the status setter or boolean flag that this issue removes.
  - The e2e seed's `recordSuccessfulFetch()` stays, as Task 1 mandates.

## Status (PR B)

| Task | State |
|---|---|
| Task B0: Preflight (PR A merged) | ⬜ not started |
| Task B1: `UserPasskey` is built from a `PasskeyRegistration` | ⬜ not started |
| Task B2: `RecommendationRunLog::finish(string, CallOutcome)`; `CallSettlement` composes `CallOutcome` | ⬜ not started |
| Task B3: `recordTransportFailure(): void` + `hasExhaustedTransportRetries()` | ⬜ not started |
| Task B4: `EntryMedium`/`EntryAttachment` `fromStored()`; incomplete items are dropped (D6) | ⬜ not started |

## Overlaps (PR B)

- **#1162** owns `RecordedCall::settle(string, bool $usable)` (its flag parameter). Task B2 changes one other line of `RecordedCall` (`settlement()`), and leaves `settle()` alone.
- **#1182** (the follow-up #1158's Appendix C names) may later move `AttestationVerifier`'s `RegisterPasskeyRequest` DTO. Task B1 touches only `passkeyFrom()` and one import.
- **#1158** changed no file Part B edits. #1157 PR B changed two: `RecommendationRunLogRepositoryTest` (the `getOwned` tests and an import, so the `finish()` sites moved +1 line) and `RecommendationDebugLogControllerTest` (its `finish()` blocks are unchanged). #1158's new `PasskeyListing` → `AccountPasskeys` → `PasskeyJson` and `RecommendationDebugLogLoader` → `RecommendationDebugLog` → `RecommendationDebugLogJson` paths only read `UserPasskey` getters and `DebugLogRow` arrays, which Tasks B1 and B2 leave unchanged.

## Global Constraints (PR B)

- **Everything in PR A's Global Constraints applies**, including the three-parameter limit. The branch is `refactor/1164-intention-revealing-entities-b`, cut from `origin/develop` after PR A merged.
- **No behaviour or wire-contract change apart from D6** (ruled, deliberate, listed in the PR body). Every complete medium or attachment serialises byte-identically, and every other response is unchanged.
- **No schema change.** `PasskeyRegistration`, `CallOutcome` and `BackedUpReadMark` carry no Doctrine attribute. `bin/console doctrine:schema:validate` stays green.
- **Commit format:** `refactor(#1164): …`, one commit per task.

---

### Task B0: Preflight (PR A merged)

**Files:** none changed.

- [ ] **Step 1: Check that the checkout is free.**

Run: `git status --short && git branch --show-current`
Expected: a clean tree. Otherwise stop and ask Lars; do not stash, reset or check out over it.

- [ ] **Step 2: Confirm that PR A is on develop and #1164 is still open.** Run from the repository root:
```bash
git fetch origin develop
gh pr list --state merged --search "1164 in:title" --json number,title,mergedAt
git grep -n "function restoreReadMark\|function recordSuccessfulFetch\|function approve" origin/develop -- backend/src/Entity
gh issue view 1164 --json state
```
Expected: PR A listed as merged; the three methods found on `origin/develop`; `{"state":"OPEN"}`. If PR A has not merged, stop: Part B starts from it.

- [ ] **Step 3: Re-run the inventory sweeps.** Run from the repository root:
```bash
git grep -n "new UserPasskey(" origin/develop -- backend/src backend/tests
git grep -nE "\->finish\(" origin/develop -- backend/src backend/tests | grep -v "loader->finish\|zip->finish"
git grep -n "recordTransportFailure" origin/develop -- backend/src backend/tests
git grep -n "CallSettlement\|settlement->" origin/develop -- backend/src backend/tests
git grep -nE "(EntryMedium|EntryAttachment)::fromArray" origin/develop -- backend/src backend/tests
```
Expected at 6ca53dd8 (PR A touches none of these files):
- 15 `new UserPasskey(`: 1 in `Service/Passkey/AttestationVerifier.php:178`, and 14 in tests (`UserPasskeyTest` ×9, one each in `AdminSettingsControllerTest`, `PasskeyListTest`, `PasskeyRegistrationTest`, `PasskeyRemovalPolicyTest`, `PasskeySignInAvailabilityTest`).
- 6 `->finish(` on a run log, all in tests (`RecommendationDebugLogControllerTest:80,277`, `RecommendationRunLogRepositoryTest:52,105`, `RecommendationRunTimingRepositoryTest:113`, `RecommendationEtaEstimatorTest:136`). The grep also prints `RecordedCall.php:81,86`: `$this->finish(` there is `RecordedCall`'s own private method, not a run log, and it stays.
- `recordTransportFailure` in `RecommendationRun`, `RunCallAttempts`, `RecommendationTransportFailureRecorder:46`, `RecommendationRunTest:149-218` and `RunCallAttemptsTest:35-68` (plus docblock mentions at `RecommendationRun:22`, `RunCallAttempts:13`, `RecommendationTransportFailureRecorder:18` and `RecommendationRunAdvancerTest:2058`, which stay true).
- `CallSettlement` in `Repository/CallSettlement.php`, `RecommendationCallRepository:38-57` and `RecordedCall:8,133,135`. `RecordedCall:116,130` call `settlement()`, which the pattern does not match.
- 3 `fromArray` lines: `Entity/EntryMedia.php:60,66`, and `tests/PhpStan/data/controller-mutates-no-entity-fixtures.php:215` (the rule fixture #1157 PR B added; Task B4 Step 3d renames it).

If anything differs, amend the matching task's blocks in this plan first.

- [ ] **Step 4: Re-verify every before-block** of Tasks B1–B4 against `git show origin/develop:backend/<path>`.

- [ ] **Step 5: Record a PHPMD baseline.**

Run: `cd backend && composer md 2>&1 | grep -E 'Entity/(UserPasskey|RecommendationRunLog|RecommendationRun|RunCallAttempts|EntryMedium|EntryAttachment|EntryMedia)\.php|Passkey/AttestationVerifier\.php|Repository/(CallSettlement|RecommendationCallRepository)\.php|Recommendation/(RecordedCall|RecommendationTransportFailureRecorder)\.php' || echo CLEAN`
Expected: `CLEAN`. If not, stop and ask Lars.

- [ ] **Step 6: Create the branch.**

Run: `git switch -c refactor/1164-intention-revealing-entities-b origin/develop`

---

### Task B1: `UserPasskey` is built from a `PasskeyRegistration`

**Files:**
- Create: `src/Entity/PasskeyRegistration.php`
- Modify: `src/Entity/UserPasskey.php:76-99`
- Modify: `src/Service/Passkey/AttestationVerifier.php:7-9, 178-188`
- Test: `tests/Entity/UserPasskeyTest.php` (the round-trip test gains assertions; all nine constructions change)
- Test fixtures: `tests/Controller/Admin/AdminSettingsControllerTest.php`, `tests/Controller/Api/PasskeyListTest.php`, `tests/Controller/Api/PasskeyRegistrationTest.php`, `tests/Service/Passkey/PasskeyRemovalPolicyTest.php`, `tests/Service/Passkey/PasskeySignInAvailabilityTest.php`

**Interfaces:**
- Produces:
  - `App\Entity\PasskeyRegistration::__construct(string $credentialId, string $userHandle, string $publicKey, int $signatureCounter, ?string $aaguid, array $transports, string $label, \DateTimeImmutable $registeredAt)`, where `$transports` is a `list<string>`.
  - `UserPasskey::__construct(User $user, PasskeyRegistration $registration)`.

  Every getter is unchanged. `$registeredAt` is stored as the existing `createdAt` column.

- [ ] **Step 1: Make the round-trip test prove every column.** The existing test is the one place that reads a stored passkey back, so it gains the assertions that catch a swapped copy in the new constructor. In `tests/Entity/UserPasskeyTest.php`, add `use App\Entity\PasskeyRegistration;` before `use App\Entity\User;`, then:

Before (lines 16-39):
```php
    public function testACredentialRoundTripsThroughTheDatabase(): void
    {
        $user = $this->user('passkey-owner@example.test');
        $passkey = new UserPasskey(
            $user,
            'Y3JlZC1hYmM',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            '00000000-0000-0000-0000-000000000000',
            ['internal', 'hybrid'],
            'MacBook Touch ID',
            new \DateTimeImmutable('2026-08-29 10:00:00'),
        );
        $this->em->persist($passkey);
        $this->em->flush();
        $this->em->clear();

        $found = $this->repository()->findOneByCredentialId('Y3JlZC1hYmM');

        self::assertNotNull($found);
        self::assertSame(['internal', 'hybrid'], $found->getTransports());
        self::assertNull($found->getLastUsedAt());
    }
```
After:
```php
    public function testACredentialRoundTripsThroughTheDatabase(): void
    {
        $user = $this->user('passkey-owner@example.test');
        $passkey = new UserPasskey(
            $user,
            new PasskeyRegistration(
                'Y3JlZC1hYmM',
                'aGFuZGxl',
                'cHVibGljLWtleQ',
                17,
                '00000000-0000-0000-0000-000000000000',
                ['internal', 'hybrid'],
                'MacBook Touch ID',
                new \DateTimeImmutable('2026-08-29 10:00:00'),
            ),
        );
        $this->em->persist($passkey);
        $this->em->flush();
        $this->em->clear();

        $found = $this->repository()->findOneByCredentialId('Y3JlZC1hYmM');

        self::assertNotNull($found);
        self::assertSame($user->requireId(), $found->getUser()->requireId());
        self::assertSame('aGFuZGxl', $found->getUserHandle());
        self::assertSame('cHVibGljLWtleQ', $found->getPublicKey());
        self::assertSame(17, $found->getSignatureCounter());
        self::assertSame('00000000-0000-0000-0000-000000000000', $found->getAaguid());
        self::assertSame(['internal', 'hybrid'], $found->getTransports());
        self::assertSame('MacBook Touch ID', $found->getLabel());
        self::assertEquals(new \DateTimeImmutable('2026-08-29 10:00:00'), $found->getCreatedAt());
        self::assertNull($found->getLastUsedAt());
    }
```
The counter is 17, not 0: a counter left at the column default must not pass.

- [ ] **Step 2: Run it to verify it fails.**

Run: `php bin/phpunit tests/Entity/UserPasskeyTest.php --filter RoundTrips`
Expected: FAIL with `Class "App\Entity\PasskeyRegistration" not found`.

- [ ] **Step 3: Create `src/Entity/PasskeyRegistration.php`.** A value object's constructor carries its fields, which is why the constructor has eight parameters: `UserPasskey`'s constructor shrinks to two.

```php
<?php

declare(strict_types=1);

namespace App\Entity;

/** One completed WebAuthn registration: what the authenticator minted, the user's name for it, and when. */
final readonly class PasskeyRegistration
{
    /**
     * @param list<string> $transports
     */
    public function __construct(
        public string $credentialId,
        public string $userHandle,
        public string $publicKey,
        public int $signatureCounter,
        public ?string $aaguid,
        public array $transports,
        public string $label,
        public \DateTimeImmutable $registeredAt,
    ) {
    }
}
```

- [ ] **Step 4: Change the constructor in `src/Entity/UserPasskey.php`.**

Before (lines 76-99):
```php
    /**
     * @param list<string> $transports
     */
    public function __construct(
        User $user,
        string $credentialId,
        string $userHandle,
        string $publicKey,
        int $signatureCounter,
        ?string $aaguid,
        array $transports,
        string $label,
        \DateTimeImmutable $createdAt,
    ) {
        $this->user = $user;
        $this->credentialId = $credentialId;
        $this->userHandle = $userHandle;
        $this->publicKey = $publicKey;
        $this->signatureCounter = $signatureCounter;
        $this->aaguid = $aaguid;
        $this->transports = $transports;
        $this->label = $label;
        $this->createdAt = $createdAt;
    }
```
After:
```php
    public function __construct(User $user, PasskeyRegistration $registration)
    {
        $this->user = $user;
        $this->credentialId = $registration->credentialId;
        $this->userHandle = $registration->userHandle;
        $this->publicKey = $registration->publicKey;
        $this->signatureCounter = $registration->signatureCounter;
        $this->aaguid = $registration->aaguid;
        $this->transports = $registration->transports;
        $this->label = $registration->label;
        $this->createdAt = $registration->registeredAt;
    }
```

- [ ] **Step 5: Switch `AttestationVerifier`.** Add `use App\Entity\PasskeyRegistration;` after `use App\Dto\Passkey\RegisterPasskeyRequest;`.

Before (lines 178-188):
```php
        return new UserPasskey(
            $user,
            $credentialId,
            Base64UrlSafe::encodeUnpadded($record->userHandle),
            Base64UrlSafe::encodeUnpadded($record->credentialPublicKey),
            $record->counter,
            self::aaguidOrNull($record->aaguid),
            self::knownTransports($record->transports),
            $label,
            $this->clock->now(),
        );
```
After:
```php
        return new UserPasskey($user, new PasskeyRegistration(
            $credentialId,
            Base64UrlSafe::encodeUnpadded($record->userHandle),
            Base64UrlSafe::encodeUnpadded($record->credentialPublicKey),
            $record->counter,
            self::aaguidOrNull($record->aaguid),
            self::knownTransports($record->transports),
            $label,
            $this->clock->now(),
        ));
```

- [ ] **Step 6: Switch every test construction.** Each block moves the eight arguments after the owner into a `PasskeyRegistration`, in the same order and with the same values. Named-argument sites keep their names: the owner's second argument becomes `registration:`, and `createdAt:` becomes `registeredAt:`. `UserPasskeyTest`'s round-trip construction is already done in Step 1; its other eight constructions follow here.

`tests/Controller/Admin/AdminSettingsControllerTest.php`: add `use App\Entity\PasskeyRegistration;` directly before `use App\Entity\User;`.

Before (lines 90-100):
```php
        $passkey = new UserPasskey(
            $owner,
            credentialId: bin2hex(random_bytes(16)),
            userHandle: bin2hex(random_bytes(16)),
            publicKey: 'test-public-key',
            signatureCounter: 0,
            aaguid: null,
            transports: [],
            label: 'Test passkey',
            createdAt: new \DateTimeImmutable('2026-08-29 10:00:00'),
        );
```
After:
```php
        $passkey = new UserPasskey(
            $owner,
            registration: new PasskeyRegistration(
                credentialId: bin2hex(random_bytes(16)),
                userHandle: bin2hex(random_bytes(16)),
                publicKey: 'test-public-key',
                signatureCounter: 0,
                aaguid: null,
                transports: [],
                label: 'Test passkey',
                registeredAt: new \DateTimeImmutable('2026-08-29 10:00:00'),
            ),
        );
```

`tests/Controller/Api/PasskeyListTest.php`: add `use App\Entity\PasskeyRegistration;` directly before `use App\Entity\User;`.

Before (lines 230-240):
```php
        $passkey = new UserPasskey(
            $user,
            $credentialId,
            $userHandle,
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            $label,
            $createdAt ?? new \DateTimeImmutable(),
        );
```
After:
```php
        $passkey = new UserPasskey(
            $user,
            new PasskeyRegistration(
                $credentialId,
                $userHandle,
                'cHVibGljLWtleQ',
                0,
                null,
                [],
                $label,
                $createdAt ?? new \DateTimeImmutable(),
            ),
        );
```

`tests/Controller/Api/PasskeyRegistrationTest.php`: add `use App\Entity\PasskeyRegistration;` directly before `use App\Entity\User;`.

Before (lines 703-713):
```php
        $this->em()->persist(new UserPasskey(
            $user,
            $credentialId,
            $userHandle,
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'Test key',
            new \DateTimeImmutable(),
        ));
```
After:
```php
        $this->em()->persist(new UserPasskey(
            $user,
            new PasskeyRegistration(
                $credentialId,
                $userHandle,
                'cHVibGljLWtleQ',
                0,
                null,
                [],
                'Test key',
                new \DateTimeImmutable(),
            ),
        ));
```

`tests/Entity/UserPasskeyTest.php` (import added in Step 1):

Before (lines 49-59):
```php
        $this->em->persist(new UserPasskey(
            $user,
            'Sub-ABC',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'Key',
            new \DateTimeImmutable(),
        ));
```
After:
```php
        $this->em->persist(new UserPasskey(
            $user,
            new PasskeyRegistration(
                'Sub-ABC',
                'aGFuZGxl',
                'cHVibGljLWtleQ',
                0,
                null,
                [],
                'Key',
                new \DateTimeImmutable(),
            ),
        ));
```

Before (lines 72-82):
```php
        $passkey = new UserPasskey(
            $user,
            'Y3JlZC1yZWM',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'Key',
            new \DateTimeImmutable(),
        );
```
After:
```php
        $passkey = new UserPasskey(
            $user,
            new PasskeyRegistration(
                'Y3JlZC1yZWM',
                'aGFuZGxl',
                'cHVibGljLWtleQ',
                0,
                null,
                [],
                'Key',
                new \DateTimeImmutable(),
            ),
        );
```

Before (lines 102-112):
```php
        $passkey = new UserPasskey(
            $owner,
            'Y3JlZC1vd24',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'Key',
            new \DateTimeImmutable(),
        );
```
After:
```php
        $passkey = new UserPasskey(
            $owner,
            new PasskeyRegistration(
                'Y3JlZC1vd24',
                'aGFuZGxl',
                'cHVibGljLWtleQ',
                0,
                null,
                [],
                'Key',
                new \DateTimeImmutable(),
            ),
        );
```

Before (lines 128-138):
```php
        $older = new UserPasskey(
            $user,
            'Y3JlZC1vbGQ',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'Older',
            new \DateTimeImmutable('2026-08-01 00:00:00'),
        );
```
After:
```php
        $older = new UserPasskey(
            $user,
            new PasskeyRegistration(
                'Y3JlZC1vbGQ',
                'aGFuZGxl',
                'cHVibGljLWtleQ',
                0,
                null,
                [],
                'Older',
                new \DateTimeImmutable('2026-08-01 00:00:00'),
            ),
        );
```

Before (lines 139-149):
```php
        $newer = new UserPasskey(
            $user,
            'Y3JlZC1uZXc',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'Newer',
            new \DateTimeImmutable('2026-08-15 00:00:00'),
        );
```
After:
```php
        $newer = new UserPasskey(
            $user,
            new PasskeyRegistration(
                'Y3JlZC1uZXc',
                'aGFuZGxl',
                'cHVibGljLWtleQ',
                0,
                null,
                [],
                'Newer',
                new \DateTimeImmutable('2026-08-15 00:00:00'),
            ),
        );
```

Before (lines 166-176):
```php
        $this->em->persist(new UserPasskey(
            $user,
            'Y3JlZC1jbnQx',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'One',
            new \DateTimeImmutable(),
        ));
```
After:
```php
        $this->em->persist(new UserPasskey(
            $user,
            new PasskeyRegistration(
                'Y3JlZC1jbnQx',
                'aGFuZGxl',
                'cHVibGljLWtleQ',
                0,
                null,
                [],
                'One',
                new \DateTimeImmutable(),
            ),
        ));
```

Before (lines 177-187):
```php
        $this->em->persist(new UserPasskey(
            $other,
            'Y3JlZC1jbnQy',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'Two',
            new \DateTimeImmutable(),
        ));
```
After:
```php
        $this->em->persist(new UserPasskey(
            $other,
            new PasskeyRegistration(
                'Y3JlZC1jbnQy',
                'aGFuZGxl',
                'cHVibGljLWtleQ',
                0,
                null,
                [],
                'Two',
                new \DateTimeImmutable(),
            ),
        ));
```

Before (lines 200-210):
```php
        $this->em->persist(new UserPasskey(
            $user,
            'Y3JlZC13aXBl',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'Key',
            new \DateTimeImmutable(),
        ));
```
After:
```php
        $this->em->persist(new UserPasskey(
            $user,
            new PasskeyRegistration(
                'Y3JlZC13aXBl',
                'aGFuZGxl',
                'cHVibGljLWtleQ',
                0,
                null,
                [],
                'Key',
                new \DateTimeImmutable(),
            ),
        ));
```

`tests/Service/Passkey/PasskeyRemovalPolicyTest.php`: add `use App\Entity\PasskeyRegistration;` directly before `use App\Entity\User;`.

Before (lines 95-105):
```php
        return new UserPasskey(
            $this->user(null),
            'Y3JlZC1hYmM',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'Test key',
            new \DateTimeImmutable(),
        );
```
After:
```php
        return new UserPasskey(
            $this->user(null),
            new PasskeyRegistration(
                'Y3JlZC1hYmM',
                'aGFuZGxl',
                'cHVibGljLWtleQ',
                0,
                null,
                [],
                'Test key',
                new \DateTimeImmutable(),
            ),
        );
```

`tests/Service/Passkey/PasskeySignInAvailabilityTest.php`: add `use App\Entity\PasskeyRegistration;` directly before `use App\Entity\UserPasskey;`.

Before (lines 102-112):
```php
        $this->em()->persist(new UserPasskey(
            $owner,
            credentialId: bin2hex(random_bytes(16)),
            userHandle: bin2hex(random_bytes(16)),
            publicKey: 'test-public-key',
            signatureCounter: 0,
            aaguid: null,
            transports: [],
            label: 'Test passkey',
            createdAt: new \DateTimeImmutable('2026-08-29 10:00:00'),
        ));
```
After:
```php
        $this->em()->persist(new UserPasskey(
            $owner,
            registration: new PasskeyRegistration(
                credentialId: bin2hex(random_bytes(16)),
                userHandle: bin2hex(random_bytes(16)),
                publicKey: 'test-public-key',
                signatureCounter: 0,
                aaguid: null,
                transports: [],
                label: 'Test passkey',
                registeredAt: new \DateTimeImmutable('2026-08-29 10:00:00'),
            ),
        ));
```


- [ ] **Step 7: Confirm that no nine-argument construction is left, and run the tests.**

Run: `git grep -n -A2 "new UserPasskey(" -- src tests | grep -c "PasskeyRegistration("`
Expected: 15.

Run: `php bin/phpunit tests/Entity/UserPasskeyTest.php tests/Controller/Api/PasskeyListTest.php tests/Controller/Api/PasskeyRegistrationTest.php tests/Controller/Api/PasskeyLoginTest.php tests/Controller/Admin/AdminSettingsControllerTest.php tests/Service/Passkey`
Expected: PASS. `PasskeyRegistrationTest` drives the real `AttestationVerifier` ceremony, so it covers Step 5.

- [ ] **Step 8: Deletion check.** In `UserPasskey::__construct`, change `$this->signatureCounter = $registration->signatureCounter;` to `$this->signatureCounter = 0;`. Run `php bin/phpunit tests/Entity/UserPasskeyTest.php --filter RoundTrips`, see it fail, and restore the line by hand. Do the same with `$this->createdAt = $registration->registeredAt;` → `$this->createdAt = new \DateTimeImmutable('2000-01-01');`. Paste the outputs.

- [ ] **Step 9: Run the task gates.**

Run: `composer check && composer md`
Expected: green.

- [ ] **Step 10: Commit.**

```bash
git add src/Entity/PasskeyRegistration.php src/Entity/UserPasskey.php src/Service/Passkey/AttestationVerifier.php tests
git commit -m "refactor(#1164): a passkey is built from one registration value"
```

---

### Task B2: `RecommendationRunLog::finish(string, CallOutcome)`; `CallSettlement` composes `CallOutcome`

**Files:**
- Create: `src/Entity/CallOutcome.php`
- Modify: `src/Entity/RecommendationRunLog.php:179-198`
- Modify: `src/Repository/CallSettlement.php` (rewritten in full)
- Modify: `src/Repository/RecommendationCallRepository.php:38-58`
- Modify: `src/Service/Recommendation/RecordedCall.php:7-8, 133-136`
- Create: `tests/Entity/RecommendationRunLogTest.php`
- Test fixtures: `tests/Controller/Api/RecommendationDebugLogControllerTest.php:80-86, 277-283`, `tests/Repository/RecommendationRunLogRepositoryTest.php:52-58, 105-111`, `tests/Repository/RecommendationRunTimingRepositoryTest.php:106-119`, `tests/Service/Recommendation/RecommendationEtaEstimatorTest.php:135-138`

**Interfaces:**
- Produces:
  - `App\Entity\CallOutcome::__construct(string $verdict, int $wireBytes, \DateTimeImmutable $finishedAt, ?string $finishReason)`.
  - `RecommendationRunLog::finish(string $responseText, CallOutcome $outcome): void`.
  - `App\Repository\CallSettlement::__construct(int $logId, CallOutcome $outcome)`.

  The database writes are unchanged: the same columns get the same values.

`finish()` has no `src` caller: production settles a log through DBAL (`RecommendationCallRepository`). The method stays because six tests build finished logs with it, and it now takes the same value the production path writes.

- [ ] **Step 1: Write the failing entity test.** Create `tests/Entity/RecommendationRunLogTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CallOutcome;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class RecommendationRunLogTest extends TestCase
{
    public function testFinishingStoresTheReplyAndEveryPartOfTheOutcome(): void
    {
        $log = new RecommendationRunLog(
            $this->run(),
            RecommendationRunLog::PHASE_BATCH,
            2,
            1,
            'request body',
            new \DateTimeImmutable('2026-08-08T10:00:00Z'),
        );

        $log->finish(
            'reply text',
            new CallOutcome(
                RecommendationRunLog::VERDICT_UNUSABLE,
                4_096,
                new \DateTimeImmutable('2026-08-08T10:00:07Z'),
                'length',
            ),
        );

        self::assertSame('reply text', $log->getResponseText());
        self::assertSame(RecommendationRunLog::VERDICT_UNUSABLE, $log->getVerdict());
        self::assertSame(4_096, $log->getWireBytes());
        self::assertEquals(new \DateTimeImmutable('2026-08-08T10:00:07Z'), $log->getFinishedAt());
        self::assertSame('length', $log->getFinishReason());
    }

    private function run(): RecommendationRun
    {
        $user = new User('run-log@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));

        return new RecommendationRun($user, new \DateTimeImmutable('2026-08-08T09:59:00Z'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails.**

Run: `php bin/phpunit tests/Entity/RecommendationRunLogTest.php`
Expected: FAIL with `Class "App\Entity\CallOutcome" not found`.

- [ ] **Step 3: Create `src/Entity/CallOutcome.php`.**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

/** How a provider call settled: the parser's verdict, what it cost on the wire, when, and why the provider stopped. */
final readonly class CallOutcome
{
    public function __construct(
        public string $verdict,
        public int $wireBytes,
        public \DateTimeImmutable $finishedAt,
        public ?string $finishReason,
    ) {
    }
}
```

- [ ] **Step 4: Change `RecommendationRunLog::finish()`.** Its docblock restated the fields and is replaced by the value object's.

Before (lines 179-198):
```php
    /**
     * The call ended: the final decoded text replaces whatever partial state
     * the checkpoints wrote, the verdict says how the reply was judged, the
     * byte count says what it cost on the wire to get there, and the finish
     * reason says why the provider stopped.
     */
    public function finish(
        string $responseText,
        string $verdict,
        int $wireBytes,
        ?string $finishReason,
        \DateTimeImmutable $finishedAt,
    ): void {
        $this->responseText = $responseText;
        $this->verdict = $verdict;
        $this->wireBytes = $wireBytes;
        $this->finishReason = $finishReason;
        $this->finishedAt = $finishedAt;
    }
}
```
After:
```php
    /** The final decoded text replaces whatever partial state the checkpoints wrote. */
    public function finish(string $responseText, CallOutcome $outcome): void
    {
        $this->responseText = $responseText;
        $this->verdict = $outcome->verdict;
        $this->wireBytes = $outcome->wireBytes;
        $this->finishReason = $outcome->finishReason;
        $this->finishedAt = $outcome->finishedAt;
    }
}
```

- [ ] **Step 5: Run the entity test to verify it passes.**

Run: `php bin/phpunit tests/Entity/RecommendationRunLogTest.php`
Expected: PASS.

- [ ] **Step 6: Make `CallSettlement` compose the outcome.** Rewrite `src/Repository/CallSettlement.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CallOutcome;

/** What a settled provider call writes onto its run-log row, whatever the verdict. */
final readonly class CallSettlement
{
    public function __construct(
        public int $logId,
        public CallOutcome $outcome,
    ) {
    }
}
```

In `src/Repository/RecommendationCallRepository.php`:

Before (lines 38-58):
```php
    public function settleAnswered(CallSettlement $settlement, string $content): void
    {
        $this->connection->update('recommendation_run_log', [
            'response_text' => $content,
            'verdict' => $settlement->verdict,
            'wire_bytes' => $settlement->wireBytes,
            'finished_at' => $settlement->finishedAt->format('Y-m-d H:i:s'),
            'finish_reason' => $settlement->finishReason,
        ], ['id' => $settlement->logId]);
    }

    public function settleTransportFailure(CallSettlement $settlement, ?string $errorDetail): void
    {
        $this->connection->update('recommendation_run_log', [
            'verdict' => $settlement->verdict,
            'wire_bytes' => $settlement->wireBytes,
            'finished_at' => $settlement->finishedAt->format('Y-m-d H:i:s'),
            'error_detail' => $errorDetail,
            'finish_reason' => $settlement->finishReason,
        ], ['id' => $settlement->logId]);
    }
```
After:
```php
    public function settleAnswered(CallSettlement $settlement, string $content): void
    {
        $outcome = $settlement->outcome;
        $this->connection->update('recommendation_run_log', [
            'response_text' => $content,
            'verdict' => $outcome->verdict,
            'wire_bytes' => $outcome->wireBytes,
            'finished_at' => $outcome->finishedAt->format('Y-m-d H:i:s'),
            'finish_reason' => $outcome->finishReason,
        ], ['id' => $settlement->logId]);
    }

    public function settleTransportFailure(CallSettlement $settlement, ?string $errorDetail): void
    {
        $outcome = $settlement->outcome;
        $this->connection->update('recommendation_run_log', [
            'verdict' => $outcome->verdict,
            'wire_bytes' => $outcome->wireBytes,
            'finished_at' => $outcome->finishedAt->format('Y-m-d H:i:s'),
            'error_detail' => $errorDetail,
            'finish_reason' => $outcome->finishReason,
        ], ['id' => $settlement->logId]);
    }
```

In `src/Service/Recommendation/RecordedCall.php`, add `use App\Entity\CallOutcome;` before `use App\Entity\RecommendationRunLog;`, and:

Before (lines 133-136):
```php
    private function settlement(int $logId, string $verdict): CallSettlement
    {
        return new CallSettlement($logId, $verdict, $this->wireBytes, $this->clock->now(), $this->finishReason);
    }
```
After:
```php
    private function settlement(int $logId, string $verdict): CallSettlement
    {
        return new CallSettlement(
            $logId,
            new CallOutcome($verdict, $this->wireBytes, $this->clock->now(), $this->finishReason),
        );
    }
```

- [ ] **Step 7: Switch the six test fixture sites (four files).** Each file gets `use App\Entity\CallOutcome;`: directly before `use App\Entity\RecommendationRun;` in `RecommendationDebugLogControllerTest`, `RecommendationRunTimingRepositoryTest` and `RecommendationEtaEstimatorTest`, and before `use App\Entity\RecommendationRunLog;` in `RecommendationRunLogRepositoryTest`. The values move in the value object's order: verdict, wire bytes, finished-at, finish reason.

`tests/Controller/Api/RecommendationDebugLogControllerTest.php`:

Before (lines 80-86):
```php
        $finished->finish(
            'done text',
            RecommendationRunLog::VERDICT_USABLE,
            1_900_000,
            'stop',
            new \DateTimeImmutable('2026-08-08T10:00:05Z'),
        );
```
After:
```php
        $finished->finish(
            'done text',
            new CallOutcome(
                RecommendationRunLog::VERDICT_USABLE,
                1_900_000,
                new \DateTimeImmutable('2026-08-08T10:00:05Z'),
                'stop',
            ),
        );
```

Before (lines 277-283):
```php
        $log->finish(
            'res',
            RecommendationRunLog::VERDICT_USABLE,
            4_096,
            'length',
            new \DateTimeImmutable('2026-08-08T10:00:05Z'),
        );
```
After:
```php
        $log->finish(
            'res',
            new CallOutcome(
                RecommendationRunLog::VERDICT_USABLE,
                4_096,
                new \DateTimeImmutable('2026-08-08T10:00:05Z'),
                'length',
            ),
        );
```

`tests/Repository/RecommendationRunLogRepositoryTest.php`:

Before (lines 52-58):
```php
        $finished->finish(
            'decoded text',
            RecommendationRunLog::VERDICT_USABLE,
            41_000,
            'stop',
            new \DateTimeImmutable('2026-08-08T10:00:05Z'),
        );
```
After:
```php
        $finished->finish(
            'decoded text',
            new CallOutcome(
                RecommendationRunLog::VERDICT_USABLE,
                41_000,
                new \DateTimeImmutable('2026-08-08T10:00:05Z'),
                'stop',
            ),
        );
```

Before (lines 105-111):
```php
        $done->finish(
            'finished text',
            RecommendationRunLog::VERDICT_UNUSABLE,
            7,
            'length',
            new \DateTimeImmutable('2026-08-08T10:00:05Z'),
        );
```
After:
```php
        $done->finish(
            'finished text',
            new CallOutcome(
                RecommendationRunLog::VERDICT_UNUSABLE,
                7,
                new \DateTimeImmutable('2026-08-08T10:00:05Z'),
                'length',
            ),
        );
```

`tests/Repository/RecommendationRunTimingRepositoryTest.php`:

Before (lines 106-119):
```php
        $this->fixtures->log(
            $run,
            $phase,
            $batchNumber,
            1,
            'req',
            new \DateTimeImmutable('2026-08-08T' . $startedAt . 'Z'),
        )->finish(
            'reply',
            RecommendationRunLog::VERDICT_USABLE,
            0,
            'stop',
            new \DateTimeImmutable('2026-08-08T' . $finishedAt . 'Z'),
        );
```
After:
```php
        $this->fixtures->log(
            $run,
            $phase,
            $batchNumber,
            1,
            'req',
            new \DateTimeImmutable('2026-08-08T' . $startedAt . 'Z'),
        )->finish(
            'reply',
            new CallOutcome(
                RecommendationRunLog::VERDICT_USABLE,
                0,
                new \DateTimeImmutable('2026-08-08T' . $finishedAt . 'Z'),
                'stop',
            ),
        );
```

`tests/Service/Recommendation/RecommendationEtaEstimatorTest.php`:

Before (lines 135-138):
```php
        $this->fixtures->log($run, $phase, $batchNumber, 1, 'req', $base->modify("+{$startOffset} seconds"))
            ->finish('reply', RecommendationRunLog::VERDICT_USABLE, 0, 'stop', $base->modify(
                '+' . ($startOffset + $spanSeconds) . ' seconds',
            ));
```
After:
```php
        $this->fixtures->log($run, $phase, $batchNumber, 1, 'req', $base->modify("+{$startOffset} seconds"))
            ->finish('reply', new CallOutcome(
                RecommendationRunLog::VERDICT_USABLE,
                0,
                $base->modify('+' . ($startOffset + $spanSeconds) . ' seconds'),
                'stop',
            ));
```

- [ ] **Step 8: Run the affected tests.**

Run: `php bin/phpunit tests/Entity/RecommendationRunLogTest.php tests/Controller/Api/RecommendationDebugLogControllerTest.php tests/Repository/RecommendationRunLogRepositoryTest.php tests/Repository/RecommendationRunTimingRepositoryTest.php tests/Service/Recommendation`
Expected: PASS. The `RecordedCall` tests under `tests/Service/Recommendation` prove that the DBAL settlement still writes every column.

- [ ] **Step 9: Deletion check.** In `RecommendationRunLog::finish`, change `$this->finishReason = $outcome->finishReason;` to `$this->finishReason = null;`. Run `php bin/phpunit tests/Entity/RecommendationRunLogTest.php`, see it fail, and restore by hand. In `RecommendationCallRepository::settleAnswered`, change `'finish_reason' => $outcome->finishReason,` to `'finish_reason' => null,`. Run `php bin/phpunit tests/Service/Recommendation` and see a `RecordedCall` test fail. If none fails, report it before committing: the settlement write is then unpinned. Restore by hand. Paste the outputs.

- [ ] **Step 10: Run the task gates.**

Run: `composer check && composer md`
Expected: green. For phptramp: the outcome is built in `RecordedCall::settlement()` and read in `RecommendationCallRepository`, with one hop between them.

- [ ] **Step 11: Commit.**

```bash
git add src/Entity/CallOutcome.php src/Entity/RecommendationRunLog.php src/Repository/CallSettlement.php src/Repository/RecommendationCallRepository.php src/Service/Recommendation/RecordedCall.php tests
git commit -m "refactor(#1164): a run log finishes with the call outcome the settlement writes"
```

---

### Task B3: `recordTransportFailure(): void` + `hasExhaustedTransportRetries()`

**Files:**
- Modify: `src/Entity/RecommendationRun.php:265-278`
- Modify: `src/Entity/RunCallAttempts.php:36-39`
- Modify: `src/Service/Recommendation/RecommendationTransportFailureRecorder.php:46-47`
- Test: `tests/Entity/RecommendationRunTest.php:144-219` (rewritten block plus one new test), `tests/Entity/RunCallAttemptsTest.php:31-38`

**Interfaces:**
- Produces:
  - `RecommendationRun::recordTransportFailure(): void`, still guarded to the running status.
  - `RecommendationRun::hasExhaustedTransportRetries(): bool`, which is `transportFailures >= MAX_TRANSPORT_FAILURES` and is unguarded like every query.
  - `RunCallAttempts::recordTransportFailure(): void`.

- [ ] **Step 1: Rewrite the tests.** In `tests/Entity/RecommendationRunTest.php`:

Before (lines 144-219):
```php
    public function testThirdTransportFailureExhaustsTheSeparateCeiling(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);

        self::assertFalse($run->recordTransportFailure());
        self::assertFalse($run->recordTransportFailure());

        self::assertTrue($run->recordTransportFailure());
    }

    /** Unusable-reply attempts and transport failures are separate counters:
     *  a corrective retry cycle must not push the transport ceiling closer,
     *  and vice versa. */
    public function testTransportFailuresAndAttemptsCountIndependently(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);

        $run->recordInvalidReply('garbage');
        $run->recordInvalidReply('garbage');

        self::assertFalse($run->progress()->attemptsExhausted);
        self::assertFalse($run->recordTransportFailure());
    }

    public function testRecordBatchWinnersResetsTransportFailuresToExactlyZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1], [2]]);
        $run->recordTransportFailure();
        $run->recordTransportFailure();

        $run->recordBatchWinners([['id' => 1, 'score' => 50, 'reason' => 'r']]);

        // Exactly MAX_TRANSPORT_FAILURES (3) fresh failures are needed to
        // exhaust again — pins the reset at 0, not -1 or 1.
        self::assertFalse($run->recordTransportFailure());
        self::assertFalse($run->recordTransportFailure());
        self::assertTrue($run->recordTransportFailure());
    }

    public function testCompleteResetsTransportFailuresToExactlyZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);
        $run->recordTransportFailure();
        $run->recordTransportFailure();

        $run->complete(new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        self::assertSame(RecommendationRun::STATUS_COMPLETED, $run->getStatus());
    }

    public function testResumeResetsTransportFailuresToExactlyZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);
        $run->recordTransportFailure();
        $run->recordTransportFailure();
        $run->fail('boom', new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        $run->resume();

        self::assertFalse($run->recordTransportFailure());
        self::assertFalse($run->recordTransportFailure());
        self::assertTrue($run->recordTransportFailure());
    }

    public function testRecordTransportFailureBeforeSnapshotThrows(): void
    {
        $run = $this->makeRun();

        $this->expectException(\LogicException::class);
        $run->recordTransportFailure();
    }
```
After:
```php
    public function testAFreshRunHasNotExhaustedItsTransportRetries(): void
    {
        self::assertFalse($this->makeRun()->hasExhaustedTransportRetries());
    }

    public function testThirdTransportFailureExhaustsTheSeparateCeiling(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);

        $run->recordTransportFailure();
        $run->recordTransportFailure();
        self::assertFalse($run->hasExhaustedTransportRetries());

        $run->recordTransportFailure();
        self::assertTrue($run->hasExhaustedTransportRetries());
    }

    /** Unusable-reply attempts and transport failures are separate counters:
     *  a corrective retry cycle must not push the transport ceiling closer,
     *  and vice versa. */
    public function testTransportFailuresAndAttemptsCountIndependently(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);

        $run->recordInvalidReply('garbage');
        $run->recordInvalidReply('garbage');
        $run->recordTransportFailure();

        self::assertFalse($run->progress()->attemptsExhausted);
        self::assertFalse($run->hasExhaustedTransportRetries());
    }

    public function testRecordBatchWinnersResetsTransportFailuresToExactlyZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1], [2]]);
        $run->recordTransportFailure();
        $run->recordTransportFailure();

        $run->recordBatchWinners([['id' => 1, 'score' => 50, 'reason' => 'r']]);

        // Exactly MAX_TRANSPORT_FAILURES (3) fresh failures are needed to
        // exhaust again — pins the reset at 0, not -1 or 1.
        $run->recordTransportFailure();
        $run->recordTransportFailure();
        self::assertFalse($run->hasExhaustedTransportRetries());
        $run->recordTransportFailure();
        self::assertTrue($run->hasExhaustedTransportRetries());
    }

    public function testCompleteResetsTransportFailuresToExactlyZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);
        $run->recordTransportFailure();
        $run->recordTransportFailure();

        $run->complete(new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        self::assertSame(RecommendationRun::STATUS_COMPLETED, $run->getStatus());
    }

    public function testResumeResetsTransportFailuresToExactlyZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);
        $run->recordTransportFailure();
        $run->recordTransportFailure();
        $run->fail('boom', new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        $run->resume();

        $run->recordTransportFailure();
        $run->recordTransportFailure();
        self::assertFalse($run->hasExhaustedTransportRetries());
        $run->recordTransportFailure();
        self::assertTrue($run->hasExhaustedTransportRetries());
    }

    public function testRecordTransportFailureBeforeSnapshotThrows(): void
    {
        $run = $this->makeRun();

        $this->expectException(\LogicException::class);
        $run->recordTransportFailure();
    }
```

In `tests/Entity/RunCallAttemptsTest.php`:

Before (lines 31-38):
```php
    public function testRecordTransportFailureIncrementsAndReturnsTheNewCount(): void
    {
        $callAttempts = new RunCallAttempts();

        self::assertSame(1, $callAttempts->recordTransportFailure());
        self::assertSame(2, $callAttempts->recordTransportFailure());
        self::assertSame(2, $callAttempts->transportFailures());
    }
```
After:
```php
    public function testRecordTransportFailureCountsEachFailure(): void
    {
        $callAttempts = new RunCallAttempts();

        $callAttempts->recordTransportFailure();
        $callAttempts->recordTransportFailure();

        self::assertSame(2, $callAttempts->transportFailures());
    }
```

- [ ] **Step 2: Run them to verify they fail.**

Run: `php bin/phpunit tests/Entity/RecommendationRunTest.php tests/Entity/RunCallAttemptsTest.php`
Expected: FAIL with `Call to undefined method App\Entity\RecommendationRun::hasExhaustedTransportRetries()`.

- [ ] **Step 3: Implement.** In `src/Entity/RunCallAttempts.php`:

Before (lines 36-39):
```php
    public function recordTransportFailure(): int
    {
        return ++$this->transportFailures;
    }
```
After:
```php
    public function recordTransportFailure(): void
    {
        $this->transportFailures++;
    }
```

In `src/Entity/RecommendationRun.php`, the rewritten method's docblock is trimmed to one line:

Before (lines 265-278):
```php
    /**
     * Records one provider call that never produced a reply -- a transport
     * failure, not an unusable one. Kept as its own counter, reset
     * independently of `attempts`, so the "unusable reply" retry semantics
     * are unaffected by an unrelated network blip.
     *
     * @return bool true once this failure has reached MAX_TRANSPORT_FAILURES
     */
    public function recordTransportFailure(): bool
    {
        $this->guardStatus(self::STATUS_RUNNING, 'recordTransportFailure');

        return $this->callAttempts->recordTransportFailure() >= self::MAX_TRANSPORT_FAILURES;
    }
```
After:
```php
    /** A call that never produced a reply: its own counter, so a network blip spends no unusable-reply retry. */
    public function recordTransportFailure(): void
    {
        $this->guardStatus(self::STATUS_RUNNING, 'recordTransportFailure');

        $this->callAttempts->recordTransportFailure();
    }

    public function hasExhaustedTransportRetries(): bool
    {
        return $this->callAttempts->transportFailures() >= self::MAX_TRANSPORT_FAILURES;
    }
```

In `src/Service/Recommendation/RecommendationTransportFailureRecorder.php`:

Before (lines 46-47):
```php
        $ceilingReached = $run->recordTransportFailure();
        if ($ceilingReached) {
```
After:
```php
        $run->recordTransportFailure();
        if ($run->hasExhaustedTransportRetries()) {
```

- [ ] **Step 4: Run the affected tests.**

Run: `php bin/phpunit tests/Entity/RecommendationRunTest.php tests/Entity/RunCallAttemptsTest.php tests/Service/Recommendation`
Expected: PASS. `RecommendationRunAdvancerTest`'s transport-failure cases prove that the recorder still fails the run on the third failure.

- [ ] **Step 5: Deletion check.** In `hasExhaustedTransportRetries()`, change `>=` to `>`. Run `php bin/phpunit tests/Entity/RecommendationRunTest.php --filter TransportFailure`, see it fail, and restore by hand. Paste the outputs.

- [ ] **Step 6: Run the task gates.**

Run: `composer check && composer md`
Expected: green. The new query matches PHPMD's `has` ignore pattern, so `RecommendationRun`'s public-method count for `TooManyPublicMethods` does not grow.

- [ ] **Step 7: Commit.**

```bash
git add src/Entity/RecommendationRun.php src/Entity/RunCallAttempts.php src/Service/Recommendation/RecommendationTransportFailureRecorder.php tests/Entity/RecommendationRunTest.php tests/Entity/RunCallAttemptsTest.php
git commit -m "refactor(#1164): recording a transport failure no longer answers whether retries are exhausted"
```

---

### Task B4: `EntryMedium`/`EntryAttachment` `fromStored()`; incomplete items are dropped (D6)

**Files:**
- Create: `src/Entity/Exception/IncompleteStoredMediaException.php`
- Modify: `src/Entity/EntryMedium.php:24-40`
- Modify: `src/Entity/EntryAttachment.php:23-39`
- Modify: `src/Entity/EntryMedia.php:57-67`
- Modify: `tests/PhpStan/data/controller-mutates-no-entity-fixtures.php:215` (the rule fixture's call to the renamed method)
- Test: `tests/Entity/EntryMediaTest.php` (six new tests), `tests/Service/Backup/EntryPartRestorerTest.php` (one new test)

**Interfaces:**
- Produces:
  - `EntryMedium::isComplete(array $stored): bool` and `EntryAttachment::isComplete(array $stored): bool`, each taking `array<string, mixed>`.
  - `EntryMedium::fromStored(array $stored): self` and `EntryAttachment::fromStored(array $stored): self`. Each throws `IncompleteStoredMediaException` on an incomplete item.
  - `EntryMedia::getMedia()`/`getAttachments()` return only complete items, as lists.

  `fromArray()` no longer exists.

- [ ] **Step 1: Write the failing tests.**

**1a.** Append to `tests/Entity/EntryMediaTest.php`, and add `use App\Entity\Exception\IncompleteStoredMediaException;` after `use App\Entity\EntryMedium;`:

```php
    public function testAStoredMediumNeedsAUrlAndAKind(): void
    {
        self::assertTrue(EntryMedium::isComplete(['url' => 'https://i/x.jpg', 'kind' => 'image']));
        self::assertFalse(EntryMedium::isComplete(['kind' => 'image']));
        self::assertFalse(EntryMedium::isComplete(['url' => 'https://i/x.jpg']));
        self::assertFalse(EntryMedium::isComplete(['url' => 42, 'kind' => 'image']));
    }

    public function testAStoredAttachmentNeedsAUrl(): void
    {
        self::assertTrue(EntryAttachment::isComplete(['url' => 'https://cdn/x.mp3']));
        self::assertFalse(EntryAttachment::isComplete(['mimeType' => 'audio/mpeg']));
    }

    public function testACompleteStoredMediumRoundTripsItsDeclaredFields(): void
    {
        $medium = EntryMedium::fromStored([
            'url' => 'https://v/clip.mp4',
            'kind' => 'video',
            'width' => 1280,
            'height' => '720',
            'previewImageUrl' => 'https://v/p.jpg',
        ]);

        self::assertSame('https://v/clip.mp4', $medium->url);
        self::assertSame('video', $medium->kind);
        self::assertSame(1280, $medium->width);
        self::assertNull($medium->height);
        self::assertSame('https://v/p.jpg', $medium->previewImageUrl);
    }

    public function testACompleteStoredAttachmentRoundTripsItsDeclaredFields(): void
    {
        $attachment = EntryAttachment::fromStored([
            'url' => 'https://cdn/ep.mp3',
            'mimeType' => 'audio/mpeg',
            'durationInSeconds' => 3723,
            'sizeInBytes' => '4200000',
            'title' => 'Chapter two',
        ]);

        self::assertSame('https://cdn/ep.mp3', $attachment->url);
        self::assertSame('audio/mpeg', $attachment->mimeType);
        self::assertSame(3723, $attachment->durationInSeconds);
        self::assertNull($attachment->sizeInBytes);
        self::assertSame('Chapter two', $attachment->title);
    }

    public function testAnIncompleteStoredMediumIsRefusedRatherThanGivenAnEmptyUrl(): void
    {
        $this->expectException(IncompleteStoredMediaException::class);

        EntryMedium::fromStored(['kind' => 'image']);
    }

    public function testAnIncompleteStoredAttachmentIsRefusedRatherThanGivenAnEmptyUrl(): void
    {
        $this->expectException(IncompleteStoredMediaException::class);

        EntryAttachment::fromStored(['mimeType' => 'audio/mpeg']);
    }
```

**1b.** Run: `php bin/phpunit tests/Entity/EntryMediaTest.php`
Expected: FAIL with `Call to undefined method App\Entity\EntryMedium::isComplete()`.

**1c.** Pin D6 through the one path that can store an incomplete item: a backup line. In `tests/Service/Backup/EntryPartRestorerTest.php`, add `use App\Entity\EntryAttachment;` and `use App\Entity\EntryMedium;` after `use App\Entity\Entry;`, and add this test after the Part A read-mark test:

```php
    public function testAStoredMediumOrAttachmentWithoutItsKeysIsLeftOutWhenRead(): void
    {
        $user = $this->subscribedUser(self::FEED_URL);
        $gzip = $this->entryPart([
            array_replace($this->entryLine('a'), [
                'media' => [
                    ['kind' => 'image'],
                    ['url' => 'https://i/no-kind.jpg'],
                    ['url' => 'https://i/kept.jpg', 'kind' => 'image'],
                ],
                'attachments' => [
                    ['mimeType' => 'audio/mpeg'],
                    ['url' => 'https://cdn/kept.mp3'],
                ],
            ]),
        ]);

        $this->restorer()->load($user, $gzip);

        $this->em->clear();
        $entry = $this->findEntry('a');
        self::assertNotNull($entry);
        self::assertSame(
            ['https://i/kept.jpg'],
            array_map(static fn (EntryMedium $medium): string => $medium->url, $entry->getMedia()),
        );
        self::assertSame(
            ['https://cdn/kept.mp3'],
            array_map(static fn (EntryAttachment $attachment): string => $attachment->url, $entry->getAttachments()),
        );
    }
```

Run: `php bin/phpunit tests/Service/Backup/EntryPartRestorerTest.php --filter WithoutItsKeys`
Expected: FAIL. Develop reads the incomplete items back as `url: ''`, so the media list is `['', 'https://i/no-kind.jpg', 'https://i/kept.jpg']`.

- [ ] **Step 2: Create `src/Entity/Exception/IncompleteStoredMediaException.php`.**

```php
<?php

declare(strict_types=1);

namespace App\Entity\Exception;

final class IncompleteStoredMediaException extends \UnexpectedValueException
{
}
```

- [ ] **Step 3: Implement.**

**3a.** In `src/Entity/EntryMedium.php`, add `use App\Entity\Exception\IncompleteStoredMediaException;` after `namespace App\Entity;` (with a blank line on each side).

Before (lines 24-40):
```php
    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $url = $data['url'] ?? null;
        $kind = $data['kind'] ?? null;
        $width = $data['width'] ?? null;
        $height = $data['height'] ?? null;
        $previewImageUrl = $data['previewImageUrl'] ?? null;

        return new self(
            \is_string($url) ? $url : '',
            \is_string($kind) ? $kind : '',
            \is_int($width) ? $width : null,
            \is_int($height) ? $height : null,
            \is_string($previewImageUrl) ? $previewImageUrl : null,
        );
    }
```
After:
```php
    /**
     * Only a hand-edited backup can store an item without its url or kind.
     *
     * @param array<string, mixed> $stored
     */
    public static function isComplete(array $stored): bool
    {
        return \is_string($stored['url'] ?? null) && \is_string($stored['kind'] ?? null);
    }

    /** @param array<string, mixed> $stored */
    public static function fromStored(array $stored): self
    {
        $url = $stored['url'] ?? null;
        $kind = $stored['kind'] ?? null;
        if (!\is_string($url) || !\is_string($kind)) {
            throw new IncompleteStoredMediaException('A stored medium needs a url and a kind.');
        }
        $width = $stored['width'] ?? null;
        $height = $stored['height'] ?? null;
        $previewImageUrl = $stored['previewImageUrl'] ?? null;

        return new self(
            $url,
            $kind,
            \is_int($width) ? $width : null,
            \is_int($height) ? $height : null,
            \is_string($previewImageUrl) ? $previewImageUrl : null,
        );
    }
```

**3b.** In `src/Entity/EntryAttachment.php`, add `use App\Entity\Exception\IncompleteStoredMediaException;` after `namespace App\Entity;` (with a blank line on each side).

Before (lines 23-39):
```php
    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $url = $data['url'] ?? null;
        $mimeType = $data['mimeType'] ?? null;
        $durationInSeconds = $data['durationInSeconds'] ?? null;
        $sizeInBytes = $data['sizeInBytes'] ?? null;
        $title = $data['title'] ?? null;

        return new self(
            \is_string($url) ? $url : '',
            \is_string($mimeType) ? $mimeType : null,
            \is_int($durationInSeconds) ? $durationInSeconds : null,
            \is_int($sizeInBytes) ? $sizeInBytes : null,
            \is_string($title) ? $title : null,
        );
    }
```
After:
```php
    /**
     * Only a hand-edited backup can store an attachment without its url.
     *
     * @param array<string, mixed> $stored
     */
    public static function isComplete(array $stored): bool
    {
        return \is_string($stored['url'] ?? null);
    }

    /** @param array<string, mixed> $stored */
    public static function fromStored(array $stored): self
    {
        $url = $stored['url'] ?? null;
        if (!\is_string($url)) {
            throw new IncompleteStoredMediaException('A stored attachment needs a url.');
        }
        $mimeType = $stored['mimeType'] ?? null;
        $durationInSeconds = $stored['durationInSeconds'] ?? null;
        $sizeInBytes = $stored['sizeInBytes'] ?? null;
        $title = $stored['title'] ?? null;

        return new self(
            $url,
            \is_string($mimeType) ? $mimeType : null,
            \is_int($durationInSeconds) ? $durationInSeconds : null,
            \is_int($sizeInBytes) ? $sizeInBytes : null,
            \is_string($title) ? $title : null,
        );
    }
```

**3c.** In `src/Entity/EntryMedia.php`:

Before (lines 57-67):
```php
    /** @return list<EntryMedium> */
    public function getMedia(): array
    {
        return array_map(EntryMedium::fromArray(...), $this->media ?? []);
    }

    /** @return list<EntryAttachment> */
    public function getAttachments(): array
    {
        return array_map(EntryAttachment::fromArray(...), $this->attachments ?? []);
    }
```
After:
```php
    /** @return list<EntryMedium> */
    public function getMedia(): array
    {
        $complete = array_filter($this->media ?? [], EntryMedium::isComplete(...));

        return array_values(array_map(EntryMedium::fromStored(...), $complete));
    }

    /** @return list<EntryAttachment> */
    public function getAttachments(): array
    {
        $complete = array_filter($this->attachments ?? [], EntryAttachment::isComplete(...));

        return array_values(array_map(EntryAttachment::fromStored(...), $complete));
    }
```

**3d.** `tests/PhpStan/data/controller-mutates-no-entity-fixtures.php` (#1157 PR B) calls the old name in its `unmappedClassesUnderAppEntity()` controller fixture, as the "static method of an unmapped value object under `App\Entity`" case the three `ControllerMutatesNoEntity*` rules must not report. The fixture is excluded from `composer stan` and from phpcs, so nothing would fail on the stale name: the case would silently stop exercising a real method. The array is complete, so `fromStored()` returns an `EntryAttachment` exactly as `fromArray()` did, and the line number stays 215, so `ControllerMutatesNoEntityRuleTest`'s expectations are unchanged.
```diff
# line 215
-                EntryAttachment::fromArray(['url' => 'https://example.test/' . $origin->x]),
+                EntryAttachment::fromStored(['url' => 'https://example.test/' . $origin->x]),
```

- [ ] **Step 4: Run the tests to verify they pass.**

Run: `php bin/phpunit tests/Entity/EntryMediaTest.php tests/Service/Backup tests/Http tests/Service/Reader tests/Service/Ingest tests/PhpStan`
Expected: PASS. The existing round-trip, JSON-shape and backup-export tests prove that every complete item serialises as before. The `tests/PhpStan` rule tests prove that line 215 is still not reported.

- [ ] **Step 5: Confirm the rename is complete.**

Run: `git grep -n "fromArray\|\$data" -- src/Entity/EntryMedium.php src/Entity/EntryAttachment.php src/Entity/EntryMedia.php`
Expected: no output.

Run: `git grep -nE "(EntryMedium|EntryAttachment)::fromArray" -- src tests`
Expected: no output.

- [ ] **Step 6: Deletion checks.** Paste the outputs for each:
  - `EntryMedia::getMedia`, remove the `array_filter` (map `$this->media ?? []` directly) → `EntryPartRestorerTest::testAStoredMediumOrAttachmentWithoutItsKeysIsLeftOutWhenRead` errors with `IncompleteStoredMediaException`.
  - `EntryMedium::isComplete`, drop `&& \is_string($stored['kind'] ?? null)` → `EntryMediaTest::testAStoredMediumNeedsAUrlAndAKind` fails.
  - `EntryAttachment::fromStored`, `\is_int($durationInSeconds) ? $durationInSeconds : null` → `null` → `EntryMediaTest::testACompleteStoredAttachmentRoundTripsItsDeclaredFields` fails.

- [ ] **Step 7: Run the task gates.**

Run: `composer check && composer md`
Expected: green. `IncompleteStoredMediaException` is thrown only by `fromStored()`, which `EntryMedia` never calls on an incomplete item, so it needs no `*Problems` HTTP mapping.

- [ ] **Step 8: Commit.**

```bash
git add src/Entity/Exception/IncompleteStoredMediaException.php src/Entity/EntryMedium.php src/Entity/EntryAttachment.php src/Entity/EntryMedia.php tests/Entity/EntryMediaTest.php tests/Service/Backup/EntryPartRestorerTest.php tests/PhpStan/data/controller-mutates-no-entity-fixtures.php
git commit -m "refactor(#1164): stored media are read with fromStored() and an incomplete item is left out"
```

---

## Finishing (PR B)

1. **Run the branch-wide sweeps.**
   ```bash
   git grep -nE "new UserPasskey\(" -A2 -- src tests | grep -c "PasskeyRegistration("   # 15
   git grep -n "): bool" -- src/Entity/RecommendationRun.php | grep -c recordTransportFailure             # 0
   git grep -n "fromArray" -- src/Entity/EntryMedium.php src/Entity/EntryAttachment.php src/Entity/EntryMedia.php tests/PhpStan/data   # nothing
   ```
2. **Run the gates, all green.** `composer stan` also reports any leftover five-argument `finish()` or nine-argument `UserPasskey` call.
   - `composer check`
   - `composer md`
   - `php bin/phpunit`
   - `docker compose exec php composer test` (after confirming the container is current)
   - `bin/console doctrine:schema:validate`, since no mapping may have changed
   - `composer infection:diff`, after the last commit

   Then scan today's dev log as in PR A.
3. **Run the SDD final whole-branch review.** Ask the reviewer to attack these points:
   - Does every `user_passkey` column get the same value it got before, for the ceremony path (`PasskeyRegistrationTest`) and for the fixtures?
   - Does every `recommendation_run_log` settle write the same columns and values?
   - Does the third transport failure still fail the run, and do the first two leave it running?
   - Does every complete medium and attachment serialise byte-identically in `EntryJson` and the backup, and is D6 the only difference?
   - Are `PasskeyRegistration`, `CallOutcome` and `IncompleteStoredMediaException` free of any HTTP import or HTTP class-name string (`DomainKnowsNoHttpRule`)?
   - Does the `ControllerMutatesNoEntity*` fixture still exercise a real static method of an unmapped `App\Entity` value object at line 215?
   - Does every method and constructor this part adds or changes take at most three parameters (`PasskeyRegistration`'s own field constructor excepted, as a value object)?
4. **Run `/simplify`** over the branch diff. If it changed anything, re-run step 2.
5. **Open the PR against `develop`** titled `refactor(#1164): passkey credential, call outcome, transport-failure query and stored media (part B)`, with this body:

   ```markdown
   Closes #1164

   Part B of #1164. Part A made Feed, User and EntryState own their transitions. This part takes the issue's
   remaining findings.

   - **`UserPasskey`** is built from its owner and one `PasskeyRegistration` (what the authenticator minted, the
     label and the registration time), instead of nine loose arguments. The columns and the ceremony are unchanged.
   - **`RecommendationRunLog::finish()`** takes a `CallOutcome` (verdict, wire bytes, finished-at, finish reason).
     `CallSettlement` now carries the same value, so the DBAL settlement and the entity share one shape.
   - **`RecommendationRun::recordTransportFailure()`** only records. `hasExhaustedTransportRetries()` answers.
     `RunCallAttempts::recordTransportFailure()` no longer returns the count either.
   - **`EntryMedium`/`EntryAttachment`** read stored items with `fromStored()`, and `isComplete()` says whether an
     item has its keys.

   **Deliberate change:** media rows with no url/kind (only possible from hand-edited backups) are no longer served
   as url:''. `EntryPartRestorerTest::testAStoredMediumOrAttachmentWithoutItsKeysIsLeftOutWhenRead` pins it.

   No schema change. Every complete medium and attachment serialises exactly as before.
   ```
   The body closes #1164 only. It mentions no other issue number.
6. **Merge when CI is green.**
   - Arm a Monitor that polls `gh pr checks <pr>` until every check has passed. Then run `gh pr merge <pr> --merge`.
   - Never use `gh pr merge --auto`.
   - If a check fails, stop and report. If phptramp fails, check `composer show larspohlmann/phptramp` first.
   - After the merge, confirm with `gh issue view 1164 --json state` that #1164 closed. Do not close it by hand.

---

## Appendix A: setter call sites at 6ca53dd8

Re-taken at 6ca53dd8, the head of #1158's last PR (#1157 PR B and #1158 added `AdminUserLimitsJsonTest`, `SubscriptionTallyReaderTest`, `EntryPageParametersTest` and two `AiSettingsJsonTest` sites, and shifted `EntryListTest` and `EntryStateResolverTest`). A textual grep of `->setX(` over `backend/src` and `backend/tests`, excluding each entity's own forwarding. For the kept setters `setTitle`, `setDescription`, `setSiteUrl` and `setUrl`, the grep also catches other receivers with the same method name (`Entry::setTitle` in `DuplicateCollapseTest`, catalog rows in `CatalogControllerTest`). Those setters are untouched, so the list is informational. For every deleted setter, the list is exact, and the tasks above carry each site.

### Feed

- **`setUrl`** (3 calls)
  - src: `src/Service/Catalog/CatalogFeedEditor.php`:41; `src/Service/Refresh/RefreshRunner.php`:417
  - tests: `tests/Service/Backup/AccountBackupExporterTest.php`:445
- **`setSiteUrl`** (19 calls)
  - src: `src/Service/Backup/RestoreLoadPass.php`:173; `src/Service/Catalog/CatalogFeedEditor.php`:63; `src/Service/Catalog/CatalogImporter.php`:137; `src/Service/Ingest/EntryIngestor.php`:163
  - tests: `tests/Command/WarmCatalogFaviconsCommandTest.php`:34; `tests/Controller/Api/CatalogControllerTest.php`:68; `tests/Http/SubscriptionJsonTest.php`:92,101,109,117,125,135,157; `tests/Service/Backup/AccountBackupExporterTest.php`:104; `tests/Service/Backup/AccountRestorerTest.php`:215; `tests/Service/Catalog/CatalogFaviconWarmerTest.php`:56; `tests/Service/Ingest/EntryIngestorTest.php`:818; `tests/Service/Opml/OpmlExporterTest.php`:31; `tests/Support/FullyPopulatedAccount.php`:89
- **`setTitle`** (74 calls)
  - src: `src/Command/E2eSeedAdminSubscriptionCommand.php`:125; `src/Service/Backup/RestoreLoadPass.php`:174; `src/Service/Catalog/CatalogFeedEditor.php`:40; `src/Service/Catalog/CatalogImporter.php`:132; `src/Service/Ingest/EntryIngestor.php`:160; `src/Service/Subscription/BulkSubscriber.php`:107; `src/Service/Subscription/SubscriptionCreator.php`:67
  - tests: `tests/Command/SearchReindexCommandTest.php`:37; `tests/Controller/Admin/AdminUserControllerTest.php`:844,931,934; `tests/Controller/Api/EntryCommentsControllerTest.php`:52; `tests/Controller/Api/EntryControllerTest.php`:53,91,115,226,254,348,1185; `tests/Controller/Api/EntryPageParametersTest.php`:174; `tests/Controller/Api/EntryReaderControllerTest.php`:58; `tests/Controller/Api/EntrySearchControllerTest.php`:44,74; `tests/Controller/Api/EntrySearchMarkReadTest.php`:33; `tests/Controller/Api/MeDigestTestControllerTest.php`:63; `tests/Controller/Api/OpmlControllerTest.php`:46; `tests/Controller/Api/RecommendationRunControllerTest.php`:107; `tests/Controller/Api/SavedSearchControllerTest.php`:47,198; `tests/Controller/Api/SavedSearchEntriesControllerTest.php`:39; `tests/Controller/Api/SavedSearchUnreadListMatchesBadgeTest.php`:47; `tests/Http/SubscriptionJsonTest.php`:156; `tests/Repository/DuplicateCollapseTest.php`:103,105,136,138,209; `tests/Repository/EntryListTest.php`:42; `tests/Repository/EntryRowsByIdsTest.php`:34; `tests/Repository/EntrySearchTest.php`:38; `tests/Repository/EntryStateRepositoryTest.php`:28; `tests/Repository/RecommendationFeedTest.php`:33,317; `tests/Repository/SavedSearchMembershipReadsTest.php`:39; `tests/Repository/UnreadMatchingEntryIdsForUserTest.php`:35; `tests/Service/Backup/AccountBackupExporterTest.php`:103; `tests/Service/Backup/AccountRestorerTest.php`:214,704; `tests/Service/Ingest/EntryIngestorTest.php`:817; `tests/Service/Mail/Digest/DigestComposerTest.php`:150; `tests/Service/Mail/Digest/DigestEntryFinderTest.php`:39; `tests/Service/Opml/OpmlExporterTest.php`:30,34; `tests/Service/Reader/EntryStateResolverTest.php`:34; `tests/Service/Reader/SearchMarkReadServiceTest.php`:28; `tests/Service/ReaderAudit/AuditSamplerTest.php`:99,115; `tests/Service/Recommendation/ForYouSweepTest.php`:97; `tests/Service/Recommendation/RecommendationCandidateLoaderTest.php`:31,387,431,495,519; `tests/Service/Recommendation/RecommendationForYouSummaryProviderTest.php`:39; `tests/Service/Recommendation/RecommendationHistoryLoaderTest.php`:32,173; `tests/Service/Recommendation/RecommendationRunAdvancerTest.php`:88; `tests/Service/Recommendation/RecommendationRunPurgerTest.php`:47; `tests/Service/Search/EntryIndexerTest.php`:30; `tests/Service/Search/EntrySearchWithFallbackTest.php`:48; `tests/Service/Search/IndexedEntrySearchTest.php`:42; `tests/Service/Subscription/BulkSubscriberTest.php`:67; `tests/Support/FullyPopulatedAccount.php`:88; `tests/Support/RecommendationRunFixtures.php`:105
- **`setDescription`** (12 calls)
  - src: `src/Service/Backup/RestoreLoadPass.php`:175; `src/Service/Catalog/CatalogFeedEditor.php`:64; `src/Service/Catalog/CatalogImporter.php`:138; `src/Service/Ingest/EntryIngestor.php`:166
  - tests: `tests/Controller/Api/CatalogControllerTest.php`:67; `tests/Http/SubscriptionJsonTest.php`:26,38,58,67,75; `tests/Service/Backup/AccountRestorerTest.php`:216; `tests/Support/FullyPopulatedAccount.php`:90
- **`setFaviconUrl`** (7 calls)
  - src: `src/Service/Backup/RestoreLoadPass.php`:176; `src/Service/Refresh/RefreshRunner.php`:396
  - tests: `tests/Controller/Api/EntryControllerTest.php`:54; `tests/Http/SubscriptionJsonTest.php`:158; `tests/Service/Backup/AccountRestorerTest.php`:217; `tests/Service/Mail/Digest/DigestComposerTest.php`:122; `tests/Support/FullyPopulatedAccount.php`:91
- **`setImageUrl`** (6 calls)
  - src: `src/Service/Backup/RestoreLoadPass.php`:177; `src/Service/Ingest/EntryIngestor.php`:173
  - tests: `tests/Entity/FeedTest.php`:28; `tests/Http/SubscriptionJsonTest.php`:143; `tests/Service/Ingest/EntryIngestorTest.php`:841; `tests/Support/FullyPopulatedAccount.php`:92
- **`setStatus`** (8 calls)
  - src: `src/Service/FeedScheduler.php`:55,104,110,122
  - tests: `tests/Http/SubscriptionJsonTest.php`:234; `tests/Repository/FeedRepositoryTest.php`:33; `tests/Repository/FeedRepositoryUserFeedScopeTest.php`:64; `tests/Service/FeedSchedulerTest.php`:179
- **`setSourceFormat`** (17 calls)
  - src: `src/Command/E2eSeedAdminSubscriptionCommand.php`:126; `src/Service/Backup/RestoreLoadPass.php`:178; `src/Service/Catalog/CatalogFeedEditor.php`:65; `src/Service/Catalog/CatalogImporter.php`:139; `src/Service/Subscription/BulkSubscriber.php`:103; `src/Service/Subscription/SubscriptionCreator.php`:66,77
  - tests: `tests/Entity/FeedTest.php`:16; `tests/Service/Backup/AccountRestorerTest.php`:218; `tests/Service/Catalog/CatalogSubscriberTest.php`:133; `tests/Service/Refresh/FeedBodyParserWiringTest.php`:57,76,86; `tests/Service/Refresh/RefreshRunnerTest.php`:161; `tests/Service/Subscription/SubscriptionServiceTest.php`:222,248; `tests/Support/FullyPopulatedAccount.php`:93
- **`setLastFetchedAt`** (17 calls)
  - src: `src/Command/E2eSeedAdminSubscriptionCommand.php`:129; `src/Service/FeedScheduler.php`:56,101,125
  - tests: `tests/Controller/Admin/AdminUserControllerTest.php`:846; `tests/Http/SubscriptionJsonTest.php`:159; `tests/Repository/FeedRepositoryTest.php`:136,138,156,194; `tests/Service/Admin/UserStatisticsTest.php`:41; `tests/Service/Backup/AccountRestorerTest.php`:222; `tests/Service/FeedSchedulerTest.php`:76; `tests/Service/Refresh/RefreshRunnerTest.php`:368,416,521; `tests/Service/Subscription/SubscriptionServiceTest.php`:185
- **`setLastSuccessfulFetchAt`** (4 calls)
  - src: `src/Service/FeedScheduler.php`:57
  - tests: `tests/Http/SubscriptionJsonTest.php`:235; `tests/Service/Refresh/RefreshRunnerTest.php`:369,415
- **`setLastNewEntryAt`** (3 calls)
  - src: `src/Service/FeedScheduler.php`:59
  - tests: `tests/Http/SubscriptionJsonTest.php`:280; `tests/Service/FeedSchedulerTest.php`:62
- **`setNextFetchAt`** (18 calls)
  - src: `src/Service/FeedScheduler.php`:61,83,105,116,126; `src/Service/Subscription/BulkSubscriber.php`:108
  - tests: `tests/Command/RefreshFeedsCommandTest.php`:37; `tests/Controller/MaintenanceControllerTest.php`:30; `tests/Http/SubscriptionJsonTest.php`:261,272; `tests/Repository/FeedRepositoryTest.php`:32; `tests/Service/Maintenance/MaintenanceTickTest.php`:129; `tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php`:97; `tests/Service/Refresh/RefreshRunnerTest.php`:169,924,1120; `tests/Service/Subscription/SubscriptionServiceTest.php`:186; `tests/Service/Worker/RefreshDueFeedsHandlerTest.php`:43
- **`setFetchIntervalMinutes`** (12 calls)
  - src: `src/Service/FeedScheduler.php`:52
  - tests: `tests/Service/FeedSchedulerTest.php`:32,45,75,115,125,135,149,156,165,191,209
- **`setConsecutiveFailures`** (7 calls)
  - src: `src/Service/FeedScheduler.php`:53,99,123
  - tests: `tests/Http/SubscriptionJsonTest.php`:236; `tests/Service/FeedSchedulerTest.php`:177,210,221
- **`setLastErrorMessage`** (5 calls)
  - src: `src/Service/FeedScheduler.php`:54,100,124
  - tests: `tests/Http/SubscriptionJsonTest.php`:237; `tests/Service/FeedSchedulerTest.php`:178
- **`setEtag`** (4 calls; `$response->setEtag()` at `src/Http/CatalogFaviconResponse.php`:18 is a Symfony `Response`, not a `Feed`)
  - src: `src/Service/Refresh/RefreshRunner.php`:336; `src/Service/Subscription/FirstFetchRecorder.php`:78
  - tests: `tests/Service/Backup/AccountRestorerTest.php`:221; `tests/Service/Refresh/BudgetedFeedQueueTest.php`:51
- **`setLastModified`** (3 calls)
  - src: `src/Service/Refresh/RefreshRunner.php`:337; `src/Service/Subscription/FirstFetchRecorder.php`:79
  - tests: `tests/Service/Refresh/BudgetedFeedQueueTest.php`:52

### User

- **`setPasswordHash`** (16 calls)
  - src: `src/Command/E2eSeedAdminCommand.php`:76; `src/Service/Auth/BootstrapAdminProvisioner.php`:42; `src/Service/Auth/PasswordResetter.php`:31; `src/Service/Auth/RegistrationService.php`:65,196; `src/Service/OAuth/OAuthAccountLinker.php`:182
  - tests: `tests/Controller/Api/JwtAccessTest.php`:277; `tests/Controller/Api/PasskeyListTest.php`:155; `tests/Entity/UserSecurityTest.php`:37,52; `tests/Security/LoginTimingEqualizerTest.php`:148; `tests/Service/OAuth/OAuthAccountLinkerTest.php`:103,147,351; `tests/Service/Passkey/PasskeyRemovalPolicyTest.php`:88; `tests/Support/UserFactory.php`:44
- **`setRoles`** (8 calls)
  - src: `src/Command/E2eSeedAdminCommand.php`:73; `src/Service/Auth/BootstrapAdminProvisioner.php`:39
  - tests: `tests/Command/E2eSeedAdminSubscriptionCommandTest.php`:33; `tests/Entity/UserSecurityTest.php`:65,73,81; `tests/Repository/UserRepositoryTest.php`:26; `tests/Support/UserFactory.php`:42
- **`setStatus`** (29 calls)
  - src: `src/Command/E2eSeedAdminCommand.php`:74; `src/Security/TrialExpiryGuard.php`:42; `src/Service/Admin/UserLimits.php`:63; `src/Service/Admin/UserStatusChanger.php`:70,83,91; `src/Service/Auth/BootstrapAdminProvisioner.php`:40; `src/Service/Auth/RegistrationService.php`:68,119,127; `src/Service/OAuth/OAuthAccountLinker.php`:176,178,209,211
  - tests: `tests/Command/E2ePurgeUsersCommandTest.php`:33; `tests/Command/E2eSeedAdminSubscriptionCommandTest.php`:34; `tests/Command/PurgeUnverifiedUsersCommandTest.php`:33; `tests/Controller/Api/JwtAccessTest.php`:134,166; `tests/Controller/Api/LoginTest.php`:377,419; `tests/Controller/Api/PasskeyLoginTest.php`:447; `tests/Http/AdminUserLimitsJsonTest.php`:17; `tests/Repository/UserRepositoryTest.php`:25; `tests/Security/TrialExpiryGuardTest.php`:20; `tests/Security/UserCheckerTest.php`:23; `tests/Service/OAuth/OAuthAccountLinkerTest.php`:428; `tests/Service/OAuth/OAuthSignInTest.php`:197; `tests/Support/UserFactory.php`:41
- **`setApprovedAt`** (9 calls)
  - src: `src/Command/E2eSeedAdminCommand.php`:75; `src/Service/Admin/UserLimits.php`:64; `src/Service/Admin/UserStatusChanger.php`:71; `src/Service/Auth/BootstrapAdminProvisioner.php`:41; `src/Service/Auth/RegistrationService.php`:70,128; `src/Service/OAuth/OAuthAccountLinker.php`:179,212
  - tests: `tests/Controller/Admin/AdminUserControllerTest.php`:396
- **`setLastLoginAt`** (4 calls)
  - src: `src/EventListener/StampLastLoginOnTokenIssue.php`:56
  - tests: `tests/Entity/UserTest.php`:89; `tests/Service/Admin/UserStatisticsTest.php`:25; `tests/Support/UserFactory.php`:47
- **`setLocale`** (9 calls)
  - src: `src/Service/Account/AccountPreferencesWriter.php`:27; `src/Service/Auth/RegistrationService.php`:64; `src/Service/Backup/RestoreLoadPass.php`:96
  - tests: `tests/Controller/Api/MeControllerTest.php`:48; `tests/Service/Backup/AccountRestorerTest.php`:301; `tests/Service/Mail/AccountMailerTest.php`:72,138; `tests/Service/Mail/Digest/DigestMailerTest.php`:120; `tests/Support/UserFactory.php`:43
- **`setTrialEndsAt`** (9 calls)
  - src: `src/Service/Admin/UserLimits.php`:29,40
  - tests: `tests/Controller/Api/JwtAccessTest.php`:403; `tests/Entity/UserTest.php`:108,110; `tests/Http/AdminUserLimitsJsonTest.php`:18; `tests/Security/TrialExpiryGuardTest.php`:21; `tests/Security/UserCheckerTest.php`:87; `tests/Support/UserFactory.php`:50
- **`setMaxSubscriptions`** (7 calls)
  - src: `src/Service/Admin/UserLimits.php`:46
  - tests: `tests/Controller/Api/SubscriptionBulkTest.php`:252; `tests/Entity/UserTest.php`:122,124; `tests/Http/AdminUserLimitsJsonTest.php`:37; `tests/Service/Subscription/SubscriptionLimitResolverTest.php`:17; `tests/Support/UserFactory.php`:51
- **`setActiveAiProviderSettings`** (17 calls)
  - src: `src/Service/Ai/AiProviderConfigurator.php`:189,198,227
  - tests: `tests/Controller/Api/RecommendationSettingsControllerTest.php`:94; `tests/Entity/UserAiConfigurationsTest.php`:41,50,52; `tests/Http/AiSettingsJsonTest.php`:163,172; `tests/Http/MeJsonTest.php`:47,68; `tests/Service/Account/AccountDeleterTest.php`:143; `tests/Service/Recommendation/RecommendationRunAdvancerTest.php`:2776; `tests/Service/Recommendation/RecommendationRunStarterTest.php`:320; `tests/Service/Recommendation/RecommendationSettingsResolverTest.php`:237; `tests/Support/RecommendationRunFixtures.php`:49,132

### EntryState

- **`setIsHidden`** (31 calls)
  - src: `src/Command/E2eSeedAdminSubscriptionCommand.php`:192; `src/Service/Backup/RestoreEntryLoader.php`:119
  - tests: `tests/Command/E2eSeedAdminSubscriptionCommandTest.php`:248; `tests/Controller/Api/EntrySearchControllerTest.php`:206,208; `tests/Entity/SubscriptionTest.php`:100; `tests/Repository/DuplicateCollapseTest.php`:75,187; `tests/Repository/EntryListTest.php`:385,644,1067; `tests/Repository/EntrySearchTest.php`:239; `tests/Repository/SubscriptionEntryCountsTest.php`:28; `tests/Repository/UnreadCountsTest.php`:43,157; `tests/Repository/UnreadMatchingEntryIdsForUserTest.php`:91; `tests/Service/Backup/AccountRestorerTest.php`:292; `tests/Service/Reader/MarkReadServiceTest.php`:56,126,227,239; `tests/Service/Reader/SearchMarkReadServiceTest.php`:67; `tests/Service/Recommendation/RecommendationCandidateLoaderTest.php`:51; `tests/Service/Retention/EntryPrunerTest.php`:307,340,361,446; `tests/Service/Search/IndexedEntrySearchTest.php`:71; `tests/Service/Search/LikeEntrySearchTest.php`:88,90; `tests/Service/Subscription/SubscriptionTallyReaderTest.php`:30
- **`setIsFavorite`** (37 calls)
  - src: `src/Service/Backup/RestoreEntryLoader.php`:120; `src/Service/Reader/EntryStateUpdater.php`:48
  - tests: `tests/Controller/Api/AccountBackupControllerTest.php`:64; `tests/Entity/SubscriptionTest.php`:147; `tests/Repository/EntryListTest.php`:401,611,645,753; `tests/Repository/EntryStateRepositoryTest.php`:67,101,141; `tests/Repository/RecommendationFeedTest.php`:175; `tests/Repository/StateCountsTest.php`:44,52,109,135; `tests/Service/Backup/AccountBackupExporterTest.php`:130,229,248; `tests/Service/Backup/AccountRestorerTest.php`:293,844; `tests/Service/Backup/EntryPartRestorerTest.php`:126,314; `tests/Service/Reader/EntryStateResolverTest.php`:110; `tests/Service/Reader/MarkReadServiceTest.php`:127; `tests/Service/Recommendation/RecommendationCandidateLoaderTest.php`:85,133; `tests/Service/Recommendation/RecommendationHistoryLoaderTest.php`:54,69,134,149,162,176; `tests/Service/Retention/EntryPrunerTest.php`:303,480; `tests/Service/Subscription/SubscriptionTallyReaderTest.php`:31; `tests/Support/FullyPopulatedAccount.php`:153
- **`setIsKept`** (21 calls)
  - src: `src/Service/Backup/RestoreEntryLoader.php`:121; `src/Service/Reader/EntryStateUpdater.php`:51
  - tests: `tests/Entity/SubscriptionTest.php`:148; `tests/Repository/EntryListTest.php`:403; `tests/Repository/EntryStateRepositoryTest.php`:105; `tests/Repository/RecommendationFeedTest.php`:177; `tests/Repository/StateCountsTest.php`:48,53,110; `tests/Service/Backup/AccountRestorerTest.php`:297; `tests/Service/Reader/EntryStateResolverTest.php`:142; `tests/Service/Reader/MarkReadServiceTest.php`:128; `tests/Service/Recommendation/RecommendationCandidateLoaderTest.php`:98,134; `tests/Service/Recommendation/RecommendationHistoryLoaderTest.php`:55,60,93; `tests/Service/Retention/EntryPrunerTest.php`:305,342,416; `tests/Support/FullyPopulatedAccount.php`:154
- **`setHiddenAt`** (3 calls)
  - src: `src/Service/Backup/RestoreEntryLoader.php`:122
  - tests: `tests/Entity/SubscriptionTest.php`:101; `tests/Service/Backup/AccountRestorerTest.php`:294
