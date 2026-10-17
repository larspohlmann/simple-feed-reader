# #1666 Saved feeds and hourly social sync — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Favourited and kept posts survive every unsubscribe by moving into per-user "Saved from Bluesky / Mastodon / feeds" virtual feeds (Part 1), and every connected account's private lists and custom feeds follow the network hourly: silent additions and removals, a per-account memory of what the user dropped, and a visible change log (Part 2).

**Architecture:** A new service module `Service/SavedPosts` owns the virtual feeds (`saved:<userId>[:<network>]`, source format `saved`, never fetched) and the rescue that `SubscriptionService` runs inside every unsubscribe before the subscription goes: favourited/kept entries move their flags to a copy of the same post in a feed the user keeps, or are copied (native `INSERT … SELECT` in `SavedPostRepository`) into the virtual feed, merged by guid. A sparse `saved_entry_origin` table keeps an RSS copy's source title and favicon; a social copy keeps its poster in the entry's author columns. Housekeeping deletes virtual-feed entries nobody flags. Part 2 adds `collections()` to the candidate sources (owned + saved Bluesky lists, saved custom feeds, what the network says is gone), a `KnownSocialCollection` memory, `PrivateFeedSync` for one account and `PrivateFeedSyncSweep` driven by the worker schedule and the maintenance tick, and a trimmed `PrivateFeedSyncChange` log that Settings → Social Media shows.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Doctrine ORM 3 / DBAL 4, PHPUnit 12, PHPStan max, PHPMD, phptramp; Angular 20 standalone + signals, Transloco, Jest.

**Spec:** GitHub #1666 (body = the settled design, "Part 1" items 1–8 and "Part 2" items 9–17). Read it next to this plan. Quoted item numbers below (`#4`, `#11` …) point into that body.

---

## Questions answered by Lars (2026-10-17: all six accepted as written)

1. **#14 "a newly connected account takes its baseline at connect time" is implemented as "the account's first check".** An account with `lastSyncedAt = null` takes the baseline on its first sync, which the sweep runs first (never-checked accounts sort ahead) and within ten minutes of connecting on a worker install. Taking it inside the connect request would add 3–5 network calls (preferences, lists, generators, each saved list) to a request that already verifies the credential, and a failed call there would still need the lazy path. The only difference: a list created on the network in those ≤10 minutes is baselined (treated as dropped) instead of added. **Accepted.**
2. **Copied likes die with a disconnected account.** `social_like.social_account_id` cascades on account delete, so on *disconnect* (#3) the copied like row vanishes with the account a moment later; the saved post shows "not liked" (the like itself stays on the network). Single/bulk/picker/sync unsubscribes keep the like. **Accepted** (no re-pointing).
3. **A saved custom feed whose generator the AppView no longer returns is treated as deleted** — by the sync (#11, removed with reason `deleted`) *and* by the picker, which today still lists it under its at-URI (test `testSavedFeedsAreNamedByTheirGeneratorAndAnUnknownOneKeepsItsUri`). One reader for both keeps "the picker decides what exists" (#14) consistent; the picker test changes. **Accepted.**
4. **Virtual feeds do not count against the subscription cap**, and the restore fit check stops counting their subscription lines. Otherwise a user at the cap could not unsubscribe (the rescue creates the virtual feed while the original still exists), and up to three slots would silently disappear. **Accepted.**
5. **Where the virtual feeds show:** "Saved from Bluesky/Mastodon" at the bottom of that network's sidebar section, "Saved from feeds" in the untagged Feeds list; each only while `entryCount > 0` (#1). Settings → Organise always lists them, so an empty one can still be found and removed. **Accepted.**
6. **#13 dropped memory never expires:** a list the user unsubscribed stays remembered even after it disappears from the network, so re-saving it on Bluesky later does not bring it back (only the picker does). A list the *sync* removed is forgotten, so re-saving it brings it back (#12). That is my reading of "never re-added automatically". **Accepted.**

## Open questions for Lars (raised by the plan review)

7. **The maintenance tick cannot budget a worst-case check.** Task 14 runs the sync last in the tick (after the membership sweep, as the review asked), for one account, when ≥ 5 s of the 25 s window remain. A worst-case Bluesky check (3 + up to 25 calls at up to 20 s each) can overrun the window; typical calls take under a second. Requiring the review's ~60 s (3 × `CALL_SECONDS`) — or even 20 s after the refresh and the membership sweep — means worker-less installs (Strato cron) would practically never sync, which contradicts #10. **Accepted: the overrun risk stands; no separate cron tick.**

## Decisions the issue left to the plan

- **Module:** `App\Service\SavedPosts` (new). Edges added: `Subscription → SavedPosts`, `Social → SavedPosts` (network of a `saved:` URL), `Backup → SavedPosts` (rebinding on restore). `SavedPosts` names only `Feed`, `Search`, `Url`, `Clock` — no cycle (checked: `Feed → Fetch`, `Search → Text`, `Url → ∅`, `Clock → ∅`).
- **"The same post in a feed the user keeps" (#4)** = an entry with the same `url_hash` in any feed the user stays subscribed to (virtual feeds included), excluding every feed of the same unsubscribe call; the lowest id wins, as `DuplicateCollapseDql` picks survivors (`backend/src/Repository/DuplicateCollapseDql.php:27-38`). An entry without a `url_hash` is always copied.
- **"Copied with the user's state" (#4):** the copy (or the kept twin) gets favourite/kept OR-ed, the read mark (`hiddenAt`) when it has no state row yet, and the viewed mark. The original's favourite/kept flags are cleared, so a feed another account keeps no longer counts the entry as protected by retention (`RetentionRepository::notProtectedDql`, `backend/src/Repository/RetentionRepository.php:150-159`). Link cards, categories and the like are copied; a pending `share_enrichment` is not (the copy is shown as the original stood).
- **An RSS copy's origin** lives in a new sparse table `saved_entry_origin` (entry id PK → title, favicon URL), not on `Entry`: `Entry` holds 15 fields, PHPMD's `TooManyFields` threshold (`maxfields=15`, reports `> 15`), so a 16th embeddable fails `composer md`.
- **A social copy's poster** is written into its author columns at copy time: a private-feed post already has them; a profile-feed post gets the feed's display title, its site (via `FeedWebsite` + `AbsoluteHttpUrl`), and `COALESCE(entry avatar, feed image)` — exactly what `EntryJson::posterOf` shows today (`backend/src/Http/EntryJson.php:150-170`).
- **API:** `SubscriptionJson.social` for a Bluesky/Mastodon virtual feed is `{network, accountId: null, kind: 'saved'}`; "Saved from feeds" has `social: null`. `sourceFormat: 'saved'` marks all three. `EntryJson.poster` of a saved entry: social → its author columns; RSS → `{name: origin title, url: null, imageUrl: null}`; `faviconUrl` → the origin's favicon when there is one.
- **Removal reasons (#17):** `unsaved` (custom feed gone from `savedFeedsPrefV2`, or a Bluesky list someone else owns that is no longer saved) and `deleted` (the network says it no longer exists: generator not returned, `getList` answers 400 `InvalidRequest`, an owned Bluesky list gone from `getLists`, any Mastodon list gone from `/api/v1/lists`). **Addition reasons:** `saved` (custom feed, a list by someone else) and `created` (an owned Bluesky list, a Mastodon list).
- **"The same post" inside a saved feed** is the same `guid_hash` **and** the same `url_hash` (null-safe: two null hashes are equal). A guid is unique per feed only, so two unrelated RSS articles may share one; a copy whose guid the saved feed already holds under another URL is stored under the namespaced guid `<guid>#<source feed id>` (rehashed through `EntryGuid`, `backend/src/Entity/EntryGuid.php:19-23`). Decided in `Pass/SavedCopyPlan`.
- **A bulk unsubscribe that includes saved feeds** removes and reclaims those saved feeds first, then rescues the rest into fresh saved feeds — otherwise the rescue would copy into a saved feed the same call deletes.
- **Indexing the copies** happens after the unsubscribe's transaction commits (`SavedPostRescue::indexCopies()`), never inside it.
- **Skip reasons (#15, #17):** `unreachable` (no or a non-2xx answer, 429 included, or a malformed 200), `incomplete` (`getLists` cut at `CollectedItemsModel::MAX_ITEMS`, more saved lists than `BlueskySavedCollections::MAX_SAVED_LISTS` = 25, or no `savedFeedsPrefV2` while the account has subscribed custom feeds), `needs_reconnect`, and `failed` (any other error in that account's check; logged, the sweep goes on or stops cleanly). Every check stamps `lastCheckedAt`, so a failing account is retried after the hour, not every ten minutes; only a complete check stamps `lastSyncedAt`, and the baseline (#14) is taken while `lastSyncedAt` is still null.
- **Cadence:** worker `RecurringMessage::every('10 minutes', new SyncPrivateFeeds())`, appended last (`backend/src/Service/Worker/WorkerSchedule.php:41-51`), up to 10 accounts a run; the maintenance tick runs it **last**, after the membership sweep, for at most 1 account when at least `PrivateFeedSyncBudgetModel::TICK_ACCOUNT_SECONDS` (5 s) remain (Open question 7). An account is due when `lastCheckedAt` is null or older than one hour, and only accounts of `UserStatus::Active` users are due.
- **The tick's budget is a deliberate compromise (Open question 7):** one Bluesky check is 3 + N calls (N ≤ 25 saved lists), each allowed `max_duration` 20 s, so a worst-case check cannot fit `MaintenanceTick::TICK_WINDOW_SECONDS` (25) at all, and after the refresh and the membership sweep rarely 20 s are left. The tick therefore asks for 5 s (a typical check takes 1–3 s) and runs the sync last, where an overrun delays no other sweep.
- **`MaintenanceTick` keeps 9 constructor parameters:** a 10th trips PHPMD `ExcessiveParameterList` (threshold 10). `ProfileImageSweep` and the new sweep are grouped behind `Social/SocialMaintenance`, whose two halves the tick calls at different points.
- **Picker removal (#16)** reuses the import request's existing `unsubscribe` ids (`SocialImporter::import`, `backend/src/Service/Social/SocialImporter.php:42-66`), so picker and sync both end in `SubscriptionService::unsubscribeAll()`, which runs the rescue. Home stays checked and fixed.
- **The picker shares the sync's reader (accepted cost):** opening the picker now also costs one `getList` per saved list by someone else (at most 25), and one failed `getList` makes the whole picker answer "unreachable", as a failed `getLists` already does. One reader for picker and sync keeps "the picker decides what exists" (#14) true.
- **Transactions:** the unsubscribe and the sync use `beginTransaction()`/`commit()`/`rollback()` as `PrivateFeeds::create()` does (`backend/src/Service/Social/PrivateFeeds.php:37-50`), never `wrapInTransaction()`, which closes the entity manager on any exception and would kill the sweeps after it.

## Global Constraints

- CLAUDE.md "PHP code style" applies in full: intent-revealing names (no `$data`, no single letters except `$qb`/`$io`/`$x`/`$y`), functions do one thing, ≤ 3 parameters (more → a model), no boolean flag parameters, guard clauses, `final readonly` by default, role folders (`Model/` suffix `Model`, `Factory/` suffix `Factory`, `Pass/`, `Support/` static-only with a private constructor, `Exception/`, a folder per interface), depend on injected interfaces, queries only in `src/Repository/`, no `toArray()`/`jsonSerialize()` in `App\Service`, typed exceptions, comments only when a reader would otherwise get the code wrong (one line, three at most).
- Persistence: entity values/enums an entity or repository uses live in `App\Entity` / `App\Enum` (`PersistenceKnowsNoServiceRule`); a repository names only Service *models/helpers*; every class in `src/Entity` and `src/Repository` is `final`.
- `DomainKnowsNoHttpRule`: nothing in `Service`/`Repository`/`Entity`/`Enum` names `App\Http`, `App\Dto` or HttpFoundation. HTTP statuses are `Response::HTTP_*` constants in `src` **and** `tests`, a `MockResponse` `http_code` included — never a literal.
- `ThinControllerRule` / `ControllerMutatesNoEntityRule`: controllers read, delegate, return.
- `ServiceModuleCycleRule`: no module cycle (see Decisions for the new edges).
- PHPMD codesize clean for every touched `src` file; phptramp: no chain of ≥ 4 methods across ≥ 2 classes forwarding an unread parameter (3 warns).
- Datetimes are naive UTC: inject `App\Service\Clock\NaiveUtcClock` wherever a "now" is persisted.
- Migrations are written for MySQL **and** SQLite (as `backend/migrations/Version20261012130000.php`), non-transactional, `skipIf` guarded, and verified from empty on SQLite plus `doctrine:schema:update --dump-sql` / `doctrine:schema:validate` on the Docker MySQL.
- **The hourly sync touches `SocialFeed` rows only of the kinds `SocialFeedKind::UserList` and `SocialFeedKind::CustomFeed`, selected by an explicit allow-list (`PrivateFeedSync::SYNCED_KINDS`), never "everything but Home".** A parallel session adds a new kind (Bluesky author feeds); any kind not on the list is never added, removed, remembered or forgotten by the sync. Task 13 carries the test.
- Every client-facing change passes `docs/architecture.md` §6: JSON in, `application/problem+json` out, bearer auth, no browser-only input. No new endpoint is added; `GET /api/me/social-accounts` gains fields.
- Frontend: standalone components + signals; styles in sibling `.scss`; no hex colours or ad-hoc `px` outside `src/app/theme/`/`src/styles*`; every user-visible string in `frontend/public/i18n/en.json` **and** `de.json`; Prettier 100 columns. Frontend tests only in Docker, one Jest run at a time: `docker compose exec -T frontend npm test -- <path>`.
- Commits `type(#1666): subject`, no attribution lines. Branch `feature/1666-saved-feeds-and-social-sync` (checked out). Commit only at the plan's commit steps.
- The plan's code fixes content, not wrapping: where PSR-12 (120 cols) or Prettier (100 cols) asks for a wrap, wrap.

## Pre-flight notes for the executor

1. **Concurrent sessions share this checkout.** `git status` and `git branch --show-current` before every task; never `checkout`/`reset`/`stash` without checking.
2. **Check `develop` for a sibling state-copy service before Task 5.** A parallel session (Bluesky author feeds) will likely land a small service that copies a user's entry state (read/favourite/kept) by guid from one feed to another. Run `git fetch origin develop && git log origin/develop --oneline -20` and `git grep -n "class .*State" origin/develop -- backend/src/Service`. If it exists, merge `develop` into this branch first and make Task 5's `SavedStateMover` reuse or absorb it (one service, one name) instead of adding a second copy of the rule. Report the merge to the planner. **Shared contract with #1667** (session `local_d39ecdfd-1218-44eb-97df-08047dcd4f4a`, which adopted it verbatim): `App\Service\SavedPosts\SavedStateMover` at `backend/src/Service/SavedPosts/SavedStateMover.php`, constructor `(EntryStateRepository, SocialLikeRepository, EntityManagerInterface)`, `move(int $userId, array<int,int> $targetByEntryId): void`, plus `EntryStateRepository::forUserByEntryIds` / `::ensureRow` and `SocialLikeRepository::copyLike`. Whichever branch lands first ships it; the other drops its copy. Do not rename or change these signatures without telling the planner, who tells that session.
3. **A parallel session also adds a `SocialFeedKind` case.** After merging `develop`, re-read `backend/src/Enum/SocialFeedKind.php`; every `match` over the kind in this plan (`SocialImporter::kindLabel`, `PrivateFeedSyncReasons`) must cover the new case, and the sync must still leave it alone (Global Constraints).
4. **DAMA wraps every test in a transaction** (`DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension`, `backend/phpunit.dist.xml:66-68`) that is rolled back afterwards, so a test sees only its own rows. A service's own `beginTransaction()`/`commit()` nests inside it as a savepoint level and commits nothing for real. Give seeded users unique e-mails anyway: one test may seed several. **A service no app class injects yet is removed from the test container** (`backend/config/services_test.yaml:2-3`): until the task that wires it in, build it by hand in its test.
5. **`infection:diff` ignores untracked files and diffs against `origin/develop`.** Commit everything and `git fetch origin develop` before the final run (Task 17).
6. **`MockHttpClient` hands the callback the URL verbatim** (an encoded `at%3A%2F%2F…` stays encoded); `RoutedHttpClient` route keys are exact strings (see `backend/tests/Service/Social/CandidateSource/BlueskyCandidateSourceTest.php:41-49`).
7. Where a step says "append to `<Test>`", add the methods inside the class and merge the `use` lines.
8. Every backend task ends with, from `backend/`: `bin/console cache:warmup && composer check && composer md`, PhpStorm inspections (`mcp__phpstorm__lint_files`) on the changed PHP (ERROR/WARNING block), then the task's commit.

## File structure

Backend (`backend/`):

| File | Responsibility |
|---|---|
| `src/Enum/SourceFormat.php` (modify) | `SAVED`, `NOT_PROFILES` |
| `src/Service/SavedPosts/Support/SavedFeedUrl.php` | `saved:<userId>[:<network>]`: build, recognise, network, rebind |
| `src/Service/SavedPosts/Support/SavedOrigin.php` | poster / origin of a feed being left |
| `src/Service/SavedPosts/Factory/SavedFeedFactory.php` | the virtual Feed and its Subscription |
| `src/Service/SavedPosts/SavedFeeds.php` | find-or-create a user's virtual feed |
| `src/Service/SavedPosts/SavedStateMover.php` | moves favourite/kept/read/like from originals to copies |
| `src/Service/SavedPosts/SavedPostRescue.php` | what every unsubscribe runs first |
| `src/Service/SavedPosts/Model/SavedCopyModel.php` | target feed + poster for one copy batch |
| `src/Entity/EntryOrigin.php`, `src/Entity/SavedEntryOrigin.php` | an RSS copy's origin (value + sparse row) |
| `src/Repository/SavedEntryOriginRepository.php`, `src/Repository/SavedPostRepository.php` | origin reads; flagged/kept/copy queries |
| `migrations/Version20261017120000.php` | `saved_entry_origin` |
| `src/Repository/{FeedRepository,NetworkScopeDql,SubscriptionRepository,RetentionRepository,EntryRepository,SocialLikeRepository,EntryListRowEnricher,EntryListRowEnrichment,EntryBatchInserter}.php` (modify) | exclusions, lookups, prune, enrichment, restore |
| `src/Service/Social/Support/SocialFeedClassifier.php` (modify) | network of a `saved:` URL |
| `src/Service/Subscription/SubscriptionService.php` (modify) | rescue inside every unsubscribe |
| `src/Service/Retention/EntryPruner.php` (modify) | unflagged saved entries leave |
| `src/Http/{SubscriptionJson,EntryJson}.php` (modify) | `kind: saved`, poster/favicon |
| `src/Service/Backup/**` (modify) | saved feeds exported, origin carried, URL rebound |
| `src/Enum/{PrivateFeedSyncSkip,PrivateFeedSyncReason}.php` | why a check was skipped, why a feed changed |
| `src/Entity/SocialAccount.php` (modify), `src/Entity/{KnownSocialCollection,PrivateFeedSyncChange}.php` | sync stamp, dropped memory, change log |
| `src/Repository/{KnownSocialCollectionRepository,PrivateFeedSyncChangeRepository}.php`, `src/Repository/SocialAccountRepository.php` (modify) | their reads/writes; due accounts |
| `migrations/Version20261017130000.php` | the three Part 2 schema changes |
| `src/Service/Social/Model/{NetworkCollectionsModel,ResolvedCollectionsModel,SavedItemsModel,PrivateFeedSyncBudgetModel,PrivateFeedSyncReportModel}.php` | collections answer, saved items, budget, report |
| `src/Service/Social/BlueskySavedCollections.php` | preferences → saved feeds and lists, gone detection |
| `src/Service/Social/CandidateSource/*`, `src/Service/Social/SocialCandidates/*` (modify) | `collections()` |
| `src/Service/Social/Pass/CollectionSyncPlan.php` | one account's additions/removals/memory |
| `src/Service/Social/Support/PrivateFeedSyncReasons.php` | reasons per network and kind |
| `src/Service/Social/{PrivateFeedSync,PrivateFeedSyncApplier,PrivateFeedSyncSweep,PrivateFeedSyncLog,SocialMaintenance}.php` | one account (read, then write); the sweep; the log; the tick's social half |
| `src/Service/Social/Model/PrivateFeedSyncOutcomeModel.php`, `src/Entity/SyncStamp.php` | one account's outcome; the account's stamp embeddable |
| `src/Service/Backup/Model/EntryCompanionsModel.php` | an entry line's link cards and origin |
| `src/Service/Social/PrivateFeeds.php` (modify) | remembers what it creates |
| `src/Service/Worker/Message/SyncPrivateFeeds.php`, `src/Service/Worker/Handler/SyncPrivateFeedsHandler.php`, `src/Service/Worker/WorkerSchedule.php` (modify) | worker entry |
| `src/Service/Maintenance/{MaintenanceTick,Model/MaintenanceSweepsModel}.php`, `src/Http/MaintenanceTickJson.php` (modify) | tick entry |
| `src/Http/SocialAccountJson.php`, `src/Controller/Api/SocialAccountController.php` (modify) | `sync` per account |

Frontend (`frontend/`): `src/app/reader/social-feed.ts`, `reader/state/{social-sections,subscriptions.store,subscription-rows}.ts`, `reader/feeds/display-title.ts`, `reader/shell/sidebar/sidebar-social-section.component.html`, `reader/entry/entry-source/entry-source.component.{ts,html,scss}`, `reader/list/entry-list/list-content.ts`, `reader/feeds/manage/manage-actions.service.ts`, `settings/social/{social-import-rows,social-import-dialog.component,social-accounts.service,social-section.component}.{ts,html,scss}`, `public/i18n/{en,de}.json`, specs beside each.

Docs: `docs/backup.md` (Task 8).

---

## Part 1 — Favourites and kept posts survive an unsubscribe

### Task 1: The `saved` source format, its URL, and everything that must never fetch or count it

Nothing creates a virtual feed yet; this task makes sure that, once one exists, the scheduler never fetches it, the profile scopes never call it a profile, the cap never counts it, and its network follows from its URL.

**Files:**
- Modify: `backend/src/Enum/SourceFormat.php:22-34`
- Create: `backend/src/Service/SavedPosts/Support/SavedFeedUrl.php`
- Modify: `backend/src/Service/Social/Support/SocialFeedClassifier.php:20-23`
- Modify: `backend/src/Repository/FeedRepository.php:185-214` (profile-image query, `dueQueryBuilder`)
- Modify: `backend/src/Repository/NetworkScopeDql.php:17-20`
- Modify: `backend/src/Repository/SubscriptionRepository.php:50-65, 92-98`
- Modify: `backend/src/Service/Backup/AccountBackupExporter.php:64`
- Test: create `backend/tests/Service/SavedPosts/Support/SavedFeedUrlTest.php`; append to `backend/tests/Repository/DueSocialFeedsTest.php`, `backend/tests/Repository/ExportableSubscriptionsTest.php`, `backend/tests/Service/Social/FeedNetworkListenerTest.php`

**Interfaces — Produces:**
```php
// SourceFormat
public const string SAVED = 'saved';
public const array NOT_PROFILES = [self::BLUESKY_API, self::MASTODON_API, self::SAVED];

final class SavedFeedUrl {
    public static function of(int $userId, ?SocialNetwork $network): string;   // saved:42 | saved:42:bluesky
    public static function isSaved(string $url): bool;
    public static function networkOf(string $url): ?SocialNetwork;
    public static function rebound(string $url, int $userId): string;          // non-saved URLs unchanged
}
// SubscriptionRepository
public function findExportableForUserWithTags(int $userId): array; // OPML: no social, no saved
public function findBackedUpForUserWithTags(int $userId): array;   // backup: no social; saved included
public function countForUser(int $userId): int;                     // saved feeds not counted
public function findOneForUserAndFeedUrl(int $userId, string $feedUrl): ?Subscription;
```

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/SavedPosts/Support/SavedFeedUrlTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\SavedPosts\Support;

use App\Enum\SocialNetwork;
use App\Service\SavedPosts\Support\SavedFeedUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SavedFeedUrlTest extends TestCase
{
    public function testEachNetworkAndTheFeedsHaveTheirOwnUrlPerUser(): void
    {
        self::assertSame('saved:42', SavedFeedUrl::of(42, null));
        self::assertSame('saved:42:bluesky', SavedFeedUrl::of(42, SocialNetwork::Bluesky));
        self::assertSame('saved:7:mastodon', SavedFeedUrl::of(7, SocialNetwork::Mastodon));
    }

    /** @return iterable<string, array{string, ?SocialNetwork}> */
    public static function networks(): iterable
    {
        yield 'bluesky' => ['saved:42:bluesky', SocialNetwork::Bluesky];
        yield 'mastodon' => ['saved:42:mastodon', SocialNetwork::Mastodon];
        yield 'feeds' => ['saved:42', null];
        yield 'unknown network' => ['saved:42:myspace', null];
        yield 'no user' => ['saved::bluesky', null];
        yield 'a private feed' => ['social:bluesky:42:home', null];
        yield 'a web feed naming a network' => ['https://example.com/saved:42:bluesky', null];
    }

    #[DataProvider('networks')]
    public function testTheNetworkIsReadFromTheUrl(string $url, ?SocialNetwork $network): void
    {
        self::assertSame($network, SavedFeedUrl::networkOf($url));
    }

    public function testOnlyTheSavedSchemeIsSaved(): void
    {
        self::assertTrue(SavedFeedUrl::isSaved('saved:42'));
        self::assertFalse(SavedFeedUrl::isSaved('social:bluesky:42:home'));
        self::assertFalse(SavedFeedUrl::isSaved('https://saved.example/feed'));
    }

    public function testReboundNamesTheSameFeedOfAnotherUserAndLeavesOtherUrlsAlone(): void
    {
        self::assertSame('saved:9:bluesky', SavedFeedUrl::rebound('saved:42:bluesky', 9));
        self::assertSame('saved:9', SavedFeedUrl::rebound('saved:42', 9));
        self::assertSame('https://example.com/feed.xml', SavedFeedUrl::rebound('https://example.com/feed.xml', 9));
    }
}
```

Append to `backend/tests/Repository/DueSocialFeedsTest.php` (the class already has `feeds()` and the `NOW` constant, lines 21, 59-65):
```php
    public function testASavedFeedIsNeverDueNotEvenWhenAskedForByItsId(): void
    {
        $saved = new Feed('saved:1:bluesky');
        $saved->setSourceFormat(SourceFormat::SAVED);
        $this->entityManager->persist($saved);
        $this->entityManager->flush();
        $now = new \DateTimeImmutable(self::NOW);

        $dueIds = array_map(
            static fn (Feed $feed): int => $feed->requireId(),
            $this->feeds()->findDue(new DueFeedCriteria($now, force: true), 50),
        );

        self::assertNotContains($saved->requireId(), $dueIds);
        self::assertSame([], $this->feeds()->findDue(new DueFeedCriteria($now, feedId: $saved->requireId()), 10));
    }
```

Append to `backend/tests/Repository/ExportableSubscriptionsTest.php` (reuses its `subscribed()` and `subscriptions()` helpers, lines 28-46):
```php
    public function testAnOpmlLeavesTheSavedFeedsOutAndABackupKeepsThem(): void
    {
        $user = new User('exportable-saved@example.com', new \DateTimeImmutable('2026-10-17T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $rss = $this->subscribed($user, 'https://example.com/saved-test.xml', SourceFormat::XML);
        $this->subscribed($user, 'social:mastodon:2:home', SourceFormat::MASTODON_API);
        $saved = $this->subscribed($user, sprintf('saved:%d:bluesky', $user->requireId()), SourceFormat::SAVED);
        $this->entityManager->flush();

        self::assertSame([$rss], $this->subscriptions()->findExportableForUserWithTags($user->requireId()));
        self::assertSame([$rss, $saved], $this->subscriptions()->findBackedUpForUserWithTags($user->requireId()));
    }

    public function testTheCapCountsNoSavedFeed(): void
    {
        $user = new User('count-saved@example.com', new \DateTimeImmutable('2026-10-17T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $rss = $this->subscribed($user, 'https://example.com/counted.xml', SourceFormat::XML);
        $saved = $this->subscribed($user, sprintf('saved:%d', $user->requireId()), SourceFormat::SAVED);
        $this->entityManager->flush();

        self::assertSame(1, $this->subscriptions()->countForUser($user->requireId()));
        self::assertSame(
            $saved,
            $this->subscriptions()->findOneForUserAndFeedUrl($user->requireId(), sprintf('saved:%d', $user->requireId())),
        );
        self::assertNull($this->subscriptions()->findOneForUserAndFeedUrl($user->requireId() + 1000, $rss->getFeed()->getUrl()));
    }
```

Append to `backend/tests/Service/Social/FeedNetworkListenerTest.php` (read it first; reuse its way of building and flushing a `Feed`). The case to add, in that file's style:
```php
    public function testASavedFeedTakesItsNetworkFromItsUrl(): void
    {
        $bluesky = new Feed('saved:5:bluesky');
        $bluesky->setSourceFormat(SourceFormat::SAVED);
        $feeds = new Feed('saved:5');
        $feeds->setSourceFormat(SourceFormat::SAVED);
        $listener = new FeedNetworkListener();

        $listener->preFlush($bluesky);
        $listener->preFlush($feeds);

        self::assertSame(SocialNetwork::Bluesky, $bluesky->getNetwork());
        self::assertNull($feeds->getNetwork());
    }

    /** Guard test: passes before this task too (a `saved:` URL is no profile URL); it pins that only the format makes a saved feed. */
    public function testASavedUrlStoredAsAnotherFormatGetsNoNetwork(): void
    {
        $feed = new Feed('saved:5:bluesky');
        (new FeedNetworkListener())->preFlush($feed);

        self::assertNull($feed->getNetwork());
    }
```

Append to `backend/tests/Repository/FeedRepositoryNetworkScopeTest.php` (read it first; follow its seeding helpers) a case proving a `saved:<id>:bluesky` feed with `SourceFormat::SAVED` is inside `NetworkScope::whole(SocialNetwork::Bluesky)` and outside `NetworkScope::profiles(SocialNetwork::Bluesky)`.

- [ ] **Step 2: Run the tests to verify they fail**

Run (from `backend/`): `php bin/phpunit tests/Service/SavedPosts/Support/SavedFeedUrlTest.php tests/Repository/DueSocialFeedsTest.php tests/Repository/ExportableSubscriptionsTest.php tests/Service/Social/FeedNetworkListenerTest.php tests/Repository/FeedRepositoryNetworkScopeTest.php`
Expected: FAIL — class `SavedFeedUrl` not found, undefined constant `SourceFormat::SAVED`, undefined methods. Exception: the guard test `testASavedUrlStoredAsAnotherFormatGetsNoNetwork` passes (run alone with `--filter`, it is green before and after).

- [ ] **Step 3: Implement**

`backend/src/Enum/SourceFormat.php`, after `SOCIAL` (line 29):
```php
    /** A user's "Saved from …" feed of favourites and kept posts from feeds they left (#1666); never fetched. */
    public const string SAVED = 'saved';

    /** Formats whose networked feed is no person's profile. */
    public const array NOT_PROFILES = [self::BLUESKY_API, self::MASTODON_API, self::SAVED];
```

`backend/src/Service/SavedPosts/Support/SavedFeedUrl.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\SavedPosts\Support;

use App\Enum\SocialNetwork;

/** A virtual feed's address, `saved:<userId>[:<network>]`: one row per user and network, never fetched. */
final class SavedFeedUrl
{
    private const string SCHEME = 'saved:';
    private const string PATTERN = '/^saved:[1-9]\d*(?::([a-z]+))?$/';

    public static function of(int $userId, ?SocialNetwork $network): string
    {
        $url = self::SCHEME . $userId;

        return null === $network ? $url : $url . ':' . $network->value;
    }

    public static function isSaved(string $url): bool
    {
        return str_starts_with($url, self::SCHEME);
    }

    public static function networkOf(string $url): ?SocialNetwork
    {
        if (1 !== preg_match(self::PATTERN, $url, $parts)) {
            return null;
        }

        return SocialNetwork::tryFrom($parts[1] ?? '');
    }

    /** A restore names the restoring user's own saved feed, never the one the file came from. */
    public static function rebound(string $url, int $userId): string
    {
        return self::isSaved($url) ? self::of($userId, self::networkOf($url)) : $url;
    }

    private function __construct()
    {
    }
}
```

`backend/src/Service/Social/Support/SocialFeedClassifier.php`, replace `networkFor()` (lines 20-23):
```php
    public static function networkFor(string $url, string $sourceFormat): ?SocialNetwork
    {
        if (SourceFormat::SAVED === $sourceFormat) {
            return SavedFeedUrl::networkOf($url);
        }

        return self::privateAddressFor($url, $sourceFormat)->network ?? ProfileFeedUrl::networkOf($url);
    }
```
and add `use App\Service\SavedPosts\Support\SavedFeedUrl;`.

`backend/src/Repository/NetworkScopeDql.php` line 18-19: replace `:socialFormats` / `SourceFormat::SOCIAL` by `:notProfiles` / `SourceFormat::NOT_PROFILES`; update the class docblock to "A profile feed is a networked feed of none of SourceFormat::NOT_PROFILES."

`backend/src/Repository/FeedRepository.php`:
- line 190: `->andWhere('f.sourceFormat NOT IN (:notProfiles)')->setParameter('notProfiles', SourceFormat::NOT_PROFILES)`
- `dueQueryBuilder()` (line 203-214): call `$this->restrictToFetchable($qb);` first, and add
```php
    private function restrictToFetchable(QueryBuilder $qb): void
    {
        $qb->andWhere('f.sourceFormat <> :savedFormat')->setParameter('savedFormat', SourceFormat::SAVED);
    }
```

`backend/src/Repository/SubscriptionRepository.php`, replace `findExportableForUserWithTags()` (lines 50-65) with:
```php
    /**
     * What an OPML export carries: a private feed is read through an account the file does not hold, and a saved feed
     * is this reader's own.
     *
     * @return list<Subscription>
     */
    public function findExportableForUserWithTags(int $userId): array
    {
        return $this->withTagsExcluding($userId, SourceFormat::NOT_PROFILES);
    }

    /**
     * What a backup carries: everything but the private feeds; saved feeds are restored as saved feeds (#1666).
     *
     * @return list<Subscription>
     */
    public function findBackedUpForUserWithTags(int $userId): array
    {
        return $this->withTagsExcluding($userId, SourceFormat::SOCIAL);
    }

    public function findOneForUserAndFeedUrl(int $userId, string $feedUrl): ?Subscription
    {
        /** @var Subscription|null $subscription */
        $subscription = $this->createQueryBuilder('s')
            ->join('s.feed', 'f')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->andWhere('f.url = :feedUrl')->setParameter('feedUrl', $feedUrl)
            ->getQuery()
            ->getOneOrNullResult();

        return $subscription;
    }

    /**
     * @param list<string> $excludedFormats
     *
     * @return list<Subscription>
     */
    private function withTagsExcluding(int $userId, array $excludedFormats): array
    {
        /** @var list<Subscription> $rows */
        $rows = $this->withTagsForUser($userId)
            ->andWhere('f.sourceFormat NOT IN (:excludedFormats)')
            ->setParameter('excludedFormats', $excludedFormats)
            ->getQuery()
            ->getResult();

        return $rows;
    }
```
and `countForUser()` (lines 92-98):
```php
    /** The count the cap holds against: a saved feed is never a subscription the user chose (#1666). */
    public function countForUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->join('s.feed', 'f')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->andWhere('f.sourceFormat <> :savedFormat')->setParameter('savedFormat', SourceFormat::SAVED)
            ->getQuery()
            ->getSingleScalarResult();
    }
```

`backend/src/Service/Backup/AccountBackupExporter.php:64`: `findExportableForUserWithTags` → `findBackedUpForUserWithTags`.

- [ ] **Step 4: Run the tests to verify they pass**

Run the Step 2 command, then `php bin/phpunit tests/Repository tests/Service/Opml tests/Service/Backup tests/Service/Social/Support`.
Expected: PASS.

- [ ] **Step 5: Gates and commit**

Pre-flight note 8. Then:
```bash
git add backend/src/Enum/SourceFormat.php backend/src/Service/SavedPosts backend/src/Service/Social/Support/SocialFeedClassifier.php backend/src/Repository/FeedRepository.php backend/src/Repository/NetworkScopeDql.php backend/src/Repository/SubscriptionRepository.php backend/src/Service/Backup/AccountBackupExporter.php backend/tests/Service/SavedPosts backend/tests/Repository/DueSocialFeedsTest.php backend/tests/Repository/ExportableSubscriptionsTest.php backend/tests/Repository/FeedRepositoryNetworkScopeTest.php backend/tests/Service/Social/FeedNetworkListenerTest.php
git commit -m "feat(#1666): a saved source format the scheduler never fetches and the cap never counts"
```

---

### Task 2: An RSS copy's origin — `saved_entry_origin`

**Files:**
- Create: `backend/src/Entity/EntryOrigin.php`, `backend/src/Entity/SavedEntryOrigin.php`, `backend/src/Repository/SavedEntryOriginRepository.php`, `backend/migrations/Version20261017120000.php`
- Test: create `backend/tests/Repository/SavedEntryOriginRepositoryTest.php`

**Interfaces — Produces:**
```php
final readonly class EntryOrigin { public function __construct(public ?string $title, public ?string $faviconUrl) {} }
final class SavedEntryOrigin { public function __construct(Entry $entry, EntryOrigin $origin); public function origin(): EntryOrigin; }
final class SavedEntryOriginRepository {
    /** @param list<int> $entryIds @return array<int, EntryOrigin> */
    public function originsByEntryIds(array $entryIds): array;
}
```

- [ ] **Step 1: Write the failing test**

`backend/tests/Repository/SavedEntryOriginRepositoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryOrigin;
use App\Entity\Feed;
use App\Entity\SavedEntryOrigin;
use App\Repository\SavedEntryOriginRepository;
use App\Tests\DbTestCase;

final class SavedEntryOriginRepositoryTest extends DbTestCase
{
    public function testOriginsAreReadByEntryAndAnEntryWithoutOneIsAbsent(): void
    {
        $feed = new Feed('saved:1');
        $this->entityManager->persist($feed);
        $withOrigin = $this->entry($feed, 'a');
        $without = $this->entry($feed, 'b');
        $this->entityManager->persist(new SavedEntryOrigin($withOrigin, new EntryOrigin('Heise', 'https://heise.de/icon.png')));
        $this->entityManager->flush();

        $origins = $this->repository()->originsByEntryIds([$withOrigin->requireId(), $without->requireId()]);

        self::assertEquals([$withOrigin->requireId() => new EntryOrigin('Heise', 'https://heise.de/icon.png')], $origins);
        self::assertSame([], $this->repository()->originsByEntryIds([]));
    }

    public function testTheOriginGoesWithItsEntry(): void
    {
        $feed = new Feed('saved:2');
        $this->entityManager->persist($feed);
        $entry = $this->entry($feed, 'gone');
        $this->entityManager->persist(new SavedEntryOrigin($entry, new EntryOrigin('Gone', null)));
        $this->entityManager->flush();
        $entryId = $entry->requireId();

        $this->entityManager->createQuery(sprintf('DELETE FROM %s e WHERE e.id = :id', Entry::class))
            ->setParameter('id', $entryId)
            ->execute();
        $this->entityManager->clear();

        self::assertSame([], $this->repository()->originsByEntryIds([$entryId]));
    }

    private function entry(Feed $feed, string $guid): Entry
    {
        $at = new \DateTimeImmutable('2026-10-17 10:00:00');
        $entry = new Entry($feed, $guid, 'https://example.com/' . $guid, 'Title', $at, $at);
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function repository(): SavedEntryOriginRepository
    {
        /** @var SavedEntryOriginRepository $repository */
        $repository = $this->entityManager->getRepository(SavedEntryOrigin::class);

        return $repository;
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Repository/SavedEntryOriginRepositoryTest.php`
Expected: FAIL — class `App\Entity\EntryOrigin` not found.

- [ ] **Step 3: Implement**

`backend/src/Entity/EntryOrigin.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

/** Where a saved copy of an article came from: the feed's title and icon at the time it was left (#1666). */
final readonly class EntryOrigin
{
    public function __construct(
        public ?string $title,
        public ?string $faviconUrl,
    ) {
    }
}
```

`backend/src/Entity/SavedEntryOrigin.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SavedEntryOriginRepository;
use Doctrine\ORM\Mapping as ORM;

/** The origin of an RSS article in a "Saved from feeds" feed; a row only such copies have. */
#[ORM\Entity(repositoryClass: SavedEntryOriginRepository::class)]
#[ORM\Table(name: 'saved_entry_origin')]
final class SavedEntryOrigin
{
    public const int TITLE_MAX = 512;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column(length: self::TITLE_MAX, nullable: true)]
    private ?string $title;

    #[ORM\Column(name: 'favicon_url', length: 2048, nullable: true)]
    private ?string $faviconUrl;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(Entry $entry, EntryOrigin $origin)
    {
        $this->entry = $entry;
        $this->title = $origin->title;
        $this->faviconUrl = $origin->faviconUrl;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function origin(): EntryOrigin
    {
        return new EntryOrigin($this->title, $this->faviconUrl);
    }
}
```

`backend/src/Repository/SavedEntryOriginRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryOrigin;
use App\Entity\SavedEntryOrigin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SavedEntryOrigin>
 */
final class SavedEntryOriginRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedEntryOrigin::class);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, EntryOrigin> by entry id; an entry without an origin is absent
     */
    public function originsByEntryIds(array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }
        /** @var list<array{entryId: int|string, title: ?string, faviconUrl: ?string}> $rows */
        $rows = $this->createQueryBuilder('origin')
            ->select('IDENTITY(origin.entry) AS entryId', 'origin.title', 'origin.faviconUrl')
            ->andWhere('origin.entry IN (:entryIds)')
            ->setParameter('entryIds', $entryIds)
            ->getQuery()
            ->getArrayResult();

        $origins = [];
        foreach ($rows as $row) {
            $origins[(int) $row['entryId']] = new EntryOrigin($row['title'], $row['faviconUrl']);
        }

        return $origins;
    }
}
```

`backend/migrations/Version20261017120000.php` — follow `Version20261012130000.php` exactly (platform branch, `abortIfUnsupportedPlatform()`, `isTransactional(): false`):
```php
    public function getDescription(): string
    {
        return 'Create saved_entry_origin: the source title and icon of an article saved from a left feed (#1666).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('saved_entry_origin'), 'saved_entry_origin already exists.');
        $this->abortIfUnsupportedPlatform();

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('CREATE TABLE saved_entry_origin (entry_id INT NOT NULL, title VARCHAR(512) DEFAULT NULL,'
                . ' favicon_url VARCHAR(2048) DEFAULT NULL, PRIMARY KEY (entry_id)) DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE saved_entry_origin ADD CONSTRAINT fk_saved_entry_origin_entry'
                . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');

            return;
        }

        $this->addSql('CREATE TABLE saved_entry_origin (entry_id INTEGER NOT NULL, title VARCHAR(512) DEFAULT NULL,'
            . ' favicon_url VARCHAR(2048) DEFAULT NULL, PRIMARY KEY (entry_id),'
            . ' CONSTRAINT fk_saved_entry_origin_entry FOREIGN KEY (entry_id) REFERENCES entry (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('saved_entry_origin'), 'saved_entry_origin does not exist.');
        $this->abortIfUnsupportedPlatform();
        $this->addSql('DROP TABLE saved_entry_origin');
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php bin/phpunit tests/Repository/SavedEntryOriginRepositoryTest.php`
Expected: PASS.

- [ ] **Step 5: Verify the migration on both dialects**

MySQL (Docker): `docker compose exec php bin/console doctrine:schema:update --dump-sql` — expected: exactly the migration's MySQL statements (Doctrine may also want an index on `entry_id`; a PK already covers it — if `--dump-sql` prints a `CREATE INDEX IDX_…` copy it into both branches). Then:
```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
docker compose exec php bin/console doctrine:schema:update --dump-sql
```
Expected: migrate runs `Version20261017120000`; validate `[OK]` twice; last prints `[OK] Nothing to update`.

SQLite, from `backend/`:
```bash
rm -f var/migration-check.db
DATABASE_URL='sqlite:///%kernel.project_dir%/var/migration-check.db' php bin/console doctrine:migrations:migrate --no-interaction
DATABASE_URL='sqlite:///%kernel.project_dir%/var/migration-check.db' php bin/console doctrine:schema:validate
rm -f var/migration-check.db
```
Expected: both `[OK]`.

- [ ] **Step 6: Backup coverage**

`tests/Service/Backup/Support/BackupSchemaCoverageTest.php` now fails on the undeclared entity. Nothing exports it yet, so for this commit declare it **wholly dropped only**: in `BackupSchemaCoverageTest::ACCOUNT_SCOPED_WHOLLY_DROPPED` (lines 129-161) add `SavedEntryOrigin::class => 'The origin of an article saved from a left feed (#1666). A backup carries it from #1666 Task 8 on.',` (plus its `use` line) and a matching `| \`SavedEntryOrigin\` | … |` row in `docs/backup.md` §6.2. Do **not** touch `BackupFieldDeclarations.php` here — Task 8 adds the `BACKED_UP`/`KIND_OF` declarations and removes this entry and row. Run `php bin/phpunit tests/Service/Backup` — expected PASS.

- [ ] **Step 7: Gates and commit**
```bash
git add backend/src/Entity/EntryOrigin.php backend/src/Entity/SavedEntryOrigin.php backend/src/Repository/SavedEntryOriginRepository.php backend/migrations/Version20261017120000.php backend/tests/Repository/SavedEntryOriginRepositoryTest.php backend/tests/Service/Backup/Support/BackupSchemaCoverageTest.php docs/backup.md
git commit -m "feat(#1666): a table for the origin of an article saved from a left feed"
```

---

### Task 3: A user's virtual feeds — `SavedFeedFactory` and `SavedFeeds`

**Files:**
- Create: `backend/src/Service/SavedPosts/Factory/SavedFeedFactory.php`, `backend/src/Service/SavedPosts/SavedFeeds.php`
- Test: create `backend/tests/Service/SavedPosts/SavedFeedsTest.php`

**Interfaces — Consumes:** `SavedFeedUrl::of()`, `SubscriptionRepository::findOneForUserAndFeedUrl()` (Task 1); `FeedFactory::create(string $url, string $sourceFormat, ?string $title): Feed` (`backend/src/Service/Feed/Factory/FeedFactory.php:12`); `SubscriptionRepository::nextPositionForUser(int): int` (`SubscriptionRepository.php:153`).
**Produces:**
```php
final readonly class SavedFeedFactory {
    public function feed(int $userId, ?SocialNetwork $network): Feed;
    public function subscription(User $user, Feed $feed, int $position): Subscription;
}
final readonly class SavedFeeds {
    /** The user's virtual feed for posts of $network (null: RSS), created on first use and flushed. */
    public function of(User $user, ?SocialNetwork $network): Subscription;
}
```

- [ ] **Step 1: Write the failing test**

`backend/tests/Service/SavedPosts/SavedFeedsTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\SavedPosts;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\SavedPosts\SavedFeeds;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SavedFeedsTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('saved-feeds@example.test');
    }

    public function testTheFirstUseCreatesAnUnfetchedFeedOutsideAllItemsAndForYou(): void
    {
        $subscription = $this->savedFeeds()->of($this->user, SocialNetwork::Bluesky);

        $feed = $subscription->getFeed();
        self::assertSame(sprintf('saved:%d:bluesky', $this->user->requireId()), $feed->getUrl());
        self::assertSame(SourceFormat::SAVED, $feed->getSourceFormat());
        self::assertSame('Saved from Bluesky', $feed->getTitle());
        self::assertSame(SocialNetwork::Bluesky, $feed->getNetwork());
        self::assertFalse($subscription->isIncludeInAllItems());
        self::assertFalse($subscription->isIncludeInForYou());
        self::assertCount(0, $subscription->getTags());
    }

    public function testFeedsAndEachNetworkGetTheirOwnAndARepeatReturnsTheSame(): void
    {
        $feeds = $this->savedFeeds()->of($this->user, null);
        $mastodon = $this->savedFeeds()->of($this->user, SocialNetwork::Mastodon);
        $again = $this->savedFeeds()->of($this->user, null);

        self::assertSame('Saved from feeds', $feeds->getFeed()->getTitle());
        self::assertNull($feeds->getFeed()->getNetwork());
        self::assertSame('Saved from Mastodon', $mastodon->getFeed()->getTitle());
        self::assertSame($feeds->requireId(), $again->requireId());
        self::assertSame(2, $this->entityManager->getRepository(Subscription::class)->count(['user' => $this->user]));
    }

    public function testAFeedRowLeftWithoutItsSubscriptionIsReused(): void
    {
        $orphan = new Feed(sprintf('saved:%d', $this->user->requireId()));
        $orphan->setSourceFormat(SourceFormat::SAVED);
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();

        $subscription = $this->savedFeeds()->of($this->user, null);

        self::assertSame($orphan->requireId(), $subscription->getFeed()->requireId());
    }

    /** Guard test: SavedFeeds never asks the cap; it pins that nobody routes it through SubscriptionCreator later. */
    public function testAFullAccountStillGetsItsSavedFeed(): void
    {
        $this->user->setMaxSubscriptions(0);

        self::assertNotNull($this->savedFeeds()->of($this->user, null)->getId());
    }

    /** Built by hand: nothing in the app injects SavedFeeds before Task 5, so the test container has removed it. */
    private function savedFeeds(): SavedFeeds
    {
        /** @var SubscriptionRepository $subscriptions */
        $subscriptions = $this->entityManager->getRepository(Subscription::class);
        /** @var FeedRepository $feeds */
        $feeds = $this->entityManager->getRepository(Feed::class);

        return new SavedFeeds(
            $subscriptions,
            $feeds,
            new SavedFeedFactory(new FeedFactory(), new NaiveUtcClock(new MockClock('2026-10-17 12:00:00', 'UTC'))),
            $this->entityManager,
        );
    }
}
```
(Add `use App\Repository\FeedRepository; use App\Repository\SubscriptionRepository; use App\Service\Clock\NaiveUtcClock; use App\Service\Feed\Factory\FeedFactory; use App\Service\SavedPosts\Factory\SavedFeedFactory; use Symfony\Component\Clock\MockClock;`.)

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Service/SavedPosts/SavedFeedsTest.php`
Expected: FAIL — class `App\Service\SavedPosts\SavedFeeds` not found.

- [ ] **Step 3: Implement**

`backend/src/Service/SavedPosts/Factory/SavedFeedFactory.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\SavedPosts\Factory;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Feed\Factory\FeedFactory;
use App\Service\SavedPosts\Support\SavedFeedUrl;

final readonly class SavedFeedFactory
{
    public function __construct(
        private FeedFactory $feedFactory,
        private NaiveUtcClock $clock,
    ) {
    }

    public function feed(int $userId, ?SocialNetwork $network): Feed
    {
        return $this->feedFactory->create(
            SavedFeedUrl::of($userId, $network),
            SourceFormat::SAVED,
            'Saved from ' . ($network?->displayName() ?? 'feeds'),
        );
    }

    public function subscription(User $user, Feed $feed, int $position): Subscription
    {
        $subscription = new Subscription($user, $feed, $this->clock->now());
        $subscription->setPosition($position);
        $subscription->setIncludeInAllItems(false);
        $subscription->setIncludeInForYou(false);

        return $subscription;
    }
}
```

`backend/src/Service/SavedPosts/SavedFeeds.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\SavedPosts;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Service\SavedPosts\Factory\SavedFeedFactory;
use App\Service\SavedPosts\Support\SavedFeedUrl;
use Doctrine\ORM\EntityManagerInterface;

/** Bypasses SubscriptionCreator on purpose: the cap would refuse the feed an unsubscribe needs to rescue into. */
final readonly class SavedFeeds
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private FeedRepository $feeds,
        private SavedFeedFactory $savedFeedFactory,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function of(User $user, ?SocialNetwork $network): Subscription
    {
        $userId = $user->requireId();
        $url = SavedFeedUrl::of($userId, $network);
        $existing = $this->subscriptions->findOneForUserAndFeedUrl($userId, $url);
        if (null !== $existing) {
            return $existing;
        }

        $feed = $this->feeds->findOneBy(['url' => $url]) ?? $this->savedFeedFactory->feed($userId, $network);
        $subscription = $this->savedFeedFactory->subscription(
            $user,
            $feed,
            $this->subscriptions->nextPositionForUser($userId),
        );
        $this->entityManager->persist($feed);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php bin/phpunit tests/Service/SavedPosts/SavedFeedsTest.php`
Expected: PASS.

- [ ] **Step 5: Gates and commit**
```bash
git add backend/src/Service/SavedPosts backend/tests/Service/SavedPosts
git commit -m "feat(#1666): one saved feed per user and network, created on first use"
```

---

### Task 4: The copy queries — `SavedPostRepository`

All SQL of the rescue: which entries a leaving subscription's owner flagged, which of them already live in a feed the user keeps, which the saved feed already holds, and the copy itself with its link cards, categories, origin and like.

**Files:**
- Create: `backend/src/Repository/SavedPostRepository.php`, `backend/src/Service/SavedPosts/Model/SavedCopyModel.php`
- Modify: `backend/src/Repository/SocialLikeRepository.php` (add `copyLike`), `backend/src/Repository/EntryRepository.php` (add `findAllByIds`)
- Test: create `backend/tests/Repository/SavedPostRepositoryTest.php`

**Interfaces — Produces:**
```php
final readonly class SavedCopyModel {
    /** $poster null keeps each entry's own author columns; else name/url replace them and avatarUrl stands in for a missing one */
    public function __construct(public int $savedFeedId, public ?EntryAuthor $poster) {}
}
final readonly class SavedPostRepository {
    /** @return array<int, ?string> url hash by entry id: the feed's entries this user favourited or kept */
    public function flaggedIn(int $userId, int $feedId): array;
    /** @param array<int, ?string> $urlHashesByEntryId @param list<int> $leavingFeedIds @return array<int, int> twin id by entry id */
    public function keptTwinsOf(int $userId, array $urlHashesByEntryId, array $leavingFeedIds): array;
    /** @param list<int> $entryIds @return array<int, int> the saved feed's row with the same guid, by entry id */
    public function copiesIn(int $savedFeedId, array $entryIds): array;
    /** @param list<int> $entryIds @return list<array{id: int, guid: string, guidHash: string, urlHash: ?string, feedId: int}> */
    public function originalsOf(array $entryIds): array;
    /** @param list<string> $guidHashes @return array<string, array{id: int, urlHash: ?string}> the saved feed's rows by guid hash */
    public function heldIn(int $savedFeedId, array $guidHashes): array;
    /** @param list<int> $entryIds @return array<int, int> copy id by entry id, for the rows this call created */
    public function copyInto(SavedCopyModel $copy, array $entryIds): array;
    /** One copy stored under another guid: the saved feed already holds the original's guid for another article. */
    public function copyRenamed(SavedCopyModel $copy, int $entryId, EntryGuid $guid): int;
    /** @param array<int, int> $copyByEntryId */
    public function copyCompanions(array $copyByEntryId, ?EntryOrigin $origin): void;
}
// SocialLikeRepository
public function copyLike(int $userId, int $fromEntryId, int $toEntryId): void;
// EntryRepository
/** @param list<int> $entryIds @return list<Entry> */
public function findAllByIds(array $entryIds): array;
```

- [ ] **Step 1: Write the failing test**

`backend/tests/Repository/SavedPostRepositoryTest.php` (DB test; builds users with `UserFactory`, feeds with `new Feed`, entries with `new Entry` as `EntryPrunerTest::persistEntry`, `backend/tests/Service/Retention/EntryPrunerTest.php:119-130`):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryAuthor;
use App\Entity\EntryOrigin;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Entity\SavedEntryOrigin;
use App\Entity\SocialLike;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Repository\EntryRepository;
use App\Repository\LinkCardRepository;
use App\Repository\SavedEntryOriginRepository;
use App\Repository\SavedPostRepository;
use App\Repository\SocialLikeRepository;
use App\Service\SavedPosts\Model\SavedCopyModel;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SavedPostRepositoryTest extends DbTestCase
{
    private const string AT = '2026-10-17 10:00:00';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('saved-posts-repo@example.test');
    }

    public function testFlaggedMeansFavouriteOrKeptByThisUserInThisFeed(): void
    {
        $feed = $this->subscribedFeed('https://flagged.example/feed.xml');
        $favourite = $this->entry($feed, 'fav', 'https://flagged.example/1');
        $kept = $this->entry($feed, 'kept', null);
        $readOnly = $this->entry($feed, 'read', 'https://flagged.example/3');
        $this->state($favourite)->markFavorite();
        $this->state($kept)->markKept();
        $this->state($readOnly)->hide(new \DateTimeImmutable(self::AT));
        $this->entityManager->flush();

        $flagged = $this->repository()->flaggedIn($this->user->requireId(), $feed->requireId());

        self::assertSame([$favourite->requireId(), $kept->requireId()], array_keys($flagged));
        self::assertSame($favourite->getUrlHash(), $flagged[$favourite->requireId()]);
        self::assertNull($flagged[$kept->requireId()]);
    }

    public function testAKeptTwinIsTheLowestIdCopyInAFeedTheUserStaysWithAndNeverInALeavingFeed(): void
    {
        $leaving = $this->subscribedFeed('https://leaving.example/feed.xml');
        $alsoLeaving = $this->subscribedFeed('https://also-leaving.example/feed.xml');
        $kept = $this->subscribedFeed('https://kept.example/feed.xml');
        $stranger = new Feed('https://not-subscribed.example/feed.xml');
        $this->entityManager->persist($stranger);
        $original = $this->entry($leaving, 'post', 'https://news.example/story');
        $this->entry($alsoLeaving, 'post', 'https://news.example/story');
        $this->entry($stranger, 'post', 'https://news.example/story');
        $twin = $this->entry($kept, 'post', 'https://news.example/story');
        $this->entry($kept, 'later', 'https://news.example/story');
        $lonely = $this->entry($leaving, 'lonely', 'https://news.example/only-here');
        $this->entityManager->flush();

        $twins = $this->repository()->keptTwinsOf(
            $this->user->requireId(),
            [$original->requireId() => $original->getUrlHash(), $lonely->requireId() => $lonely->getUrlHash()],
            [$leaving->requireId(), $alsoLeaving->requireId()],
        );

        self::assertSame([$original->requireId() => $twin->requireId()], $twins);
    }

    public function testAnEntryWithoutAUrlHashHasNoTwin(): void
    {
        $leaving = $this->subscribedFeed('https://hashless.example/feed.xml');
        $entry = $this->entry($leaving, 'no-url', null);
        $this->entityManager->flush();

        self::assertSame([], $this->repository()->keptTwinsOf($this->user->requireId(), [$entry->requireId() => null], [$leaving->requireId()]));
    }

    public function testACopyKeepsEveryColumnButItsFeedAndASecondCopyOfTheSameGuidIsSkipped(): void
    {
        $source = $this->subscribedFeed('https://copied.example/feed.xml');
        $saved = $this->savedFeed();
        $entry = $this->entry($source, 'guid-1', 'https://copied.example/1');
        $entry->setSummary('Summary');
        $entry->setContentHtml('<p>Body</p>');
        $entry->setAuthor(new EntryAuthor('Jane', 'https://jane.example', null));
        $this->entityManager->flush();

        $copies = $this->repository()->copyInto(new SavedCopyModel($saved->requireId(), null), [$entry->requireId()]);
        $again = $this->repository()->copyInto(new SavedCopyModel($saved->requireId(), null), [$entry->requireId()]);
        $this->entityManager->clear();

        self::assertCount(1, $copies);
        self::assertSame([], $again);
        $copy = $this->entityManager->find(Entry::class, $copies[$entry->requireId()]);
        self::assertNotNull($copy);
        self::assertSame($saved->requireId(), $copy->getFeed()->requireId());
        self::assertSame('guid-1', $copy->getGuid());
        self::assertSame('https://copied.example/1', $copy->getUrl());
        self::assertSame('Summary', $copy->getSummary());
        self::assertSame('<p>Body</p>', $copy->getContentHtml());
        self::assertSame('Jane', $copy->getAuthor());
        self::assertEquals($entry->getEffectiveDate(), $copy->getEffectiveDate());
        self::assertSame(
            [$entry->requireId() => $copy->requireId()],
            $this->repository()->copiesIn($saved->requireId(), [$entry->requireId()]),
        );
    }

    public function testARenamedCopyKeepsTheArticleUnderItsNewGuid(): void
    {
        $source = $this->subscribedFeed('https://renamed.example/feed.xml');
        $saved = $this->savedFeed();
        $entry = $this->entry($source, '1', 'https://renamed.example/article');
        $guid = new EntryGuid('1#' . $source->requireId());

        $copyId = $this->repository()->copyRenamed(new SavedCopyModel($saved->requireId(), null), $entry->requireId(), $guid);
        $this->entityManager->clear();

        $copy = $this->entityManager->find(Entry::class, $copyId);
        self::assertSame('1#' . $source->requireId(), $copy?->getGuid());
        self::assertSame($guid->getHash(), $copy?->getGuidHash());
        self::assertSame('https://renamed.example/article', $copy?->getUrl());
    }

    public function testOriginalsAndHeldRowsAreReadWithTheirHashes(): void
    {
        $source = $this->subscribedFeed('https://held.example/feed.xml');
        $saved = $this->savedFeed();
        $entry = $this->entry($source, 'g', 'https://held.example/1');
        $copies = $this->repository()->copyInto(new SavedCopyModel($saved->requireId(), null), [$entry->requireId()]);

        self::assertSame(
            [['id' => $entry->requireId(), 'guid' => 'g', 'guidHash' => $entry->getGuidHash(), 'urlHash' => $entry->getUrlHash(), 'feedId' => $source->requireId()]],
            $this->repository()->originalsOf([$entry->requireId()]),
        );
        self::assertSame(
            [$entry->getGuidHash() => ['id' => $copies[$entry->requireId()], 'urlHash' => $entry->getUrlHash()]],
            $this->repository()->heldIn($saved->requireId(), [$entry->getGuidHash(), hash('sha256', 'absent')]),
        );
    }

    public function testAPosterReplacesTheAuthorAndOnlyStandsInForAMissingAvatar(): void
    {
        $profile = $this->subscribedFeed('https://bsky.app/profile/did:plc:bob/rss');
        $saved = $this->savedFeed();
        $plain = $this->entry($profile, 'plain', 'https://bsky.app/profile/did:plc:bob/post/1');
        $repost = $this->entry($profile, 'repost', 'https://bsky.app/profile/did:plc:bob/post/2');
        $repost->setAuthor(new EntryAuthor(null, null, 'https://cdn.example/reposted.jpg'));
        $this->entityManager->flush();
        $poster = new EntryAuthor('Bob', 'https://bsky.app/profile/did:plc:bob', 'https://cdn.example/bob.jpg');

        $copies = $this->repository()->copyInto(
            new SavedCopyModel($saved->requireId(), $poster),
            [$plain->requireId(), $repost->requireId()],
        );
        $this->entityManager->clear();

        $plainCopy = $this->entityManager->find(Entry::class, $copies[$plain->requireId()]);
        $repostCopy = $this->entityManager->find(Entry::class, $copies[$repost->requireId()]);
        self::assertSame('Bob', $plainCopy?->getAuthor());
        self::assertSame('https://bsky.app/profile/did:plc:bob', $plainCopy?->getAuthorUrl());
        self::assertSame('https://cdn.example/bob.jpg', $plainCopy?->getAuthorAvatarUrl());
        self::assertSame('https://cdn.example/reposted.jpg', $repostCopy?->getAuthorAvatarUrl());
    }

    public function testCompanionsFollowTheCopyAndAnOriginIsRecordedWhenGiven(): void
    {
        $source = $this->subscribedFeed('https://companions.example/feed.xml');
        $saved = $this->savedFeed();
        $entry = $this->entry($source, 'with-card', 'https://companions.example/1');
        $this->entityManager->persist(new LinkCard($entry, 0, new LinkCardContent(
            'https://shared.example/a',
            'Shared',
            null,
            null,
            'shared.example',
        )));
        $this->entityManager->flush();
        $copies = $this->repository()->copyInto(new SavedCopyModel($saved->requireId(), null), [$entry->requireId()]);

        $this->repository()->copyCompanions($copies, new EntryOrigin('Companions', 'https://companions.example/favicon.ico'));

        $copyId = $copies[$entry->requireId()];
        /** @var LinkCardRepository $cards */
        $cards = $this->entityManager->getRepository(LinkCard::class);
        self::assertCount(1, $cards->contentsByEntryIds([$copyId])[$copyId] ?? []);
        /** @var SavedEntryOriginRepository $origins */
        $origins = $this->entityManager->getRepository(SavedEntryOrigin::class);
        self::assertEquals(
            [$copyId => new EntryOrigin('Companions', 'https://companions.example/favicon.ico')],
            $origins->originsByEntryIds([$copyId]),
        );
    }

    public function testALikeIsCopiedOnceAndNeverOverAnExistingOne(): void
    {
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->user));
        $source = $this->subscribedFeed('https://liked.example/feed.xml');
        $from = $this->entry($source, 'liked', 'https://liked.example/1');
        $to = $this->entry($source, 'target', 'https://liked.example/2');
        $this->entityManager->persist(new SocialLike($this->user, $from, $account, 'at://like/1', new \DateTimeImmutable(self::AT)));
        $this->entityManager->flush();
        /** @var SocialLikeRepository $likes */
        $likes = $this->entityManager->getRepository(SocialLike::class);

        $likes->copyLike($this->user->requireId(), $from->requireId(), $to->requireId());
        $likes->copyLike($this->user->requireId(), $from->requireId(), $to->requireId());

        self::assertSame(1, $likes->count(['entry' => $to]));
        self::assertSame('at://like/1', $likes->findOneFor($this->user, $to)?->getRemoteId());
    }

    private function subscribedFeed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($this->user, $feed, new \DateTimeImmutable(self::AT)));

        return $feed;
    }

    private function savedFeed(): Feed
    {
        $feed = new Feed(sprintf('saved:%d', $this->user->requireId()));
        $feed->setSourceFormat(SourceFormat::SAVED);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    private function entry(Feed $feed, string $guid, ?string $url): Entry
    {
        $at = new \DateTimeImmutable(self::AT);
        $entry = new Entry($feed, $guid, $url, 'Title ' . $guid, $at, $at, null === $url ? null : hash('sha256', $url));
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function state(Entry $entry): EntryState
    {
        $state = new EntryState($this->user, $entry);
        $this->entityManager->persist($state);

        return $state;
    }

    /** Built by hand: nothing injects it before Task 5, so the test container has removed it. */
    private function repository(): SavedPostRepository
    {
        return new SavedPostRepository($this->entityManager);
    }
}
```
(Add `use App\Entity\EntryGuid;`.)
(Drop the unused `EntryRepository` import. The url hash here is any non-null string per URL: `Entry`'s 7th constructor argument, `backend/src/Entity/Entry.php:76-84`.)

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Repository/SavedPostRepositoryTest.php`
Expected: FAIL — class `SavedCopyModel` not found.

- [ ] **Step 3: Implement**

`backend/src/Service/SavedPosts/Model/SavedCopyModel.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\SavedPosts\Model;

use App\Entity\EntryAuthor;

final readonly class SavedCopyModel
{
    /** @param EntryAuthor|null $poster null keeps each entry's author; its avatar only fills a missing one */
    public function __construct(
        public int $savedFeedId,
        public ?EntryAuthor $poster,
    ) {
    }
}
```

`backend/src/Repository/SavedPostRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryOrigin;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Service\SavedPosts\Model\SavedCopyModel;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/** The rescue's queries (#1666): an INSERT … SELECT copies every entry column, so a column added later is copied too. */
final readonly class SavedPostRepository
{
    private const array POSTER_FIELDS = ['author.name', 'author.profileUrl', 'author.avatarUrl'];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return array<int, ?string> */
    public function flaggedIn(int $userId, int $feedId): array
    {
        /** @var list<array{id: int, urlHash: ?string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('e.id AS id', 'e.location.urlHash AS urlHash')
            ->from(Entry::class, 'e')
            ->join(EntryState::class, 'es', 'WITH', 'es.entry = e AND es.user = :user')
            ->andWhere('e.feed = :feed')
            ->andWhere('es.isFavorite = :flagged OR es.isKept = :flagged')
            ->setParameter('user', $userId)
            ->setParameter('feed', $feedId)
            ->setParameter('flagged', true, Types::BOOLEAN)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $flagged = [];
        foreach ($rows as $row) {
            $flagged[(int) $row['id']] = $row['urlHash'];
        }

        return $flagged;
    }

    /**
     * @param array<int, ?string> $urlHashesByEntryId
     * @param list<int>           $leavingFeedIds
     *
     * @return array<int, int>
     */
    public function keptTwinsOf(int $userId, array $urlHashesByEntryId, array $leavingFeedIds): array
    {
        $hashes = array_values(array_unique(array_filter($urlHashesByEntryId, static fn (?string $hash): bool => null !== $hash)));
        if ([] === $hashes) {
            return [];
        }
        /** @var list<array{id: int, urlHash: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('e.id AS id', 'e.location.urlHash AS urlHash')
            ->from(Entry::class, 'e')
            ->join(Subscription::class, 's', 'WITH', 's.feed = e.feed AND s.user = :user')
            ->andWhere('e.location.urlHash IN (:hashes)')
            ->andWhere('e.feed NOT IN (:leaving)')
            ->setParameter('user', $userId)
            ->setParameter('hashes', $hashes)
            ->setParameter('leaving', $leavingFeedIds)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $twinByHash = [];
        foreach ($rows as $row) {
            $twinByHash[$row['urlHash']] ??= (int) $row['id'];
        }

        return self::byEntry($urlHashesByEntryId, $twinByHash);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, int>
     */
    public function copiesIn(int $savedFeedId, array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }
        /** @var list<array{originalId: int, copyId: int}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('original.id AS originalId', 'held.id AS copyId')
            ->from(Entry::class, 'original')
            ->join(Entry::class, 'held', 'WITH', 'held.guid.hash = original.guid.hash AND held.feed = :saved')
            ->andWhere('original.id IN (:entryIds)')
            ->setParameter('saved', $savedFeedId)
            ->setParameter('entryIds', $entryIds)
            ->getQuery()
            ->getArrayResult();

        $copies = [];
        foreach ($rows as $row) {
            $copies[(int) $row['originalId']] = (int) $row['copyId'];
        }

        return $copies;
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<array{id: int, guid: string, guidHash: string, urlHash: ?string, feedId: int}>
     */
    public function originalsOf(array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }
        /** @var list<array{id: int|string, guid: string, guidHash: string, urlHash: ?string, feedId: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('e.id AS id', 'e.guid.value AS guid', 'e.guid.hash AS guidHash', 'e.location.urlHash AS urlHash')
            ->addSelect('IDENTITY(e.feed) AS feedId')
            ->from(Entry::class, 'e')
            ->andWhere('e.id IN (:entryIds)')
            ->setParameter('entryIds', $entryIds)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'guid' => $row['guid'],
                'guidHash' => $row['guidHash'],
                'urlHash' => $row['urlHash'],
                'feedId' => (int) $row['feedId'],
            ],
            $rows,
        );
    }

    /**
     * @param list<string> $guidHashes
     *
     * @return array<string, array{id: int, urlHash: ?string}>
     */
    public function heldIn(int $savedFeedId, array $guidHashes): array
    {
        if ([] === $guidHashes) {
            return [];
        }
        /** @var list<array{id: int|string, guidHash: string, urlHash: ?string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('held.id AS id', 'held.guid.hash AS guidHash', 'held.location.urlHash AS urlHash')
            ->from(Entry::class, 'held')
            ->andWhere('held.feed = :saved')
            ->andWhere('held.guid.hash IN (:guidHashes)')
            ->setParameter('saved', $savedFeedId)
            ->setParameter('guidHashes', $guidHashes)
            ->getQuery()
            ->getArrayResult();

        $held = [];
        foreach ($rows as $row) {
            $held[$row['guidHash']] = ['id' => (int) $row['id'], 'urlHash' => $row['urlHash']];
        }

        return $held;
    }

    /**
     * Callers pass only entries the plan found unheld; the NOT EXISTS guard still keeps a concurrent copy out.
     *
     * @param list<int> $entryIds
     *
     * @return array<int, int>
     */
    public function copyInto(SavedCopyModel $copy, array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }
        $held = $this->copiesIn($copy->savedFeedId, $entryIds);
        $this->entityManager->getConnection()->executeStatement(
            $this->copySql($this->posterOverrides($copy->poster)),
            ['savedFeedId' => $copy->savedFeedId, 'entryIds' => $entryIds] + self::posterParameters($copy->poster),
            ['entryIds' => ArrayParameterType::INTEGER],
        );

        return array_diff_key($this->copiesIn($copy->savedFeedId, $entryIds), $held);
    }

    public function copyRenamed(SavedCopyModel $copy, int $entryId, EntryGuid $guid): int
    {
        $metadata = $this->entityManager->getClassMetadata(Entry::class);
        $overrides = $this->posterOverrides($copy->poster) + [
            $metadata->getColumnName('guid.value') => ':guid',
            $metadata->getColumnName('guid.hash') => ':guidHash',
        ];
        $this->entityManager->getConnection()->executeStatement(
            $this->copySql($overrides),
            [
                'savedFeedId' => $copy->savedFeedId,
                'entryIds' => [$entryId],
                'guid' => $guid->getValue(),
                'guidHash' => $guid->getHash(),
            ] + self::posterParameters($copy->poster),
            ['entryIds' => ArrayParameterType::INTEGER],
        );

        return (int) $this->entityManager->createQueryBuilder()
            ->select('held.id')
            ->from(Entry::class, 'held')
            ->andWhere('held.feed = :saved')->setParameter('saved', $copy->savedFeedId)
            ->andWhere('held.guid.hash = :guidHash')->setParameter('guidHash', $guid->getHash())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return array<string, ?string> */
    private static function posterParameters(?EntryAuthor $poster): array
    {
        if (null === $poster) {
            return [];
        }

        return [
            'posterName' => $poster->getName(),
            'posterUrl' => $poster->getProfileUrl(),
            'posterAvatarUrl' => $poster->getAvatarUrl(),
        ];
    }

    /** @param array<int, int> $copyByEntryId */
    public function copyCompanions(array $copyByEntryId, ?EntryOrigin $origin): void
    {
        $connection = $this->entityManager->getConnection();
        foreach ($copyByEntryId as $entryId => $copyId) {
            $pair = ['copy' => $copyId, 'original' => $entryId];
            $connection->executeStatement(
                'INSERT INTO link_card (entry_id, position, url, title, description, image_url, site_name)'
                . ' SELECT :copy, position, url, title, description, image_url, site_name FROM link_card'
                . ' WHERE entry_id = :original',
                $pair,
            );
            $connection->executeStatement(
                'INSERT INTO entry_category (entry_id, category_id, position, label)'
                . ' SELECT :copy, category_id, position, label FROM entry_category WHERE entry_id = :original',
                $pair,
            );
            $this->recordOrigin($copyId, $origin);
        }
    }

    private function recordOrigin(int $copyId, ?EntryOrigin $origin): void
    {
        if (null === $origin) {
            return;
        }
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO saved_entry_origin (entry_id, title, favicon_url) VALUES (:copy, :title, :faviconUrl)',
            ['copy' => $copyId, 'title' => $origin->title, 'faviconUrl' => $origin->faviconUrl],
        );
    }

    /** @return array<string, string> column => SQL expression; empty when each entry keeps its own author */
    private function posterOverrides(?EntryAuthor $poster): array
    {
        if (null === $poster) {
            return [];
        }
        [$name, $url, $avatar] = array_map(
            $this->entityManager->getClassMetadata(Entry::class)->getColumnName(...),
            self::POSTER_FIELDS,
        );

        return [$name => ':posterName', $url => ':posterUrl', $avatar => sprintf('COALESCE(e.%s, :posterAvatarUrl)', $avatar)];
    }

    /**
     * Every entry column but its id is copied; an override replaces a column's value by an SQL expression. The guard
     * compares the guid hash the copy will carry, overridden or not. feed_id is a join column, not in the column list.
     *
     * @param array<string, string> $overrides
     */
    private function copySql(array $overrides): string
    {
        $metadata = $this->entityManager->getClassMetadata(Entry::class);
        $guidHash = $metadata->getColumnName('guid.hash');
        $copied = array_values(array_diff($metadata->getColumnNames(), [$metadata->getColumnName('id'), ...array_keys($overrides)]));
        $sources = [...array_map(static fn (string $column): string => 'e.' . $column, $copied), ...array_values($overrides)];

        return sprintf(
            'INSERT INTO entry (feed_id, %s) SELECT :savedFeedId, %s FROM entry e WHERE e.id IN (:entryIds)'
            . ' AND NOT EXISTS (SELECT 1 FROM entry held WHERE held.feed_id = :savedFeedId AND held.%s = %s)',
            implode(', ', [...$copied, ...array_keys($overrides)]),
            implode(', ', $sources),
            $guidHash,
            $overrides[$guidHash] ?? 'e.' . $guidHash,
        );
    }

    /**
     * @param array<int, ?string> $urlHashesByEntryId
     * @param array<string, int>  $twinByHash
     *
     * @return array<int, int>
     */
    private static function byEntry(array $urlHashesByEntryId, array $twinByHash): array
    {
        $twins = [];
        foreach ($urlHashesByEntryId as $entryId => $hash) {
            if (null !== $hash && isset($twinByHash[$hash])) {
                $twins[$entryId] = $twinByHash[$hash];
            }
        }

        return $twins;
    }
}
```
Add `use App\Entity\EntryAuthor;` and `use App\Entity\EntryGuid;`. The guard's right-hand side is an SQL expression (`e.guid_hash` or `:guidHash`), which is why `copySql()` formats it rather than binding it. The `poster*` parameters go only to the poster statement: DBAL's SQL parser refuses a named parameter the statement does not contain. `getColumnNames()` lists the embedded columns (`author`, `author_url`, …) because Doctrine flattens embeddables into field mappings; verify once with `bin/console doctrine:mapping:describe 'App\Entity\Entry'` that `feed_id` is absent from the field columns and present as the join column.

`backend/src/Repository/SocialLikeRepository.php`, add:
```php
    /** The like moves with a saved post; a like the target already carries wins. */
    public function copyLike(int $userId, int $fromEntryId, int $toEntryId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO social_like (user_id, entry_id, social_account_id, remote_id, liked_at)'
            . ' SELECT user_id, :toEntry, social_account_id, remote_id, liked_at FROM social_like'
            . ' WHERE user_id = :user AND entry_id = :fromEntry'
            . ' AND NOT EXISTS (SELECT 1 FROM social_like held WHERE held.user_id = :user AND held.entry_id = :toEntry)',
            ['user' => $userId, 'fromEntry' => $fromEntryId, 'toEntry' => $toEntryId],
        );
    }
```

`backend/src/Repository/EntryRepository.php`, add:
```php
    /**
     * @param list<int> $entryIds
     *
     * @return list<Entry>
     */
    public function findAllByIds(array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }
        /** @var list<Entry> $entries */
        $entries = $this->createQueryBuilder('e')
            ->andWhere('e.id IN (:ids)')->setParameter('ids', $entryIds)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $entries;
    }
```

- [ ] **Step 4: Run to verify it passes (both dialects)**

Run: `php bin/phpunit tests/Repository/SavedPostRepositoryTest.php` and `docker compose exec php php bin/phpunit tests/Repository/SavedPostRepositoryTest.php`
Expected: PASS on SQLite and MySQL (the native SQL is dialect-neutral; MySQL proves the `INSERT … SELECT … NOT EXISTS` on the same table).

- [ ] **Step 5: Gates and commit**
```bash
git add backend/src/Repository/SavedPostRepository.php backend/src/Repository/SocialLikeRepository.php backend/src/Repository/EntryRepository.php backend/src/Service/SavedPosts/Model backend/tests/Repository/SavedPostRepositoryTest.php
git commit -m "feat(#1666): queries that copy a flagged entry into a saved feed with its cards, categories and like"
```

---

### Task 5: The rescue — every unsubscribe moves favourites and kept posts first

**Before starting: pre-flight note 2** (sibling state-copy service on `develop`).

**Files:**
- Create: `backend/src/Service/SavedPosts/Support/SavedOrigin.php`, `backend/src/Service/SavedPosts/Pass/SavedCopyPlan.php`, `backend/src/Service/SavedPosts/SavedStateMover.php`, `backend/src/Service/SavedPosts/SavedPostRescue.php`
- Modify: `backend/src/Service/Subscription/SubscriptionService.php:27-74`
- Modify tests that construct `SubscriptionService`: `backend/tests/Service/Subscription/SubscriptionServiceTest.php:90-112`, `backend/tests/Service/Subscription/UnsubscribeAllTest.php:154-182`
- Test: create `backend/tests/Service/SavedPosts/SavedPostRescueTest.php`, `backend/tests/Service/SavedPosts/Pass/SavedCopyPlanTest.php`; append to `backend/tests/Service/Social/SocialAccountEditorTest.php`

**Interfaces — Consumes:** Tasks 1–4. `EntryStateRepository::forUserByEntryIds(int, list<int>): array<int, EntryState>` and `ensureRow(int $userId, int $entryId, ?\DateTimeImmutable $hiddenSince)` (`backend/src/Repository/EntryStateRepository.php:46,93`); `EntryIndexer::index(list<Entry>)` (`backend/src/Service/Search/EntryIndexer.php:36`); `SubscriptionDisplayTitle::from()` (`backend/src/Repository/SubscriptionDisplayTitle.php:10`); `FeedWebsite::of()`; `AbsoluteHttpUrl::orNull()`.
**Produces:**
```php
final class SavedOrigin {   // Support
    public static function posterOf(Subscription $leaving): ?EntryAuthor;   // profile feeds only
    public static function originOf(Subscription $leaving): ?EntryOrigin;   // RSS (no network) only
}
final readonly class SavedStateMover {
    /** @param array<int, int> $targetByEntryId moves favourite/kept (OR), read, viewed and like; clears the source flags; flushes */
    public function move(int $userId, array $targetByEntryId): void;
}
final class SavedCopyPlan {   // Pass, built with new per copy batch
    /**
     * @param list<array{id: int, guid: string, guidHash: string, urlHash: ?string, feedId: int}> $originals
     * @param array<string, array{id: int, urlHash: ?string}>                                   $held by guid hash
     */
    public function __construct(array $originals, array $held);
    /** @param list<array{guid: string, guidHash: string, feedId: int}> $originals @return list<string> every hash to look up */
    public static function heldHashesToAsk(array $originals): array;
    /** @return array<int, int> */          public function merged(): array;   // same guid and url already held
    /** @return list<int> */                public function plain(): array;    // guid not held: copied as is
    /** @return array<int, EntryGuid> */    public function renamed(): array;  // guid held for another url: <guid>#<feedId>
}
final readonly class SavedPostRescue {
    /**
     * @param list<Subscription> $leaving one unsubscribe call's subscriptions, none of them a saved feed
     *
     * @return list<int> the copies it created, to index once the caller's transaction committed
     */
    public function rescueFrom(array $leaving): array;
    /** @param list<int> $copyIds */
    public function indexCopies(array $copyIds): void;
}
```

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/SavedPosts/SavedPostRescueTest.php` — integration through `SubscriptionService` (the container's), so the real wiring is what passes:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\SavedPosts;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\SocialLike;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\EntryStateRepository;
use App\Service\SavedPosts\Support\SavedFeedUrl;
use App\Service\Subscription\SubscriptionService;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SavedPostRescueTest extends DbTestCase
{
    private const string AT = '2026-10-17 10:00:00';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->newUser('rescue@example.test');
    }

    public function testAFavouriteOfAnRssFeedMovesToSavedFromFeedsWithItsOriginAndTheRestGoes(): void
    {
        $feed = $this->feed('https://heise.example/feed.xml', 'Heise');
        $feed->setFaviconUrl('https://heise.example/favicon.ico');
        $subscription = $this->subscribe($this->user, $feed);
        $favourite = $this->entry($feed, 'fav', 'https://heise.example/1');
        $this->entry($feed, 'plain', 'https://heise.example/2');
        $this->state($this->user, $favourite)->markFavorite();
        $this->entityManager->flush();

        $this->service()->unsubscribe($subscription);
        $this->entityManager->clear();

        $saved = $this->savedFeed(null);
        $copies = $this->entriesOf($saved);
        self::assertCount(1, $copies);
        self::assertSame('fav', $copies[0]->getGuid());
        self::assertTrue($this->stateOf($copies[0])?->isFavorite());
        self::assertNull($this->entityManager->getRepository(Feed::class)->findOneBy(['url' => 'https://heise.example/feed.xml']));
        self::assertSame('Saved from feeds', $saved->getTitle());
    }

    public function testAKeptPostOfABlueskyProfileFeedKeepsItsPosterAndItsLike(): void
    {
        $feed = $this->feed('https://bsky.app/profile/did:plc:bob/rss', 'Bob');
        $feed->setSiteUrl('https://bsky.app/profile/did:plc:bob');
        $feed->setImageUrl('https://cdn.example/bob.jpg');
        $subscription = $this->subscribe($this->user, $feed);
        $kept = $this->entry($feed, 'at://did:plc:bob/app.bsky.feed.post/1', 'https://bsky.app/profile/did:plc:bob/post/1');
        $this->state($this->user, $kept)->markKept();
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->user));
        $this->entityManager->persist(new SocialLike($this->user, $kept, $account, 'at://like/1', new \DateTimeImmutable(self::AT)));
        $this->entityManager->flush();

        $this->service()->unsubscribe($subscription);
        $this->entityManager->clear();

        $copy = $this->entriesOf($this->savedFeed(SocialNetwork::Bluesky))[0];
        self::assertSame('Bob', $copy->getAuthor());
        self::assertSame('https://bsky.app/profile/did:plc:bob', $copy->getAuthorUrl());
        self::assertSame('https://cdn.example/bob.jpg', $copy->getAuthorAvatarUrl());
        self::assertTrue($this->stateOf($copy)?->isKept());
        self::assertSame(1, $this->entityManager->getRepository(SocialLike::class)->count(['entry' => $copy]));
    }

    public function testThePostSavedTwiceIsMergedIntoOneCopyWithBothFlags(): void
    {
        $home = $this->feed('https://first.example/feed.xml', 'First');
        $list = $this->feed('https://second.example/feed.xml', 'Second');
        $first = $this->subscribe($this->user, $home);
        $second = $this->subscribe($this->user, $list);
        $this->state($this->user, $this->entry($home, 'same-guid', null))->markFavorite();
        $this->state($this->user, $this->entry($list, 'same-guid', null))->markKept();
        $this->entityManager->flush();

        $this->service()->unsubscribeAll([$first, $second]);
        $this->entityManager->clear();

        $copies = $this->entriesOf($this->savedFeed(null));
        self::assertCount(1, $copies);
        self::assertTrue($this->stateOf($copies[0])?->isFavorite());
        self::assertTrue($this->stateOf($copies[0])?->isKept());
    }

    public function testThePostStillInAFeedTheUserKeepsTakesTheFlagsAndNothingIsCopied(): void
    {
        $leaving = $this->feed('https://leaving.example/feed.xml', 'Leaving');
        $kept = $this->feed('https://kept.example/feed.xml', 'Kept');
        $subscription = $this->subscribe($this->user, $leaving);
        $this->subscribe($this->user, $kept);
        $original = $this->entry($leaving, 'a', 'https://news.example/story');
        $twin = $this->entry($kept, 'b', 'https://news.example/story');
        $this->state($this->user, $original)->markFavorite();
        $this->entityManager->flush();
        $twinId = $twin->requireId();

        $this->service()->unsubscribe($subscription);
        $this->entityManager->clear();

        self::assertNull($this->entityManager->getRepository(Feed::class)->findOneBy(['url' => SavedFeedUrl::of($this->user->requireId(), null)]));
        self::assertTrue($this->stateOfId($twinId)?->isFavorite());
    }

    public function testAFeedAnotherReaderKeepsLosesOnlyTheFlagsThatMoved(): void
    {
        $shared = $this->feed('https://shared.example/feed.xml', 'Shared');
        $other = $this->newUser('rescue-other@example.test');
        $subscription = $this->subscribe($this->user, $shared);
        $this->subscribe($other, $shared);
        $original = $this->entry($shared, 'shared', 'https://shared.example/1');
        $state = $this->state($this->user, $original);
        $state->markFavorite();
        $state->hide(new \DateTimeImmutable(self::AT));
        $this->entityManager->flush();
        $originalId = $original->requireId();

        $this->service()->unsubscribe($subscription);
        $this->entityManager->clear();

        $left = $this->stateOfId($originalId);
        self::assertFalse($left?->isFavorite());
        self::assertTrue($left?->isHidden());
        $copy = $this->entriesOf($this->savedFeed(null))[0];
        self::assertTrue($this->stateOf($copy)?->isFavorite());
        self::assertTrue($this->stateOf($copy)?->isHidden());
    }

    public function testUnsubscribingASavedFeedDeletesItsPostsForGood(): void
    {
        $feed = $this->feed('https://gone.example/feed.xml', 'Gone');
        $subscription = $this->subscribe($this->user, $feed);
        $this->state($this->user, $this->entry($feed, 'fav', null))->markFavorite();
        $this->entityManager->flush();
        $this->service()->unsubscribe($subscription);
        $this->entityManager->clear();
        $saved = $this->entityManager->getRepository(Subscription::class)->findOneBy(['user' => $this->user]);
        self::assertNotNull($saved);

        $this->service()->unsubscribe($saved);
        $this->entityManager->clear();

        self::assertSame(0, $this->entityManager->getRepository(Feed::class)->count(['sourceFormat' => SourceFormat::SAVED]));
        self::assertSame(0, $this->entityManager->getRepository(Entry::class)->count(['guid.value' => 'fav']));
    }

    public function testTwoArticlesSharingAGuidUnderDifferentUrlsAreBothKept(): void
    {
        $first = $this->feed('https://first-blog.example/feed.xml', 'First blog');
        $second = $this->feed('https://second-blog.example/feed.xml', 'Second blog');
        $firstSubscription = $this->subscribe($this->user, $first);
        $secondSubscription = $this->subscribe($this->user, $second);
        $this->state($this->user, $this->entry($first, '1', 'https://first-blog.example/hello'))->markFavorite();
        $this->state($this->user, $this->entry($second, '1', 'https://second-blog.example/other'))->markKept();
        $this->entityManager->flush();
        $secondId = $second->requireId();

        $this->service()->unsubscribeAll([$firstSubscription, $secondSubscription]);
        $this->entityManager->clear();

        $copies = $this->entriesOf($this->savedFeed(null));
        self::assertSame(['1', '1#' . $secondId], array_map(static fn (Entry $copy): string => $copy->getGuid(), $copies));
        self::assertSame('https://second-blog.example/other', $copies[1]->getUrl());
        self::assertTrue($this->stateOf($copies[0])?->isFavorite());
        self::assertTrue($this->stateOf($copies[1])?->isKept());
        self::assertFalse($this->stateOf($copies[1])?->isFavorite());
    }

    public function testABulkUnsubscribeThatAlsoDropsTheSavedFeedRescuesIntoAFreshOne(): void
    {
        $old = $this->feed('https://old.example/feed.xml', 'Old');
        $oldSubscription = $this->subscribe($this->user, $old);
        $this->state($this->user, $this->entry($old, 'old-fav', null))->markFavorite();
        $this->entityManager->flush();
        $this->service()->unsubscribe($oldSubscription);
        $saved = $this->entityManager->getRepository(Subscription::class)->findOneBy(['user' => $this->user]);
        self::assertNotNull($saved);
        $feed = $this->feed('https://new.example/feed.xml', 'New');
        $subscription = $this->subscribe($this->user, $feed);
        $this->state($this->user, $this->entry($feed, 'new-fav', null))->markFavorite();
        $this->entityManager->flush();

        $this->service()->unsubscribeAll([$saved, $subscription]);
        $this->entityManager->clear();

        $copies = $this->entriesOf($this->savedFeed(null));
        self::assertSame(['new-fav'], array_map(static fn (Entry $copy): string => $copy->getGuid(), $copies));
        self::assertTrue($this->stateOf($copies[0])?->isFavorite());
    }

    /** Guard test: passes before this task too; it pins that a rescue with nothing to move creates no saved feed. */
    public function testNothingFlaggedCreatesNoSavedFeed(): void
    {
        $feed = $this->feed('https://unflagged.example/feed.xml', 'Unflagged');
        $subscription = $this->subscribe($this->user, $feed);
        $this->entry($feed, 'plain', null);
        $this->entityManager->flush();

        $this->service()->unsubscribe($subscription);

        self::assertSame(0, $this->entityManager->getRepository(Subscription::class)->count(['user' => $this->user]));
    }

    private function newUser(string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($this->entityManager, $hasher))->create($email);
    }

    private function feed(string $url, string $title): Feed
    {
        $feed = new Feed($url);
        $feed->setTitle($title);
        $this->entityManager->persist($feed);

        return $feed;
    }

    private function subscribe(User $user, Feed $feed): Subscription
    {
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable(self::AT));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    private function entry(Feed $feed, string $guid, ?string $url): Entry
    {
        $at = new \DateTimeImmutable(self::AT);
        $entry = new Entry($feed, $guid, $url, 'Title', $at, $at, null === $url ? null : hash('sha256', $url));
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function state(User $user, Entry $entry): EntryState
    {
        $state = new EntryState($user, $entry);
        $this->entityManager->persist($state);

        return $state;
    }

    private function savedFeed(?SocialNetwork $network): Feed
    {
        $feed = $this->entityManager->getRepository(Feed::class)->findOneBy([
            'url' => SavedFeedUrl::of($this->user->requireId(), $network),
        ]);
        self::assertNotNull($feed);

        return $feed;
    }

    /** @return list<Entry> */
    private function entriesOf(Feed $feed): array
    {
        return array_values($this->entityManager->getRepository(Entry::class)->findBy(['feed' => $feed], ['id' => 'ASC']));
    }

    private function stateOf(Entry $entry): ?EntryState
    {
        return $this->stateOfId($entry->requireId());
    }

    private function stateOfId(int $entryId): ?EntryState
    {
        /** @var EntryStateRepository $states */
        $states = $this->entityManager->getRepository(EntryState::class);

        return $states->findOneForUserEntry($this->user->requireId(), $entryId);
    }

    private function service(): SubscriptionService
    {
        /** @var SubscriptionService $service */
        $service = self::getContainer()->get(SubscriptionService::class);

        return $service;
    }
}
```

Append to `backend/tests/Service/Social/SocialAccountEditorTest.php` (read it first; it builds the editor at lines 170-176 with `PrivateFeedsWiring`) a test: connect a Bluesky account, create Home through `privateFeeds()`, persist an entry in Home's feed with guid `at://did:plc:x/app.bsky.feed.post/1` and a kept `EntryState`, `disconnect()`, then assert one entry with that guid in the feed `saved:<userId>:bluesky` and that its state is kept. Name: `testDisconnectingMovesKeptPostsToSavedFromBluesky`.

`backend/tests/Service/SavedPosts/Pass/SavedCopyPlanTest.php` (pure unit):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\SavedPosts\Pass;

use App\Entity\EntryGuid;
use App\Service\SavedPosts\Pass\SavedCopyPlan;
use PHPUnit\Framework\TestCase;

final class SavedCopyPlanTest extends TestCase
{
    public function testAnUnheldGuidIsCopiedAsIs(): void
    {
        $plan = new SavedCopyPlan([self::original(1, 'a', 'url-a', 10)], []);

        self::assertSame([1], $plan->plain());
        self::assertSame([], $plan->merged());
        self::assertSame([], $plan->renamed());
    }

    public function testTheSameGuidAndUrlMergeAndTwoNullUrlsCountAsTheSame(): void
    {
        $plan = new SavedCopyPlan(
            [self::original(1, 'a', 'url-a', 10), self::original(2, 'b', null, 10)],
            [hash('sha256', 'a') => ['id' => 70, 'urlHash' => 'url-a'], hash('sha256', 'b') => ['id' => 71, 'urlHash' => null]],
        );

        self::assertSame([1 => 70, 2 => 71], $plan->merged());
        self::assertSame([], $plan->plain());
    }

    public function testTheSameGuidUnderAnotherUrlIsRenamedForItsFeed(): void
    {
        $plan = new SavedCopyPlan(
            [self::original(1, '1', 'url-other', 20)],
            [hash('sha256', '1') => ['id' => 70, 'urlHash' => 'url-first']],
        );

        self::assertEquals([1 => new EntryGuid('1#20')], $plan->renamed());
        self::assertSame([], $plan->merged());
    }

    public function testARenamedCopyAlreadyHeldMergesIntoIt(): void
    {
        $plan = new SavedCopyPlan(
            [self::original(1, '1', 'url-other', 20)],
            [
                hash('sha256', '1') => ['id' => 70, 'urlHash' => 'url-first'],
                hash('sha256', '1#20') => ['id' => 71, 'urlHash' => 'url-other'],
            ],
        );

        self::assertSame([1 => 71], $plan->merged());
        self::assertSame([], $plan->renamed());
    }

    public function testItAsksForTheGuidAndTheRenamedGuidOfEachOriginal(): void
    {
        self::assertSame(
            [hash('sha256', 'a'), hash('sha256', 'a#10')],
            SavedCopyPlan::heldHashesToAsk([self::original(1, 'a', null, 10)]),
        );
    }

    /** @return array{id: int, guid: string, guidHash: string, urlHash: ?string, feedId: int} */
    private static function original(int $id, string $guid, ?string $urlHash, int $feedId): array
    {
        return ['id' => $id, 'guid' => $guid, 'guidHash' => hash('sha256', $guid), 'urlHash' => $urlHash, 'feedId' => $feedId];
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/SavedPosts/SavedPostRescueTest.php tests/Service/SavedPosts/Pass/SavedCopyPlanTest.php tests/Service/Social/SocialAccountEditorTest.php`
Expected: FAIL — favourites are deleted with the feed; no `saved:` feed exists; `SavedCopyPlan` not found. The guard test `testNothingFlaggedCreatesNoSavedFeed` passes.

- [ ] **Step 3: Implement**

`backend/src/Service/SavedPosts/Support/SavedOrigin.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\SavedPosts\Support;

use App\Entity\EntryAuthor;
use App\Entity\EntryOrigin;
use App\Entity\Subscription;
use App\Enum\SourceFormat;
use App\Repository\SubscriptionDisplayTitle;
use App\Service\Url\Support\AbsoluteHttpUrl;
use App\Service\Url\Support\FeedWebsite;

/** What a saved copy keeps of the feed it is leaving: the profile it was posted on, or the RSS feed's name and icon. */
final class SavedOrigin
{
    public static function posterOf(Subscription $leaving): ?EntryAuthor
    {
        $feed = $leaving->getFeed();
        if (null === $feed->getNetwork() || SourceFormat::isSocial($feed->getSourceFormat())) {
            return null;
        }

        return new EntryAuthor(
            self::titleOf($leaving),
            AbsoluteHttpUrl::orNull(FeedWebsite::of($feed->getUrl(), $feed->getSiteUrl())),
            $feed->getImageUrl(),
        );
    }

    public static function originOf(Subscription $leaving): ?EntryOrigin
    {
        $feed = $leaving->getFeed();

        return null === $feed->getNetwork() ? new EntryOrigin(self::titleOf($leaving), $feed->getFaviconUrl()) : null;
    }

    private static function titleOf(Subscription $leaving): string
    {
        $feed = $leaving->getFeed();

        return SubscriptionDisplayTitle::from($leaving->getCustomTitle(), $feed->getTitle(), $feed->getUrl());
    }

    private function __construct()
    {
    }
}
```
(`EntryAuthor` columns are 255 for the name (`backend/src/Entity/EntryAuthor.php:15`) and `SavedEntryOrigin::TITLE_MAX` is 512: truncate the name with `mb_substr($title, 0, 255)` in `posterOf()` — MySQL strict mode fails the insert otherwise, as `Feed::recordCacheValidators` warns, `Feed.php:260`.)

`backend/src/Service/SavedPosts/SavedStateMover.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\SavedPosts;

use App\Entity\EntryState;
use App\Repository\EntryStateRepository;
use App\Repository\SocialLikeRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Moves a user's marks from one entry to another of the same post: favourite and kept merge, the source's go. */
final readonly class SavedStateMover
{
    public function __construct(
        private EntryStateRepository $entryStates,
        private SocialLikeRepository $socialLikes,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<int, int> $targetByEntryId */
    public function move(int $userId, array $targetByEntryId): void
    {
        if ([] === $targetByEntryId) {
            return;
        }
        $sources = $this->entryStates->forUserByEntryIds($userId, array_keys($targetByEntryId));
        foreach ($targetByEntryId as $entryId => $targetId) {
            $this->entryStates->ensureRow($userId, $targetId, self::hiddenSinceOf($sources[$entryId] ?? null));
        }
        $targets = $this->entryStates->forUserByEntryIds($userId, array_values($targetByEntryId));
        foreach ($targetByEntryId as $entryId => $targetId) {
            self::merge($sources[$entryId] ?? null, $targets[$targetId] ?? null);
            $this->socialLikes->copyLike($userId, $entryId, $targetId);
        }
        $this->entityManager->flush();
    }

    private static function hiddenSinceOf(?EntryState $source): ?\DateTimeImmutable
    {
        return true === $source?->isHidden() ? $source->getHiddenAt() : null;
    }

    private static function merge(?EntryState $source, ?EntryState $target): void
    {
        if (null === $source || null === $target) {
            return;
        }
        if ($source->isFavorite()) {
            $target->markFavorite();
        }
        if ($source->isKept()) {
            $target->markKept();
        }
        $viewedAt = $source->getViewedAt();
        if (null !== $viewedAt) {
            $target->markViewed($viewedAt);
        }
        $source->clearFavorite();
        $source->clearKept();
    }
}
```

`backend/src/Service/SavedPosts/Pass/SavedCopyPlan.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\SavedPosts\Pass;

use App\Entity\EntryGuid;

/**
 * Which of a batch's posts the saved feed already holds. A guid is unique per feed only, so the same guid under
 * another URL is another article: it is stored as `<guid>#<source feed id>`.
 */
final class SavedCopyPlan
{
    /** @var array<int, int> */
    private array $merged = [];

    /** @var list<int> */
    private array $plain = [];

    /** @var array<int, EntryGuid> */
    private array $renamed = [];

    /**
     * @param list<array{id: int, guid: string, guidHash: string, urlHash: ?string, feedId: int}> $originals
     * @param array<string, array{id: int, urlHash: ?string}>                                   $held
     */
    public function __construct(array $originals, array $held)
    {
        foreach ($originals as $original) {
            $this->place($original, $held);
        }
    }

    /**
     * @param list<array{guid: string, guidHash: string, feedId: int}> $originals
     *
     * @return list<string>
     */
    public static function heldHashesToAsk(array $originals): array
    {
        $hashes = [];
        foreach ($originals as $original) {
            $hashes[] = $original['guidHash'];
            $hashes[] = self::renamedGuidOf($original)->getHash();
        }

        return $hashes;
    }

    /** @return array<int, int> */
    public function merged(): array
    {
        return $this->merged;
    }

    /** @return list<int> */
    public function plain(): array
    {
        return $this->plain;
    }

    /** @return array<int, EntryGuid> */
    public function renamed(): array
    {
        return $this->renamed;
    }

    /**
     * @param array{id: int, guid: string, guidHash: string, urlHash: ?string, feedId: int} $original
     * @param array<string, array{id: int, urlHash: ?string}>                              $held
     */
    private function place(array $original, array $held): void
    {
        $sameGuid = $held[$original['guidHash']] ?? null;
        if (null === $sameGuid) {
            $this->plain[] = $original['id'];

            return;
        }
        if ($sameGuid['urlHash'] === $original['urlHash']) {
            $this->merged[$original['id']] = $sameGuid['id'];

            return;
        }
        $renamedGuid = self::renamedGuidOf($original);
        $renamedHeld = $held[$renamedGuid->getHash()] ?? null;
        if (null !== $renamedHeld) {
            $this->merged[$original['id']] = $renamedHeld['id'];

            return;
        }
        $this->renamed[$original['id']] = $renamedGuid;
    }

    /** @param array{guid: string, feedId: int} $original */
    private static function renamedGuidOf(array $original): EntryGuid
    {
        return new EntryGuid($original['guid'] . '#' . $original['feedId']);
    }
}
```
(`EntryGuid` is an `#[ORM\Embeddable]` value with a public constructor that hashes, `backend/src/Entity/EntryGuid.php:19-23`; building one outside an entity is safe.)

`backend/src/Service/SavedPosts/SavedPostRescue.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\SavedPosts;

use App\Entity\EntryGuid;
use App\Entity\Subscription;
use App\Repository\EntryRepository;
use App\Repository\SavedPostRepository;
use App\Service\SavedPosts\Model\SavedCopyModel;
use App\Service\SavedPosts\Pass\SavedCopyPlan;
use App\Service\SavedPosts\Support\SavedOrigin;
use App\Service\Search\EntryIndexer;

/** Runs before a subscription goes (#1666): a favourite or kept post moves to a feed the user keeps, else to a copy. */
final readonly class SavedPostRescue
{
    public function __construct(
        private SavedPostRepository $savedPosts,
        private SavedFeeds $savedFeeds,
        private SavedStateMover $stateMover,
        private EntryRepository $entries,
        private EntryIndexer $indexer,
    ) {
    }

    /**
     * @param list<Subscription> $leaving
     *
     * @return list<int>
     */
    public function rescueFrom(array $leaving): array
    {
        $leavingFeedIds = array_map(static fn (Subscription $subscription): int => $subscription->getFeed()->requireId(), $leaving);
        $copyIds = [];
        foreach ($leaving as $subscription) {
            array_push($copyIds, ...$this->rescue($subscription, $leavingFeedIds));
        }

        return $copyIds;
    }

    /** @param list<int> $copyIds */
    public function indexCopies(array $copyIds): void
    {
        $this->indexer->index($this->entries->findAllByIds($copyIds));
    }

    /**
     * @param list<int> $leavingFeedIds
     *
     * @return list<int> the copies created
     */
    private function rescue(Subscription $subscription, array $leavingFeedIds): array
    {
        $userId = $subscription->getUser()->requireId();
        $flagged = $this->savedPosts->flaggedIn($userId, $subscription->getFeed()->requireId());
        if ([] === $flagged) {
            return [];
        }
        $twins = $this->savedPosts->keptTwinsOf($userId, $flagged, $leavingFeedIds);
        $toCopy = array_keys(array_diff_key($flagged, $twins));
        if ([] === $toCopy) {
            $this->stateMover->move($userId, $twins);

            return [];
        }
        [$held, $created] = $this->copied($subscription, $toCopy);
        $this->stateMover->move($userId, $twins + $held + $created);

        return array_values($created);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array{array<int, int>, array<int, int>} the copies the saved feed held, and the ones made now
     */
    private function copied(Subscription $subscription, array $entryIds): array
    {
        $savedFeedId = $this->savedFeeds->of($subscription->getUser(), $subscription->getFeed()->getNetwork())
            ->getFeed()->requireId();
        $originals = $this->savedPosts->originalsOf($entryIds);
        $plan = new SavedCopyPlan(
            $originals,
            $this->savedPosts->heldIn($savedFeedId, SavedCopyPlan::heldHashesToAsk($originals)),
        );
        $copy = new SavedCopyModel($savedFeedId, SavedOrigin::posterOf($subscription));
        $created = $this->savedPosts->copyInto($copy, $plan->plain()) + $this->renamedCopies($copy, $plan->renamed());
        $this->savedPosts->copyCompanions($created, SavedOrigin::originOf($subscription));

        return [$plan->merged(), $created];
    }

    /**
     * @param array<int, EntryGuid> $guidsByEntryId
     *
     * @return array<int, int>
     */
    private function renamedCopies(SavedCopyModel $copy, array $guidsByEntryId): array
    {
        $created = [];
        foreach ($guidsByEntryId as $entryId => $guid) {
            $created[$entryId] = $this->savedPosts->copyRenamed($copy, $entryId, $guid);
        }

        return $created;
    }
}
```
(Two subscriptions of one call can bring the same post: the first creates the copy, the second's plan finds it held and merges, which is what `testThePostSavedTwiceIsMergedIntoOneCopyWithBothFlags` pins.)

`backend/src/Service/Subscription/SubscriptionService.php`: add constructor parameter `private SavedPostRescue $savedPostRescue,` (after `$orphanedFeeds`), and replace `unsubscribe()`/`unsubscribeAll()` (lines 37-74) with:
```php
    public function unsubscribe(Subscription $subscription): void
    {
        $this->unsubscribeAll([$subscription]);
    }

    /**
     * Saved feeds of the call go first, so the rescue of the others never copies into a saved feed this call deletes.
     * Each half flushes its removal before reclaiming, so the DELETE's no-subscriber guard sees the rows gone.
     *
     * @param list<Subscription> $subscriptions
     */
    public function unsubscribeAll(array $subscriptions): int
    {
        if ([] === $subscriptions) {
            return 0;
        }

        $savedFeeds = array_values(array_filter($subscriptions, self::isSavedFeed(...)));
        $others = array_values(array_filter(
            $subscriptions,
            static fn (Subscription $subscription): bool => !self::isSavedFeed($subscription),
        ));
        $this->removeAfter($savedFeeds, static fn (): array => []);
        $copyIds = $this->removeAfter($others, $this->savedPostRescue->rescueFrom(...));
        $this->savedPostRescue->indexCopies($copyIds);

        return \count($subscriptions);
    }

    /**
     * @param list<Subscription>                       $subscriptions
     * @param \Closure(list<Subscription>): list<int> $beforeRemoval
     *
     * @return list<int> what $beforeRemoval returned
     */
    private function removeAfter(array $subscriptions, \Closure $beforeRemoval): array
    {
        if ([] === $subscriptions) {
            return [];
        }
        $this->entityManager->beginTransaction();
        try {
            $result = $beforeRemoval($subscriptions);
            foreach ($subscriptions as $subscription) {
                $this->entityManager->remove($subscription);
            }
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }
        $this->reclaimFeedsOf($subscriptions);

        return $result;
    }

    /** @param list<Subscription> $subscriptions */
    private function reclaimFeedsOf(array $subscriptions): void
    {
        $feedIds = [];
        foreach ($subscriptions as $subscription) {
            $feedIds[$subscription->getFeed()->requireId()] = true;
        }
        foreach (array_keys($feedIds) as $feedId) {
            $this->orphanedFeeds->reclaim($feedId);
        }
    }

    private static function isSavedFeed(Subscription $subscription): bool
    {
        return SourceFormat::SAVED === $subscription->getFeed()->getSourceFormat();
    }
```
Read each feed id **before** the removal flush (as today, `SubscriptionService.php:62-66`): `reclaimFeedsOf()` reads `getFeed()->requireId()` on removed-but-still-held entities, which Doctrine keeps hydrated, so it is safe; if PHPStan or a test shows otherwise, collect the ids at the top of `removeAfter()` and pass them on. `beginTransaction()` instead of `wrapInTransaction()` keeps the entity manager open after a failure (Decisions, "Transactions").
Constructor order now: `$discovery, $creator, $scrapeFallbackPolicy, $firstFetch, $orphanedFeeds, $savedPostRescue, $entityManager`. In `SubscriptionServiceTest.php:110` and `UnsubscribeAllTest.php:172-179` insert `self::getContainer()->get(SavedPostRescue::class)` (assert its instance) before the entity manager argument. `UnsubscribeAllTest::testAnEmptyListNeverTouchesTheEntityManager` keeps passing: the empty guard is first. `testTwoSubscriptionsToOneFeedReclaimItOnce` keeps one `delete from feed`: the rescue deletes nothing.

- [ ] **Step 4: Run to verify they pass**

Run: `php bin/phpunit tests/Service/SavedPosts tests/Service/Subscription tests/Service/Social tests/Controller/Api/SubscriptionBulkTest.php tests/Controller/Api/SubscriptionControllerTest.php`
Expected: PASS. Then the MySQL leg for the new tests: `docker compose exec php php bin/phpunit tests/Service/SavedPosts`.

- [ ] **Step 5: Gates and commit**

Also `composer tramp` (part of `composer check`): `$leavingFeedIds` runs `rescueFrom → rescue → keptTwinsOf`, read at the end, 3 methods over 2 classes — a warning at most, not a failure; if it warns, keep it (a one-call threading, not a tunnel) and say so in the report.
```bash
git add backend/src/Service/SavedPosts backend/src/Service/Subscription/SubscriptionService.php backend/tests/Service/SavedPosts backend/tests/Service/Subscription backend/tests/Service/Social/SocialAccountEditorTest.php
git commit -m "feat(#1666): every unsubscribe moves favourites and kept posts into a saved feed"
```

---

### Task 6: Housekeeping lets unflagged saved posts go

**Files:**
- Modify: `backend/src/Repository/RetentionRepository.php` (add `unsavedIdsInSavedFeeds`), `backend/src/Service/Retention/EntryPruner.php:38-44`
- Test: append to `backend/tests/Service/Retention/EntryPrunerTest.php`

- [ ] **Step 1: Write the failing test** (append; uses that class's `persistEntry()`, lines 119-130, and its `$this->pruner`)
```php
    public function testASavedFeedKeepsOnlyWhatSomeoneStillFlagsHoweverNew(): void
    {
        $user = new User('prune-saved@example.com', $this->clock->now());
        $this->entityManager->persist($user);
        $saved = new Feed('saved:9001');
        $saved->setSourceFormat(SourceFormat::SAVED);
        $this->entityManager->persist($saved);
        $this->entityManager->flush();
        $kept = $this->persistEntry($saved, 'kept', $this->clock->now());
        $unfavourited = $this->persistEntry($saved, 'unfavourited', $this->clock->now());
        $state = new EntryState($user, $kept);
        $state->markKept();
        $this->entityManager->persist($state);
        $this->entityManager->persist(new EntryState($user, $unfavourited));
        $this->entityManager->flush();

        $this->pruner->prune();
        $this->entityManager->clear();

        self::assertNotNull($this->findByGuid($saved, 'kept'));
        self::assertNull($this->findByGuid($saved, 'unfavourited'));
    }
```
(Add `use App\Enum\SourceFormat;`. The entries are fresh, inside every window and below the 20-entry floor, so only the new pass can delete `unfavourited`.)

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Service/Retention/EntryPrunerTest.php --filter testASavedFeed`
Expected: FAIL — `unfavourited` survives.

- [ ] **Step 3: Implement**

`RetentionRepository`, add `use App\Enum\SourceFormat;` to its imports (it has none today, `backend/src/Repository/RetentionRepository.php:7-14`) and the method (uses its private `notProtectedDql()` and `idsOf()`, lines 150-172):
```php
    /** @return list<int> the entries of saved feeds that nobody favourites or keeps any more (#1666) */
    public function unsavedIdsInSavedFeeds(): array
    {
        return self::idsOf(
            $this->entityManager->createQueryBuilder()
                ->select('e.id')
                ->from(Entry::class, 'e')
                ->join('e.feed', 'f')
                ->where('f.sourceFormat = :saved')
                ->andWhere($this->notProtectedDql())
                ->setParameter('saved', SourceFormat::SAVED)
                ->setParameter('true', true, Types::BOOLEAN),
        );
    }
```
`EntryPruner::prune()`:
```php
        $deletedEntries = $this->pruneByAge() + $this->pruneByFeedCap() + $this->pruneUnsaved();
```
and
```php
    private function pruneUnsaved(): int
    {
        return $this->deleteByIds($this->retention->unsavedIdsInSavedFeeds());
    }
```
Update the class docblock to "Four passes: …, a saved feed's entries nobody flags any more, then completed runs left empty."

- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Retention tests/Service/Refresh/RefreshHousekeepingTest.php` — Expected: PASS.

- [ ] **Step 5: Commit**
```bash
git add backend/src/Repository/RetentionRepository.php backend/src/Service/Retention/EntryPruner.php backend/tests/Service/Retention/EntryPrunerTest.php
git commit -m "feat(#1666): housekeeping removes a saved post nobody keeps or favourites any more"
```

---

### Task 7: The API shows where a saved post came from

**Files:**
- Modify: `backend/src/Http/SubscriptionJson.php:109-127`, `backend/src/Http/EntryJson.php:58-170`, `backend/src/Repository/EntryListRowEnrichment.php`, `backend/src/Repository/EntryListRowEnricher.php:18-68`
- Test: append to `backend/tests/Http/EntryJsonTest.php`; create `backend/tests/Http/SubscriptionJsonSavedTest.php`; append to `backend/tests/Repository/EntryListRowEnricherTest.php`

**Interfaces — Produces:** `EntryListRowEnrichment::$origin` (`?EntryOrigin`, last constructor parameter, default null); `SubscriptionJson.social.kind` may be `'saved'`.

- [ ] **Step 1: Write the failing tests**

Append to `EntryJsonTest` (helpers `entryOfFeedWithFormat()` and `row()` exist, lines 632-662):
```php
    public function testASavedSocialPostNamesItsPosterAndTheSavedFeedStaysTheSource(): void
    {
        $entry = $this->entryOfFeedWithFormat(
            SourceFormat::SAVED,
            new EntryAuthor('Bob', 'https://bsky.app/profile/did:plc:bob', 'https://cdn.example/bob.jpg'),
        );
        $entry->getFeed()->classifyAs(SocialNetwork::Bluesky);

        $json = EntryJson::listRow($this->row($entry));

        self::assertSame('Source', $json['source']);
        self::assertSame([
            'name' => 'Bob',
            'url' => 'https://bsky.app/profile/did:plc:bob',
            'imageUrl' => 'https://cdn.example/bob.jpg',
        ], $json['poster']);
    }

    public function testASavedArticleNamesItsOriginFeedAndShowsItsIconNotItsAuthor(): void
    {
        $entry = $this->entryOfFeedWithFormat(SourceFormat::SAVED, new EntryAuthor('A. Writer'));
        $entry->getFeed()->setFaviconUrl('https://saved.example/none.ico');

        $json = EntryJson::listRow($this->row(
            $entry,
            new EntryListRowEnrichment(origin: new EntryOrigin('Heise', 'https://heise.example/favicon.ico')),
        ));

        self::assertSame(['name' => 'Heise', 'url' => null, 'imageUrl' => null], $json['poster']);
        self::assertSame('https://heise.example/favicon.ico', $json['faviconUrl']);
    }

    /** Guard test: passes before this task too (a network-less saved feed has no poster); it pins the fallback. */
    public function testASavedArticleWithoutAnOriginHasNoPosterAndTheFeedsIcon(): void
    {
        $entry = $this->entryOfFeedWithFormat(SourceFormat::SAVED, new EntryAuthor('A. Writer'));
        $entry->getFeed()->setFaviconUrl('https://saved.example/feed.ico');

        $json = EntryJson::listRow($this->row($entry));

        self::assertNull($json['poster']);
        self::assertSame('https://saved.example/feed.ico', $json['faviconUrl']);
    }
```
`backend/tests/Http/SubscriptionJsonSavedTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Http\SubscriptionJson;
use PHPUnit\Framework\TestCase;

final class SubscriptionJsonSavedTest extends TestCase
{
    public function testASavedFeedOfANetworkIsMarkedSavedWithoutAnAccount(): void
    {
        $json = SubscriptionJson::one($this->subscriptionTo('saved:4:mastodon', SocialNetwork::Mastodon));

        self::assertSame('saved', $json['sourceFormat']);
        self::assertSame(['network' => 'mastodon', 'accountId' => null, 'kind' => 'saved'], $json['social']);
    }

    public function testSavedFromFeedsIsNoSocialFeed(): void
    {
        self::assertNull(SubscriptionJson::one($this->subscriptionTo('saved:4', null))['social']);
    }

    private function subscriptionTo(string $url, ?SocialNetwork $network): Subscription
    {
        $feed = new Feed($url);
        $feed->setSourceFormat(SourceFormat::SAVED);
        $feed->classifyAs($network);

        return new Subscription(new User('json@example.test', new \DateTimeImmutable('2026-10-17')), $feed, new \DateTimeImmutable('2026-10-17'));
    }
}
```
Append to `EntryListRowEnricherTest` (read it first; follow how it seeds rows and calls `enrich()`) a case: an entry with a `SavedEntryOrigin` row gets `enrichment->origin` equal to that `EntryOrigin`; one without gets `null`. Construct the enricher from the container as that test does.

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Http/EntryJsonTest.php tests/Http/SubscriptionJsonSavedTest.php tests/Repository/EntryListRowEnricherTest.php`
Expected: FAIL — unknown named argument `origin`; `kind` is `profile`. The guard test `testASavedArticleWithoutAnOriginHasNoPosterAndTheFeedsIcon` passes.

- [ ] **Step 3: Implement**

`EntryListRowEnrichment`: add the last parameter
```php
        /** where a saved article came from; null for every other entry */
        public ?EntryOrigin $origin = null,
```
`EntryListRowEnricher`: add constructor parameter `private SavedEntryOriginRepository $savedOrigins,`; in `enrichmentsByEntryId()` read `$origins = $this->savedOrigins->originsByEntryIds($entryIds);` and pass `$origins[$entryId] ?? null` as the last argument.

`SubscriptionJson::social()` (lines 114-127):
```php
    private static function social(Feed $feed): ?array
    {
        $network = $feed->getNetwork();
        if (null === $network) {
            return null;
        }
        if (SourceFormat::SAVED === $feed->getSourceFormat()) {
            return ['network' => $network->value, 'accountId' => null, 'kind' => self::SAVED_KIND];
        }
        $address = SocialFeedClassifier::privateAddressOf($feed);

        return [
            'network' => $network->value,
            'accountId' => $address?->accountId,
            'kind' => null === $address ? self::PROFILE_KIND : $address->kind->value,
        ];
    }
```
with `private const string SAVED_KIND = 'saved';` and `use App\Enum\SourceFormat;`; update the docblock to "… a saved feed is `saved`; any other networked feed is a profile feed."

`EntryJson`: line 81 `'faviconUrl' => $row->enrichment->origin?->faviconUrl ?? $entry->getFeed()->getFaviconUrl(),` (keep the comment above it). Replace `posterOf()` (lines 150-170):
```php
    /**
     * Who posted the entry when that is someone other than the feed. A private or saved social post names its own
     * author; every post of a profile feed is that person's, so it shows their picture and profile unless the post
     * brings an avatar of its own (a repost); a saved article names the feed it was saved from.
     *
     * @return array{name: string, url: string|null, imageUrl: string|null}|null
     */
    private static function posterOf(EntryListRow $row): ?array
    {
        $entry = $row->entry;
        $feed = $entry->getFeed();
        $sourceFormat = $feed->getSourceFormat();
        if (SourceFormat::SAVED === $sourceFormat) {
            return null === $feed->getNetwork() ? self::originPosterOf($row) : self::authorPosterOf($entry);
        }
        if (SourceFormat::isSocial($sourceFormat)) {
            return self::authorPosterOf($entry);
        }
        if (null !== $feed->getNetwork()) {
            return [
                'name' => $row->subscriptionTitle,
                'url' => AbsoluteHttpUrl::orNull(FeedWebsite::of($feed->getUrl(), $feed->getSiteUrl())),
                'imageUrl' => $entry->getAuthorAvatarUrl() ?? $feed->getImageUrl(),
            ];
        }

        return null;
    }

    /** @return array{name: string, url: string|null, imageUrl: string|null}|null */
    private static function authorPosterOf(Entry $entry): ?array
    {
        $author = $entry->getAuthor();
        if (null === $author || '' === $author) {
            return null;
        }

        return ['name' => $author, 'url' => $entry->getAuthorUrl(), 'imageUrl' => $entry->getAuthorAvatarUrl()];
    }

    /** @return array{name: string, url: null, imageUrl: null}|null */
    private static function originPosterOf(EntryListRow $row): ?array
    {
        $title = $row->enrichment->origin?->title;

        return null === $title ? null : ['name' => $title, 'url' => null, 'imageUrl' => null];
    }
```
(`use App\Entity\Entry;`). The existing private-feed and profile tests keep passing unchanged.

- [ ] **Step 4: Run** `php bin/phpunit tests/Http tests/Repository/EntryListRowEnricherTest.php tests/Controller/Api` — Expected: PASS.

- [ ] **Step 5: Commit**
```bash
git add backend/src/Http/SubscriptionJson.php backend/src/Http/EntryJson.php backend/src/Repository/EntryListRowEnrichment.php backend/src/Repository/EntryListRowEnricher.php backend/tests/Http backend/tests/Repository/EntryListRowEnricherTest.php
git commit -m "feat(#1666): a saved post names its poster or its origin feed"
```

---

### Task 8: Backups carry saved feeds and restore them as the restoring user's own

**Files:**
- Modify: `backend/src/Service/Backup/BackupLines.php:131-165` (`entryLine`), `backend/src/Service/Backup/Pass/BackupPartWalk.php:31-102`, `backend/src/Service/Backup/Factory/BackupPartWalkFactory.php`, `backend/src/Service/Backup/Dto/EntryLine.php`, `backend/src/Repository/EntryBatchInserter.php`, `backend/src/Service/Backup/Pass/RestoreEntryLoader.php:156-159`, `backend/src/Service/Backup/BackupReader.php:49,106,181-193`, `backend/src/Service/Backup/RestoreLoader.php:39`, `backend/src/Service/Backup/EntryPartInspector.php:51`, `backend/src/Service/Backup/EntryPartRestorer.php:29`, `backend/src/Service/Backup/Pass/BackupTally.php:106-133`
- Create: `backend/src/Service/Backup/Model/EntryCompanionsModel.php`, `backend/src/Service/Backup/Support/SavedFeedRefusal.php`
- Modify tests: `backend/tests/Support/BackupFieldDeclarations.php`, `backend/tests/Service/Backup/Support/BackupSchemaCoverageTest.php` (move `SavedEntryOrigin` out of the wholly-dropped list), `backend/tests/Support/FullyPopulatedAccount.php`
- Docs: `docs/backup.md` §5 (`entry` row), §6.2 (`SocialFeed` row wording; drop the temporary `SavedEntryOrigin` row)
- Test: create `backend/tests/Service/Backup/SavedFeedBackupTest.php`, `backend/tests/Service/Backup/Dto/FeedLineTest.php` (append)

**Interfaces — Produces:**
```php
final readonly class EntryCompanionsModel {   // Backup/Model
    /** @param list<LinkCardContent> $linkCards */
    public function __construct(public array $linkCards = [], public ?EntryOrigin $origin = null) {}
}
// BackupLines::entryLine(Entry $entry, string $feedUrl, EntryCompanionsModel $companions): string — adds 'origin' => null|{title, faviconUrl}
// EntryLine gains public ?EntryOrigin $origin = null (last constructor parameter)
// EntryBatchInserter::insertOrigins(array<string,int> $entryIdsByGuidHash, list<EntryLine> $lines): void
// BackupReader::read(string $gzipBytes, ?int $restoringUserId = null): \Generator — rebinds saved-feed URLs when given
```

- [ ] **Step 1: Write the failing test**

`backend/tests/Service/Backup/SavedFeedBackupTest.php` — export one account holding a saved RSS post with origin, restore into a second account, assert the second account owns `saved:<secondId>` with that post, its favourite and its origin. Read `backend/tests/Service/Backup/AccountRestorerTest.php` first and build the round trip with the same helpers it uses (exporter parts → gzip bytes → the restorer the controller uses). The assertions:
```php
        $restoredFeed = $this->entityManager->getRepository(Feed::class)->findOneBy(['url' => sprintf('saved:%d', $restorer->requireId())]);
        self::assertNotNull($restoredFeed);
        self::assertSame(SourceFormat::SAVED, $restoredFeed->getSourceFormat());
        self::assertNull($this->entityManager->getRepository(Feed::class)->findOneBy(['url' => sprintf('saved:%d', $exporter->requireId() + 100000)]));
        $copy = $this->entityManager->getRepository(Entry::class)->findOneBy(['feed' => $restoredFeed]);
        self::assertNotNull($copy);
        /** @var SavedEntryOriginRepository $origins */
        $origins = $this->entityManager->getRepository(SavedEntryOrigin::class);
        self::assertEquals([$copy->requireId() => new EntryOrigin('Heise', 'https://heise.example/favicon.ico')], $origins->originsByEntryIds([$copy->requireId()]));
        // and the state: favourite
```
Add a second test proving a file whose feed line names `saved:<anotherUsersId>` while that user's saved feed exists is restored into the *restoring* user's saved feed and leaves the other user's feed without new entries (the reason for the rebind). Add a third, `BackupTallyTest`-style if that test exists (else inline): a file with N ordinary subscriptions and one saved one reports `subscriptions: N` to the fit check. Add a fourth and fifth: a feed line `{"url": "https://shared.example/feed.xml", "sourceFormat": "saved", …}` and a feed line `{"url": "saved:4", "sourceFormat": "xml", …}` each make `FeedLine::fromLine()` throw `InvalidBackupException` (a crafted file must not turn someone's real feed into a saved one, nor a saved URL into a fetched feed). Test them directly on `FeedLine::fromLine()` in `backend/tests/Service/Backup/Dto/FeedLineTest.php` (append; it exists), with every other key the DTO reads present.

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Service/Backup/SavedFeedBackupTest.php`
Expected: FAIL — the restored feed keeps the exporter's id in its URL and no origin is written.

- [ ] **Step 3: Implement**

Export:
- `EntryCompanionsModel` as above.
- `BackupLines::entryLine(Entry $entry, string $feedUrl, EntryCompanionsModel $companions)`: `'linkCards' => array_map(self::linkCardFields(...), $companions->linkCards),` and, after `linkCards`, `'origin' => null === $companions->origin ? null : ['title' => $companions->origin->title, 'faviconUrl' => $companions->origin->faviconUrl],`.
- `BackupPartWalk`: constructor gains `private readonly SavedEntryOriginRepository $savedOrigins,` (after `$linkCards`); `bufferBatch()` reads `$originsByEntryId = $this->savedOrigins->originsByEntryIds($entryIds);` and passes `new EntryCompanionsModel($cardsByEntryId[$id] ?? [], $originsByEntryId[$id] ?? null)`. `BackupPartWalkFactory` injects and passes it (8 constructor arguments on the walk — under PHPMD's 10).

Restore:
- `EntryLine`: add `public ?EntryOrigin $origin = null,` and in `fromLine()` `origin: self::originOf($line),` with
```php
    /** @param array<string, mixed> $line */
    private static function originOf(array $line): ?EntryOrigin
    {
        $fields = LineField::objectOrNull($line, 'origin');
        if (null === $fields) {
            return null;
        }

        return new EntryOrigin(
            self::clipped(LineField::stringOrNull($fields, 'title'), SavedEntryOrigin::TITLE_MAX),
            AbsoluteHttpUrl::orNullWithin(LineField::stringOrNull($fields, 'faviconUrl'), EntryAuthor::URL_MAX_LENGTH),
        );
    }
```
(`AbsoluteHttpUrl::orNullWithin` is used by `ProfileImageSweep:116`; write `clipped()` as `null === $value ? null : mb_substr($value, 0, $max)`. The file is user-supplied, so the icon passes the same gate as an author URL — read `authorUrlOf()` in that file and reuse it if it does exactly this.)
- `EntryBatchInserter::insertOrigins()`, modelled on `insertLinkCards()` (`backend/src/Repository/EntryBatchInserter.php:57-77`), one `INSERT INTO saved_entry_origin (entry_id, title, favicon_url) VALUES …` per 100 rows, skipping lines whose `origin` is null.
- `RestoreEntryLoader::insertBufferedEntries()` (lines 156-159):
```php
            $createdIds = $this->recordCreatedIds($target, $fresh);
            $this->inserter->insertLinkCards($createdIds, $fresh);
            $this->inserter->insertOrigins($createdIds, $fresh);
```
- Rebinding: `BackupReader::read(string $gzipBytes, ?int $restoringUserId = null)`; in the loop, `yield $this->toDto($kind, self::rebound($decoded, $restoringUserId));` with
```php
    /**
     * @param array<string, mixed> $decoded
     *
     * @return array<string, mixed>
     */
    private static function rebound(array $decoded, ?int $restoringUserId): array
    {
        if (null === $restoringUserId) {
            return $decoded;
        }
        foreach (['url', 'feedUrl'] as $key) {
            if (\is_string($decoded[$key] ?? null)) {
                $decoded[$key] = SavedFeedUrl::rebound($decoded[$key], $restoringUserId);
            }
        }

        return $decoded;
    }
```
(Only feed lines carry `url` as the feed's address and every other kind carries `feedUrl`; an entry line's own `url` is the article address and never starts with `saved:`. State that in a one-line comment — a reader would otherwise think article URLs get rewritten.) Pass `$user->requireId()` from `RestoreLoader::load()` (line 39), `EntryPartInspector::scan()` (needs the user: `inspect()` already has it — pass it down) and `EntryPartRestorer::load()` (line 29). `BackupInspector` (preview) stays unrebound.
- `backend/src/Service/Backup/Support/SavedFeedRefusal.php` (create), the sibling of `PrivateFeedRefusal` (`backend/src/Service/Backup/Support/PrivateFeedRefusal.php:15-48`):
```php
/** A saved feed is a saved: URL with the saved format, both or neither: a file must not make a shared feed a saved one. */
final class SavedFeedRefusal
{
    private function __construct()
    {
    }

    /** @throws InvalidBackupException */
    public static function ofFeed(string $feedUrl, string $sourceFormat): void
    {
        if (SavedFeedUrl::isSaved($feedUrl) === (SourceFormat::SAVED === $sourceFormat)) {
            return;
        }

        throw new InvalidBackupException(
            sprintf('Feed "%s" mixes a saved-feed address and format; a backup never does.', $feedUrl),
        );
    }
}
```
  and in `FeedLine::fromLine()` (`backend/src/Service/Backup/Dto/FeedLine.php:43`) call `SavedFeedRefusal::ofFeed($feedLine->url, $feedLine->sourceFormat);` after `PrivateFeedRefusal::ofFeed(...)`; add `@throws InvalidBackupException for a mixed saved feed` to its docblock.
- `BackupTally::acceptSubscription()`: count a line toward `subscriptions` only when `!SavedFeedUrl::isSaved($line->feedUrl)`; keep recording it in `subscribedFeedUrls` regardless (the entry-part check needs it).

Coverage and docs:
- `backend/tests/Support/BackupFieldDeclarations.php`: add `use App\Entity\SavedEntryOrigin;`, to `BACKED_UP`
```php
        SavedEntryOrigin::class => [
            // An RSS copy's origin rides in its entry's line as one object (#1666).
            'entry' => 'origin', 'title' => 'origin.title', 'faviconUrl' => 'origin.faviconUrl',
        ],
```
  and to `KIND_OF` `SavedEntryOrigin::class => BackupSchema::KIND_ENTRY,`. In `BackupSchemaCoverageTest` remove the `SavedEntryOrigin` entry Task 2 put in `ACCOUNT_SCOPED_WHOLLY_DROPPED` (and its now unused `use` line if the class no longer names it).
- `FullyPopulatedAccount::create()`: after the `LinkCard`, `$this->entityManager->persist(new SavedEntryOrigin($entry, new EntryOrigin('Populated origin', 'https://populated.example/favicon.ico')));` so the export carries the key. Run `AccountRestorerTest` and update any exact-equality expectation it has of the entry line to include `origin`.
- `docs/backup.md`: in §5's `entry` row add "its origin when it is an article saved from a feed you left (`origin`: `title`, `faviconUrl`)"; in the `feed` row add "Your Saved from … feeds are included and come back as your own."; drop the temporary §6.2 `SavedEntryOrigin` row.

- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Backup tests/Repository/EntryBatchInserterTest.php tests/Controller/Api` and `docker compose exec php php bin/phpunit tests/Service/Backup` — Expected: PASS.

- [ ] **Step 5: Commit**
```bash
git add backend/src/Service/Backup backend/src/Repository/EntryBatchInserter.php backend/tests/Service/Backup backend/tests/Support/BackupFieldDeclarations.php backend/tests/Support/FullyPopulatedAccount.php docs/backup.md
git commit -m "feat(#1666): backups carry saved feeds and restore them as the restoring user's own"
```

---

### Task 9: Frontend — saved feeds in the sidebar, on the cards and in the confirmations

**Files:**
- Modify: `frontend/src/app/reader/social-feed.ts`, `frontend/src/app/reader/state/social-sections.ts`, `frontend/src/app/reader/state/subscriptions.store.ts:50-58`, `frontend/src/app/reader/state/subscription-rows.ts:36-41`, `frontend/src/app/reader/feeds/display-title.ts`, `frontend/src/app/reader/shell/sidebar/sidebar-social-section.component.html`, `frontend/src/app/reader/entry/entry-source/entry-source.component.{ts,html,scss}`, `frontend/src/app/reader/list/entry-list/list-content.ts:37-46`, `frontend/src/app/reader/feeds/manage/manage-actions.service.ts:184-194,341-360`, `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Modify specs for the new `SocialSection.saved`: `frontend/src/app/reader/shell/sidebar/sidebar-social-section.component.spec.ts`, `frontend/src/app/reader/shell/sidebar/sidebar.component.spec.ts`, `frontend/src/app/reader/state/subscriptions.store.spec.ts`
- Test: append to `frontend/src/app/reader/state/social-sections.spec.ts`, `frontend/src/app/reader/feeds/manage/manage-actions.service.spec.ts`, `frontend/src/app/reader/state/subscription-rows.spec.ts` (create if absent), create `frontend/src/app/reader/entry/entry-source/entry-source.component.spec.ts`

**Interfaces — Produces:**
```ts
// social-feed.ts
export type SocialFeedRefDto = { network: SocialNetworkName } & (
  | { kind: 'profile'; accountId: null }
  | { kind: 'saved'; accountId: null }
  | { kind: 'home' | 'list' | 'custom_feed'; accountId: number }
);
// social-sections.ts
export function isSavedFeed(subscription: SubscriptionDto): boolean; // sourceFormat === 'saved'
export interface SocialSection { …; readonly saved: SubscriptionDto | null }
// display-title.ts
export function savedFeedLabel(i18n: TranslocoService, network: SocialNetworkName | null): string;
```

- [ ] **Step 1: Write the failing specs**

Append to `social-sections.spec.ts` (helpers `feed`, `ref`, `profile` at lines 6-16):
```ts
const saved = (id: number, network: 'bluesky' | 'mastodon', entryCount: number): SubscriptionDto =>
  makeSubscription({
    id,
    title: `Saved from ${network}`,
    sourceFormat: 'saved',
    entryCount,
    social: { network, accountId: null, kind: 'saved' },
  });

describe('saved feeds in the social sections', () => {
  it('shows a network’s saved feed apart from its profiles while it holds posts', () => {
    const [section] = buildSocialSections(
      [profile(1, 'bluesky', 'Bob'), saved(2, 'bluesky', 3)],
      [],
    );

    expect(section.saved?.id).toBe(2);
    expect(section.profiles.subscriptions.map(({ id }) => id)).toEqual([1]);
    expect(isPrivateFeed(saved(2, 'bluesky', 3))).toBe(false);
  });

  it('hides an empty saved feed and keeps a section that holds only saved posts', () => {
    expect(buildSocialSections([saved(2, 'bluesky', 0)], [])).toEqual([]);
    expect(buildSocialSections([saved(3, 'mastodon', 1)], [])[0].network).toBe('mastodon');
  });
});
```
Append to `manage-actions.service.spec.ts` (setup lines 30-51; `open` records the confirm data):
```ts
  it('unsubscribe: says where favourites and kept posts go', () => {
    closed = undefined;
    service.unsubscribe(makeSubscription({ id: 7, title: 'Bob', social: { network: 'bluesky', accountId: null, kind: 'profile' } }));

    expect(JSON.stringify(open.mock.calls[0])).toContain('Favourites and kept posts move to Saved from Bluesky.');
  });

  it('unsubscribe: warns that a saved feed’s posts are deleted for good', () => {
    closed = undefined;
    service.unsubscribe(makeSubscription({ id: 8, title: 'Saved from feeds', sourceFormat: 'saved' }));

    expect(JSON.stringify(open.mock.calls[0])).toContain('Its saved posts are deleted for good.');
  });
```
(Confirm with a dry read of `ConfirmService.confirmThen` how `open` is reached; the existing "names an unrenamed Home" test at line 71 proves the message is inside `open.mock.calls[0]`.) Add one bulk case: two RSS feeds → message contains `move to Saved from feeds`; an RSS and a Bluesky feed → contains `move to their Saved feeds`.

`entry-source.component.spec.ts`: mount `EntrySourceComponent` with `provideTranslocoTesting()`, `provideRouter([])`, a stub `SubscriptionRows` (`{ find: () => savedRow }`) and an entry factory if `frontend/src/app/reader/testing/` has one (check `ls frontend/src/app/reader/testing/`; use it). Assert: for an entry whose subscription is `sourceFormat: 'saved'` titled "Saved from Bluesky" with `poster: {name: 'Bob', url: 'https://bsky.app/profile/bob', imageUrl: null}`, the host text, whitespace-normalised (`(host.textContent ?? '').replace(/\s+/g, ' ').trim()`, as `tabLabels()` does in `social-import-dialog.component.spec.ts`), equals `Bob · Saved from Bluesky`; for an ordinary feed it equals the poster/feed name only.

Subscription rows spec: an unrenamed saved Mastodon feed is titled "Saved from Mastodon" from i18n, a renamed one keeps its custom title.

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/state src/app/reader/feeds/manage src/app/reader/entry/entry-source`
Expected: FAIL.

- [ ] **Step 3: Implement**

- `social-feed.ts`: the union above.
- `social-sections.ts`:
```ts
/** A "Saved from …" feed: favourites and kept posts of feeds the user left. */
export function isSavedFeed(subscription: SubscriptionDto): boolean {
  return subscription.sourceFormat === 'saved';
}

export function isPrivateFeed(subscription: SubscriptionDto): subscription is PrivateSubscription {
  return isSocialFeed(subscription) && !isProfileFeed(subscription) && !isSavedFeed(subscription);
}
```
In `buildSocialSections`: `profiles: socialProfiles(ofNetwork.filter((s) => !isPrivateFeed(s) && !isSavedFeed(s)))`, `saved: ofNetwork.find((s) => isSavedFeed(s) && s.entryCount > 0) ?? null`, and the final filter `section.accounts.length > 0 || section.profiles.subscriptions.length > 0 || section.saved !== null`. Add `readonly saved: SubscriptionDto | null;` (doc: "Shown only while it holds posts.") to `SocialSection`.

  The new required field breaks every `SocialSection` literal in the specs; add `saved: null` to each: `frontend/src/app/reader/shell/sidebar/sidebar-social-section.component.spec.ts` (the literals at lines 33, 113, 134, 186, 207, 286, 307, 362, 399 — re-grep `: SocialSection = {` and every spread of one), `frontend/src/app/reader/shell/sidebar/sidebar.component.spec.ts:1908`, and both expected sections of the `toEqual` in `frontend/src/app/reader/state/subscriptions.store.spec.ts:444-470`. Then `grep -rn "showsHandles:" frontend/src/app --include=*.spec.ts` to catch any literal the list missed.
- `subscriptions.store.ts` `untagged`: `.filter((s) => !isSocialFeed(s) && !(isSavedFeed(s) && s.entryCount === 0))`; update its comment: "Social feeds live in their network's section, and an empty saved feed shows nowhere."
- `display-title.ts`:
```ts
export function isUnrenamedSavedFeed(subscription: SubscriptionDto): boolean {
  return subscription.sourceFormat === 'saved' && subscription.customTitle === null;
}

export function savedFeedLabel(i18n: TranslocoService, network: SocialNetworkName | null): string {
  return i18n.translate(`reader.savedFrom.${network ?? 'feeds'}`);
}
```
- `subscription-rows.ts` `withDisplayTitle()`:
```ts
  private withDisplayTitle(subscription: SubscriptionDto): SubscriptionDto {
    const social = subscription.social;
    if (isUnrenamedSavedFeed(subscription)) {
      return { ...subscription, title: savedFeedLabel(this.i18n, social?.network ?? null) };
    }
    return social && isUnrenamedHome(subscription)
      ? { ...subscription, title: homeLabel(this.i18n, social.network) }
      : subscription;
  }
```
- `sidebar-social-section.component.html`, inside `@if (expanded())`, after the profiles block:
```html
  @if (current.saved; as saved) {
    <div class="feedrow" data-testid="sidebar-social-saved">
      <app-sidebar-feed-row [subscription]="saved" [selection]="selection()" [organising]="organising()" />
    </div>
  }
```
- `list-content.ts` `uncollapsibleSources`: `.filter((subscription) => isPrivateFeed(subscription) || isSavedFeed(subscription))`; comment: "A home timeline, list, custom feed or saved feed carries many people: …".
- `entry-source.component.ts`: add
```ts
  /** A saved post names the feed it now lives in after its poster or origin. */
  protected readonly savedIn = computed(() => {
    const row = this.rows.find(this.entry().subscriptionId);
    return row?.sourceFormat === 'saved' && this.entry().poster ? row.title : null;
  });
```
and at the end of the template `@if (savedIn(); as savedTitle) {<span class="saved-in"> · {{ savedTitle }}</span>}`; in the `.scss` `.saved-in { color: var(--text-muted); }` (read the file's existing tokens; use the one the source line already uses for secondary text — no new hex, no px).
- `manage-actions.service.ts` `unsubscribe()`:
```ts
  unsubscribe(sub: SubscriptionDto): void {
    const data: ConfirmData = {
      title: this.i18n.translate('manage.unsubscribeTitle'),
      message: this.unsubscribeMessage(sub),
      confirmLabel: this.i18n.translate('manage.unsubscribeConfirm'),
      danger: true,
    };
    …unchanged…
  }

  private unsubscribeMessage(sub: SubscriptionDto): string {
    if (isSavedFeed(sub)) return this.i18n.translate('manage.unsubscribeSavedMessage', { title: sub.title });
    const moves = this.i18n.translate('manage.savedPostsMove', {
      saved: savedFeedLabel(this.i18n, sub.social?.network ?? null),
    });
    return `${this.i18n.translate('manage.unsubscribeMessage', { title: sub.title })} ${moves}`;
  }
```
In `bulkUnsubscribeConfirm()` append to the message: `this.bulkSavedNote(subscriptions)`:
```ts
  private bulkSavedNote(subscriptions: SubscriptionDto[]): string {
    const savedOnes = subscriptions.filter(isSavedFeed);
    const destinations = new Set(
      subscriptions.filter((s) => !isSavedFeed(s)).map((s) => s.social?.network ?? null),
    );
    const parts: string[] = [];
    if (destinations.size === 1) {
      const [network] = destinations;
      parts.push(this.i18n.translate('manage.savedPostsMove', { saved: savedFeedLabel(this.i18n, network) }));
    } else if (destinations.size > 1) {
      parts.push(this.i18n.translate('manage.bulk.savedPostsMoveMany'));
    }
    if (savedOnes.length > 0) {
      parts.push(this.i18n.translate('manage.bulk.savedPostsDeleted', { named: savedOnes.map((s) => s.title).join(', ') }));
    }
    return parts.map((part) => ` ${part}`).join('');
  }
```
- i18n (`en.json`; mirror every key in `de.json`):
  - `reader.savedFrom`: `{ "bluesky": "Saved from Bluesky", "mastodon": "Saved from Mastodon", "feeds": "Saved from feeds" }` — de: `"Gespeichert aus Bluesky"`, `"Gespeichert aus Mastodon"`, `"Gespeichert aus Feeds"`.
  - `manage.savedPostsMove`: `"Favourites and kept posts move to {{saved}}."` — de `"Favoriten und behaltene Beiträge wandern nach „{{saved}}“."`
  - `manage.unsubscribeSavedMessage`: `"Remove “{{title}}”? Its saved posts are deleted for good."` — de `"„{{title}}“ entfernen? Seine gespeicherten Beiträge werden endgültig gelöscht."`
  - `manage.bulk.savedPostsMoveMany`: `"Favourites and kept posts move to their Saved feeds."` — de `"Favoriten und behaltene Beiträge wandern in die passenden Gespeichert-Feeds."`
  - `manage.bulk.savedPostsDeleted`: `"The saved posts in {{named}} are deleted for good."` — de `"Die gespeicherten Beiträge in {{named}} werden endgültig gelöscht."`
  - `settings.social.disconnectMessage` (`en.json:379`): `"The stored credential is deleted and the account's timeline, lists and feeds are removed. Favourites and kept posts move to Saved from {{network}}. Feeds you imported stay."` (the last sentence is today's reassurance and still true: followed accounts are ordinary RSS feeds) with `{{network}}` passed from `social-section.component.ts:154-159` as `this.i18n.translate('settings.social.' + account.network)`; de accordingly.

- [ ] **Step 4: Run** `docker compose exec -T frontend npm test -- src/app/reader src/app/settings` then `docker compose exec -T frontend npm run check` — Expected: PASS.

- [ ] **Step 5: Commit**
```bash
git add frontend/src frontend/public/i18n
git commit -m "feat(#1666): saved feeds in the sidebar, on cards and in the unsubscribe confirmations"
```

---

## Part 2 — Hourly sync of private feeds

### Task 10: The sync's schema — stamp, dropped memory, change log

**Files:**
- Create: `backend/src/Enum/PrivateFeedSyncSkip.php`, `backend/src/Enum/PrivateFeedSyncReason.php`, `backend/src/Entity/KnownSocialCollection.php`, `backend/src/Entity/PrivateFeedSyncChange.php`, `backend/src/Repository/KnownSocialCollectionRepository.php`, `backend/src/Repository/PrivateFeedSyncChangeRepository.php`, `backend/migrations/Version20261017130000.php`
- Create: `backend/src/Entity/SyncStamp.php`
- Modify: `backend/src/Entity/SocialAccount.php`, `backend/src/Repository/SocialAccountRepository.php`
- Modify tests: `backend/tests/Service/Backup/Support/BackupSchemaCoverageTest.php` (+ `docs/backup.md` §6.2 rows), `backend/tests/Support/SocialAccounts.php` (accounts with their own identity)
- Test: create `backend/tests/Repository/KnownSocialCollectionRepositoryTest.php`, `backend/tests/Repository/PrivateFeedSyncChangeRepositoryTest.php`; append to `backend/tests/Repository/SocialAccountRepositoryTest.php`

**Interfaces — Produces:**
```php
enum PrivateFeedSyncSkip: string {
    case Unreachable = 'unreachable'; case Incomplete = 'incomplete'; case NeedsReconnect = 'needs_reconnect';
    case Failed = 'failed'; // any other error in the check, logged
}
enum PrivateFeedSyncReason: string {
    case Saved = 'saved'; case Created = 'created';     // additions
    case Unsaved = 'unsaved'; case Deleted = 'deleted'; // removals
    public function isAddition(): bool;
}
// SocialAccount
public function getLastCheckedAt(): ?\DateTimeImmutable;   // every check, skipped or not: due-ness and "Last checked"
public function getLastSyncedAt(): ?\DateTimeImmutable;    // the last complete check; null = the next complete one is the baseline
public function getLastSyncSkip(): ?PrivateFeedSyncSkip;    // why the last check was skipped; null when it completed
public function recordSync(\DateTimeImmutable $checkedAt): void;
public function recordSkippedSync(\DateTimeImmutable $checkedAt, PrivateFeedSyncSkip $skip): void;
final class KnownSocialCollection { __construct(SocialAccount, SocialFeedKind, string $remoteId) }
final class KnownSocialCollectionRepository {
    /** @return array<string, true> by kind:remoteId (PrivateFeedModel::keyOf) */ public function keysOf(SocialAccount $account): array;
    public function remember(SocialAccount $account, SocialFeedKind $kind, string $remoteId): void;   // idempotent, no flush
    public function forget(SocialAccount $account, SocialFeedKind $kind, string $remoteId): void;     // DQL delete
}
final class PrivateFeedSyncChange { __construct(SocialAccount, SocialFeedKind, string $remoteId, string $name, PrivateFeedSyncReason, \DateTimeImmutable $changedAt); getters }
final class PrivateFeedSyncChangeRepository {
    public function trimTo(SocialAccount $account, int $keep): void;
    /** @param list<SocialAccount> $accounts @return array<int, list<PrivateFeedSyncChange>> newest first, by account id */
    public function recentOf(array $accounts): array;
}
// SocialAccountRepository
/** @return list<int> ids, never checked first, then the longest unchecked; accounts of active users only */
public function findIdsDueForSync(\DateTimeImmutable $checkedBefore, int $limit): array;
/** Stamps a failed check through the connection: works after a failure closed the entity manager. */
public function recordFailedSync(int $accountId, \DateTimeImmutable $checkedAt): void;
// tests/Support/SocialAccounts
public static function blueskyAs(User $owner, string $did): SocialAccount;        // blueskyOf() = blueskyAs($owner, 'did:plc:alice')
public static function mastodonAs(User $owner, string $identity): SocialAccount;  // mastodonOf() unchanged in behaviour
```

- [ ] **Step 1: Write the failing tests**

`KnownSocialCollectionRepositoryTest` (DbTestCase; account via `SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($user))`): remember two (one twice) → `keysOf()` is `['list:at://l/1' => true, 'custom_feed:at://f/1' => true]` after a flush; `forget()` the list → only the feed key; a second account's keys stay separate; deleting the account removes its rows (cascade).

`PrivateFeedSyncChangeRepositoryTest`: persist 12 changes for account A at minutes 1..12 (`new \DateTimeImmutable('2026-10-17 10:00:00')->modify("+{$minute} minutes")`) and 1 for B; `trimTo(A, 10)` → A keeps minutes 3..12; `recentOf([A, B])[A id]` is newest first (minute 12 first); B unaffected.

Append to `SocialAccountRepositoryTest`: one user, three Bluesky accounts built with `SocialAccounts::blueskyAs($user, 'did:plc:due-1')`, `…due-2`, `…due-3` (one user may not hold the same identity twice: `uniq_social_account_identity`, `backend/src/Entity/SocialAccount.php:15`) — never checked, checked 2 h before `$now`, checked 10 min before `$now` (`recordSync()`); `findIdsDueForSync($now->modify('-1 hour'), 10)` returns the first two ids, never-checked first; `limit: 1` returns only the never-checked one; a fourth account of a second user created with `(new UserFactory(...))->create('due-suspended@example.test', status: UserStatus::Suspended)` (`backend/tests/Support/UserFactory.php:26-38`; `UserFactory` makes users `Active` by default) is never returned. A second test: `recordFailedSync($id, $at)` after `$this->entityManager->close()` still writes — re-read the row through a fresh `getConnection()->fetchAssociative('SELECT last_checked_at, last_sync_skip FROM social_account WHERE id = ?', [$id])` and expect the stamp and `failed`.

In `backend/tests/Support/SocialAccounts.php` add `blueskyAs(User $owner, string $did)` (the body of today's `blueskyOf()`, `SocialAccounts.php:39-51`, with `$did` for `'did:plc:alice'`) and make `blueskyOf()` call `self::blueskyAs($owner, 'did:plc:alice')`; add `mastodonAs(User $owner, string $identity)` the same way over `mastodonAt()` (lines 63-76, give `mastodonAt` an `$identity` parameter).

- [ ] **Step 2: Run** `php bin/phpunit tests/Repository/KnownSocialCollectionRepositoryTest.php tests/Repository/PrivateFeedSyncChangeRepositoryTest.php tests/Repository/SocialAccountRepositoryTest.php` — Expected: FAIL (classes missing).

- [ ] **Step 3: Implement**

`SocialAccount` (fields stay under PHPMD's 15: it has 14 — add an embeddable to stay at 15):
- Create `backend/src/Entity/SyncStamp.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PrivateFeedSyncSkip;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * When an account's lists and feeds were last checked against the network, when a check last completed, and why the
 * last one was skipped (#1666). A skip keeps syncedAt: the baseline waits for the first complete answer.
 */
#[ORM\Embeddable]
final readonly class SyncStamp
{
    public function __construct(
        #[ORM\Column(name: 'last_checked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $checkedAt = null,
        #[ORM\Column(name: 'last_synced_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $syncedAt = null,
        #[ORM\Column(name: 'last_sync_skip', length: 24, nullable: true, enumType: PrivateFeedSyncSkip::class)]
        private ?PrivateFeedSyncSkip $skip = null,
    ) {
    }

    public static function completed(\DateTimeImmutable $at): self
    {
        return new self($at, $at);
    }

    public function skipped(\DateTimeImmutable $at, PrivateFeedSyncSkip $skip): self
    {
        return new self($at, $this->syncedAt, $skip);
    }

    public function checkedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function syncedAt(): ?\DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function skip(): ?PrivateFeedSyncSkip
    {
        return $this->skip;
    }
}
```
- In `SocialAccount`: `#[ORM\Embedded(class: SyncStamp::class, columnPrefix: false)] private SyncStamp $syncStamp;`, set `$this->syncStamp = new SyncStamp();` in the constructor, and
```php
    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->syncStamp->checkedAt();
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->syncStamp->syncedAt();
    }

    public function getLastSyncSkip(): ?PrivateFeedSyncSkip
    {
        return $this->syncStamp->skip();
    }

    public function recordSync(\DateTimeImmutable $checkedAt): void
    {
        $this->syncStamp = SyncStamp::completed($checkedAt);
    }

    public function recordSkippedSync(\DateTimeImmutable $checkedAt, PrivateFeedSyncSkip $skip): void
    {
        $this->syncStamp = $this->syncStamp->skipped($checkedAt, $skip);
    }
```
(Count `SocialAccount`'s fields before and after with PHPMD; if it reports `TooManyFields`, the embeddable is what keeps it at one field — do not add two scalar fields.)

`KnownSocialCollection` (table `known_social_collection`, mirror `SocialFeed`'s mapping, `backend/src/Entity/SocialFeed.php:11-50`): `id`, `socialAccount` (ManyToOne, CASCADE), `kind` (`SocialFeedKind`, length 16), `remoteId` (length 512); `#[ORM\Index(name: 'idx_known_social_collection_account', columns: ['social_account_id'])]`, `#[ORM\UniqueConstraint(name: 'uniq_known_social_collection', columns: ['social_account_id', 'kind', 'remote_id'])]`; class docblock: "A list or custom feed the sync has seen for an account; one the account no longer subscribes is one the user dropped (#1666)."

`PrivateFeedSyncChange` (table `private_feed_sync_change`): `id`, `socialAccount` (CASCADE), `kind`, `remoteId` (512), `name` (512), `reason` (`PrivateFeedSyncReason`, length 16), `changedAt` (DATETIME_IMMUTABLE); `#[ORM\Index(name: 'idx_private_feed_sync_change_account', columns: ['social_account_id', 'changed_at'])]`; getters `getKind()`, `getRemoteId()`, `getName()`, `getReason()`, `getChangedAt()`, `getSocialAccount()`.

Repositories:
```php
    // KnownSocialCollectionRepository
    public function keysOf(SocialAccount $account): array
    {
        $keys = [];
        foreach ($this->findBy(['socialAccount' => $account]) as $known) {
            $keys[PrivateFeedModel::keyOf($known->getKind(), $known->getRemoteId())] = true;
        }

        return $keys;
    }

    public function remember(SocialAccount $account, SocialFeedKind $kind, string $remoteId): void
    {
        if (null !== $this->findOneBy(['socialAccount' => $account, 'kind' => $kind, 'remoteId' => $remoteId])) {
            return;
        }
        $this->getEntityManager()->persist(new KnownSocialCollection($account, $kind, $remoteId));
    }

    public function forget(SocialAccount $account, SocialFeedKind $kind, string $remoteId): void
    {
        $this->createQueryBuilder('known')
            ->delete()
            ->andWhere('known.socialAccount = :account')->setParameter('account', $account)
            ->andWhere('known.kind = :kind')->setParameter('kind', $kind)
            ->andWhere('known.remoteId = :remoteId')->setParameter('remoteId', $remoteId)
            ->getQuery()
            ->execute();
    }
```
(`PrivateFeedModel::keyOf()` is a Service model helper, `backend/src/Service/Social/Model/PrivateFeedModel.php:45`; a repository may name it. `remember()` is called with an unflushed sibling persisted in the same unit of work at most once per key per call path — the sync dedupes keys before calling.)

`PrivateFeedSyncChangeRepository::trimTo()`: select the account's ids ordered `changedAt DESC, id DESC` with `setFirstResult($keep)`; delete those ids (`id IN`). `recentOf()`: `[] === $accounts` → `[]`; else one query `WHERE change.socialAccount IN (:accounts) ORDER BY change.changedAt DESC, change.id DESC`, grouped by `$change->getSocialAccount()->requireId()`.

`SocialAccountRepository`:
```php
    /** @return list<int> */
    public function findIdsDueForSync(\DateTimeImmutable $checkedBefore, int $limit): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->createQueryBuilder('sa')
            ->select('sa.id')
            ->addSelect('COALESCE(sa.syncStamp.checkedAt, :epoch) AS HIDDEN checkOrder')
            ->join('sa.user', 'owner')
            ->andWhere('owner.status = :active')
            ->andWhere('sa.syncStamp.checkedAt IS NULL OR sa.syncStamp.checkedAt < :checkedBefore')
            ->setParameter('active', UserStatus::Active)
            ->setParameter('checkedBefore', $checkedBefore)
            ->setParameter('epoch', new \DateTimeImmutable('@0'))
            ->orderBy('checkOrder', 'ASC')
            ->addOrderBy('sa.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }

    public function recordFailedSync(int $accountId, \DateTimeImmutable $checkedAt): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE social_account SET last_checked_at = :checkedAt, last_sync_skip = :failed WHERE id = :id',
            ['checkedAt' => $checkedAt, 'failed' => PrivateFeedSyncSkip::Failed->value, 'id' => $accountId],
            ['checkedAt' => Types::DATETIME_IMMUTABLE],
        );
    }
```
Ids, not entities: the sweep loads each account itself, so a failure that clears or closes the entity manager cannot leave it holding detached accounts (Task 14). Same never-first ordering as `FeedRepository::findDue` (`FeedRepository.php:37-44`). Accounts that need reconnecting are returned: the sync records their skip, which Settings shows. Only active users' accounts are due — a suspended or unapproved user's account would spend network calls for nobody (the feed refresh path has no such filter today, `FeedRepository::dueQueryBuilder`, lines 203-214; this is new here, not copied). `User::$status` is the mapped field (`backend/src/Entity/User.php:37`).

Migration `Version20261017130000` (both dialects, as `Version20261012130000` and `Version20261012140000`): `ALTER TABLE social_account ADD last_checked_at DATETIME DEFAULT NULL, ADD last_synced_at DATETIME DEFAULT NULL, ADD last_sync_skip VARCHAR(24) DEFAULT NULL` (SQLite: three `ALTER TABLE social_account ADD …` statements, `DATETIME` type — copy what `--dump-sql` prints on each platform), `CREATE TABLE known_social_collection …` and `CREATE TABLE private_feed_sync_change …` with their FKs `fk_known_social_collection_account`, `fk_private_feed_sync_change_account` → `social_account (id) ON DELETE CASCADE`, indexes and unique as mapped. `skipIf($schema->hasTable('known_social_collection'), …)`. `down()` drops both tables and the three columns.

Backup coverage: `KnownSocialCollection` and `PrivateFeedSyncChange` go into `ACCOUNT_SCOPED_WHOLLY_DROPPED` (`'What the hourly sync remembers of a connected account (#1666). It belongs to an account a backup drops.'` / `'The recent automatic changes to a connected account\'s lists and feeds (#1666); account telemetry a backup drops.'`) with matching rows in `docs/backup.md` §6.2. `SocialAccount` is already wholly dropped, so its new columns need nothing.

- [ ] **Step 4: Verify the migration** exactly as Task 2 Step 5 (MySQL `--dump-sql` → migrate → validate → nothing to update; SQLite from empty).

- [ ] **Step 5: Run** the Step 2 command plus `php bin/phpunit tests/Service/Backup tests/Service/Social` — Expected: PASS.

- [ ] **Step 6: Commit**
```bash
git add backend/src/Enum/PrivateFeedSyncSkip.php backend/src/Enum/PrivateFeedSyncReason.php backend/src/Entity backend/src/Repository backend/migrations/Version20261017130000.php backend/tests docs/backup.md
# backend/tests includes tests/Support/SocialAccounts.php
git commit -m "feat(#1666): an account's sync stamp, dropped memory and change log"
```

---

### Task 11: What exists on the network — `collections()`

**Files:**
- Create: `backend/src/Service/Social/Model/NetworkCollectionsModel.php`, `backend/src/Service/Social/Model/ResolvedCollectionsModel.php`, `backend/src/Service/Social/Model/SavedItemsModel.php`, `backend/src/Service/Social/BlueskySavedCollections.php`
- Modify: `backend/src/Service/Social/CandidateSource/CandidateSourceInterface.php`, `BlueskyCandidateSource.php`, `MastodonCandidateSource.php`, `backend/src/Service/Social/SocialCandidates/SocialCandidatesInterface.php`, `NetworkSocialCandidates.php`
- Modify tests: `backend/tests/Support/StubSocialCandidates.php`, `backend/tests/Service/Social/CandidateSource/BlueskyCandidateSourceTest.php` (its `sourceOver()` builds the new collaborator; the "unknown one keeps its URI" test changes per Open question 3), `MastodonCandidateSourceTest.php`
- Test: append to both candidate-source tests and `backend/tests/Service/Social/SocialCandidates/NetworkSocialCandidatesTest.php` (its anonymous `CandidateSourceInterface`, lines 19-35, gains `collections()`)

**Interfaces — Produces:**
```php
final readonly class NetworkCollectionsModel {
    /**
     * @param list<SocialCollectionModel> $lists       owned and saved lists, each URI once
     * @param list<SocialCollectionModel> $customFeeds saved custom feeds that still exist
     * @param list<string>                $goneIds     saved ids the network says no longer exist
     */
    public function __construct(public array $lists, public array $customFeeds, public array $goneIds = [], public bool $truncated = false, public bool $savedFeedsKnown = true) {}
}
final readonly class ResolvedCollectionsModel {
    /** @param list<SocialCollectionModel> $found @param list<string> $gone */
    public function __construct(public array $found = [], public array $gone = []) {}
}
// CandidateSourceInterface + SocialCandidatesInterface
/** @throws SocialCredentialsRejectedException @throws SocialNetworkUnreachableException */
public function collections(SocialAccount $account): NetworkCollectionsModel;
final readonly class SavedItemsModel { public function __construct(public array $feedUris = [], public array $listUris = [], public bool $hasSavedFeedsPref = false) {} }
final readonly class BlueskySavedCollections {
    public const int MAX_SAVED_LISTS = 25;
    public function savedItemsOf(SocialAccount $account): SavedItemsModel;  // getPreferences with the session; malformed → unreachable
    /** @param array<string, true> $ownedListUris */
    public function resolve(SavedItemsModel $saved, array $ownedListUris): NetworkCollectionsModel; // others' lists, feeds, gone, cap
}
```
`BlueskyCandidateSource::candidates()` keeps `NetworkCandidatesModel(follows, lists, customFeeds, truncated)`: `lists` = owned curated lists then saved lists by others; `customFeeds` = existing saved feeds; `truncated` = follows or owned lists cut.

- [ ] **Step 1: Write the failing tests**

Append to `BlueskyCandidateSourceTest` (route constants lines 41-49; `routes()`, `json()`, `savedFeeds()` helpers lines 92-122). Add constants:
```php
    private const string GET_LIST = 'GET https://public.api.bsky.app/xrpc/app.bsky.graph.getList?list=';
    private const string OWN_LIST = 'at://did:plc:alice/app.bsky.graph.list/news';
    private const string THEIR_LIST = 'at://did:plc:bob/app.bsky.graph.list/writers';
    private const string DELETED_LIST = 'at://did:plc:bob/app.bsky.graph.list/deleted';
```
and a helper
```php
    /** @param list<array{string, string}> $items type, value */
    private static function savedItems(array $items): MockResponse
    {
        return self::json(['preferences' => [[
            '$type' => 'app.bsky.actor.defs#savedFeedsPrefV2',
            'items' => array_map(
                static fn (array $item): array => ['type' => $item[0], 'value' => $item[1], 'pinned' => false, 'id' => $item[1]],
                $items,
            ),
        ]]]);
    }
```
Tests:
```php
    public function testCollectionsHoldOwnedAndSavedListsOnceAndNameSavedOnesByTheirList(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::PREFERENCES => self::savedItems([['list', self::OWN_LIST], ['list', self::THEIR_LIST]]),
            self::LISTS => self::json(['lists' => [
                ['uri' => self::OWN_LIST, 'name' => 'News', 'purpose' => 'app.bsky.graph.defs#curatelist'],
            ]]),
            self::GET_LIST . rawurlencode(self::THEIR_LIST) . '&limit=1' => self::json(['list' => [
                'uri' => self::THEIR_LIST, 'name' => 'Writers', 'purpose' => 'app.bsky.graph.defs#curatelist',
            ], 'items' => []]),
        ]));

        $collections = $this->source($http)->collections($this->account);

        self::assertEquals([
            new SocialCollectionModel(self::OWN_LIST, 'News'),
            new SocialCollectionModel(self::THEIR_LIST, 'Writers'),
        ], $collections->lists);
        self::assertSame([], $collections->goneIds);
        self::assertFalse($collections->truncated);
        self::assertNotContains(
            'https://public.api.bsky.app/xrpc/app.bsky.graph.getList?list=' . rawurlencode(self::OWN_LIST) . '&limit=1',
            $http->requestedUrls(),
        );
    }

    public function testASavedListTheNetworkNoLongerHasAndAMissingGeneratorAreGone(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::PREFERENCES => self::savedItems([['list', self::DELETED_LIST], ['feed', self::DISCOVER], ['feed', self::GONE]]),
            self::GET_LIST . rawurlencode(self::DELETED_LIST) . '&limit=1' => new MockResponse(
                '{"error":"InvalidRequest","message":"List not found"}',
                ['http_code' => Response::HTTP_BAD_REQUEST],
            ),
            self::GENERATORS . self::feedsQuery([self::DISCOVER, self::GONE]) => self::json(['feeds' => [
                ['uri' => self::DISCOVER, 'displayName' => 'Discover'],
            ]]),
        ]));

        $collections = $this->source($http)->collections($this->account);

        self::assertSame([], $collections->lists);
        self::assertEquals([new SocialCollectionModel(self::DISCOVER, 'Discover')], $collections->customFeeds);
        self::assertSame([self::GONE, self::DELETED_LIST], $collections->goneIds);
    }

    public function testASavedListThatIsNoCuratedListIsNeitherOfferedNorGone(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::PREFERENCES => self::savedItems([['list', self::THEIR_LIST]]),
            self::GET_LIST . rawurlencode(self::THEIR_LIST) . '&limit=1' => self::json(['list' => [
                'uri' => self::THEIR_LIST, 'name' => 'Blocks', 'purpose' => 'app.bsky.graph.defs#modlist',
            ]]),
        ]));

        $collections = $this->source($http)->collections($this->account);

        self::assertSame([], $collections->lists);
        self::assertSame([], $collections->goneIds);
    }

    public function testAnyOtherFailedListAnswerIsUnreachable(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::PREFERENCES => self::savedItems([['list', self::THEIR_LIST]]),
            self::GET_LIST . rawurlencode(self::THEIR_LIST) . '&limit=1' => new MockResponse('', ['http_code' => Response::HTTP_BAD_GATEWAY]),
        ]));

        $this->expectException(SocialNetworkUnreachableException::class);
        $this->source($http)->collections($this->account);
    }

    public function testCollectionsNeverAskForTheFollows(): void
    {
        $http = new RoutedHttpClient($this->routes());

        $this->source($http)->collections($this->account);

        self::assertSame([], array_filter($http->requestedUrls(), static fn (string $url): bool => str_contains($url, 'getFollows')));
    }

    public function testOwnedListsCutAtTheCapMarkTheCollectionsTruncated(): void
    {
        $lists = array_map(
            static fn (int $index): array => ['uri' => 'at://did:plc:alice/app.bsky.graph.list/' . $index, 'name' => 'L' . $index, 'purpose' => 'app.bsky.graph.defs#curatelist'],
            range(1, CollectedItemsModel::MAX_ITEMS + 1),
        );
        $http = new RoutedHttpClient($this->routes([self::LISTS => self::json(['lists' => $lists, 'cursor' => 'more'])]));

        self::assertTrue($this->source($http)->collections($this->account)->truncated);
    }

    public function testMoreSavedListsThanTheCapAreCutAndMarkTheCollectionsTruncated(): void
    {
        $uris = array_map(
            static fn (int $index): string => 'at://did:plc:bob/app.bsky.graph.list/' . $index,
            range(1, BlueskySavedCollections::MAX_SAVED_LISTS + 1),
        );
        $routes = [self::PREFERENCES => self::savedItems(array_map(static fn (string $uri): array => ['list', $uri], $uris))];
        foreach (\array_slice($uris, 0, BlueskySavedCollections::MAX_SAVED_LISTS) as $uri) {
            $routes[self::GET_LIST . rawurlencode($uri) . '&limit=1'] = self::json(['list' => [
                'uri' => $uri, 'name' => 'L', 'purpose' => 'app.bsky.graph.defs#curatelist',
            ]]);
        }
        $http = new RoutedHttpClient($this->routes($routes));

        $collections = $this->source($http)->collections($this->account);

        self::assertTrue($collections->truncated);
        self::assertCount(BlueskySavedCollections::MAX_SAVED_LISTS, $collections->lists);
    }

    public function testAnAccountWithoutTheSavedFeedsPreferenceHasUnknownSavedFeeds(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::PREFERENCES => self::json(['preferences' => [['$type' => 'app.bsky.actor.defs#savedFeedsPref', 'saved' => [self::DISCOVER]]]]),
        ]));

        self::assertFalse($this->source($http)->collections($this->account)->savedFeedsKnown);
    }

    public function testASavedListAnsweredWithoutItsListIsUnreachable(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::PREFERENCES => self::savedItems([['list', self::THEIR_LIST]]),
            self::GET_LIST . rawurlencode(self::THEIR_LIST) . '&limit=1' => self::json(['items' => []]),
        ]));

        $this->expectException(SocialNetworkUnreachableException::class);
        $this->source($http)->collections($this->account);
    }
```
Add two cases to the existing data provider `answersWithoutTheirCollection()` (lines 435-460):
```php
        yield 'preferences without the field' => [
            [self::PREFERENCES => self::json([])],
            'Bluesky answered without preferences.',
        ];
        yield 'saved feeds without items' => [
            [self::PREFERENCES => self::json(['preferences' => [['$type' => 'app.bsky.actor.defs#savedFeedsPrefV2']]])],
            'Bluesky answered saved feeds without items.',
        ];
```
Today an empty `{}` 200 for the preferences reads as "nothing saved" — and the sync would then remove every custom feed; these cases pin that a malformed answer is unreachable instead.
Change `testSavedFeedsAreNamedByTheirGeneratorAndAnUnknownOneKeepsItsUri` (rename to `testSavedFeedsAreNamedByTheirGeneratorAndAnUnknownOneIsLeftOut`): expected `customFeeds` is `[new SocialCollectionModel(self::DISCOVER, 'Discover')]`; its preferences hold a saved `list` (`at://did:plc:alice/app.bsky.graph.list/news`) — add a `GET_LIST` route for it answering a curated list named News so the test's routes stay complete. `testANamelessRepeatOfAGeneratorKeepsItsName` keeps passing (both URIs are returned).

`MastodonCandidateSourceTest`: `collections()` asks only `/api/v1/lists` (no `verify_credentials`, no `following`), returns those lists, `customFeeds = []`, `goneIds = []`, `truncated = false`.

`NetworkSocialCandidatesTest`: `collections()` dispatches by network as `candidates()` does.

- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Social/CandidateSource tests/Service/Social/SocialCandidates` — Expected: FAIL (`collections()` undefined).

- [ ] **Step 3: Implement**

`backend/src/Service/Social/Model/SavedItemsModel.php`:
```php
final readonly class SavedItemsModel
{
    /**
     * @param list<string> $feedUris custom feeds in savedFeedsPrefV2, each once
     * @param list<string> $listUris lists in savedFeedsPrefV2, each once
     */
    public function __construct(
        public array $feedUris = [],
        public array $listUris = [],
        public bool $hasSavedFeedsPref = false,
    ) {
    }
}
```
`NetworkCollectionsModel` takes `(array $lists, array $customFeeds, array $goneIds = [], bool $truncated = false, bool $savedFeedsKnown = true)`; `savedFeedsKnown` is false when the account has no `savedFeedsPrefV2` (an account still on the legacy v1 preference): its saved feeds are unknown, not absent.

`BlueskySavedCollections` — move `customFeeds()`, `savedFeedUris()`, `feedUris()`, `generatorNames()`, `namesIn()`, `collectionIn()` out of `BlueskyCandidateSource` (lines 121-204) and split them into the two halves the candidate source calls around `getLists`:
```php
final readonly class BlueskySavedCollections
{
    public const int MAX_SAVED_LISTS = 25;

    private const string GET_FEED_GENERATORS = AppViewClient::BASE_URL . 'app.bsky.feed.getFeedGenerators';
    private const string GET_LIST = AppViewClient::BASE_URL . 'app.bsky.graph.getList';
    private const string GET_PREFERENCES = '/xrpc/app.bsky.actor.getPreferences';
    private const string SAVED_FEEDS = 'app.bsky.actor.defs#savedFeedsPrefV2';
    private const string CURATED_LIST = 'app.bsky.graph.defs#curatelist';
    private const string MISSING_RECORD = 'InvalidRequest';
    private const int GENERATORS_PER_CALL = 25;

    public function __construct(
        private BlueskyApiClient $bluesky,
        private BlueskySessions $sessions,
    ) {
    }

    /** The account's own call, made first, so a refused credential stops before any public call. */
    public function savedItemsOf(SocialAccount $account): SavedItemsModel
    {
        $answer = BlueskySessions::accepted($this->sessions->askAs($account, self::GET_PREFERENCES));
        $preferences = JsonNodeModel::of($answer->json())->listAt('preferences')
            ?? throw new SocialNetworkUnreachableException('Bluesky answered without preferences.');

        $saved = ['feed' => [], 'list' => []];
        $hasSavedFeedsPref = false;
        foreach ($preferences as $preference) {
            if (self::SAVED_FEEDS !== $preference->type()) {
                continue;
            }
            $hasSavedFeedsPref = true;
            $items = $preference->listAt('items')
                ?? throw new SocialNetworkUnreachableException('Bluesky answered saved feeds without items.');
            foreach ($items as $item) {
                $type = $item->string('type');
                $value = $item->string('value');
                if (null !== $value && isset($saved[$type ?? ''])) {
                    $saved[$type][] = $value;
                }
            }
        }

        return new SavedItemsModel(
            array_values(array_unique($saved['feed'])),
            array_values(array_unique($saved['list'])),
            $hasSavedFeedsPref,
        );
    }

    /** @param array<string, true> $ownedListUris */
    public function resolve(SavedItemsModel $saved, array $ownedListUris): NetworkCollectionsModel
    {
        $othersLists = array_values(array_filter(
            $saved->listUris,
            static fn (string $uri): bool => !isset($ownedListUris[$uri]),
        ));
        $feeds = $this->generators($saved->feedUris);
        $lists = $this->savedLists(\array_slice($othersLists, 0, self::MAX_SAVED_LISTS));

        return new NetworkCollectionsModel(
            $lists->found,
            $feeds->found,
            [...$feeds->gone, ...$lists->gone],
            \count($othersLists) > self::MAX_SAVED_LISTS,
            $saved->hasSavedFeedsPref,
        );
    }

    /** @param list<string> $uris */
    private function generators(array $uris): ResolvedCollectionsModel
    {
        $names = [];
        foreach (array_chunk($uris, self::GENERATORS_PER_CALL) as $batch) {
            $query = implode('&', array_map(static fn (string $uri): string => 'feeds=' . rawurlencode($uri), $batch));
            $answer = JsonNodeModel::of($this->bluesky->send(ApiRequestModel::get(self::GET_FEED_GENERATORS . '?' . $query))->json());
            $names = [...$names, ...self::namesIn($answer)];
        }
        $found = [];
        $gone = [];
        foreach ($uris as $uri) {
            if (\array_key_exists($uri, $names)) {
                $found[] = new SocialCollectionModel($uri, $names[$uri] ?? $uri);
            } else {
                $gone[] = $uri;
            }
        }

        return new ResolvedCollectionsModel($found, $gone);
    }

    /** @param list<string> $uris */
    private function savedLists(array $uris): ResolvedCollectionsModel
    {
        $found = [];
        $gone = [];
        foreach ($uris as $uri) {
            $answer = $this->bluesky->answer(ApiRequestModel::get(self::GET_LIST . '?list=' . rawurlencode($uri) . '&limit=1'));
            if (self::isMissing($answer)) {
                $gone[] = $uri;
                continue;
            }
            $collection = self::curatedListIn(JsonNodeModel::of(BlueskyApiClient::accepted($answer)->json())->node('list'));
            if (null !== $collection) {
                $found[] = $collection;
            }
        }

        return new ResolvedCollectionsModel($found, $gone);
    }

    /** @throws SocialNetworkUnreachableException when the answer lacks the list's uri, purpose or name */
    private static function curatedListIn(JsonNodeModel $list): ?SocialCollectionModel
    {
        $uri = $list->string('uri');
        $purpose = $list->string('purpose');
        $name = $list->string('name');
        if (null === $uri || null === $purpose || null === $name) {
            throw new SocialNetworkUnreachableException('Bluesky answered without the saved list.');
        }

        return self::CURATED_LIST === $purpose ? new SocialCollectionModel($uri, $name) : null;
    }

    /** @return array<string, string|null> display name by uri; null when the generator has none */
    private static function namesIn(JsonNodeModel $answer): array
    {
        $names = [];
        foreach ($answer->listAt('feeds') ?? throw new SocialNetworkUnreachableException('Bluesky answered without feeds.') as $generator) {
            $uri = $generator->string('uri');
            if (null !== $uri) {
                $names[$uri] = $generator->string('displayName') ?? ($names[$uri] ?? null);
            }
        }

        return $names;
    }

    private static function isMissing(ApiResponseModel $answer): bool
    {
        return Response::HTTP_BAD_REQUEST === $answer->status
            && self::MISSING_RECORD === JsonNodeModel::of($answer->json())->string('error');
    }
}
```
(`namesIn()` keeps a name a nameless repeat would otherwise drop — what `testANamelessRepeatOfAGeneratorKeepsItsName` pins. `answer()` throws `SocialNetworkUnreachableException` only when nothing answered, `BlueskyApiClient.php:47-50`; `accepted()` throws it on any other non-2xx and `SocialNetworkThrottledException` on 429. Add `use Symfony\Component\HttpFoundation\Response;` — `BlueskySessions` already uses it in this module, `backend/src/Service/Social/BlueskySessions.php:17`, so the module already uses the status constants the house rule asks for.) Split the class further if PHPMD's class complexity asks for it — `savedItemsOf()` into a `Support/BlueskySavedItems` static reader is the natural cut.

`BlueskyCandidateSource`: constructor `(BlueskyApiClient $bluesky, BlueskySavedCollections $savedCollections)` — `BlueskySessions` moves into `BlueskySavedCollections`. Then:
```php
    public function candidates(SocialAccount $account): NetworkCandidatesModel
    {
        $collections = $this->collections($account);
        $follows = $this->collect(self::actorPage('app.bsky.graph.getFollows', $account->getIdentity()), 'follows');

        return new NetworkCandidatesModel(
            self::follows($follows->items),
            $collections->lists,
            $collections->customFeeds,
            $follows->truncated || $collections->truncated,
        );
    }

    /** Preferences first (the account's own call), then the public lists, then what the preferences name. */
    public function collections(SocialAccount $account): NetworkCollectionsModel
    {
        $saved = $this->savedCollections->savedItemsOf($account);
        $owned = $this->collect(self::actorPage('app.bsky.graph.getLists', $account->getIdentity()), 'lists');
        $ownedLists = self::curatedLists($owned->items);
        $others = $this->savedCollections->resolve($saved, self::urisOf($ownedLists));

        return new NetworkCollectionsModel(
            [...$ownedLists, ...$others->lists],
            $others->customFeeds,
            $others->goneIds,
            $owned->truncated || $others->truncated,
            $others->savedFeedsKnown,
        );
    }

    /**
     * @param list<SocialCollectionModel> $lists
     *
     * @return array<string, true>
     */
    private static function urisOf(array $lists): array
    {
        return array_fill_keys(array_map(static fn (SocialCollectionModel $list): string => $list->id, $lists), true);
    }
```
Call order: preferences (with the session), `getLists`, generators, each saved list, then (candidates only) follows — so `testARefusedAppPasswordIsRejectedBeforeAnyPublicCall` (one request) and `testTheCachedSessionReadsThePreferencesWithoutOpeningANewOne` (`requests[0]` carries the bearer) keep passing unchanged, and `testSavedFeedsAreNamedBy…`'s `requests[1]` is still the preferences call after `createSession`. Only `testAStaleCachedSessionIsReplacedAndThePreferencesAskedAgain`'s ordered `MockHttpClient` list (lines 289-295) changes: stale preferences, createSession, preferences, **lists, follows** (today: follows, lists).

`BlueskyCandidateSourceTest::sourceOver()` (lines 67-82): `new BlueskyCandidateSource($api, new BlueskySavedCollections($api, new BlueskySessions(...)))` with the `BlueskySessions` it builds today.

`MastodonCandidateSource::collections()`:
```php
    public function collections(SocialAccount $account): NetworkCollectionsModel
    {
        $session = new MastodonSessionModel($account->getServiceUrl(), $this->storedCredential->open($account));

        return new NetworkCollectionsModel($this->lists($session), []);
    }
```
`NetworkSocialCandidates::collections()` mirrors `candidates()` (lines 21-27). In `NetworkSocialCandidatesTest` the anonymous `CandidateSourceInterface` (lines 19-35) gains
```php
            public function collections(SocialAccount $account): NetworkCollectionsModel
            {
                return new NetworkCollectionsModel($this->answer->lists, $this->answer->customFeeds);
            }
```
`StubSocialCandidates` becomes:
```php
final class StubSocialCandidates implements SocialCandidatesInterface
{
    public int $calls = 0;

    /**
     * @param NetworkCandidatesModel|\Throwable       $outcome     what candidates() answers
     * @param NetworkCollectionsModel|\Throwable|null $collections what collections() answers; null derives it from $outcome
     */
    public function __construct(
        private readonly NetworkCandidatesModel|\Throwable $outcome,
        private readonly NetworkCollectionsModel|\Throwable|null $collections = null,
    ) {
    }

    public function candidates(SocialAccount $account): NetworkCandidatesModel
    {
        ++$this->calls;
        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }

    public function collections(SocialAccount $account): NetworkCollectionsModel
    {
        ++$this->calls;
        $answer = $this->collections ?? $this->outcome;
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer instanceof NetworkCollectionsModel
            ? $answer
            : new NetworkCollectionsModel($answer->lists, $answer->customFeeds, [], $answer->truncated);
    }
}
```
Tests that only sync pass the collections as the second argument: `new StubSocialCandidates(new NetworkCandidatesModel([], [], [], false), new NetworkCollectionsModel([...], [...]))`, or a `\Throwable` there to make the check fail.

- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Social tests/Controller/Api/SocialImportControllerTest.php` — Expected: PASS.

- [ ] **Step 5: Commit**
```bash
git add backend/src/Service/Social backend/tests/Support/StubSocialCandidates.php backend/tests/Service/Social
git commit -m "feat(#1666): read an account's owned and saved lists and feeds, and what the network says is gone"
```

---

### Task 12: Creating a private feed remembers it

**Files:**
- Modify: `backend/src/Service/Social/PrivateFeeds.php:24-90`, `backend/tests/Support/PrivateFeedsWiring.php`
- Test: append to `backend/tests/Service/Social/PrivateFeedsTest.php`

- [ ] **Step 1: Write the failing test** (append; helpers `privateFeeds()`, `stored()`, lines 191-205)
```php
    public function testAListOrCustomFeedIsRememberedAndHomeIsNot(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));

        $this->privateFeeds()->create($account, PrivateFeedModel::home());
        $this->privateFeeds()->create($account, PrivateFeedModel::userList('at://did:plc:alice/app.bsky.graph.list/1', 'One'));
        $this->privateFeeds()->create($account, PrivateFeedModel::customFeed('at://did:plc:bob/app.bsky.feed.generator/f', 'F'));

        /** @var KnownSocialCollectionRepository $known */
        $known = $this->entityManager->getRepository(KnownSocialCollection::class);
        self::assertSame(
            ['list:at://did:plc:alice/app.bsky.graph.list/1' => true, 'custom_feed:at://did:plc:bob/app.bsky.feed.generator/f' => true],
            $known->keysOf($account),
        );
    }
```

- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Social/PrivateFeedsTest.php --filter Remembered` — Expected: FAIL.

- [ ] **Step 3: Implement**

`PrivateFeeds`: add constructor parameter `private KnownSocialCollectionRepository $knownCollections,` (after `$socialFeeds`); in `createOnce()` before the final `flush()`:
```php
        if (SocialFeedKind::Home !== $privateFeed->kind) {
            $this->knownCollections->remember($account, $privateFeed->kind, $privateFeed->remoteId);
        }
```
(Home is never synced, so it is never remembered.) `PrivateFeedsWiring::build()` passes `$entityManager->getRepository(KnownSocialCollection::class)` (assert type).

- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Social` — Expected: PASS.

- [ ] **Step 5: Commit**
```bash
git add backend/src/Service/Social/PrivateFeeds.php backend/tests/Support/PrivateFeedsWiring.php backend/tests/Service/Social/PrivateFeedsTest.php
git commit -m "feat(#1666): a list or feed the reader adds is remembered for the sync"
```

---

### Task 13: Syncing one account — `PrivateFeedSync`

**Files:**
- Create: `backend/src/Service/Social/Pass/CollectionSyncPlan.php`, `backend/src/Service/Social/Support/PrivateFeedSyncReasons.php`, `backend/src/Service/Social/PrivateFeedSync.php`, `backend/src/Service/Social/PrivateFeedSyncApplier.php`, `backend/src/Service/Social/Model/PrivateFeedSyncOutcomeModel.php`
- Modify: `backend/src/Repository/SocialFeedRepository.php` (add `subscribedCollectionsOfKinds`)
- Create (tests): `backend/tests/Support/PrivateFeedSyncWiring.php` — builds `PrivateFeedSync` by hand; Task 14 adds its sweep and reuses it in `MaintenanceTickTest`
- Test: create `backend/tests/Service/Social/Pass/CollectionSyncPlanTest.php`, `backend/tests/Service/Social/PrivateFeedSyncTest.php`, `backend/tests/Service/Social/Support/PrivateFeedSyncReasonsTest.php`

**Interfaces — Consumes:** Tasks 10–12; `PrivateFeeds::create()` (`PrivateFeeds.php:37`); `SubscriptionService::unsubscribeAll()`; `SubscriptionRepository::findAllByIdsForUser(int, list<int>)` (`SubscriptionRepository.php:109`); `ActingAccountGuard::actAs()` (`ActingAccountGuard.php:31`); `SocialCandidatesInterface::collections()`.
**Produces:**
```php
final class CollectionSyncPlan {   // Pass, built with new per account
    /**
     * @param array<string, PrivateFeedModel> $onNetwork   by key
     * @param array<string, int>              $subscribed  subscription id by key (allow-listed kinds only)
     * @param array<string, true>             $known
     */
    public function __construct(array $onNetwork, array $subscribed, array $known, bool $isBaseline);
    /** @return list<PrivateFeedModel> */ public function additions(): array;      // none on a baseline
    /** @return array<string, int> */     public function removals(): array;       // subscription id by key
    /** @return list<PrivateFeedModel> */ public function toRemember(): array;     // baseline: on network ∪ subscribed; else subscribed not yet known
}
final class PrivateFeedSyncReasons {   // Support
    public static function added(SocialAccount $account, PrivateFeedModel $collection): PrivateFeedSyncReason;
    public static function removed(SocialAccount $account, string $key, NetworkCollectionsModel $network): PrivateFeedSyncReason;
}
final readonly class PrivateFeedSyncOutcomeModel {
    public function __construct(public int $added = 0, public int $removed = 0, public ?PrivateFeedSyncSkip $skip = null) {}
}
final readonly class PrivateFeedSync {
    public const array SYNCED_KINDS = [SocialFeedKind::UserList, SocialFeedKind::CustomFeed];
    public const int KEPT_CHANGES = 10;
    public function sync(SocialAccount $account): PrivateFeedSyncOutcomeModel;
}
// SocialFeedRepository
/** @param list<SocialFeedKind> $kinds @return list<array{kind: SocialFeedKind, remoteId: string, subscriptionId: int}> */
public function subscribedCollectionsOfKinds(SocialAccount $account, array $kinds): array;
```

- [ ] **Step 1: Write the failing tests**

`CollectionSyncPlanTest` (pure unit):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Pass;

use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Pass\CollectionSyncPlan;
use PHPUnit\Framework\TestCase;

final class CollectionSyncPlanTest extends TestCase
{
    private const string NEWS = 'at://did:plc:alice/app.bsky.graph.list/news';
    private const string ART = 'at://did:plc:bob/app.bsky.feed.generator/art';
    private const string DROPPED = 'at://did:plc:alice/app.bsky.graph.list/dropped';

    public function testANewCollectionIsAddedAndADroppedOneIsNot(): void
    {
        $plan = new CollectionSyncPlan(
            self::network([PrivateFeedModel::userList(self::NEWS, 'News'), PrivateFeedModel::userList(self::DROPPED, 'Dropped')]),
            [],
            ['list:' . self::DROPPED => true],
            false,
        );

        self::assertEquals([PrivateFeedModel::userList(self::NEWS, 'News')], $plan->additions());
        self::assertSame([], $plan->removals());
    }

    public function testASubscribedCollectionGoneFromTheNetworkIsRemoved(): void
    {
        $plan = new CollectionSyncPlan(self::network([]), ['custom_feed:' . self::ART => 12], ['custom_feed:' . self::ART => true], false);

        self::assertSame(['custom_feed:' . self::ART => 12], $plan->removals());
    }

    public function testACompleteEmptyAnswerStillRemoves(): void
    {
        $plan = new CollectionSyncPlan([], ['list:' . self::NEWS => 3], [], false);

        self::assertSame(['list:' . self::NEWS => 3], $plan->removals());
    }

    public function testABaselineAddsNothingAndRemembersEverythingItSees(): void
    {
        $plan = new CollectionSyncPlan(
            self::network([PrivateFeedModel::userList(self::NEWS, 'News')]),
            ['custom_feed:' . self::ART => 4],
            [],
            true,
        );

        self::assertSame([], $plan->additions());
        self::assertEquals(
            [PrivateFeedModel::userList(self::NEWS, 'News'), PrivateFeedModel::customFeed(self::ART, '')],
            $plan->toRemember(),
        );
    }

    public function testASubscribedCollectionNotYetKnownIsRememberedOutsideABaseline(): void
    {
        $plan = new CollectionSyncPlan(
            self::network([PrivateFeedModel::userList(self::NEWS, 'News')]),
            ['list:' . self::NEWS => 5],
            [],
            false,
        );

        self::assertSame([], $plan->additions());
        self::assertEquals([PrivateFeedModel::userList(self::NEWS, 'News')], $plan->toRemember());
    }

    /**
     * @param list<PrivateFeedModel> $collections
     *
     * @return array<string, PrivateFeedModel>
     */
    private static function network(array $collections): array
    {
        $byKey = [];
        foreach ($collections as $collection) {
            $byKey[$collection->key()] = $collection;
        }

        return $byKey;
    }
}
```
(`toRemember()` for a subscribed key absent from the network rebuilds the model with `PrivateFeedModel::of($kind, $remoteId, '')` — the name is never stored in the memory. Parse the key back with `explode(':', $key, 2)` and `SocialFeedKind::from()` inside the plan; write that as a private static `modelOf(string $key): PrivateFeedModel`. The plan's fourth constructor parameter is a bool *state* (the account was never checked), not a behaviour flag on a method; if the reviewer objects, replace it by two named constructors `CollectionSyncPlan::baseline(...)` / `CollectionSyncPlan::following(...)`.)

`PrivateFeedSyncReasonsTest`: a Bluesky custom feed added → `Saved`; an owned Bluesky list (`at://<account DID>/…`, `SocialAccounts::bluesky()` has DID `did:plc:alice`) → `Created`; someone else's list → `Saved`; a Mastodon list → `Created`. Removed: a key whose id is in `goneIds` → `Deleted`; an owned Bluesky list absent → `Deleted`; a Mastodon list absent → `Deleted`; a Bluesky custom feed absent and not gone → `Unsaved`; someone else's list absent → `Unsaved`.

`backend/tests/Support/PrivateFeedSyncWiring.php` — nothing injects `PrivateFeedSync` or its applier until Task 14, so the test container has removed them (pre-flight note 4); build them by hand over one shared clock:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\KnownSocialCollection;
use App\Entity\PrivateFeedSyncChange;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Repository\KnownSocialCollectionRepository;
use App\Repository\PrivateFeedSyncChangeRepository;
use App\Repository\SocialFeedRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\PrivateFeedSync;
use App\Service\Social\PrivateFeedSyncApplier;
use App\Service\Social\SocialCandidates\SocialCandidatesInterface;
use App\Service\Subscription\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

/** PrivateFeedSync over the test's entity manager, clock and network double; the container's SubscriptionService. */
final class PrivateFeedSyncWiring
{
    public static function sync(
        ContainerInterface $container,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
        SocialCandidatesInterface $network,
    ): PrivateFeedSync {
        /** @var SocialFeedRepository $socialFeeds */
        $socialFeeds = $entityManager->getRepository(SocialFeed::class);
        /** @var KnownSocialCollectionRepository $known */
        $known = $entityManager->getRepository(KnownSocialCollection::class);
        /** @var PrivateFeedSyncChangeRepository $changes */
        $changes = $entityManager->getRepository(PrivateFeedSyncChange::class);
        /** @var SubscriptionRepository $subscriptions */
        $subscriptions = $entityManager->getRepository(Subscription::class);
        $subscriptionService = $container->get(SubscriptionService::class);
        Assert::assertInstanceOf(SubscriptionService::class, $subscriptionService);
        $naiveClock = new NaiveUtcClock($clock);

        return new PrivateFeedSync(
            $network,
            new ActingAccountGuard($entityManager),
            new PrivateFeedSyncApplier(
                $socialFeeds,
                $known,
                $changes,
                PrivateFeedsWiring::build($container, $entityManager, $clock),
                $subscriptionService,
                $subscriptions,
                $naiveClock,
                $entityManager,
            ),
            $socialFeeds,
            $naiveClock,
            $entityManager,
        );
    }

    private function __construct()
    {
    }
}
```
`PrivateFeedSyncTest` (DbTestCase; `$this->clock = new MockClock('2026-10-17 12:00:00', 'UTC')`; `PrivateFeedSyncWiring::sync(self::getContainer(), $this->entityManager, $this->clock, $stub)` with `$stub = new StubSocialCandidates(new NetworkCandidatesModel([], [], [], false), <the case's NetworkCollectionsModel or \Throwable>)`; seed collections through `PrivateFeedsWiring::build(...)->create(...)` as `PrivateFeedsTest` does, `backend/tests/Service/Social/PrivateFeedsTest.php:191-205`). Cases, each asserting subscriptions, `KnownSocialCollection` keys, `PrivateFeedSyncChange` rows and the account stamp:
1. `testTheFirstCheckTakesABaselineAndAddsNothing` — network has News; nothing subscribed → no subscription created, News known, stamp set, skip null, no change rows.
2. `testANewlySavedFeedIsAddedWithImportDefaultsAndLogged` — stamp set an hour earlier (`recordSync`), network adds Art → a subscription to `social:bluesky:<id>:feed:<uri>` with `includeInAllItems`/`includeInForYou` false and no tags; one change `Saved`, name `Art`.
3. `testAnUnsavedFeedIsRemovedSilentlyAndItsFavouritesSurvive` — Art subscribed (through `PrivateFeeds::create`), a favourited entry in it, network now without Art → subscription gone, Art forgotten, change `Unsaved`, the entry's copy in `saved:<userId>:bluesky` (proves the rescue path).
4. `testADroppedListIsNotAddedBack` — News created via `PrivateFeeds::create` then unsubscribed via `SubscriptionService::unsubscribe`, network still has News → no subscription.
5. `testHomeAndAnyKindOffTheAllowListAreNeverTouched` — **the allow-list proof**: create Home (`PrivateFeedModel::home()`), stamp set, network answers `NetworkCollectionsModel([], [], [], false)` → Home's subscription still exists, no change rows. Add an assertion `self::assertSame([SocialFeedKind::UserList, SocialFeedKind::CustomFeed], PrivateFeedSync::SYNCED_KINDS)` so adding a kind to the list is a deliberate edit. (When the parallel session's new kind is on `develop`, add a second row of that kind next to Home in this test.)
6. `testAnUnreachableNetworkSkipsTheAccountAndChangesNothing` — stub throws `SocialNetworkUnreachableException` → skip `Unreachable`, stamp set, subscription untouched.
7. `testATruncatedAnswerSkipsTheAccount` — `truncated: true` → skip `Incomplete`, nothing removed.
8. `testARefusedCredentialMarksTheAccountAndSkips` — stub throws `SocialCredentialsRejectedException` → account `NeedsReconnect`, skip `NeedsReconnect`.
9. `testAnAccountThatNeedsReconnectingIsSkippedWithoutAsking` — `markNeedsReconnect()` first → stub `calls` stays 0, skip `NeedsReconnect`.
10. `testTheLogKeepsTheTenNewestChanges` — 11 additions in one check (11 custom feeds) → 10 rows remain.
11. `testAFullAccountSkipsAnAdditionAndTriesAgainNextTime` — `setMaxSubscriptions(0)`, network adds Art → no subscription, Art **not** known (so the next hour retries), no change row.
12. `testAFailedFirstCheckLeavesTheBaselineToTheFirstCompleteOne` — never synced; first check: stub throws `SocialNetworkUnreachableException` → skip `Unreachable`, `getLastCheckedAt()` set, `getLastSyncedAt()` still null. Advance the clock 2 h, second check answers News and Art → **no subscription created**, News and Art both known, `getLastSyncedAt()` set. (Two `PrivateFeedSyncWiring::sync()` builds over two stubs, one per check.)
13. `testWithoutTheSavedFeedsPreferenceASubscribedCustomFeedSkipsTheAccount` — Art subscribed and synced once; network `new NetworkCollectionsModel([], [], [], false, savedFeedsKnown: false)` → skip `Incomplete`, Art still subscribed. And with no custom feed subscribed, the same answer completes (a list-only account on the legacy preference still syncs its lists).

- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Social/Pass/CollectionSyncPlanTest.php tests/Service/Social/Support/PrivateFeedSyncReasonsTest.php tests/Service/Social/PrivateFeedSyncTest.php` — Expected: FAIL.

- [ ] **Step 3: Implement**

`SocialFeedRepository::subscribedCollectionsOfKinds()` — `subscribedCollectionsOf()` (lines 82-98) plus `->andWhere('sf.kind IN (:kinds)')->setParameter('kinds', $kinds)`; make `subscribedCollectionsOf()` delegate with every kind (`SocialFeedKind::cases()`) so there is one query.

`CollectionSyncPlan` (Pass):
```php
final class CollectionSyncPlan
{
    /**
     * @param array<string, PrivateFeedModel> $onNetwork
     * @param array<string, int>              $subscribed
     * @param array<string, true>             $known
     */
    public function __construct(
        private readonly array $onNetwork,
        private readonly array $subscribed,
        private readonly array $known,
        private readonly bool $isBaseline,
    ) {
    }

    /** @return list<PrivateFeedModel> */
    public function additions(): array
    {
        if ($this->isBaseline) {
            return [];
        }

        return array_values(array_diff_key($this->onNetwork, $this->subscribed, $this->known));
    }

    /** @return array<string, int> */
    public function removals(): array
    {
        return array_diff_key($this->subscribed, $this->onNetwork);
    }

    /** @return list<PrivateFeedModel> */
    public function toRemember(): array
    {
        $seen = $this->isBaseline ? $this->onNetwork : [];
        foreach (array_keys(array_diff_key($this->subscribed, $this->removals())) as $key) {
            $seen[$key] ??= $this->onNetwork[$key] ?? self::modelOf($key);
        }

        return array_values(array_diff_key($seen, $this->known));
    }

    private static function modelOf(string $key): PrivateFeedModel
    {
        [$kind, $remoteId] = explode(':', $key, 2);

        return PrivateFeedModel::of(SocialFeedKind::from($kind), $remoteId, '');
    }
}
```
`additions()` relies on `array_diff_key` with three arrays (keys present in neither of the others). The baseline expectation in `CollectionSyncPlanTest::testABaselineAddsNothingAndRemembersEverythingItSees` lists News first, then Art — the order this loop produces.

`PrivateFeedSyncReasons` (Support):
```php
    public static function added(SocialAccount $account, PrivateFeedModel $collection): PrivateFeedSyncReason
    {
        return self::isOwnedList($account, $collection->kind, $collection->remoteId)
            ? PrivateFeedSyncReason::Created
            : PrivateFeedSyncReason::Saved;
    }

    public static function removed(SocialAccount $account, string $key, NetworkCollectionsModel $network): PrivateFeedSyncReason
    {
        [$kind, $remoteId] = explode(':', $key, 2);
        $feedKind = SocialFeedKind::from($kind);
        if (\in_array($remoteId, $network->goneIds, true) || self::isOwnedList($account, $feedKind, $remoteId)) {
            return PrivateFeedSyncReason::Deleted;
        }

        return PrivateFeedSyncReason::Unsaved;
    }

    /** A Mastodon list is always the account's own; a Bluesky list is when its at-URI names the account's DID. */
    private static function isOwnedList(SocialAccount $account, SocialFeedKind $kind, string $remoteId): bool
    {
        if (SocialFeedKind::UserList !== $kind) {
            return false;
        }

        return match ($account->getNetwork()) {
            SocialNetwork::Mastodon => true,
            SocialNetwork::Bluesky => str_starts_with($remoteId, 'at://' . $account->getIdentity() . '/'),
        };
    }
```

`PrivateFeedSync`:
```php
final readonly class PrivateFeedSync
{
    public const array SYNCED_KINDS = [SocialFeedKind::UserList, SocialFeedKind::CustomFeed];
    public const int KEPT_CHANGES = 10;

    public function __construct(
        private SocialCandidatesInterface $networkCollections,
        private ActingAccountGuard $guard,
        private PrivateFeedSyncApplier $applier,
        private SocialFeedRepository $socialFeeds,
        private NaiveUtcClock $clock,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function sync(SocialAccount $account): PrivateFeedSyncOutcomeModel
    {
        try {
            $network = $this->guard->actAs($account, fn (): NetworkCollectionsModel => $this->networkCollections->collections($account));
        } catch (SocialAccountNeedsReconnectException | SocialCredentialsRejectedException) {
            return $this->skip($account, PrivateFeedSyncSkip::NeedsReconnect);
        } catch (SocialNetworkUnreachableException) {
            return $this->skip($account, PrivateFeedSyncSkip::Unreachable);
        }
        if ($network->truncated || $this->losesUnknownCustomFeeds($account, $network)) {
            return $this->skip($account, PrivateFeedSyncSkip::Incomplete);
        }

        return $this->applier->apply($account, $network);
    }

    /** An account without savedFeedsPrefV2 lists no custom feed: absent there is not unsaved. */
    private function losesUnknownCustomFeeds(SocialAccount $account, NetworkCollectionsModel $network): bool
    {
        return !$network->savedFeedsKnown
            && [] !== $this->socialFeeds->subscribedCollectionsOfKinds($account, [SocialFeedKind::CustomFeed]);
    }

    private function skip(SocialAccount $account, PrivateFeedSyncSkip $skip): PrivateFeedSyncOutcomeModel
    {
        $account->recordSkippedSync($this->clock->now(), $skip);
        $this->entityManager->flush();

        return new PrivateFeedSyncOutcomeModel(skip: $skip);
    }
}
```
and the writing half as its own service `backend/src/Service/Social/PrivateFeedSyncApplier.php` (add to the File structure; keeps each class small for PHPMD):
```php
final readonly class PrivateFeedSyncApplier
{
    public function __construct(
        private SocialFeedRepository $socialFeeds,
        private KnownSocialCollectionRepository $knownCollections,
        private PrivateFeedSyncChangeRepository $changes,
        private PrivateFeeds $privateFeeds,
        private SubscriptionService $subscriptionService,
        private SubscriptionRepository $subscriptions,
        private NaiveUtcClock $clock,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function apply(SocialAccount $account, NetworkCollectionsModel $network): PrivateFeedSyncOutcomeModel
    {
        $plan = new CollectionSyncPlan(
            self::byKey($network),
            $this->subscribedOf($account),
            $this->knownCollections->keysOf($account),
            null === $account->getLastSyncedAt(),
        );

        $this->entityManager->beginTransaction();
        try {
            $outcome = $this->applied($account, $network, $plan);
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }

        return $outcome;
    }

    private function applied(SocialAccount $account, NetworkCollectionsModel $network, CollectionSyncPlan $plan): PrivateFeedSyncOutcomeModel
    {
        $removed = $this->remove($account, $network, $plan->removals());
        $added = $this->add($account, $plan->additions());
        foreach ($plan->toRemember() as $collection) {
            $this->knownCollections->remember($account, $collection->kind, $collection->remoteId);
        }
        $account->recordSync($this->clock->now());
        $this->entityManager->flush();
        $this->changes->trimTo($account, PrivateFeedSync::KEPT_CHANGES);

        return new PrivateFeedSyncOutcomeModel($added, $removed);
    }
    …
}
```
Private helpers:
- `subscribedOf(SocialAccount): array<string, int>` from `$this->socialFeeds->subscribedCollectionsOfKinds($account, PrivateFeedSync::SYNCED_KINDS)` keyed by `PrivateFeedModel::keyOf()`.
- `byKey(NetworkCollectionsModel): array<string, PrivateFeedModel>`: lists as `PrivateFeedModel::userList`, custom feeds as `customFeed`, keyed by `key()`. Only allow-listed kinds can come out of it by construction.
- `remove(SocialAccount, NetworkCollectionsModel, array<string,int> $removals): int`: if empty → 0; `$this->subscriptionService->unsubscribeAll($this->subscriptions->findAllByIdsForUser($account->getUser()->requireId(), array_values($removals)))`; for each key: `forget()` it and persist a `PrivateFeedSyncChange` with `PrivateFeedSyncReasons::removed($account, $key, $network)` and the subscription's display title as `name` (read titles *before* unsubscribing: `SubscriptionDisplayTitle::from(customTitle, feed title, url)` of each found subscription).
- `add(SocialAccount, list<PrivateFeedModel>): int`: for each, `try { $outcome = $this->privateFeeds->create($account, $collection); } catch (SubscriptionLimitReachedException) { continue; }`; on `PrivateFeedOutcome::Created` persist a change with `PrivateFeedSyncReasons::added()`, count it. (`create()` remembers it — Task 12 — so the full-account case stays unremembered automatically.)
The `name` of an addition is the collection's name; truncate names to 512 with `mb_substr` in the `PrivateFeedSyncChange` constructor.

8 constructor parameters on the applier — under PHPMD's 10. `beginTransaction()`/`rollback()` rather than `wrapInTransaction()` (Decisions, "Transactions"); `SubscriptionService::unsubscribeAll()` and `PrivateFeeds::create()` nest their own levels inside it. The baseline flag is `null === $account->getLastSyncedAt()`, which only a complete check sets (Task 10), so a skipped first check never turns the next complete one into an "add everything". If phptramp reports `$account` threading `apply → remove → …`, that is a value every hop reads, which phptramp does not count.

- [ ] **Step 4: Run** the Step 2 command, then `php bin/phpunit tests/Service/Social tests/Service/SavedPosts` and the MySQL leg `docker compose exec php php bin/phpunit tests/Service/Social/PrivateFeedSyncTest.php` — Expected: PASS.

- [ ] **Step 5: Commit**
```bash
git add backend/src/Service/Social backend/src/Repository/SocialFeedRepository.php backend/tests/Service/Social backend/tests/Support/PrivateFeedSyncWiring.php
git commit -m "feat(#1666): one account's lists and feeds follow the network, silently and logged"
```

---

### Task 14: Run it hourly — worker, maintenance tick, budgets

**Files:**
- Create: `backend/src/Service/Social/Model/PrivateFeedSyncBudgetModel.php`, `backend/src/Service/Social/Model/PrivateFeedSyncReportModel.php`, `backend/src/Service/Social/PrivateFeedSyncSweep.php`, `backend/src/Service/Social/SocialMaintenance.php`, `backend/src/Service/Worker/Message/SyncPrivateFeeds.php`, `backend/src/Service/Worker/Handler/SyncPrivateFeedsHandler.php`
- Modify: `backend/src/Service/Worker/WorkerSchedule.php:41-51`, `backend/src/Service/Maintenance/MaintenanceTick.php:20,40-88`, `backend/src/Service/Maintenance/Model/MaintenanceSweepsModel.php`, `backend/src/Http/MaintenanceTickJson.php:24-45`
- Modify tests: `backend/tests/Service/Maintenance/MaintenanceTickTest.php:248-296`, `backend/tests/Http/MaintenanceTickJsonTest.php:22-72`, `backend/tests/Service/Worker/WorkerScheduleWiringTest.php:28-77`, `backend/tests/Support/PrivateFeedSyncWiring.php` (add `sweep()`)
- Test: create `backend/tests/Service/Social/PrivateFeedSyncSweepTest.php`, `backend/tests/Service/Social/Model/PrivateFeedSyncBudgetModelTest.php`, `backend/tests/Service/Worker/SyncPrivateFeedsHandlerTest.php`

**Interfaces — Produces:**
```php
final readonly class PrivateFeedSyncBudgetModel {
    /** What the tick asks to have left before it starts one account (Open question 7): typical checks take 1–3 s. */
    public const int TICK_ACCOUNT_SECONDS = 5;
    public static function accounts(int $count): self;
    public static function fittingBefore(\DateTimeImmutable $deadline, \DateTimeImmutable $now, int $maxAccounts): self;
    public function isEmpty(): bool;
    public int $accounts;
}
final readonly class PrivateFeedSyncReportModel {
    public function __construct(public int $checked, public int $skipped, public int $added, public int $removed) {}
    public static function none(): self; public function adding(PrivateFeedSyncOutcomeModel $outcome): self;
    /** @return array{checked: int, skipped: int, added: int, removed: int} */ public function toLogContext(): array;
}
final readonly class PrivateFeedSyncSweep {
    public const string DUE_AFTER = '-1 hour';
    /** One account's failure is logged and stamped `failed`; the sweep stops early only when that failure closed the entity manager. */
    public function sweep(PrivateFeedSyncBudgetModel $budget): PrivateFeedSyncReportModel;
}
final readonly class SocialMaintenance {   // the tick's two social halves, called at different points of the tick
    public function refreshProfileImages(\DateTimeImmutable $deadline): ProfileImageSweepReportModel;
    public function syncPrivateFeeds(\DateTimeImmutable $deadline): PrivateFeedSyncReportModel;
}
// MaintenanceSweepsModel gains `public PrivateFeedSyncReportModel $privateFeedSync` right after $profileImages
// MaintenanceTickJson key 'privateFeedSync' => ['accountsChecked' => …, 'accountsSkipped' => …, 'added' => …, 'removed' => …]
// tests/Support/PrivateFeedSyncWiring
public static function sweep(ContainerInterface $container, EntityManagerInterface $entityManager, ClockInterface $clock, SocialCandidatesInterface $network, LoggerInterface $logger = new NullLogger()): PrivateFeedSyncSweep;
```

- [ ] **Step 1: Write the failing tests**

`PrivateFeedSyncBudgetModelTest`: mirror `backend/tests/Service/Social/Model/ProfileImageBudgetModelTest.php` (read it) — 12 s left, max 3 → 2 accounts; 4 s → empty; 30 s, max 1 → 1; negative count → `\InvalidArgumentException`.

`PrivateFeedSyncSweepTest` (DbTestCase, `MockClock('2026-10-17 12:00:00', 'UTC')`; one user from `UserFactory` — active by default — and three accounts `SocialAccounts::blueskyAs($user, 'did:plc:sweep-1')`, `…sweep-2`, `…sweep-3`, the identity differing because `uniq_social_account_identity` allows one per user; sweep from `PrivateFeedSyncWiring::sweep(...)`):
1. Never checked, checked at `11:30`, checked at `10:30` (`recordSync`) — `accounts(10)` over a stub answering `new NetworkCollectionsModel([], [])` → report `checked: 2`; the 11:30 account's stamp unchanged; `accounts(1)` checks only the never-checked one.
2. An empty budget asks nothing: `StubSocialCandidates::$calls === 0` (`collections()` counts calls, Task 11).
3. `testAnAccountWhoseCheckThrowsIsStampedFailedAndTheSweepGoesOn` — two due accounts, stub `new StubSocialCandidates(new NetworkCandidatesModel([], [], [], false), new \ValueError('broken answer'))`, a `RecordingLogger` (`backend/tests/Support/RecordingLogger.php`) → report `checked: 2, skipped: 2`; both accounts reloaded (`$this->entityManager->clear()` then `find`) show `getLastSyncSkip() === PrivateFeedSyncSkip::Failed` and a `getLastCheckedAt()`; two `error` records; `$this->entityManager->isOpen()` stays true.

`MaintenanceTickJsonTest`: add `new PrivateFeedSyncReportModel(20, 21, 22, 23)` as the new 6th constructor argument and expect `'privateFeedSync' => ['accountsChecked' => 20, 'accountsSkipped' => 21, 'added' => 22, 'removed' => 23]` right after `'profileImages'`; the skipped-tick test expects `['accountsChecked' => 0, 'accountsSkipped' => 0, 'added' => 0, 'removed' => 0, 'skipped' => MaintenanceTickJson::SKIPPED_REASON]`.

`WorkerScheduleWiringTest::testTheWorkerScheduleCarriesExactlyTheDecidedEntries()` (lines 28-77): `assertCount(11, …)`, append `SyncPrivateFeeds::class` after `RefreshProfileImages::class` in the class list and `'every 10 minutes'` at the end of the frequency list; add the `use` line. Do not create a second schedule test.

`backend/tests/Service/Worker/SyncPrivateFeedsHandlerTest.php`, modelled on `RefreshProfileImagesHandlerTest` (same folder):
```php
final class SyncPrivateFeedsHandlerTest extends DbTestCase
{
    public function testFiringSweepsAndLogsTheReport(): void
    {
        $sweep = PrivateFeedSyncWiring::sweep(
            self::getContainer(),
            $this->entityManager,
            new MockClock('2026-10-17 12:00:00', 'UTC'),
            new StubSocialCandidates(new SocialNetworkUnreachableException('no network in this test')),
        );
        $logger = new RecordingLogger();

        (new SyncPrivateFeedsHandler($sweep, $logger))(new SyncPrivateFeeds());

        self::assertCount(1, $logger->records);
        self::assertSame('Worker private feed sync finished.', $logger->records[0]['message']);
        self::assertSame(['checked' => 0, 'skipped' => 0, 'added' => 0, 'removed' => 0], $logger->records[0]['context']['report']);
    }
}
```
(DAMA isolates the test, so no account is due — the zeros are exact.)

`MaintenanceTickTest::tickAround()`: replace `$this->profileImageSweep(...)` with
```php
            new SocialMaintenance(
                $this->profileImageSweep($appViewFetcher ?? self::unreachableAppView(), $clock),
                PrivateFeedSyncWiring::sweep(
                    self::getContainer(),
                    $this->entityManager,
                    $clock,
                    new StubSocialCandidates(new SocialNetworkUnreachableException('no network in this test')),
                ),
                $clock,
            ),
```
No test may reach a network; an unreachable stub keeps any seeded account out of it.

- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Social tests/Service/Maintenance tests/Http/MaintenanceTickJsonTest.php tests/Service/Worker` — Expected: FAIL.

- [ ] **Step 3: Implement**

`PrivateFeedSyncBudgetModel` — copy `ProfileImageBudgetModel` (`backend/src/Service/Social/Model/ProfileImageBudgetModel.php`) with `accounts` for `calls` and `TICK_ACCOUNT_SECONDS` for `CALL_SECONDS`.

`PrivateFeedSyncSweep`:
```php
final readonly class PrivateFeedSyncSweep
{
    public const string DUE_AFTER = '-1 hour';

    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private PrivateFeedSync $privateFeedSync,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function sweep(PrivateFeedSyncBudgetModel $budget): PrivateFeedSyncReportModel
    {
        $report = PrivateFeedSyncReportModel::none();
        if ($budget->isEmpty()) {
            return $report;
        }
        $dueBefore = $this->clock->now()->modify(self::DUE_AFTER);
        foreach ($this->socialAccounts->findIdsDueForSync($dueBefore, $budget->accounts) as $accountId) {
            $account = $this->socialAccounts->find($accountId);
            if (null !== $account) {
                $report = $report->adding($this->syncIsolated($account));
            }
            if (!$this->entityManager->isOpen()) {
                break;
            }
        }

        return $report;
    }

    /** One account's surprise must not starve the sweep: it is logged and stamped, and the next account starts clean. */
    private function syncIsolated(SocialAccount $account): PrivateFeedSyncOutcomeModel
    {
        try {
            return $this->privateFeedSync->sync($account);
        } catch (\Throwable $exception) {
            $this->logger->error('Private feed sync failed for an account.', [
                'accountId' => $account->requireId(),
                'exception' => $exception,
            ]);
            $this->socialAccounts->recordFailedSync($account->requireId(), $this->clock->now());
            if ($this->entityManager->isOpen()) {
                $this->entityManager->clear();
            }

            return new PrivateFeedSyncOutcomeModel(skip: PrivateFeedSyncSkip::Failed);
        }
    }
}
```
(Accounts are loaded one id at a time because `clear()` detaches everything; a closed entity manager — a failed flush closes it — ends the sweep after the stamp, which `recordFailedSync()` writes through the connection. The tick calls the sweep last, so nothing after it needs the entity manager; the worker handles one message per run.)

`PrivateFeedSyncReportModel::adding()` increments `checked` always, `skipped` when `$outcome->skip !== null`, and sums `added`/`removed`.

`SocialMaintenance`:
```php
/** The maintenance tick's social halves: profile pictures where they ran before, the private feeds' sync last. */
final readonly class SocialMaintenance
{
    private const int PROFILE_IMAGE_MAX_CALLS = 1;
    private const int PRIVATE_FEED_MAX_ACCOUNTS = 1;

    public function __construct(
        private ProfileImageSweep $profileImageSweep,
        private PrivateFeedSyncSweep $privateFeedSyncSweep,
        private ClockInterface $clock,
    ) {
    }

    public function refreshProfileImages(\DateTimeImmutable $deadline): ProfileImageSweepReportModel
    {
        return $this->profileImageSweep->sweep(
            ProfileImageBudgetModel::fittingBefore($deadline, $this->clock->now(), self::PROFILE_IMAGE_MAX_CALLS),
        );
    }

    public function syncPrivateFeeds(\DateTimeImmutable $deadline): PrivateFeedSyncReportModel
    {
        return $this->privateFeedSyncSweep->sweep(
            PrivateFeedSyncBudgetModel::fittingBefore($deadline, $this->clock->now(), self::PRIVATE_FEED_MAX_ACCOUNTS),
        );
    }
}
```
`MaintenanceTick`: constructor `ProfileImageSweep $profileImageSweep` → `SocialMaintenance $socialMaintenance` (still 9 parameters); drop `PROFILE_IMAGE_MAX_CALLS`; `sweep()` becomes
```php
    private function sweep(\DateTimeImmutable $deadline): MaintenanceSweepsModel
    {
        $recommendations = $this->forYouSweep->sweepOnce();
        $digests = $this->sendDueDigests->run();
        $imageVerification = $this->imageVerificationSweep->verifyDue();
        $linkCards = $this->linkCardSweep->sweep(
            LinkCardBudgetModel::fittingBefore($deadline, $this->clock->now(), self::LINK_CARD_MAX_FETCHES),
        );
        $profileImages = $this->socialMaintenance->refreshProfileImages($deadline);
        $budget = SweepBudgetModel::remainingUntil($deadline, $this->clock->now(), self::MEMBERSHIP_BUDGET_SECONDS);
        $memberships = $this->membershipSweep->sweep($budget);

        return new MaintenanceSweepsModel(
            $recommendations,
            $digests,
            $imageVerification,
            $linkCards,
            $profileImages,
            $this->socialMaintenance->syncPrivateFeeds($deadline),
            $memberships,
        );
    }
```
(The sync runs last: its network calls cannot eat the membership sweep's window, and a failure in it cannot close the entity manager for a later sweep.) `MaintenanceSweepsModel`: new constructor parameter `public PrivateFeedSyncReportModel $privateFeedSync,` after `$profileImages`; `skippedAfterAbortedRefresh()` passes `PrivateFeedSyncReportModel::none()`. `MaintenanceTickJson`: `'privateFeedSync' => self::markedIfSkipped($sweeps, self::privateFeedSync($sweeps->privateFeedSync)),` after `profileImages`, with
```php
    /** @return array{accountsChecked: int, accountsSkipped: int, added: int, removed: int} */
    private static function privateFeedSync(PrivateFeedSyncReportModel $report): array
    {
        return [
            'accountsChecked' => $report->checked,
            'accountsSkipped' => $report->skipped,
            'added' => $report->added,
            'removed' => $report->removed,
        ];
    }
```
Worker: `SyncPrivateFeeds` (property-less `final readonly class`, as `RefreshProfileImages`), `SyncPrivateFeedsHandler` (as `RefreshProfileImagesHandler`, `ACCOUNTS = 10` passed as `PrivateFeedSyncBudgetModel::accounts(self::ACCOUNTS)` — the worker has no request window, so no time budget), logging `'Worker private feed sync finished.'` with `['report' => $report->toLogContext()]`. In `WorkerSchedule::getSchedule()` (lines 40-52) after the `RefreshProfileImages` line:
```php
            ->add(RecurringMessage::every('10 minutes', new SyncPrivateFeeds()))
```
`PrivateFeedSyncWiring::sweep()`:
```php
    public static function sweep(
        ContainerInterface $container,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
        SocialCandidatesInterface $network,
        LoggerInterface $logger = new NullLogger(),
    ): PrivateFeedSyncSweep {
        /** @var SocialAccountRepository $socialAccounts */
        $socialAccounts = $entityManager->getRepository(SocialAccount::class);

        return new PrivateFeedSyncSweep(
            $socialAccounts,
            self::sync($container, $entityManager, $clock, $network),
            $entityManager,
            new NaiveUtcClock($clock),
            $logger,
        );
    }
```

- [ ] **Step 4: Run** the Step 2 command and `php bin/phpunit tests/Controller` (maintenance endpoint tests) — Expected: PASS.

- [ ] **Step 5: Schedule and log check**: `docker compose exec php bin/console debug:scheduler` lists `SyncPrivateFeeds` last; scan `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .` for deprecations.

- [ ] **Step 6: Commit**
```bash
git add backend/src/Service/Social backend/src/Service/Worker backend/src/Service/Maintenance backend/src/Http/MaintenanceTickJson.php backend/tests
git commit -m "feat(#1666): the worker and the maintenance tick check each account's lists and feeds hourly"
```

---

### Task 15: Settings API — when each account was checked and what changed

**Files:**
- Create: `backend/src/Service/Social/PrivateFeedSyncLog.php`
- Modify: `backend/src/Http/SocialAccountJson.php`, `backend/src/Controller/Api/SocialAccountController.php:26-40`
- Modify tests: `backend/tests/Controller/Api/SocialAccountControllerTest.php:66-69` (key list)
- Test: append to `SocialAccountControllerTest`

**Interfaces — Produces:**
```php
final readonly class PrivateFeedSyncLog {
    /** @param list<SocialAccount> $accounts @return array<int, list<PrivateFeedSyncChange>> */
    public function recentOf(array $accounts): array;
    /** @return list<PrivateFeedSyncChange> */
    public function recentOfOne(SocialAccount $account): array;
}
// SocialAccountJson
/** @param list<SocialAccount> $accounts @param array<int, list<PrivateFeedSyncChange>> $recentChanges */
public function list(array $accounts, array $recentChanges): array;
/** @param list<PrivateFeedSyncChange> $recentChanges */
public function account(SocialAccount $account, array $recentChanges): array;  // no default: every caller says which changes
```
Account JSON gains (last key):
```json
"sync": {"checkedAt": "ATOM|null", "skipped": "unreachable|incomplete|needs_reconnect|failed|null",
         "changes": [{"kind": "list|custom_feed", "name": "News", "reason": "saved|created|unsaved|deleted", "added": true, "at": "ATOM"}]}
```

- [ ] **Step 1: Write the failing tests**

In `testConnectingBlueskyReturnsTheAccountWithoutTheSecret` extend the key list to `[…, 'connectedAt', 'sync']` and assert `['checkedAt' => null, 'skipped' => null, 'changes' => []]`. Append `testTheListShowsTheLastCheckAndTheRecentChangesNewestFirst`: connect, then in the container's entity manager stamp the account (`recordSkippedSync(new \DateTimeImmutable('2026-10-17 11:00:00'), PrivateFeedSyncSkip::Unreachable)`) and persist two `PrivateFeedSyncChange` rows (Saved at 10:00, Deleted at 10:30), flush, `GET /api/me/social-accounts` → `sync.checkedAt` `2026-10-17T11:00:00+00:00`, `skipped` `unreachable`, `changes[0].reason` `deleted` with `added: false`, `changes[1]` `saved` with `added: true`.

- [ ] **Step 2: Run** `php bin/phpunit tests/Controller/Api/SocialAccountControllerTest.php` — Expected: FAIL.

- [ ] **Step 3: Implement**

`PrivateFeedSyncLog::recentOf()` delegates to `PrivateFeedSyncChangeRepository::recentOf()`, and `recentOfOne($account)` returns `$this->recentOf([$account])[$account->requireId()] ?? []` (a service so the controller depends on the Social module, not a repository — the controllers in this tree inject services).

`SocialAccountJson::account()` adds `'sync' => $this->syncOf($account, $recentChanges)`:
```php
    /**
     * @param list<PrivateFeedSyncChange> $recentChanges
     *
     * @return array{checkedAt: string|null, skipped: string|null, changes: list<array<string, string|bool>>}
     */
    private function syncOf(SocialAccount $account, array $recentChanges): array
    {
        return [
            'checkedAt' => $account->getLastCheckedAt()?->format(\DateTimeInterface::ATOM),
            'skipped' => $account->getLastSyncSkip()?->value,
            'changes' => array_map(
                static fn (PrivateFeedSyncChange $change): array => [
                    'kind' => $change->getKind()->value,
                    'name' => $change->getName(),
                    'reason' => $change->getReason()->value,
                    'added' => $change->getReason()->isAddition(),
                    'at' => $change->getChangedAt()->format(\DateTimeInterface::ATOM),
                ],
                $recentChanges,
            ),
        ];
    }
```
`list()` passes `$recentChanges[$account->requireId()] ?? []` per account. The stored datetimes are naive UTC; `format(ATOM)` on a value Doctrine hydrated prints the PHP default zone — check `connectedAt`'s existing output (`SocialAccountControllerTest:76-77`) and follow whatever that path does (the app runs with UTC as its default timezone, which the existing `connectedAt` assertion relies on).

`SocialAccountController`: constructor gains `private PrivateFeedSyncLog $syncLog,`. The two connect actions (lines 49-51, 61-63) pass `[]` explicitly — a new account has no change yet (`$this->accountJson->account($account, [])`); `replaceCredential()` (line 81) passes the real log so a reconnect answers what `GET` answers:
```php
        $account = $this->connector->reconnect($account, $credentials);

        return new JsonResponse($this->accountJson->account($account, $this->syncLog->recentOfOne($account)));
```
Append to the controller test: `testAReconnectAnswersTheAccountWithItsRecentChanges` — connect, persist one change, reconnect (follow the existing reconnect test's request), expect `sync.changes` to hold it. `list()`:
```php
        $accounts = $this->editor->accountsOf($user);

        return new JsonResponse($this->accountJson->list($accounts, $this->syncLog->recentOf($accounts)));
```

- [ ] **Step 4: Run** `php bin/phpunit tests/Controller/Api tests/Http` — Expected: PASS. iOS checklist §6: GET, bearer, JSON, no new input — passes.

- [ ] **Step 5: Commit**
```bash
git add backend/src/Service/Social/PrivateFeedSyncLog.php backend/src/Http/SocialAccountJson.php backend/src/Controller/Api/SocialAccountController.php backend/tests/Controller/Api/SocialAccountControllerTest.php
git commit -m "feat(#1666): each social account says when it was last checked and what changed"
```

---

### Task 16: Frontend — untick to remove in the picker; last check and changes in Settings

**Files:**
- Modify: `frontend/src/app/settings/social/social-import-rows.ts:14-25,49-58,120-135`, `social-import-dialog.component.ts:147-150,217-222`, `social-import-dialog.component.html:83-95`, `social-accounts.service.ts:11-20`, `social-section.component.ts`, `social-section.component.html:225-249`, `social-section.component.scss`, `frontend/public/i18n/{en,de}.json`
- Test: `social-import-dialog.component.spec.ts` (change the two tests at lines 339-361 and 380-390; add removal tests), `social-import-rows.spec.ts`, `social-section.component.spec.ts`

**Interfaces — Produces:**
```ts
// social-import-rows.ts
export interface ImportRow { …; readonly removable: boolean } // a subscribed list or custom feed, never Home
// social-accounts.service.ts
export type SyncSkip = 'unreachable' | 'incomplete' | 'needs_reconnect' | 'failed';
export type SyncReason = 'saved' | 'created' | 'unsaved' | 'deleted';
export interface SyncChange { readonly kind: 'list' | 'custom_feed'; readonly name: string; readonly reason: SyncReason; readonly added: boolean; readonly at: string }
export interface SocialAccountSync { readonly checkedAt: string | null; readonly skipped: SyncSkip | null; readonly changes: readonly SyncChange[] }
export interface SocialAccount { …; readonly sync: SocialAccountSync }
```

- [ ] **Step 1: Write the failing specs**

Replace `it('offers lists and custom feeds as checkboxes, the added ones checked and fixed'…)` with:
```ts
  it('offers lists and custom feeds as checkboxes; an added list stays ticked and can be unticked, Home stays fixed', () => {
    const fixture = loaded(
      candidates({
        lists: [
          collection({ kind: 'home', id: null, name: 'Home', state: 'subscribed', subscriptionId: 11 }),
          collection({ state: 'subscribed', subscriptionId: 12 }),
        ],
        customFeeds: [DISCOVER],
      }),
    );
    showTab(fixture, 'Lists');

    expect(collectionBox(fixture, 'home:').checked).toBe(true);
    expect(collectionBox(fixture, 'home:').disabled).toBe(true);
    expect(collectionBox(fixture, 'list:at://list/a').checked).toBe(true);
    expect(collectionBox(fixture, 'list:at://list/a').disabled).toBe(false);
    expect(collectionBox(fixture, 'list:at://list/a').getAttribute('aria-label')).toBe('Remove News');
    showTab(fixture, 'Feeds');
    expect(collectionBox(fixture, 'custom_feed:at://feed/a').getAttribute('aria-label')).toBe(
      'Add Discover',
    );
  });
```
Replace `it('does not toggle an already added collection'…)` with:
```ts
  it('unticking an added list removes it through the import’s unsubscribe ids', () => {
    const fixture = loaded(candidates({ lists: [collection({ state: 'subscribed', subscriptionId: 12 })] }));
    showTab(fixture, 'Lists');

    collectionBox(fixture, 'list:at://list/a').click();
    fixture.detectChanges();
    expect(collectionBox(fixture, 'list:at://list/a').checked).toBe(false);

    press(fixture, 'social-import-apply');
    const post = http.expectOne(IMPORT);
    expect(post.request.body).toEqual({ subscribe: [], unsubscribe: [12], collections: [] });
    post.flush({ imported: 0, alreadySubscribed: 0, invalid: 0, skippedOverLimit: 0, removed: 1 });
  });

  it('never unticks a subscribed Home', () => {
    const home = collection({ kind: 'home', id: null, name: 'Home', state: 'subscribed', subscriptionId: 11 });
    const fixture = loaded(candidates({ lists: [home] }));
    showTab(fixture, 'Lists');

    collectionBox(fixture, 'home:').dispatchEvent(new Event('change'));
    fixture.detectChanges();

    expect(collectionBox(fixture, 'home:').checked).toBe(true);
    expect(applyButton(fixture).disabled).toBe(true);
  });
```
`social-section.component.spec.ts` (read it; it flushes `GET …/social-accounts`): an account with `sync: { checkedAt: <ISO 5 minutes before Date.now()>, skipped: null, changes: [{ kind: 'list', name: 'News', reason: 'saved', added: true, at: … }] }` shows `Lists and feeds last checked` in its import row's description (find the row by its `social-import-<id>` button and read the closest `.row-desc`) and `Added News (newly saved)` in `social-sync-changes-<id>`; `skipped: 'unreachable'` shows a description containing `Bluesky did not answer`, `skipped: 'failed'` one containing `the check failed`; `checkedAt: null` shows `Not checked yet`. Add `sync: { checkedAt: null, skipped: null, changes: [] }` to every existing account fixture in that spec and in `social-accounts.service.spec.ts`.

- [ ] **Step 2: Run** `docker compose exec -T frontend npm test -- src/app/settings/social` — Expected: FAIL.

- [ ] **Step 3: Implement**

`social-import-rows.ts`: `ImportRow.removable`; follow rows `removable: false`; collection rows `removable: collection.state === 'subscribed' && collection.kind !== 'home'`; `checkboxLabel`: removable → `translate('settings.social.importRemoveCollection', { name })`, else the existing key.

`social-import-dialog.component.ts`:
```ts
  protected toggle(row: ImportRow): void {
    if (row.state === 'subscribed' && !row.removable) return;
    this.chosen.update((current) => toggled(current, row.key));
  }
```
and
```ts
  private chosenRemovals(): number[] {
    const candidates = this.importer.candidates();
    const leftovers = (candidates?.follows ?? [])
      .filter((follow) => follow.state === 'no_longer_followed' && this.chosen().has(followKey(follow)))
      .map((follow) => follow.subscriptionId);
    const unticked = [...(candidates?.lists ?? []), ...(candidates?.customFeeds ?? [])]
      .filter((item) => item.state === 'subscribed' && item.kind !== 'home')
      .filter((item) => this.chosen().has(collectionKey(item)))
      .map((item) => item.subscriptionId);
    return [...leftovers, ...unticked].flatMap((id) => (id === null ? [] : [id]));
  }
```
Template (lines 89-95):
```html
            <input
              type="checkbox"
              [checked]="row.removable ? !picked : picked || row.state === 'subscribed'"
              [disabled]="row.state === 'subscribed' && !row.removable"
              [attr.aria-label]="row.checkboxLabel"
              (change)="toggle(row)"
            />
```
and `[class.is-done]="row.state === 'subscribed' && !picked"`.

`social-accounts.service.ts`: the types above.

`social-section.component.ts`:
```ts
  protected describeSync(account: SocialAccount): string {
    const { checkedAt, skipped } = account.sync;
    if (checkedAt === null) return this.i18n.translate('settings.social.syncNever');
    const when = relativeTime(checkedAt, this.language.lang());
    if (skipped === null) return this.i18n.translate('settings.social.syncChecked', { when });
    const network = this.i18n.translate(`settings.social.${account.network}`);
    return this.i18n.translate(`settings.social.syncSkipped.${skipped}`, { when, network });
  }

  protected describeChange(change: SyncChange): string {
    return this.i18n.translate(`settings.social.syncChange.${change.reason}`, { name: change.name });
  }
```
(`relativeTime` from `../../reader/format`, `reader/format.ts:55`.) `app-settings-row` has only a default slot (its control area) and a `rowTitleTip` slot (`frontend/src/app/shared/settings/settings-row/settings-row.component.html:1-20`), so the status goes into the row's `description` and the change list sits below the row. In `social-section.component.html` (import section, lines 232-247):
```html
        @for (account of svc.accounts(); track account.id) {
          <app-settings-row
            [stackable]="true"
            [title]="account.handle"
            [description]="describeImportSource(account) + ' · ' + describeSync(account)"
          >
            …the existing import button, unchanged…
          </app-settings-row>
          @if (account.sync.changes.length) {
            <ul class="sync-changes" [attr.data-testid]="'social-sync-changes-' + account.id">
              @for (change of account.sync.changes; track change.at + change.name) {
                <li>{{ describeChange(change) }}</li>
              }
            </ul>
          }
        }
```
`.scss`: `.sync-changes { margin: 0; padding-inline-start: var(--space-4); font-size: var(--fs-sm); color: var(--text-muted); }` — reuse tokens the file already uses (`--space-*`, `--fs-sm` appear in `social-section.component.scss`; check the muted-text token name there or in `docs/design-language.md`); no px, no hex.

i18n (`en.json` `settings.social`; mirror in `de.json`):
```json
      "importRemoveCollection": "Remove {{name}}",
      "syncNever": "Not checked yet",
      "syncChecked": "Lists and feeds last checked {{when}}",
      "syncSkipped": {
        "unreachable": "Skipped {{when}}: {{network}} did not answer",
        "incomplete": "Skipped {{when}}: {{network}} sent an incomplete answer",
        "needs_reconnect": "Skipped {{when}}: the account needs reconnecting",
        "failed": "Skipped {{when}}: the check failed and is tried again within the hour"
      },
      "syncChange": {
        "saved": "Added {{name}} (newly saved)",
        "created": "Added {{name}} (new list)",
        "unsaved": "Removed {{name}} (no longer saved)",
        "deleted": "Removed {{name}} (deleted on the network)"
      }
```
de: `"{{name}} entfernen"`, `"Noch nicht geprüft"`, `"Listen und Feeds zuletzt geprüft {{when}}"`, `"Übersprungen {{when}}: {{network}} hat nicht geantwortet"`, `"Übersprungen {{when}}: {{network}} hat unvollständig geantwortet"`, `"Übersprungen {{when}}: Das Konto muss neu verbunden werden"`, `"Übersprungen {{when}}: Die Prüfung ist fehlgeschlagen und wird innerhalb einer Stunde wiederholt"`, `"{{name}} hinzugefügt (neu gespeichert)"`, `"{{name}} hinzugefügt (neue Liste)"`, `"{{name}} entfernt (nicht mehr gespeichert)"`, `"{{name}} entfernt (im Netzwerk gelöscht)"`. Also update `importSectionCaption` (line 387) to end: "… Lists and feeds you save or create on the network are added within the hour, and ones you remove there go too; anything you remove here is not added back."

- [ ] **Step 4: Run** `docker compose exec -T frontend npm test -- src/app/settings` then `docker compose exec -T frontend npm run check` — Expected: PASS.

- [ ] **Step 5: Commit**
```bash
git add frontend/src/app/settings/social frontend/public/i18n
git commit -m "feat(#1666): untick a list to remove it; settings show each account's last check and changes"
```

---

### Task 17: Final gates and PR

- [ ] **Step 1: Merge `develop`** — `git fetch origin develop && git merge origin/develop` (pre-flight notes 2 and 3: re-check the sibling state service and any new `SocialFeedKind`; re-run Task 13's allow-list test after the merge).
- [ ] **Step 2: Backend** (from `backend/`): `bin/console cache:warmup && composer check && composer md`, `php bin/phpunit`, `docker compose exec php composer test`, `composer infection:diff` (everything committed, pre-flight 5). Expected: all green; Infection at or above `minMsi`. Escaped mutants on the new code get a test, not a threshold change.
- [ ] **Step 3: Frontend**: `docker compose exec -T frontend npm run check`.
- [ ] **Step 4: Migrations from empty on both dialects** (Task 2 Step 5 commands) once more with both new migrations.
- [ ] **Step 5: Real run** on the Docker stack (memory "verify with a real run"): favourite a post in a test RSS feed, unsubscribe in the UI → the post shows under Favourites as "<Feed> · Saved from feeds", the sidebar shows Saved from feeds; unfavourite it, run `docker compose exec php bin/console app:refresh --prune` (check the command name with `bin/console list app`) → the feed disappears from the sidebar. With a connected test Bluesky account (no real `createSession` in tests; a real run here is fine on the owner's own test account only if one is configured — otherwise skip and say so): run `docker compose exec php bin/console messenger:consume scheduler_worker --time-limit=60` once and check Settings → Social Media shows "Lists and feeds last checked …". Scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 80 | jq .`).
- [ ] **Step 6: PR** into `develop` with `gh pr create --base develop`, title `feat(#1666): saved feeds keep favourites; private feeds follow the network hourly`, body: summary of Part 1 and Part 2, the decisions above, the answered open questions, and `Closes #1666`. No attribution lines.

## Self-review notes

- Spec coverage: #1 Tasks 1, 3, 9 · #2 Tasks 1, 3, 7 · #3 Task 5 (all paths through `unsubscribeAll`, disconnect test) · #4 Tasks 4, 5 · #5 Tasks 5, 7, 9 · #6 Task 6 · #7 Task 8 · #8 Task 9 · #9 Task 13 (allow-list; Home and follows untouched) · #10 Task 14 · #11 Tasks 11, 13 · #12 Tasks 11, 13 · #13 Tasks 12, 13 · #14 Task 13 (Open question 1) · #15 Task 13 · #16 Tasks 13, 16 · #17 Tasks 10, 15, 16.
- Names used across tasks: `SavedFeedUrl::of/isSaved/networkOf/rebound`, `SavedFeeds::of`, `SavedPostRepository::{flaggedIn,keptTwinsOf,copiesIn,originalsOf,heldIn,copyInto,copyRenamed,copyCompanions}`, `SavedCopyPlan::{heldHashesToAsk,merged,plain,renamed}`, `SavedStateMover::move` (shared contract, unchanged), `SavedPostRescue::{rescueFrom,indexCopies}`, `SavedFeedRefusal::ofFeed`, `SyncStamp::{completed,skipped}`, `SocialAccount::{getLastCheckedAt,getLastSyncedAt,getLastSyncSkip,recordSync,recordSkippedSync}`, `SocialAccountRepository::{findIdsDueForSync,recordFailedSync}`, `SavedItemsModel`, `BlueskySavedCollections::{savedItemsOf,resolve,MAX_SAVED_LISTS}`, `NetworkCollectionsModel(lists, customFeeds, goneIds, truncated, savedFeedsKnown)`, `PrivateFeedSync::SYNCED_KINDS`, `PrivateFeedSyncApplier::apply`, `PrivateFeedSyncSweep::sweep`, `SocialMaintenance::{refreshProfileImages,syncPrivateFeeds}`, `PrivateFeedSyncWiring::{sync,sweep}`, `PrivateFeedSyncLog::{recentOf,recentOfOne}`.
