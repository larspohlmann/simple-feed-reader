# Flag Parameters, String Modes and Missing Value Objects (#1167) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close #1167 in two PRs.
- **PR A** (Tasks A0–A9, `Refs #1167`): every behaviour-switching flag and string mode the issue lists becomes a split method, an enum or a value object, and so does `BackupReader`'s first-line flag. The four full-replace admin settings PUTs stop resetting a field they were not sent.
- **PR B** (Tasks B0–B3, `Closes #1167`): repository methods scoped to a user take the owner first and share one name per concept.

**Architecture:**
- `RefreshRequest::allDue()` loses its two defaulted flags. Two withers, `withoutPruning()` and `ignoringSchedule()`, return new instances. `RefreshFeedsCommand` composes them from its options and depends on `RefreshRunnerInterface`, which already aliases `RefreshRunner`.
- `DigestImageKind` (Thumbnail, Favicon) replaces the `bool $isFavicon` and the `array<string, bool>` request map. It owns the MIME type, and the embedder matches on it for the resize. The embedder's `?EmbeddedImage` null return goes too: one `embedOne()` throws, and the loop catches.
- `SlideshowMarkup` renders every slide lazy and then marks the first image eager, so `item()` loses `bool $eager`.
- `App\Enum\EntryView` replaces the `string $view` on `EntryQuery`, `EntryScopePredicates`, `EntryListSort` and `EntryController`. `EntryQuery` refuses `ForYou`, so the for-you view can no longer fall silently through to "no filter".
- `FeedTagMove::move(Subscription, MoveFeedToTagRequest)` takes the drag DTO the editor already holds, and derives the owner from the subscription. Five parameters become two.
- `CatalogFaviconDueCriteria` carries `(staleBefore, retryBefore)`. One private query builder in `CatalogFeedRepository` serves both find and count. `DueFeedCriteria` is the precedent.
- `InstanceSettingsRequest`, `ProxySettingsRequest`, `GrafanaSettingsRequest` and `MailSettingsRequest` lose the constructor defaults on every setting. Each controller asks the serializer to require every property through one shared `App\Http\FullReplacePayload::CONTEXT`. A PUT that leaves a setting out is a 422 `validation_error`, not a silent reset. The one-shot intents keep their defaults and stay optional: `invalidateExistingPasskeys`, `password`/`removePassword` and `token`/`removeToken`. The SPA's three `DraftSettingsService` subclasses already build every body from a fully typed `bodyFromState()`, so the frontend needs no change. Tests build the three DTOs through a test-only `SettingsRequests` factory that carries the old defaults.
- `BackupReader` loses `assertOrdered(…, bool $isFirstLine, …)`. The file grammar moves into an immutable `BackupLineOrder` that knows when no line has been read, and the header's part-numbering checks move onto `BackupHeader::requireCoherent()`. That leaves the reader's class complexity well under PHPMD's 50.
- PR B: in `src/Repository`, a user-scoped method takes the owner as its first criterion and is named `…ForUser`. The three names for "one owned row" (`findOneOwnedBy`, `findOwnedById`, `getOwned`) become `findOneForUser` / `getOneForUser`. Where a reorder would swap two adjacent `int`s, the method is renamed too, so a missed call site fails loudly instead of binding the ids backwards.

**Tech Stack:** PHP 8.4, Symfony 7.4 (Serializer `require_all_properties`, `#[MapRequestPayload]`), Doctrine ORM QueryBuilder, PHPUnit 12, PHPStan level max with the custom rules in `backend/tests/PhpStan/`, PHPMD codesize, phptramp, Infection 0.34.

**Spec:**
- GitHub issue #1167 (`gh issue view 1167`).
- CLAUDE.md, "PHP code style — Clean Code is mandatory": no boolean flag parameters, at most three parameters, queries in `src/Repository`, errors as exceptions, comments one line (three at most).
- `docs/architecture.md` §7 (where queries live).

## Status

| Task | State |
|---|---|
| A0: Preflight | ⬜ |
| A1: `RefreshRequest` withers | ⬜ |
| A2: `DigestImageKind` | ⬜ |
| A3: `SlideshowMarkup` without `bool $eager` | ⬜ |
| A4: `EntryView` enum | ⬜ |
| A5: `FeedTagMove::move(Subscription, MoveFeedToTagRequest)` | ⬜ |
| A6: `CatalogFaviconDueCriteria` | ⬜ |
| A7a: `InstanceSettingsRequest` requires every setting | ⬜ |
| A7b: Proxy, Grafana and Mail settings requests require every setting | ⬜ |
| A8: Test helpers stop taking flags | ⬜ |
| A9: `BackupLineOrder` replaces `assertOrdered`'s first-line flag | ⬜ |
| B0: Preflight (PR A merged) | ⬜ |
| B1: One name for "one owned row", owner first | ⬜ |
| B2: `EntryListRepository` owner first | ⬜ |
| B3: Id-list lookups owner first; architecture.md records the rule | ⬜ |

## Scope

| Issue bullet | Task |
|---|---|
| `RefreshRequest::allDue(int, bool $prune = true, bool $force = false)` | A1 |
| `DigestImageEmbedder::tryEmbed(string, bool $isFavicon)` and the `array<string,bool>` from `requests()` | A2 |
| `SlideshowMarkup::item(…, bool $eager)` | A3 |
| Entry list `view` typed as `string`; `'for-you'` falls through to `default` | A4 |
| `FeedTagMove::move()` has five parameters, three of them nullable ints | A5 |
| `CatalogFeedRepository::findNeedingFavicon`/`countNeedingFavicon` duplicate `(staleBefore, retryBefore)` | A6 |
| `InstanceSettingsRequest` defaults make a partial PUT silently reset fields | A7a |
| The same silent reset in `ProxySettingsRequest`, `GrafanaSettingsRequest` and `MailSettingsRequest` (planner ruling: the general fix) | A7b |
| Adjacent `int` ids in inconsistent order; one concept with three names | B1, B2, B3 |
| Test-helper flags, handed over by #1164's plan ("#1167 owns test-helper flags") | A8 |
| `BackupReader::assertOrdered(string, bool $isFirstLine, int, int)`, found in this plan's sweep (planner ruling: same defect class) | A9 |

**Scope decisions:**
- **Behaviour-switching flags only.** A `bool` that is a stored value is not a flag parameter: an entity setter (`setEnabled(bool)`), a JSON field (`MeJson::profile(…, bool $mailEnabled, …)`), a fact a decision reads (`LokiSinkFactory::selects`), or a value OR'd into a field (`FetchAttempt::followedTo`). A data-provider parameter and an interface-mandated signature (`LockInterface::acquire(bool $blocking)`) are data too. A8 takes only the test helpers that branch on their `bool`.
- **Left to their own issues:** `RecordedCall::settle(string, bool $usable)` and `RecommendationAnswerBudget::reasoningHeadroomTokens(bool)` (#1162), `ReaderLeadImage::restore(…, bool $topPlacesLeadVisual)` (#1163). #1164 has already removed the `EntryState` flag setters and `EntryStateRepository::ensureRow()`'s `bool $seedHidden`: `ensureRow(int $userId, int $entryId, ?\DateTimeImmutable $hiddenSince)`.
- **PR B's rule covers `src/Repository`, and `OwnedTagsCache`, which mirrors `TagRepository::findAllByIdsForUser`.** A method's subject stays in front of the owner. That subject is the query builder or the rows it works on: `EntryListRowEnricher::enrich($rows, $userId)`, `SavedSearchMembershipLoader::loadInto($rows, $userId)`, `DuplicateCollapseDql::apply($qb, $applyScope, $userId)`. Services such as `OwnedSubscriptions::resolve(array $ids, int $userId)` keep their signatures.
- **No typed id values.** The issue offers them as an alternative. They would touch every repository and most services. One order plus a rename of every adjacent-`int` reorder gets the same safety at the call sites this issue names.
- **The saved-search match-mode flags stay.** `SavedSearchRepository::findOneForUserByTerm(…, bool $wholeWord, bool $phrase)` and its siblings belonged to #1155, which Lars closed as not planned on purpose. No follow-up.

## Depends on #1164 (landed)

#1164 has landed: PR A as #1185 and PR B as #1186, both merged into `develop` by `0f80267b`. The plan was written at `1cdcf65d`. Every file it edits that #1164 changed is listed below and was re-read at `0f80267b`; every other file it edits is byte-identical between the two commits. Locate each edit by its text; the line numbers are orientation only.

These files are edited by this plan and were also changed by #1164. Each row records what #1164 left at `0f80267b` and why the step still applies as written.

| Step | File | #1164's change, as landed | Reconcile |
|---|---|---|---|
| A1 Step 5 | `tests/Service/Refresh/RefreshRunnerTest.php` | The Feed fixture setters became `recordSuccessfulFetch()`/`scheduleNextFetchAt()` and friends. | The two `allDue(300, prune: false)` lines are untouched, now at 389 and 430. `replace_all` on their text applies. |
| A1 Step 5 | `tests/Command/RefreshFeedsCommandTest.php` | `dueFeed()` calls `$feed->scheduleNextFetchAt(…)` at line 37. | The docblock above `dueFeed()` (lines 30-31) is unchanged, so the diff applies. |
| A4 Step 6 | `tests/Repository/EntryListTest.php`, `tests/Repository/DuplicateCollapseTest.php` | `EntryState` setters became `hide()`/`markFavorite()`/`markKept()`. | The view literals and the import anchors (`use App\Enum\ListOrder;`, `use App\Entity\User;`) are unchanged. The perl substitution applies. |
| A7a Step 1 | `tests/Controller/Admin/AdminSettingsControllerTest.php` | `givenAnEnrolledPasskey()` builds `new UserPasskey($owner, registration: PasskeyRegistrations::any(…))`, with the `PasskeyRegistrations` import. | This plan edits only the PUT bodies and adds one helper and two tests. Every PUT literal in the table matches `0f80267b`. Keep #1164's fixture as it stands. |
| A8 Step 2 | `tests/Service/Reader/SearchMarkReadServiceTest.php` | `stateFor()` is `$isHidden ? $state->hide(new \DateTimeImmutable('2026-07-05T00:00:00Z')) : $state->markUnread();`. | A8 replaces that helper. `markUnread()` on a new `EntryState` changes nothing, so `unreadStateFor()` does not call it. |
| B1 Step 1 | `tests/Repository/RecommendationRunLogRepositoryTest.php` | The `->finish(` calls at lines 53 and 108 take a `CallOutcome`, and `use App\Entity\CallOutcome;` was added. | This plan edits only the two `getOwned` tests (lines 152-185), which #1164 did not touch. |
| B2 Steps 1, 3 | `tests/Service/Reader/EntryStateUpdaterTest.php`, `src/Service/Reader/EntryStateUpdater.php`, `tests/Service/Reader/EntryStateResolverTest.php` | `testUnfavouritingAndUnkeepingClearBothFlags` adds a fifth `getOneRowForUser($target->requireId(), $user->requireId())` call. `EntryStateUpdater.php:47-52` uses `markFavorite()`/`clearFavorite()` and `markKept()`/`clearKept()`. `EntryStateResolverTest.php:110-112` uses `markFavorite()` and the three-argument `ensureRow()`. | The new call has the same text as the other four, so the `replace_all` covers all five. `EntryStateUpdater.php:70` and `EntryStateResolverTest.php:107` are unchanged. |

No overlap, checked against `git diff 1cdcf65d 0f80267b`:
- **A2, A3, A5, A6, A7b:** #1164 changed none of their files.
- **A9:** #1164 changed neither `BackupReader` nor `BackupHeader`. It changed `EntryMedia::getMedia()`/`getAttachments()`, which now read through `fromStored()` and drop incomplete items. `BackupLines` calls those getters, so backup export drops incomplete media too (#1164 ruling S2). A9 edits neither file, and `BackupReaderTest` still has 28 tests.
- **B3:** #1164 did not change `DigestEntryFinderTest`, `SavedSearchMatchFixture` or any B3 call site.

## Global Constraints

- **Paths and commands are relative to `backend/`** unless they start with `docs/`, `frontend/` or `CLAUDE.md`.
- **No wire change, with one deliberate exception.** Every response body, status code and header stays byte-identical, except A7a/A7b. `PUT /api/admin/settings`, `/api/admin/proxy`, `/api/admin/grafana` and `/api/admin/mail` with a setting missing now answer 422 `validation_error` naming the field and store nothing. They used to reset the missing field to a constructor default. The SPA and `bin/e2e.sh` already send every setting. The PR A body lists all four.
- **Clean Code (CLAUDE.md) is mandatory.**
  - Names reveal intent.
  - No boolean flag parameters.
  - Three parameters at most.
  - Guard clauses over nesting.
  - `final readonly` by default.
  - A controller calls only `get*`/`is*`/`has*`/`requireId()` on an entity.
  - Queries live in `src/Repository`.
  - Domain code (`App\Enum`, `App\Repository`, `App\Service`) imports nothing from `App\Http` (`DomainKnowsNoHttpRule`). `App\Exception\ValidationException` is domain, as `ListOrder` shows.
- **Comments:** default none. At most three lines, and only where a future reader would otherwise get the code wrong. A docblock this plan rewrites is trimmed to that bar. Delete `@param`/`@return` lines that repeat the signature, unless PHPStan needs the array shape.
- **Tests read persisted ids with `requireId()`** (`EntityIdCoercionRule` covers `tests/`).
- **Every touched `src` file is PHPMD-clean** under `composer md`. Fix the design, never the threshold.
- **PHPStan at level max:** no new baseline entry and no `@phpstan-ignore`.
- **Gates for every task:**
  - the task's own tests,
  - `composer check` (cs + stan + tramp),
  - `composer md`,
  - PhpStorm inspections on every changed PHP file (`mcp__phpstorm__lint_files`). ERROR and WARNING block.
- **Gates per PR (Finishing):**
  - `php bin/phpunit` (SQLite),
  - `docker compose exec php composer test` (MySQL),
  - `composer check`,
  - `composer md`,
  - `composer infection:diff`,
  - PhpStorm lint on all changed PHP.
  - PR A also changes one comment in `frontend/`, so it also runs `docker compose exec -T frontend npm run check`.
- **Every new test gets a deletion check.** Delete or break the production line the test covers, run the test and watch it fail, then restore the line by hand with the Edit tool (never `git checkout --`). Paste both outputs into the task report.
- **Commit format:** `refactor(#1167): <lower-case summary>`, one commit per task, no attribution lines. Never commit to `develop`.
- **Branches, both cut from `origin/develop`:**
  - PR A: `refactor/1167-flags-and-value-objects`.
  - PR B: `refactor/1167-owner-first-repositories`, cut after PR A merges.
- **Do not merge.** Open each PR and stop. Merging is the user's call.
- **The checkout is shared.** Check `git status` and `git branch --show-current` before any `checkout`, `reset` or `stash`. Another session may be mid-edit.

---

# PR A

### Task A0: Preflight

**Files:** none changed.

- [ ] **Step 1: Confirm #1164 has fully landed**

Run:
```bash
gh issue view 1164 --json state --jq .state
git fetch origin
git merge-base --is-ancestor 0f80267b origin/develop && echo 'develop has #1164'
git log origin/develop --oneline | grep -c '(#1164)'
git grep -n 'setIsHidden\|stateFor' origin/develop -- backend/tests/Service/Reader/SearchMarkReadServiceTest.php
git grep -n 'function __construct' origin/develop -- backend/src/Entity/UserPasskey.php
```
Expected:
- `CLOSED`.
- `develop has #1164`.
- At least 22 commits. `0f80267b` alone carries 22 with `(#1164)` in the subject.
- No `setIsHidden`. `stateFor(` appears four times: the declaration at line 64 and the three callers at 99, 111 and 126.
- `public function __construct(User $user, PasskeyRegistration $registration)`.

If #1164 is not CLOSED, or `0f80267b` is not on `develop`, stop and report. Every before-block in this plan was taken at `0f80267b`.

- [ ] **Step 2: Cut the branch**

```bash
git status --short && git branch --show-current
git switch -c refactor/1167-flags-and-value-objects origin/develop
git merge-base --is-ancestor 0f80267b HEAD && echo 'HEAD contains 0f80267b'
```
Expected: `HEAD contains 0f80267b`. Otherwise stop and report.

- [ ] **Step 3: Re-take the call sites this PR rewrites**

Run each command and compare the result with the task that owns it. A site the plan does not list gets the same rewrite as its siblings, and the executor notes it in the task report.
```bash
git grep -n "RefreshRequest::allDue(" -- src tests
git grep -n "tryEmbed\|isFavicon" -- src tests
git grep -nE "view: '|new EntryQuery\(\\\$userId, '" -- tests
git grep -n 'feedTagMove->move\|this->move(\$moved' -- src tests/Service/Subscription
git grep -n "NeedingFavicon(" -- src tests
git grep -nE "new (Instance|Proxy|Grafana|Mail)SettingsRequest\(" -- src tests
git grep -n "assertOrdered\|KIND_RANK\|SINGLETON_KINDS" -- src tests
git grep -n "unreadMemberIdsSince(" -- src tests
git grep -n "new FakeOAuthProvider(\|failExchange\|user(verified\|function persistFeed(\|function stateFor(" -- tests
```
Expected at `0f80267b`:
- `allDue(`: 45 hits. Only `src/Command/RefreshFeedsCommand.php:69` and `tests/Service/Refresh/RefreshRunnerTest.php:389,430` pass more than the budget.
- `tryEmbed|isFavicon`: 5 hits, all in `src/Service/Mail/Digest/DigestImageEmbedder.php`.
- View literals: 22 hits, 16 in `tests/Repository/EntryListTest.php` and 6 in `tests/Repository/DuplicateCollapseTest.php`. `tests/Repository/EntryQueryTest.php` passes views positionally to `new EntryQuery(1, …)`; A4 rewrites it in full.
- Tag moves: `SubscriptionEditor.php:36`, and 8 `$this->move($moved, …)` calls in `FeedTagMoveTest.php`.
- `NeedingFavicon(`: 7 hits, in `CatalogFeedRepository.php:76,102`, `CatalogFaviconWarmer.php:55,81`, `CatalogFeedRepositoryTest.php:120,152` and `CatalogFaviconWarmerTest.php:188`.
- Settings DTOs: `InstanceSettingsRequestTest` ×5 and `RelyingPartyChangeTest` ×1 (A7a); the three `tests/Dto/Admin/*SettingsRequestTest.php` and the seven service tests A7b lists (A7b). None in `src`.
- `assertOrdered|KIND_RANK|SINGLETON_KINDS`: 9 hits, all in `src/Service/Backup/BackupReader.php`.
- `unreadMemberIdsSince(`: 3 hits, in `SavedSearchEntryRepository.php:160`, `DigestEntryFinder.php:29` and `SavedSearchMembershipReadsTest.php:264`.
- Flag helpers: `new FakeOAuthProvider(` in `OAuthFlowTest.php:869` and `OAuthCallbackTest.php:146` only; `failExchange` in those two files and `FakeOAuthProvider.php`; `user(verified` in `SendDueDigestsTest.php:150`. `function stateFor(` also appears in `tests/Service/Backup/EntryPartRestorerTest.php` and `tests/Support/FullyPopulatedAccount.php`, and `function persistFeed(` in `WarmCatalogFaviconsCommandTest.php`, `CatalogFaviconWarmerTest.php` and `EntryCategoryWriterTest.php`. None of those five takes a `bool`, so A8 leaves them alone.

---

### Task A1: `RefreshRequest` withers replace `allDue()`'s flags

**Files:**
- Modify: `src/Service/Refresh/RefreshRequest.php`
- Modify: `src/Command/RefreshFeedsCommand.php`
- Modify: `tests/Service/Refresh/FakeRefreshRunner.php` (records requests)
- Modify: `tests/Service/Refresh/RefreshRunnerTest.php` (two lines)
- Modify: `tests/Command/RefreshFeedsCommandTest.php` (one docblock line)
- Create: `tests/Service/Refresh/RefreshRequestTest.php`
- Create: `tests/Command/RefreshFeedsCommandRequestTest.php`

**Interfaces:**
- Produces:
  - `RefreshRequest::allDue(int $budgetSeconds): self`, which prunes and runs on schedule.
  - `RefreshRequest::withoutPruning(): self`.
  - `RefreshRequest::ignoringSchedule(): self`.
  - `RefreshFeedsCommand::__construct(RefreshRunnerInterface $refreshRunner)`.
  - `FakeRefreshRunner::$requests` (`list<RefreshRequest>`).
- Unchanged: the public properties `userId`, `feedId`, `tagId`, `force`, `budgetSeconds` and `prune`, which `RefreshRunner` reads, and `forUser`/`forUserTag`/`forFeed`/`forUserFeed`.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Refresh/RefreshRequestTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Service\Refresh\RefreshRequest;
use PHPUnit\Framework\TestCase;

final class RefreshRequestTest extends TestCase
{
    public function testAnAllDueRequestRunsOnScheduleAndPrunes(): void
    {
        $request = RefreshRequest::allDue(45);

        self::assertNull($request->userId);
        self::assertNull($request->feedId);
        self::assertNull($request->tagId);
        self::assertSame(45, $request->budgetSeconds);
        self::assertTrue($request->prune);
        self::assertFalse($request->force);
    }

    public function testWithoutPruningTurnsOnlyThePruningOff(): void
    {
        $request = RefreshRequest::allDue(45)->withoutPruning();

        self::assertFalse($request->prune);
        self::assertFalse($request->force);
        self::assertSame(45, $request->budgetSeconds);
    }

    public function testWithoutPruningKeepsTheScope(): void
    {
        $request = RefreshRequest::forUserTag(7, 13, 30)->withoutPruning();

        self::assertSame(7, $request->userId);
        self::assertSame(13, $request->tagId);
        self::assertNull($request->feedId);
        self::assertTrue($request->force);
        self::assertSame(30, $request->budgetSeconds);
    }

    public function testIgnoringScheduleTurnsOnlyTheScheduleOff(): void
    {
        $request = RefreshRequest::allDue(45)->ignoringSchedule();

        self::assertTrue($request->force);
        self::assertTrue($request->prune);
        self::assertSame(45, $request->budgetSeconds);
    }

    public function testIgnoringScheduleKeepsTheScope(): void
    {
        $request = RefreshRequest::forUserFeed(7, 11, 30)->ignoringSchedule();

        self::assertSame(7, $request->userId);
        self::assertSame(11, $request->feedId);
        self::assertNull($request->tagId);
        self::assertFalse($request->prune);
        self::assertSame(30, $request->budgetSeconds);
    }
}
```

`tests/Service/Refresh/FakeRefreshRunner.php`: record what it was asked. Replace the class body:
```php
final class FakeRefreshRunner implements RefreshRunnerInterface
{
    /** @var list<RefreshRequest> */
    public array $requests = [];

    /** @var list<RefreshReport> */
    private array $reports;

    public function __construct(RefreshReport ...$reports)
    {
        $this->reports = array_values($reports);
    }

    public function run(RefreshRequest $request): RefreshReport
    {
        $this->requests[] = $request;
        $report = array_shift($this->reports);
        if (null === $report) {
            throw new \LogicException('The runner was asked for more slices than the test prepared.');
        }

        return $report;
    }
}
```
Keep the class docblock as it is.

`tests/Command/RefreshFeedsCommandRequestTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RefreshFeedsCommand;
use App\Service\Refresh\RefreshReport;
use App\Service\Refresh\RefreshRequest;
use App\Tests\Service\Refresh\FakeRefreshRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class RefreshFeedsCommandRequestTest extends TestCase
{
    public function testAPlainRunPrunesAndRunsOnSchedule(): void
    {
        $request = $this->requestFor(['--budget' => '45']);

        self::assertSame(45, $request->budgetSeconds);
        self::assertTrue($request->prune);
        self::assertFalse($request->force);
    }

    public function testNoPruneTurnsOnlyThePruningOff(): void
    {
        $request = $this->requestFor(['--budget' => '45', '--no-prune' => true]);

        self::assertFalse($request->prune);
        self::assertFalse($request->force);
    }

    public function testForceTurnsOnlyTheScheduleOff(): void
    {
        $request = $this->requestFor(['--budget' => '45', '--force' => true]);

        self::assertTrue($request->force);
        self::assertTrue($request->prune);
    }

    /** @param array<string, string|bool> $input */
    private function requestFor(array $input): RefreshRequest
    {
        $runner = new FakeRefreshRunner(RefreshReport::busy());
        (new CommandTester(new RefreshFeedsCommand($runner)))->execute($input);

        return $runner->requests[0] ?? self::fail('The command never asked for a refresh.');
    }
}
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `php bin/phpunit tests/Service/Refresh/RefreshRequestTest.php tests/Command/RefreshFeedsCommandRequestTest.php`
Expected:
- FAIL: `Call to undefined method App\Service\Refresh\RefreshRequest::withoutPruning()` / `ignoringSchedule()`.
- A `TypeError`: `RefreshFeedsCommand::__construct()` expects `RefreshRunner`, and the test passes `FakeRefreshRunner`.

- [ ] **Step 3: Implement**

`src/Service/Refresh/RefreshRequest.php`: replace `allDue()` and add the two withers after it.
```php
    public static function allDue(int $budgetSeconds): self
    {
        return new self(null, null, null, false, $budgetSeconds, true);
    }

    public function withoutPruning(): self
    {
        return new self($this->userId, $this->feedId, $this->tagId, $this->force, $this->budgetSeconds, false);
    }

    public function ignoringSchedule(): self
    {
        return new self($this->userId, $this->feedId, $this->tagId, true, $this->budgetSeconds, $this->prune);
    }
```

`src/Command/RefreshFeedsCommand.php`:
- Replace `use App\Service\Refresh\RefreshRunner;` with `use App\Service\Refresh\RefreshRunnerInterface;`.
- Constructor: `public function __construct(private readonly RefreshRunnerInterface $refreshRunner)`.
- In `execute()`, replace the `default =>` arm:
```php
            default => $this->allDueRequest($input, $budget),
```
- Add after `execute()`:
```php
    private function allDueRequest(InputInterface $input, int $budget): RefreshRequest
    {
        $request = RefreshRequest::allDue($budget);
        if ((bool) $input->getOption('no-prune')) {
            $request = $request->withoutPruning();
        }
        if ((bool) $input->getOption('force')) {
            $request = $request->ignoringSchedule();
        }

        return $request;
    }
```

- [ ] **Step 4: Run the new tests to verify they pass**

Run: `php bin/phpunit tests/Service/Refresh/RefreshRequestTest.php tests/Command/RefreshFeedsCommandRequestTest.php`
Expected: PASS (8 tests).

- [ ] **Step 5: Move the remaining callers**

`tests/Service/Refresh/RefreshRunnerTest.php`. The line appears twice, at 389 and 430, so use Edit with `replace_all`:
```diff
-        $this->runner()->run(RefreshRequest::allDue(300, prune: false));
+        $this->runner()->run(RefreshRequest::allDue(300)->withoutPruning());
```

`tests/Command/RefreshFeedsCommandTest.php`, the docblock above `dueFeed()`:
```diff
-     * `--feed`, `--user` and `--no-prune`, so RefreshFeedsCommand builds an
-     * allDue() request with prune: true (#246) and an unsubscribed feed
+     * `--feed`, `--user` and `--no-prune`, so RefreshFeedsCommand builds a
+     * pruning allDue() request (#246) and an unsubscribed feed
```

Run `git grep -n "allDue(" -- src tests`. Expected: every hit passes exactly one argument.

- [ ] **Step 6: Run the refresh suites and the container lint**

Run:
```bash
php bin/phpunit tests/Service/Refresh tests/Command/RefreshFeedsCommandTest.php tests/Command/RefreshFeedsCommandRequestTest.php tests/Controller/MaintenanceControllerTest.php
php bin/console lint:container
```
Expected: PASS. The container lint accepts the interface: `config/services.yaml:123` aliases `RefreshRunnerInterface` to `RefreshRunner`.

- [ ] **Step 7: Deletion checks, gates, commit**

Run the deletion checks in turn. Restore each line by hand before the next.
- In `allDueRequest()`, delete the `withoutPruning()` branch. `testNoPruneTurnsOnlyThePruningOff` must fail.
- In `allDueRequest()`, delete the `ignoringSchedule()` branch. `testForceTurnsOnlyTheScheduleOff` must fail.
- In `withoutPruning()`, pass `null` for `$this->tagId`. `testWithoutPruningKeepsTheScope` must fail.

Then run `composer check && composer md` and lint the changed PHP in PhpStorm.
```bash
git add src/Service/Refresh/RefreshRequest.php src/Command/RefreshFeedsCommand.php tests/Service/Refresh/FakeRefreshRunner.php tests/Service/Refresh/RefreshRequestTest.php tests/Command/RefreshFeedsCommandRequestTest.php tests/Service/Refresh/RefreshRunnerTest.php tests/Command/RefreshFeedsCommandTest.php
git commit -m "refactor(#1167): refresh request composes withoutPruning and ignoringSchedule instead of flags"
```

---

### Task A2: `DigestImageKind` replaces the favicon flag

**Files:**
- Create: `src/Service/Mail/Digest/DigestImageKind.php`
- Modify: `src/Service/Mail/Digest/DigestImageEmbedder.php` (rewritten in full)
- Test: `tests/Service/Mail/Digest/DigestImageEmbedderTest.php` (unchanged, it is the regression net)

**Interfaces:**
- Produces: `enum DigestImageKind { case Thumbnail; case Favicon; public function contentType(): string }`.
- Unchanged: `DigestImageEmbedderInterface::embed(DigestPage): DigestImageSet`.

This is a refactor with no new behaviour. The ten existing embedder tests pin every observable. They cover kind to resize method, kind to content type, first sighting wins in both directions, a failure dropping one image only, the loop continuing after a failure, the log context, and the CID format.

- [ ] **Step 1: Pin the current behaviour**

Run: `php bin/phpunit tests/Service/Mail/Digest/DigestImageEmbedderTest.php`
Expected: PASS (10 tests).

- [ ] **Step 2: Write the enum**

`src/Service/Mail/Digest/DigestImageKind.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

enum DigestImageKind
{
    case Thumbnail;
    case Favicon;

    public function contentType(): string
    {
        return match ($this) {
            self::Thumbnail => 'image/jpeg',
            self::Favicon => 'image/png',
        };
    }
}
```

- [ ] **Step 3: Rewrite the embedder**

`src/Service/Mail/Digest/DigestImageEmbedder.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Service\Catalog\CatalogFaviconFetcherInterface;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Mail\Digest\Exception\ImageProcessingException;
use Psr\Log\LoggerInterface;

/**
 * Fetches every thumbnail and favicon a rendered page references, resizes each,
 * and returns them as CID parts keyed by source URL. A distinct URL is fetched
 * once and reused; any fetch or resize failure drops that image, not the mail.
 */
final readonly class DigestImageEmbedder implements DigestImageEmbedderInterface
{
    private const int THUMBNAIL_WIDTH = 176;
    private const int THUMBNAIL_HEIGHT = 132;
    private const int FAVICON_SIZE = 32;

    public function __construct(
        private CatalogFaviconFetcherInterface $downloader,
        private DigestImageResizerInterface $resizer,
        private LoggerInterface $logger,
    ) {
    }

    public function embed(DigestPage $page): DigestImageSet
    {
        $images = [];
        $cidByUrl = [];

        foreach ($this->requests($page) as $url => $kind) {
            try {
                $image = $this->embedOne($url, $kind);
            } catch (FaviconUnavailableException | ImageProcessingException $e) {
                $this->logger->debug('Digest image skipped: {url}', ['url' => $url, 'exception' => $e]);
                continue;
            }

            $cidByUrl[$url] = $image->cid;
            $images[] = $image;
        }

        return new DigestImageSet($images, $cidByUrl);
    }

    /**
     * The first sighting of a URL decides its kind.
     *
     * @return array<string, DigestImageKind>
     */
    private function requests(DigestPage $page): array
    {
        $requests = [];

        foreach ($page->groups as $group) {
            foreach ($group->cards as $card) {
                if ($card->faviconUrl !== null) {
                    $requests[$card->faviconUrl] ??= DigestImageKind::Favicon;
                }
                if ($card->imageUrl !== null) {
                    $requests[$card->imageUrl] ??= DigestImageKind::Thumbnail;
                }
            }
        }

        return $requests;
    }

    /**
     * @throws FaviconUnavailableException
     * @throws ImageProcessingException
     */
    private function embedOne(string $url, DigestImageKind $kind): EmbeddedImage
    {
        return new EmbeddedImage(
            'img' . substr(hash('xxh128', $url), 0, 16),
            $this->resized($this->downloader->download($url)->bytes, $kind),
            $kind->contentType(),
        );
    }

    /**
     * @throws ImageProcessingException
     */
    private function resized(string $sourceBytes, DigestImageKind $kind): string
    {
        return match ($kind) {
            DigestImageKind::Favicon => $this->resizer->containPng(
                $sourceBytes,
                self::FAVICON_SIZE,
                self::FAVICON_SIZE,
            ),
            DigestImageKind::Thumbnail => $this->resizer->coverJpeg(
                $sourceBytes,
                self::THUMBNAIL_WIDTH,
                self::THUMBNAIL_HEIGHT,
            ),
        };
    }
}
```

- [ ] **Step 4: Run the embedder and digest tests**

Run: `php bin/phpunit tests/Service/Mail/Digest`
Expected: PASS.

- [ ] **Step 5: Deletion checks, gates, commit**

- Swap the two `contentType()` arms. `testAUrlSeenOnlyAsAFaviconIsResizedWithContainPngToPng` and its thumbnail twin must fail.
- Change the `continue` in `embed()` to `break`. `testAFetchFailureDoesNotStopLaterImagesInTheLoop` must fail.

Restore each change, then run `composer check && composer md` and the PhpStorm lint.
```bash
git add src/Service/Mail/Digest/DigestImageKind.php src/Service/Mail/Digest/DigestImageEmbedder.php
git commit -m "refactor(#1167): digest images carry a DigestImageKind instead of an is-favicon flag"
```

---

### Task A3: `SlideshowMarkup` marks the first slide eager after rendering

**Files:**
- Modify: `src/Service/Reader/Slideshow/SlideshowMarkup.php:27-44`
- Test: `tests/Service/Reader/Slideshow/SlideshowMarkupTest.php` (one new test)

**Interfaces:** none change. `figureFor(HTMLDocument, Slideshow): Element` is the only public method.

- [ ] **Step 1: Write the pinning test**

Add to `SlideshowMarkupTest`, after `testBuildsFigureWithCaptionAndLazyImages()`:
```php
    public function testOnlyTheFirstOfThreeSlidesLoadsEagerly(): void
    {
        $document = HtmlDocumentParser::parseOrNull('<body></body>');
        self::assertNotNull($document);
        $show = Slideshow::fromSlides(
            [
                new Slide('https://img/1.jpg', 'a'),
                new Slide('https://img/2.jpg', 'b'),
                new Slide('https://img/3.jpg', 'c'),
            ],
            null,
            null,
            null,
        );
        self::assertNotNull($show);

        $document->body?->appendChild((new SlideshowMarkup())->figureFor($document, $show));
        $html = $document->saveHtml();

        self::assertSame(1, substr_count($html, 'loading="eager"'));
        self::assertSame(2, substr_count($html, 'loading="lazy"'));
        self::assertStringContainsString('src="https://img/1.jpg" alt="a" loading="eager"', $html);
    }
```

- [ ] **Step 2: Run it against the current code**

Run: `php bin/phpunit tests/Service/Reader/Slideshow/SlideshowMarkupTest.php`
Expected: PASS (5 tests). This task is a refactor, so the test pins the behaviour before the change.

- [ ] **Step 3: Implement**

In `figureFor()`, replace the list loop:
```php
        $list = $document->createElement('ol');
        foreach ($slideshow->slides as $slide) {
            $list->appendChild($this->item($document, $slide));
        }
        $this->loadFirstSlideEagerly($list);
        $figure->appendChild($list);
```

Replace `item()`, and delete its two-line comment, which the new method name replaces:
```php
    private function item(HTMLDocument $document, Slide $slide): Element
    {
        $image = $document->createElement('img');
        $image->setAttribute('src', $slide->imageUrl);
        $image->setAttribute('alt', $slide->alt);
        $image->setAttribute('loading', 'lazy');

        $item = $document->createElement('li');
        $item->appendChild($image);
        if (!$slide->caption->isEmpty()) {
            $item->appendChild($this->caption($document, $slide->caption));
        }

        return $item;
    }

    private function loadFirstSlideEagerly(Element $list): void
    {
        $list->querySelector('img')?->setAttribute('loading', 'eager');
    }
```
`setAttribute` on an attribute that already exists keeps its position, so the `src … alt … loading` order the tests assert holds.

- [ ] **Step 4: Run the slideshow tests**

Run: `php bin/phpunit tests/Service/Reader/Slideshow`
Expected: PASS.

- [ ] **Step 5: Deletion check, gates, commit**

- Delete the `$this->loadFirstSlideEagerly($list);` call. `testOnlyTheFirstOfThreeSlidesLoadsEagerly` and `testBuildsFigureWithCaptionAndLazyImages` must fail.

Restore the call, then run `composer check && composer md` and the PhpStorm lint.
```bash
git add src/Service/Reader/Slideshow/SlideshowMarkup.php tests/Service/Reader/Slideshow/SlideshowMarkupTest.php
git commit -m "refactor(#1167): slideshow renders slides lazy and marks the first eager, no eager flag"
```

---

### Task A4: `EntryView` enum replaces the string view

**Files:**
- Create: `src/Enum/EntryView.php`
- Create: `tests/Enum/EntryViewTest.php`
- Modify: `src/Repository/EntryQuery.php:36-88`
- Modify: `src/Repository/EntryListSort.php:26-29`
- Modify: `src/Repository/EntryScopePredicates.php:60-81`
- Modify: `src/Controller/Api/EntryController.php:61-100`
- Modify: `tests/Repository/EntryQueryTest.php` (rewritten in full)
- Modify: `tests/Repository/EntryListTest.php`, `tests/Repository/DuplicateCollapseTest.php` (view literals)

**Interfaces:**
- Produces:
  - `enum App\Enum\EntryView: string { All='all'; Unread='unread'; Favorites='favorites'; Kept='kept'; Viewed='viewed'; ForYou='for-you' }`.
  - `EntryView::fromRequestValue(?string $value): self`, which throws `App\Exception\ValidationException`.
  - `EntryView::isChronological(): bool`.
  - `EntryQuery::$view` is now `EntryView`. The constructor throws `\LogicException` for `EntryView::ForYou`.
  - `EntryListSort::forView(EntryView $view): self`.
- Wire contract: unchanged. That includes the 422 body `{"view": ["Unknown view. Use one of: all, unread, favorites, kept, viewed, for-you."]}`, pinned by `EntryControllerTest::testRejectsUnknownView`, and view-before-order validation precedence.

- [ ] **Step 1: Write the failing tests**

`tests/Enum/EntryViewTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\EntryView;
use App\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class EntryViewTest extends TestCase
{
    public function testAnAbsentViewIsAll(): void
    {
        self::assertSame(EntryView::All, EntryView::fromRequestValue(null));
    }

    public function testEachRequestValueNamesItsView(): void
    {
        self::assertSame(EntryView::All, EntryView::fromRequestValue('all'));
        self::assertSame(EntryView::Unread, EntryView::fromRequestValue('unread'));
        self::assertSame(EntryView::Favorites, EntryView::fromRequestValue('favorites'));
        self::assertSame(EntryView::Kept, EntryView::fromRequestValue('kept'));
        self::assertSame(EntryView::Viewed, EntryView::fromRequestValue('viewed'));
        self::assertSame(EntryView::ForYou, EntryView::fromRequestValue('for-you'));
    }

    public function testAnEmptyOrUnknownViewIsAValidationErrorOnTheViewField(): void
    {
        foreach (['', 'bogus', 'Unread'] as $value) {
            try {
                EntryView::fromRequestValue($value);
                self::fail("The view '{$value}' must be rejected.");
            } catch (ValidationException $exception) {
                self::assertSame(
                    ['view' => ['Unknown view. Use one of: all, unread, favorites, kept, viewed, for-you.']],
                    $exception->errors,
                );
            }
        }
    }

    public function testOnlyAllAndUnreadAreChronological(): void
    {
        $chronological = array_values(array_filter(
            EntryView::cases(),
            static fn (EntryView $view): bool => $view->isChronological(),
        ));

        self::assertSame([EntryView::All, EntryView::Unread], $chronological);
    }
}
```

`tests/Repository/EntryQueryTest.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Enum\EntryView;
use App\Enum\ListOrder;
use App\Repository\EntryListSort;
use App\Repository\EntryQuery;
use PHPUnit\Framework\TestCase;

final class EntryQueryTest extends TestCase
{
    public function testAllAndUnreadWithNoFeedOrTagHideExcludedFeeds(): void
    {
        self::assertTrue((new EntryQuery(1, EntryView::All))->hidesExcludedFeeds());
        self::assertTrue((new EntryQuery(1, EntryView::Unread))->hidesExcludedFeeds());
    }

    public function testAFeedScopedViewNeverHides(): void
    {
        self::assertFalse((new EntryQuery(1, EntryView::All, subscriptionId: 5))->hidesExcludedFeeds());
        self::assertFalse((new EntryQuery(1, EntryView::Unread, subscriptionId: 5))->hidesExcludedFeeds());
    }

    public function testATagScopedViewNeverHides(): void
    {
        self::assertFalse((new EntryQuery(1, EntryView::All, tagId: 3))->hidesExcludedFeeds());
        self::assertFalse((new EntryQuery(1, EntryView::Unread, tagId: 3))->hidesExcludedFeeds());
    }

    public function testFavoritesKeptAndViewedNeverHide(): void
    {
        foreach ([EntryView::Favorites, EntryView::Kept, EntryView::Viewed] as $view) {
            self::assertFalse((new EntryQuery(1, $view))->hidesExcludedFeeds(), $view->value);
        }
    }

    public function testAllAndUnreadFanInAcrossFeeds(): void
    {
        self::assertTrue((new EntryQuery(1, EntryView::All))->isDateOrderedFanIn());
        self::assertTrue((new EntryQuery(1, EntryView::Unread))->isDateOrderedFanIn());
    }

    public function testATagScopedChronologicalViewStillFansIn(): void
    {
        self::assertTrue((new EntryQuery(1, EntryView::All, tagId: 3))->isDateOrderedFanIn());
        self::assertTrue((new EntryQuery(1, EntryView::Unread, tagId: 3))->isDateOrderedFanIn());
    }

    public function testASingleSubscriptionScopeNeverFansIn(): void
    {
        self::assertFalse((new EntryQuery(1, EntryView::All, subscriptionId: 5))->isDateOrderedFanIn());
        self::assertFalse((new EntryQuery(1, EntryView::Unread, subscriptionId: 5))->isDateOrderedFanIn());
    }

    public function testStateDrivenViewsNeverFanIn(): void
    {
        foreach ([EntryView::Favorites, EntryView::Kept, EntryView::Viewed] as $view) {
            self::assertFalse((new EntryQuery(1, $view))->isDateOrderedFanIn(), $view->value);
        }
    }

    public function testTheForYouFeedIsNotAnEntryListQuery(): void
    {
        $this->expectException(\LogicException::class);

        new EntryQuery(1, EntryView::ForYou);
    }

    public function testTheOrderingPairsTheViewsSortWithTheRequestedOrder(): void
    {
        $viewed = (new EntryQuery(1, EntryView::Viewed, order: ListOrder::OldestFirst))->ordering();
        self::assertSame(EntryListSort::ViewedAt, $viewed->sort);
        self::assertSame(ListOrder::OldestFirst, $viewed->order);

        $all = (new EntryQuery(1))->ordering();
        self::assertSame(EntryListSort::PublishedDate, $all->sort);
        self::assertSame(ListOrder::NewestFirst, $all->order);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Enum/EntryViewTest.php tests/Repository/EntryQueryTest.php`
Expected: FAIL: `Class "App\Enum\EntryView" not found`.

- [ ] **Step 3: Write the enum**

`src/Enum/EntryView.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enum;

use App\Exception\ValidationException;

enum EntryView: string
{
    case All = 'all';
    case Unread = 'unread';
    case Favorites = 'favorites';
    case Kept = 'kept';
    case Viewed = 'viewed';
    case ForYou = 'for-you';

    /**
     * @throws ValidationException when the value names no view
     */
    public static function fromRequestValue(?string $value): self
    {
        if ($value === null) {
            return self::All;
        }

        return self::tryFrom($value)
            ?? throw new ValidationException(['view' => ['Unknown view. Use one of: ' . self::valueList() . '.']]);
    }

    public function isChronological(): bool
    {
        return $this === self::All || $this === self::Unread;
    }

    private static function valueList(): string
    {
        return implode(', ', array_map(static fn (self $view): string => $view->value, self::cases()));
    }
}
```

- [ ] **Step 4: Type `EntryQuery`, `EntryListSort` and `EntryScopePredicates` on it**

`src/Repository/EntryQuery.php`:
- Add `use App\Enum\EntryView;` before `use App\Enum\ListOrder;`.
- Replace the constructor docblock and the constructor:
```php
    /**
     * @param int $limit the size the client asked for
     */
    public function __construct(
        public int $userId,
        public EntryView $view = EntryView::All,
        public ?int $subscriptionId = null,
        public ?int $tagId = null,
        public ?EntryCursor $cursor = null,
        int $limit = self::DEFAULT_LIMIT,
        public ListOrder $order = ListOrder::NewestFirst,
    ) {
        if ($view === EntryView::ForYou) {
            throw new \LogicException('The for-you feed pages through ForYouFeedQuery, never an EntryQuery.');
        }
        $this->limit = self::clampLimit($limit);
    }
```
- In `hidesExcludedFeeds()`, replace `return $this->view === 'all' || $this->view === 'unread';` with `return $this->view->isChronological();`.
- Replace the docblock and body of `isDateOrderedFanIn()`:
```php
    /**
     * A chronological list spanning many feeds, a tag's subset included (#1040) but not one subscription:
     * the only shape that gains from driving the join from `entry` and its effective-date index.
     */
    public function isDateOrderedFanIn(): bool
    {
        if ($this->subscriptionId !== null) {
            return false;
        }

        return $this->view->isChronological();
    }
```

`src/Repository/EntryListSort.php`:
- Add `use App\Enum\EntryView;` after `namespace App\Repository;`.
- Replace `forView()`'s signature and body. Keep its docblock:
```php
    public static function forView(EntryView $view): self
    {
        return $view === EntryView::Viewed ? self::ViewedAt : self::PublishedDate;
    }
```

`src/Repository/EntryScopePredicates.php`:
- Add `use App\Enum\EntryView;` before `use Doctrine\DBAL\Types\Types;`.
- Replace `applyView()`:
```php
    private function applyView(QueryBuilder $qb, EntryAliases $a, EntryView $view): void
    {
        switch ($view) {
            case EntryView::Unread:
                $this->unread($qb, $a);
                break;
            case EntryView::Favorites:
                $this->stateFlagIsSet($qb, $a, 'isFavorite');
                break;
            case EntryView::Kept:
                $this->stateFlagIsSet($qb, $a, 'isKept');
                break;
            case EntryView::Viewed:
                $this->stateFlagIsSet($qb, $a, 'isViewed');
                break;
            case EntryView::All:
            case EntryView::ForYou:
                break;
        }
    }

    private function stateFlagIsSet(QueryBuilder $qb, EntryAliases $a, string $flag): void
    {
        $qb->andWhere(\sprintf('%s.%s = :flag', $a->state, $flag))->setParameter('flag', true, Types::BOOLEAN);
    }
```

- [ ] **Step 5: The controller parses the enum**

`src/Controller/Api/EntryController.php`:
- Add `use App\Enum\EntryView;` before `use App\Enum\ListOrder;`.
- Delete `use App\Exception\ValidationException;`. The `match` below was its only user, and the enum now throws it.
- Replace everything in `list()` from the `// Validate \`view\` in-controller` comment through the `if ($view === 'for-you') { … }` block:
```php
        // Parsed here, not by a MapQueryParameter filter, so a bad view is the same
        // `validation_error` as every other invalid field, which the client switches on.
        $entryView = EntryView::fromRequestValue($view);
        $listOrder = ListOrder::fromRequestValue($page->order);

        // Score-ranked: it pages with its own cursor and never reaches EntryQuery.
        if ($entryView === EntryView::ForYou) {
            return new JsonResponse(RecommendationFeedJson::page($this->forYouFeed->page(
                new ForYouFeedQuery($user, $page->cursor, $page->limit, $page->unread),
            )));
        }
```
- In the `new EntryQuery(` call, change `view: $view,` to `view: $entryView,`.
- Change the return to `return new JsonResponse(EntryPage::of($rows, $query->limit, EntryListSort::forView($entryView)));`.

- [ ] **Step 6: Move the repository tests to the enum**

#1164 rewrote `EntryState` setter lines in both files but no view literal. The substitution matches view literals only:
```bash
perl -pi -e 's/view: \x27(all|unread|favorites|kept|viewed)\x27/"view: EntryView::" . ucfirst($1)/ge; s/(new EntryQuery\(\$userId), \x27(all|unread|favorites|kept|viewed)\x27/"$1, EntryView::" . ucfirst($2)/ge' tests/Repository/EntryListTest.php tests/Repository/DuplicateCollapseTest.php
```
Add the imports:
- `tests/Repository/EntryListTest.php`: `use App\Enum\EntryView;` directly before `use App\Enum\ListOrder;`.
- `tests/Repository/DuplicateCollapseTest.php`: `use App\Enum\EntryView;` directly after `use App\Entity\User;`.

Then run `git grep -nE "view: '|EntryQuery\(\\\$userId, '" -- tests`. Expected: no output.

- [ ] **Step 7: Run the entry-list suites**

Run: `php bin/phpunit tests/Enum tests/Repository/EntryQueryTest.php tests/Repository/EntryListTest.php tests/Repository/DuplicateCollapseTest.php tests/Http/EntryPageTest.php tests/Controller/Api/EntryControllerTest.php`
Expected: PASS. `testRejectsUnknownView` still receives the six-value message, and `view=for-you&order=up` still answers 422.

- [ ] **Step 8: Deletion checks, gates, commit**

Restore each change by hand before the next:
- Delete the `ForYou` guard in `EntryQuery`. `testTheForYouFeedIsNotAnEntryListQuery` must fail.
- Make `isChronological()` return `$this === self::All`. `testOnlyAllAndUnreadAreChronological`, `testAllAndUnreadWithNoFeedOrTagHideExcludedFeeds` and `EntryListTest`'s unread fan-in cases must fail.
- Change `'isKept'` to `'isFavorite'` in `applyView()`. `EntryListTest`'s kept-view test must fail.

Then run `composer check && composer md` and the PhpStorm lint.
```bash
git add src/Enum/EntryView.php src/Repository/EntryQuery.php src/Repository/EntryListSort.php src/Repository/EntryScopePredicates.php src/Controller/Api/EntryController.php tests/Enum/EntryViewTest.php tests/Repository/EntryQueryTest.php tests/Repository/EntryListTest.php tests/Repository/DuplicateCollapseTest.php
git commit -m "refactor(#1167): the entry list view is an EntryView enum and for-you cannot reach EntryQuery"
```

---

### Task A5: `FeedTagMove::move(Subscription, MoveFeedToTagRequest)`

**Files:**
- Modify: `src/Service/Subscription/FeedTagMove.php:31-58`
- Modify: `src/Service/Subscription/SubscriptionEditor.php:34-44`
- Modify: `tests/Service/Subscription/FeedTagMoveTest.php` (call sites and the helper)

**Interfaces:**
- Consumes: `App\Dto\Subscription\MoveFeedToTagRequest(?int $fromTagId = null, ?int $toTagId = null, ?int $position = null)`. A null tag id is the untagged "Feeds" list, and a null position appends. Services already take DTOs: `SubscriptionEditor::moveToTag` takes this one.
- Produces: `FeedTagMove::move(Subscription $subscription, MoveFeedToTagRequest $move): void`. The owner is `$subscription->getUser()->requireId()`, the value `SubscriptionEditor` passed before.

- [ ] **Step 1: Move the test to the new signature**

`tests/Service/Subscription/FeedTagMoveTest.php`:
- Add `use App\Dto\Subscription\MoveFeedToTagRequest;` directly before `use App\Entity\Feed;`.
- Replace the private `move()` helper:
```php
    private function move(Subscription $subscription, MoveFeedToTagRequest $move): void
    {
        $service = self::getContainer()->get(FeedTagMove::class);
        self::assertInstanceOf(FeedTagMove::class, $service);
        $service->move($subscription, $move);
    }
```
- Replace the call sites, each located by its text:
```diff
-        $this->move($moved, $news->requireId(), $tech->requireId(), 1, $user->requireId());
+        $this->move($moved, new MoveFeedToTagRequest($news->requireId(), $tech->requireId(), 1));
-        $this->move($moved, $news->requireId(), $tech->requireId(), null, $user->requireId());
+        $this->move($moved, new MoveFeedToTagRequest($news->requireId(), $tech->requireId()));
-        $this->move($moved, $news->requireId(), $tech->requireId(), 0, $user->requireId());
+        $this->move($moved, new MoveFeedToTagRequest($news->requireId(), $tech->requireId(), 0));
-        $this->move($moved, $news->requireId(), null, 1, $user->requireId());
+        $this->move($moved, new MoveFeedToTagRequest($news->requireId(), null, 1));
-        $this->move($moved, $news->requireId(), $tech->requireId(), 99, $user->requireId());
+        $this->move($moved, new MoveFeedToTagRequest($news->requireId(), $tech->requireId(), 99));
-        $this->move($moved, $tech->requireId(), $tech->requireId(), 0, $user->requireId());
+        $this->move($moved, new MoveFeedToTagRequest($tech->requireId(), $tech->requireId(), 0));
-        $this->move($moved, null, $foreignTag->requireId(), 0, $user->requireId());
+        $this->move($moved, new MoveFeedToTagRequest(null, $foreignTag->requireId(), 0));
```
The `…, 0, $user->requireId());` line appears twice, at lines 61 and 77, so use `replace_all` for it.

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Service/Subscription/FeedTagMoveTest.php`
Expected: FAIL: `FeedTagMove::move(): Argument #2 ($fromTagId) must be of type ?int, App\Dto\Subscription\MoveFeedToTagRequest given`.

- [ ] **Step 3: Implement**

`src/Service/Subscription/FeedTagMove.php`:
- Add `use App\Dto\Subscription\MoveFeedToTagRequest;` directly before `use App\Entity\Subscription;`.
- Replace `move()`:
```php
    public function move(Subscription $subscription, MoveFeedToTagRequest $move): void
    {
        // A same-list drop is a reorder, which the reorder endpoints own.
        if ($move->fromTagId === $move->toTagId) {
            return;
        }

        $userId = $subscription->getUser()->requireId();
        $fromTag = $this->ownedTagOrNull($move->fromTagId, $userId);
        $toTag = $this->ownedTagOrNull($move->toTagId, $userId);

        if (null !== $fromTag) {
            $subscription->removeTag($fromTag);
        }

        if (null !== $toTag) {
            $this->placeInTag($subscription, $toTag, $move->position);

            return;
        }

        if ($subscription->getTags()->isEmpty()) {
            $this->placeInUntaggedList($subscription, $userId, $move->position);
        }
    }
```

`src/Service/Subscription/SubscriptionEditor.php`, in `moveToTag()`:
```php
    public function moveToTag(Subscription $subscription, MoveFeedToTagRequest $request): void
    {
        $this->feedTagMove->move($subscription, $request);
        $this->entityManager->flush();
    }
```

- [ ] **Step 4: Run the subscription suites**

Run: `php bin/phpunit tests/Service/Subscription tests/Controller/Api/MoveFeedToTagTest.php tests/Controller/Api/SubscriptionControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion check, gates, commit**

- Replace `$subscription->getUser()->requireId()` with `$subscription->requireId()`. `testRejectsATagTheUserDoesNotOwn` still passes, because that id owns no tag. At least one of the placement tests must fail, where `findOneOwnedBy` then misses the user's own tag. If none fails, report it: the owner derivation is then untested, and a test that moves between two of the user's tags under a different subscription id is needed.

Restore the line, then run `composer check && composer md` and the PhpStorm lint.
```bash
git add src/Service/Subscription/FeedTagMove.php src/Service/Subscription/SubscriptionEditor.php tests/Service/Subscription/FeedTagMoveTest.php
git commit -m "refactor(#1167): feed tag move takes the drag request and derives the owner"
```

---

### Task A6: `CatalogFaviconDueCriteria`

**Files:**
- Create: `src/Repository/CatalogFaviconDueCriteria.php`
- Modify: `src/Repository/CatalogFeedRepository.php:67-113`
- Modify: `src/Service/Catalog/CatalogFaviconWarmer.php:48-95`
- Modify: `tests/Repository/CatalogFeedRepositoryTest.php:76-155` (two tests reworked, one added)
- Modify: `tests/Service/Catalog/CatalogFaviconWarmerTest.php:183-193`

**Interfaces:**
- Produces:
  - `final readonly class App\Repository\CatalogFaviconDueCriteria(\DateTimeImmutable $staleBefore, \DateTimeImmutable $retryBefore)`.
  - `CatalogFeedRepository::findNeedingFavicon(CatalogFaviconDueCriteria $criteria, ?int $limit): list<CatalogFeed>`.
  - `CatalogFeedRepository::countNeedingFavicon(CatalogFaviconDueCriteria $criteria): int`.

- [ ] **Step 1: Write the failing tests**

`tests/Repository/CatalogFeedRepositoryTest.php`:
- Add `use App\Repository\CatalogFaviconDueCriteria;` directly before `use App\Repository\CatalogFeedRepository;`.
- Add the constant after the class's opening brace: `private const string NOW = '2026-07-01T00:00:00+00:00';`.
- Replace `testFindNeedingFaviconAppliesStaleAndRetryThresholdsAndSkipsDisabledFeeds()` and `testFindNeedingFaviconRespectsLimit()` with:
```php
    public function testFindNeedingFaviconAppliesStaleAndRetryThresholdsAndSkipsDisabledFeeds(): void
    {
        $this->persistFaviconQueue();

        $rows = $this->catalogFeeds()->findNeedingFavicon($this->faviconCriteria(), null);

        self::assertSame(['Never Fetched Feed', 'Stale Icon Feed', 'Long Failed Feed'], array_map(
            static fn (CatalogFeed $f): string => $f->getTitle(),
            $rows,
        ));
    }

    public function testCountNeedingFaviconCountsTheRowsFindNeedingFaviconReturns(): void
    {
        $this->persistFaviconQueue();

        self::assertSame(3, $this->catalogFeeds()->countNeedingFavicon($this->faviconCriteria()));
    }

    public function testFindNeedingFaviconRespectsLimit(): void
    {
        $this->persistFaviconQueue();

        self::assertCount(2, $this->catalogFeeds()->findNeedingFavicon($this->faviconCriteria(), 2));
    }
```
- Add these helpers at the end of the class:
```php
    private function catalogFeeds(): CatalogFeedRepository
    {
        $repository = self::getContainer()->get(CatalogFeedRepository::class);
        self::assertInstanceOf(CatalogFeedRepository::class, $repository);

        return $repository;
    }

    private function faviconCriteria(): CatalogFaviconDueCriteria
    {
        $now = new \DateTimeImmutable(self::NOW);

        return new CatalogFaviconDueCriteria($now->modify('-7 days'), $now->modify('-1 day'));
    }

    /**
     * Six feeds, three of them due: never fetched, stale, and failed before the retry window.
     */
    private function persistFaviconQueue(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $now = new \DateTimeImmutable(self::NOW);
        $criteria = $this->faviconCriteria();

        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');

        $neverFetched = new CatalogFeed($category, 'Never Fetched Feed', 'https://example.com/never.xml');

        $staleIcon = new CatalogFeed($category, 'Stale Icon Feed', 'https://example.com/stale.xml');
        $staleIcon->storeFavicon(
            'https://example.com/stale-favicon.ico',
            'bytes',
            'image/x-icon',
            $criteria->staleBefore->modify('-1 day'),
        );

        $freshIcon = new CatalogFeed($category, 'Fresh Icon Feed', 'https://example.com/fresh.xml');
        $freshIcon->storeFavicon('https://example.com/fresh-favicon.ico', 'bytes', 'image/x-icon', $now);

        $recentlyFailed = new CatalogFeed($category, 'Recently Failed Feed', 'https://example.com/recently-failed.xml');
        $recentlyFailed->recordFaviconFailure($now);

        $longFailed = new CatalogFeed($category, 'Long Failed Feed', 'https://example.com/long-failed.xml');
        $longFailed->recordFaviconFailure($criteria->retryBefore->modify('-10 days'));

        $disabled = new CatalogFeed($category, 'Disabled Feed', 'https://example.com/disabled.xml');
        $disabled->setEnabled(false);

        $em->persist($category);
        foreach ([$neverFetched, $staleIcon, $freshIcon, $recentlyFailed, $longFailed, $disabled] as $feed) {
            $em->persist($feed);
        }
        $em->flush();
        $em->clear();
    }
```

`tests/Service/Catalog/CatalogFaviconWarmerTest.php`:
- Add `use App\Repository\CatalogFaviconDueCriteria;` in the import block, in alphabetical order.
- Replace the body of `due()`:
```php
        $now = $this->clock()->now();

        return $this->feeds()->findNeedingFavicon(
            new CatalogFaviconDueCriteria($now->sub(new \DateInterval('P90D')), $now->sub(new \DateInterval('P14D'))),
            null,
        );
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Repository/CatalogFeedRepositoryTest.php`
Expected: FAIL: `Class "App\Repository\CatalogFaviconDueCriteria" not found`.

- [ ] **Step 3: Implement**

`src/Repository/CatalogFaviconDueCriteria.php`:
```php
<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * A catalog feed wants an icon when it has none or one older than $staleBefore, unless it failed after $retryBefore.
 */
final readonly class CatalogFaviconDueCriteria
{
    public function __construct(
        public \DateTimeImmutable $staleBefore,
        public \DateTimeImmutable $retryBefore,
    ) {
    }
}
```

`src/Repository/CatalogFeedRepository.php`:
- Add `use Doctrine\ORM\QueryBuilder;` after `use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;`.
- Replace `findNeedingFavicon()` and `countNeedingFavicon()` together with their docblocks:
```php
    /**
     * The warm queue, oldest row first.
     *
     * @return list<CatalogFeed>
     */
    public function findNeedingFavicon(CatalogFaviconDueCriteria $criteria, ?int $limit): array
    {
        $qb = $this->needingFaviconQueryBuilder($criteria)->orderBy('f.id', 'ASC');
        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        /** @var list<CatalogFeed> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    public function countNeedingFavicon(CatalogFaviconDueCriteria $criteria): int
    {
        return (int) $this->needingFaviconQueryBuilder($criteria)
            ->select('COUNT(f.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
```
- Add at the end of the class:
```php
    private function needingFaviconQueryBuilder(CatalogFaviconDueCriteria $criteria): QueryBuilder
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.enabled = true')
            ->andWhere('f.faviconFetchedAt IS NULL OR f.faviconFetchedAt < :stale')
            ->setParameter('stale', $criteria->staleBefore)
            ->andWhere('f.faviconFailedAt IS NULL OR f.faviconFailedAt < :retry')
            ->setParameter('retry', $criteria->retryBefore);
    }
```

`src/Service/Catalog/CatalogFaviconWarmer.php`:
- Add `use App\Repository\CatalogFaviconDueCriteria;` before `use App\Repository\CatalogFeedRepository;`.
- In `warm()`:
```diff
-        [$staleBefore, $retryBefore] = $this->windows($now);
-
-        $due = $this->feeds->findNeedingFavicon($staleBefore, $retryBefore, $limit ?? self::BATCH_LIMIT);
+        $criteria = $this->dueCriteriaAt($now);
+
+        $due = $this->feeds->findNeedingFavicon($criteria, $limit ?? self::BATCH_LIMIT);
```
```diff
-            $this->feeds->countNeedingFavicon($staleBefore, $retryBefore),
+            $this->feeds->countNeedingFavicon($criteria),
```
- Replace `windows()` and its docblock:
```php
    /**
     * @throws \DateInvalidOperationException
     */
    private function dueCriteriaAt(\DateTimeImmutable $now): CatalogFaviconDueCriteria
    {
        return new CatalogFaviconDueCriteria(
            $now->sub(new \DateInterval(self::STALE_AFTER)),
            $now->sub(new \DateInterval(self::RETRY_FAILURES_AFTER)),
        );
    }
```

- [ ] **Step 4: Run the catalog suites**

Run: `php bin/phpunit tests/Repository/CatalogFeedRepositoryTest.php tests/Service/Catalog tests/Command/WarmCatalogFaviconsCommandTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion checks, gates, commit**

Break a copy of the query, not the shared builder, so each check isolates what it tests:
- In `countNeedingFavicon()`, build from `$this->createQueryBuilder('f')->select('COUNT(f.id)')->andWhere('f.enabled = true')` without the two window clauses. `testCountNeedingFaviconCountsTheRowsFindNeedingFaviconReturns` must fail (5, not 3).
- In the shared builder, delete the `faviconFailedAt` clause. The find test must fail, because `Recently Failed Feed` appears.

Restore each change, then run `composer check && composer md` and the PhpStorm lint.
```bash
git add src/Repository/CatalogFaviconDueCriteria.php src/Repository/CatalogFeedRepository.php src/Service/Catalog/CatalogFaviconWarmer.php tests/Repository/CatalogFeedRepositoryTest.php tests/Service/Catalog/CatalogFaviconWarmerTest.php
git commit -m "refactor(#1167): catalog favicon find and count share one CatalogFaviconDueCriteria"
```

---

### Task A7a: `InstanceSettingsRequest` requires every setting

**Files:**
- Create: `src/Http/FullReplacePayload.php` (the shared serializer context; A7b reuses it)
- Modify: `src/Dto/Admin/InstanceSettingsRequest.php` (rewritten in full)
- Modify: `src/Controller/Admin/AdminSettingsController.php:32`
- Modify: `src/Entity/InstanceSetting.php:71-74` (a docblock that names the DTO's default)
- Modify: `tests/Controller/Admin/AdminSettingsControllerTest.php` (PUT bodies, one helper, two tests)
- Modify: `tests/Dto/Admin/InstanceSettingsRequestTest.php` (rewritten in full)
- Modify: `tests/Service/Settings/RelyingPartyChangeTest.php:90-97`
- Modify: `bin/e2e.sh:82-89` (comment)
- Modify: `frontend/src/app/settings/admin/admin-settings/admin-settings-api.ts:32-38` (comment)

**Interfaces:**
- Produces: `InstanceSettingsRequest::__construct(bool $requireEmailConfirmation, bool $requireApproval, ?string $publicBaseUrl, ?string $passkeyRpId, ?string $passkeyRpName, bool $passkeySignInEnabled, bool $invalidateExistingPasskeys = false)`. Every setting is required. The one-shot confirmation moves last and keeps its default.
- Produces `final class App\Http\FullReplacePayload { public const array CONTEXT = [AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true]; }`. A7b's three controllers use it too, so the context is written once, not four times.
- The controller adds `serializationContext: FullReplacePayload::CONTEXT`. The serializer otherwise gives a missing nullable parameter `null` even without a default (`AbstractNormalizer.php:396`), and that would reset `publicBaseUrl` exactly as before.
- Wire change (deliberate): a PUT that leaves a setting out answers 422 `validation_error` with that field in `errors` and stores nothing. The error key is the parameter name (`getAttributeDenormalizationContext` sets `deserialization_path` to it).

- [ ] **Step 1: Write the failing tests and full request bodies**

`tests/Controller/Admin/AdminSettingsControllerTest.php`:
- Add this helper after `passkeys()`:
```php
    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function settingsBody(array $changes = []): array
    {
        return [
            'requireEmailConfirmation' => true,
            'requireApproval' => true,
            'publicBaseUrl' => null,
            'passkeyRpId' => null,
            'passkeyRpName' => null,
            'passkeySignInEnabled' => false,
            ...$changes,
        ];
    }
```
- Every `jsonRequest('PUT', self::SETTINGS, [ … ])` passes the full body instead. Each literal array becomes `$this->settingsBody([ … ])` holding only the entries that differ from the helper's defaults:

| Test | New body argument |
|---|---|
| `testPutUpdatesTheToggles` | `$this->settingsBody(['requireEmailConfirmation' => false])`. Also delete this test's docblock: it describes the reset this task removes. |
| `testPutPersistsThePublicBaseUrl` | `$this->settingsBody(['publicBaseUrl' => 'https://reader.example.ts.net/reader'])` |
| `testPutRejectsAMalformedPublicBaseUrl` | `$this->settingsBody(['publicBaseUrl' => 'not a url'])` |
| `testPutRejectsANonBooleanPayload` | `$this->settingsBody(['requireEmailConfirmation' => 'nope'])` |
| `testARelyingPartyIdThatCouldNeverWorkIsRefused` | `$this->settingsBody(['publicBaseUrl' => 'https://example.test', 'passkeyRpId' => '203.0.113.5', 'passkeyRpName' => 'Reader'])` |
| `testChangingTheRelyingPartyIdIsRefusedWhileCredentialsExist` | `$this->settingsBody(['passkeyRpId' => 'example.test', 'passkeyRpName' => 'Reader'])` |
| `testAConfirmedChangeDeletesEveryCredential` | `$this->settingsBody(['passkeyRpId' => 'example.test', 'passkeyRpName' => 'Reader', 'invalidateExistingPasskeys' => true])` |
| `testTheProxiedHostIsAcceptedWhileThePublicBaseUrlNamesAnother` | `$this->settingsBody(['publicBaseUrl' => 'http://localhost:4200', 'passkeyRpId' => 'reader.example.ts.net', 'passkeyRpName' => 'Reader'])` |
| `testChangingPublicBaseUrlAloneLeavesTheRelyingPartyAlone` | first PUT `$this->settingsBody(['publicBaseUrl' => 'https://a.example'])`, second `$this->settingsBody(['publicBaseUrl' => 'https://b.example'])` |
| `testPinningTheRelyingPartyIdSurvivesAPublicBaseUrlChangeWithNoConfirmation` | first `$this->settingsBody(['publicBaseUrl' => 'https://a.example.test', 'passkeyRpId' => 'example.test', 'passkeyRpName' => 'Reader'])`, second the same with `'https://b.example.test'` |
| `testResendingTheSameRelyingPartyIdSucceedsWithCredentialsPresent` | `$body = $this->settingsBody(['passkeyRpId' => 'example.test', 'passkeyRpName' => 'Reader']);` |
| `testPasskeySignInEnabledDefaultsToFalseAndRoundTrips` | `$this->settingsBody(['passkeySignInEnabled' => true])` |
| `testPasskeyRpIdEffectiveReflectsTheStoredOverrideOrTheServingHost` | `$this->settingsBody(['passkeyRpId' => 'example.test', 'passkeyRpName' => 'Reader'])` |

- Add two tests after `testPutRejectsANonBooleanPayload()`:
```php
    public function testPutRefusesABodyThatLeavesASettingOutAndKeepsIt(): void
    {
        $client = $this->adminClient();
        $client->jsonRequest('PUT', self::SETTINGS, $this->settingsBody(['passkeySignInEnabled' => true]));
        self::assertResponseIsSuccessful();

        $incomplete = $this->settingsBody();
        unset($incomplete['passkeySignInEnabled']);
        $client->jsonRequest('PUT', self::SETTINGS, $incomplete);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->payload($client);
        self::assertSame('validation_error', $problem['type']);
        self::assertIsArray($problem['errors']);
        self::assertSame(['passkeySignInEnabled'], array_keys($problem['errors']));

        $client->request('GET', self::SETTINGS);
        self::assertTrue($this->payload($client)['passkeySignInEnabled']);
    }

    public function testPutRefusesABodyThatLeavesANullableSettingOutAndKeepsIt(): void
    {
        $client = $this->adminClient();
        $client->jsonRequest('PUT', self::SETTINGS, $this->settingsBody(['publicBaseUrl' => 'https://kept.example']));
        self::assertResponseIsSuccessful();

        $incomplete = $this->settingsBody();
        unset($incomplete['publicBaseUrl']);
        $client->jsonRequest('PUT', self::SETTINGS, $incomplete);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->payload($client);
        self::assertIsArray($problem['errors']);
        self::assertSame(['publicBaseUrl'], array_keys($problem['errors']));

        $client->request('GET', self::SETTINGS);
        self::assertSame('https://kept.example', $this->payload($client)['publicBaseUrl']);
    }
```
`payload()` comes from `ApiTestCase`, which the other tests in the file already use.

`tests/Dto/Admin/InstanceSettingsRequestTest.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Dto\Admin;

use App\Dto\Admin\InstanceSettingsRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class InstanceSettingsRequestTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testPasskeyRpIdAtTheLengthLimitIsValidButOneOverIsNot(): void
    {
        $atLimit = self::withRelyingParty(str_repeat('a', 255), null);
        $overLimit = self::withRelyingParty(str_repeat('a', 256), null);

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }

    public function testPasskeyRpNameAtTheLengthLimitIsValidButOneOverIsNot(): void
    {
        $atLimit = self::withRelyingParty(null, str_repeat('a', 100));
        $overLimit = self::withRelyingParty(null, str_repeat('a', 101));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }

    public function testTheUpdateCarriesEverySettingAndTheConfirmationDefaultsToOff(): void
    {
        $request = new InstanceSettingsRequest(
            requireEmailConfirmation: false,
            requireApproval: true,
            publicBaseUrl: 'https://reader.example',
            passkeyRpId: 'reader.example',
            passkeyRpName: 'Reader',
            passkeySignInEnabled: true,
        );

        $update = $request->toUpdate();

        self::assertFalse($request->invalidateExistingPasskeys);
        self::assertFalse($update->requireEmailConfirmation);
        self::assertTrue($update->requireApproval);
        self::assertSame('https://reader.example', $update->publicBaseUrl);
        self::assertSame('reader.example', $update->passkeyRpId);
        self::assertSame('Reader', $update->passkeyRpName);
        self::assertTrue($update->passkeySignInEnabled);
    }

    private static function withRelyingParty(?string $passkeyRpId, ?string $passkeyRpName): InstanceSettingsRequest
    {
        return new InstanceSettingsRequest(
            requireEmailConfirmation: true,
            requireApproval: true,
            publicBaseUrl: null,
            passkeyRpId: $passkeyRpId,
            passkeyRpName: $passkeyRpName,
            passkeySignInEnabled: false,
        );
    }
}
```

`tests/Service/Settings/RelyingPartyChangeTest.php`, the `requestFor()` helper:
```php
    private function requestFor(string $passkeyRpId, string $publicBaseUrl): InstanceSettingsRequest
    {
        return new InstanceSettingsRequest(
            requireEmailConfirmation: true,
            requireApproval: true,
            publicBaseUrl: $publicBaseUrl,
            passkeyRpId: $passkeyRpId,
            passkeyRpName: 'Reader',
            passkeySignInEnabled: false,
        );
    }
```

- [ ] **Step 2: Run to verify the new tests fail**

Run: `php bin/phpunit tests/Controller/Admin/AdminSettingsControllerTest.php tests/Dto/Admin/InstanceSettingsRequestTest.php tests/Service/Settings/RelyingPartyChangeTest.php`
Expected:
- The two new controller tests FAIL: 200 instead of 422, because the defaults fill the missing fields.
- Every other test in the three files PASSES, because full bodies and named arguments work with the defaults still in place.

- [ ] **Step 3: Implement**

`src/Dto/Admin/InstanceSettingsRequest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Service\Settings\InstanceSettingsUpdate;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Every setting is required, so a PUT that leaves one out is a 422, not a reset. A null URL or relying-party field
 * restores its derived default. `invalidateExistingPasskeys` is no setting: it confirms an id change refused with 409.
 */
final readonly class InstanceSettingsRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Type('bool')]
        public bool $requireEmailConfirmation,
        #[Assert\NotNull]
        #[Assert\Type('bool')]
        public bool $requireApproval,
        #[Assert\Url(requireTld: false)]
        #[Assert\Length(max: 255)]
        public ?string $publicBaseUrl,
        #[Assert\Length(max: 255)]
        public ?string $passkeyRpId,
        #[Assert\Length(max: 100)]
        public ?string $passkeyRpName,
        #[Assert\NotNull]
        #[Assert\Type('bool')]
        public bool $passkeySignInEnabled,
        public bool $invalidateExistingPasskeys = false,
    ) {
    }

    public function toUpdate(): InstanceSettingsUpdate
    {
        return new InstanceSettingsUpdate(
            requireEmailConfirmation: $this->requireEmailConfirmation,
            requireApproval: $this->requireApproval,
            publicBaseUrl: $this->publicBaseUrl,
            passkeyRpId: $this->passkeyRpId,
            passkeyRpName: $this->passkeyRpName,
            passkeySignInEnabled: $this->passkeySignInEnabled,
        );
    }
}
```

`src/Http/FullReplacePayload.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

final class FullReplacePayload
{
    /** Without it the serializer gives a missing nullable setting null instead of refusing the body. */
    public const array CONTEXT = [AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true];
}
```

`src/Controller/Admin/AdminSettingsController.php`:
- Add `use App\Http\FullReplacePayload;` after `use App\Http\Admin\InstanceSettingsJson;`.
- Replace the `update` signature:
```php
    public function update(
        #[MapRequestPayload(serializationContext: FullReplacePayload::CONTEXT)] InstanceSettingsRequest $request,
    ): JsonResponse {
```

`src/Entity/InstanceSetting.php`, the `$passkeySignInEnabled` docblock:
```diff
-     * This default is also declared in the migration's column DEFAULT (this
-     * property's own `options`), and in the constructor defaults of
-     * {@see \App\Service\Settings\InstanceSettingsUpdate} and
-     * {@see \App\Dto\Admin\InstanceSettingsRequest}.
+     * This default is also declared in the migration's column DEFAULT (this
+     * property's own `options`) and in the constructor default of
+     * {@see \App\Service\Settings\InstanceSettingsUpdate}.
```

`bin/e2e.sh`, the comment above `settings_body()`. Replace lines 82-89 (the eight `#` lines from `# Build a full InstanceSettingsRequest body` through `# passkeyRpIdEffective` … `not part of the PUT DTO.`) with:
```bash
# Build a full InstanceSettingsRequest body from the current settings on stdin, optionally forcing the two
# registration gates. The PUT refuses a body that leaves a setting out, so every writable field is carried
# through verbatim; mailEnabled, publicBaseUrlDefault and passkeyRpIdEffective are read-only in the GET.
```

`frontend/src/app/settings/admin/admin-settings/admin-settings-api.ts`, the docblock above `InstanceSettingsUpdate`:
```ts
/**
 * The full-replace PUT body (#624): leaving a setting out is a 422 (#1167).
 * `invalidateExistingPasskeys` is no stored setting: it confirms a relying-party id change the server
 * refused with a 409.
 */
```

- [ ] **Step 4: Run the settings suites**

Run: `php bin/phpunit tests/Controller/Admin/AdminSettingsControllerTest.php tests/Dto/Admin tests/Service/Settings`
Expected: PASS.

Then run `docker compose exec -T frontend npm run check`. Expected: PASS.

- [ ] **Step 5: Deletion checks, gates, commit**

Restore each change by hand before the next:
- Remove `serializationContext: FullReplacePayload::CONTEXT` from the attribute. `testPutRefusesABodyThatLeavesANullableSettingOutAndKeepsIt` must fail (200).
- Give `$passkeySignInEnabled` its old `= false` default, and move it back before `$invalidateExistingPasskeys`. `testPutRefusesABodyThatLeavesASettingOutAndKeepsIt` must fail.

Then run `composer check && composer md` and the PhpStorm lint. Also run `shellcheck bin/e2e.sh`; CI fails on any finding.
```bash
git add src/Http/FullReplacePayload.php src/Dto/Admin/InstanceSettingsRequest.php src/Controller/Admin/AdminSettingsController.php src/Entity/InstanceSetting.php tests/Controller/Admin/AdminSettingsControllerTest.php tests/Dto/Admin/InstanceSettingsRequestTest.php tests/Service/Settings/RelyingPartyChangeTest.php bin/e2e.sh ../frontend/src/app/settings/admin/admin-settings/admin-settings-api.ts
git commit -m "refactor(#1167): admin settings put requires every setting instead of resetting a missing one"
```

---

### Task A7b: Proxy, Grafana and Mail settings requests require every setting

**Files:**
- Modify: `src/Dto/Admin/ProxySettingsRequest.php`, `src/Dto/Admin/GrafanaSettingsRequest.php` and `src/Dto/Admin/MailSettingsRequest.php` (each rewritten in full)
- Modify: `src/Controller/Admin/AdminProxyController.php:33`, `src/Controller/Admin/AdminGrafanaController.php:32` and `src/Controller/Admin/AdminMailController.php:35`
- Create: `tests/Support/SettingsRequests.php`
- Modify: `tests/Controller/Admin/AdminProxyControllerTest.php`, `AdminGrafanaControllerTest.php` and `AdminMailControllerTest.php` (full bodies, and one 422 test each)
- Modify: `tests/Dto/Admin/ProxySettingsRequestTest.php`, `GrafanaSettingsRequestTest.php` and `MailSettingsRequestTest.php` (the no-argument test in each)
- Modify: the seven tests that construct these DTOs, which move to the factory:
  - `tests/Service/Mail/Settings/MailConnectionTesterTest.php`,
  - `tests/Service/Mail/Settings/MailSettingsTest.php`,
  - `tests/Service/Mail/Transport/DynamicMailTransportTest.php`,
  - `tests/Service/Proxy/ProxyConnectionTesterTest.php`,
  - `tests/Service/Proxy/ProxySettingsTest.php`,
  - `tests/Functional/RequestProfilingTest.php`,
  - `tests/Service/Grafana/GrafanaSettingsTest.php`.
- Frontend: no change. Every PUT from `DraftSettingsService` goes through `put(body: Body)`, and the three subclasses (`proxy-settings.service.ts`, `grafana-settings.service.ts`, `mail-settings.service.ts`) only ever pass `{ ...this.bodyFromState(current), … }`. `bodyFromState()` returns `SaveProxySettings`, `SaveGrafanaSettings` or `SaveMailSettings`, and each of those types declares every DTO field as non-optional. TypeScript therefore refuses a body that leaves one out, and no Jest spec changes.

**Interfaces:**
- Consumes: `App\Http\FullReplacePayload::CONTEXT` (A7a).
- Produces:
  - `ProxySettingsRequest(bool $enabled, bool $directFallback, string $type, string $host, int $port, ?string $username, bool $remoteDns, ?string $password = null, bool $removePassword = false)`.
  - `GrafanaSettingsRequest(?string $lokiPushUrl, ?string $lokiUsername, ?string $grafanaUrl, ?string $pyroscopePushUrl, bool $profilingEnabled, ?string $token = null, bool $removeToken = false)`. `token`/`removeToken` move to the end.
  - `MailSettingsRequest(bool $enabled, string $host, int $port, ?string $username, string $encryption, string $fromAddress, string $fromName, bool $useProxy, ?string $password = null, bool $removePassword = false)`. `useProxy` moves before the password intent.
  - `App\Tests\Support\SettingsRequests::proxy(…)`, `::grafana(…)` and `::mail(…)`. They take the DTO's fields as named parameters with the old defaults, so a test names only what it is about.
- The secret intents keep their defaults and stay optional on the wire. The serializer takes a parameter's default before it looks at `require_all_properties` (`AbstractNormalizer.php:394`). They are one-shot instructions, not settings, like `invalidateExistingPasskeys`.
- Wire change (deliberate): a PUT to `/api/admin/proxy`, `/api/admin/grafana` or `/api/admin/mail` that leaves a setting out answers 422 `validation_error`, lists each missing field in `errors` in parameter order, and stores nothing.

- [ ] **Step 1: The factory, the full bodies and the failing tests**

`tests/Support/SettingsRequests.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Dto\Admin\GrafanaSettingsRequest;
use App\Dto\Admin\MailSettingsRequest;
use App\Dto\Admin\ProxySettingsRequest;
use App\Enum\MailEncryption;
use App\Enum\ProxyType;
use App\Service\Mail\Settings\MailConnection;
use App\Service\Proxy\ProxyConnection;

/**
 * The settings requests require every setting; these carry the old defaults so a test names only what it is about.
 */
final class SettingsRequests
{
    public static function proxy(
        bool $enabled = false,
        bool $directFallback = true,
        string $type = ProxyType::Socks5->value,
        string $host = '',
        int $port = ProxyConnection::DEFAULT_PORT,
        ?string $username = null,
        bool $remoteDns = false,
        ?string $password = null,
        bool $removePassword = false,
    ): ProxySettingsRequest {
        return new ProxySettingsRequest(
            enabled: $enabled,
            directFallback: $directFallback,
            type: $type,
            host: $host,
            port: $port,
            username: $username,
            remoteDns: $remoteDns,
            password: $password,
            removePassword: $removePassword,
        );
    }

    public static function grafana(
        ?string $lokiPushUrl = null,
        ?string $lokiUsername = null,
        ?string $grafanaUrl = null,
        ?string $token = null,
        bool $removeToken = false,
        ?string $pyroscopePushUrl = null,
        bool $profilingEnabled = false,
    ): GrafanaSettingsRequest {
        return new GrafanaSettingsRequest(
            lokiPushUrl: $lokiPushUrl,
            lokiUsername: $lokiUsername,
            grafanaUrl: $grafanaUrl,
            pyroscopePushUrl: $pyroscopePushUrl,
            profilingEnabled: $profilingEnabled,
            token: $token,
            removeToken: $removeToken,
        );
    }

    public static function mail(
        bool $enabled = false,
        string $host = '',
        int $port = MailConnection::DEFAULT_PORT,
        ?string $username = null,
        string $encryption = MailEncryption::Starttls->value,
        string $fromAddress = '',
        string $fromName = '',
        ?string $password = null,
        bool $removePassword = false,
        bool $useProxy = false,
    ): MailSettingsRequest {
        return new MailSettingsRequest(
            enabled: $enabled,
            host: $host,
            port: $port,
            username: $username,
            encryption: $encryption,
            fromAddress: $fromAddress,
            fromName: $fromName,
            useProxy: $useProxy,
            password: $password,
            removePassword: $removePassword,
        );
    }
}
```
Every existing construction uses named arguments. The factories keep the old parameter order anyway, so a positional call would still bind as it did.

Move the ten test files to the factory:
```bash
perl -pi -e 's/new ProxySettingsRequest\(/SettingsRequests::proxy(/g; s/new GrafanaSettingsRequest\(/SettingsRequests::grafana(/g; s/new MailSettingsRequest\(/SettingsRequests::mail(/g' tests/Service/Mail/Settings/MailConnectionTesterTest.php tests/Service/Mail/Settings/MailSettingsTest.php tests/Service/Mail/Transport/DynamicMailTransportTest.php tests/Service/Proxy/ProxyConnectionTesterTest.php tests/Service/Proxy/ProxySettingsTest.php tests/Functional/RequestProfilingTest.php tests/Service/Grafana/GrafanaSettingsTest.php tests/Dto/Admin/ProxySettingsRequestTest.php tests/Dto/Admin/GrafanaSettingsRequestTest.php tests/Dto/Admin/MailSettingsRequestTest.php
```
Fix the imports:
- `MailConnectionTesterTest`, `MailSettingsTest` and `DynamicMailTransportTest`: replace the two lines `use App\Dto\Admin\MailSettingsRequest;` and `use App\Dto\Admin\ProxySettingsRequest;` with `use App\Tests\Support\SettingsRequests;`.
- `ProxyConnectionTesterTest` and `ProxySettingsTest`: replace `use App\Dto\Admin\ProxySettingsRequest;` with `use App\Tests\Support\SettingsRequests;`.
- `RequestProfilingTest` and `GrafanaSettingsTest`: replace `use App\Dto\Admin\GrafanaSettingsRequest;` with `use App\Tests\Support\SettingsRequests;`.
- The three `tests/Dto/Admin/*SettingsRequestTest.php`: keep the DTO import, because Step 3 constructs the DTO directly there and `ProxySettingsRequestTest`/`MailSettingsRequestTest` name it in types. Add `use App\Tests\Support\SettingsRequests;` after `use App\Dto\Admin\…SettingsRequest;`.

Then run `git grep -nE "new (Proxy|Grafana|Mail)SettingsRequest\(" -- tests`. Expected: no output.

`tests/Controller/Admin/AdminProxyControllerTest.php`:
- Add after `requestWithJsonBody()`:
```php
    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function proxyBody(array $changes = []): array
    {
        return [
            'enabled' => true,
            'directFallback' => true,
            'type' => 'SOCKS5',
            'host' => 'proxy.example',
            'port' => 1080,
            'username' => 'user',
            'remoteDns' => false,
            ...$changes,
        ];
    }
```
- Every PUT literal already carries `enabled`/`type`/`host`/`port`/`username` with these values. Replace each literal with the helper holding only what differs:

| Test | Body |
|---|---|
| `testAdminCanRoundTripProxySettingsWithoutLeakingTheSecret` | `$this->proxyBody(['password' => 'sw0rdfish'])` |
| `testRemoteDnsIsPersistedAndEchoedBack` | `$this->proxyBody(['remoteDns' => true, 'password' => 'sw0rdfish'])` |
| `testPuttingWithoutAPasswordKeepsTheStoredSecret` | first `$this->proxyBody(['password' => 'sw0rdfish'])`, second `$this->proxyBody(['password' => null])` |
| `testUpdateRemovesTheStoredPasswordWhenRemovePasswordIsSet` | first `$this->proxyBody(['password' => 'sw0rdfish'])`, second `$this->proxyBody(['removePassword' => true])` |

- Replace `testRemoteDnsDefaultsToOffWhenTheClientOmitsIt()`, which pins the reset this task removes, with:
```php
    public function testAPutLeavingSettingsOutIsRefusedAndStoresNothing(): void
    {
        $admin = $this->admin();
        $this->requestWithJsonBody('PUT', $admin, $this->proxyBody(['remoteDns' => true, 'password' => 'sw0rdfish']));
        self::assertResponseIsSuccessful();

        $incomplete = $this->proxyBody(['host' => 'other.example']);
        unset($incomplete['username'], $incomplete['remoteDns']);
        $this->requestWithJsonBody('PUT', $admin, $incomplete);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->payload($this->client);
        self::assertSame('validation_error', $problem['type']);
        self::assertIsArray($problem['errors']);
        self::assertSame(['username', 'remoteDns'], array_keys($problem['errors']));

        $this->client->request(
            'GET',
            self::PROXY,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );
        $stored = $this->payload($this->client);
        self::assertSame('proxy.example', $stored['host']);
        self::assertTrue($stored['remoteDns']);
    }
```

`tests/Controller/Admin/AdminGrafanaControllerTest.php`:
- Add after `requestWithJsonBody()`:
```php
    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function grafanaBody(array $changes = []): array
    {
        return [
            'lokiPushUrl' => null,
            'lokiUsername' => null,
            'grafanaUrl' => 'https://cloud.example/grafana',
            'pyroscopePushUrl' => null,
            'profilingEnabled' => false,
            ...$changes,
        ];
    }
```
- Replace the PUT literals:

| Test | Body |
|---|---|
| `testAdminCanRoundTripAnOverrideAndTokenWithoutLeakingTheSecret` | `$this->grafanaBody(['token' => 'glc_secrettoken'])` |
| `testRemoveTokenClearsTheStoredSecret` | first `$this->grafanaBody(['token' => 'glc_secrettoken'])`, second `$this->grafanaBody(['removeToken' => true])` |
| `testAdminCanRoundTripTheProfilingToggleAndPyroscopeUrl` | `$this->grafanaBody(['profilingEnabled' => true, 'pyroscopePushUrl' => 'http://custom:4040'])` |

- Add at the end of the class:
```php
    public function testAPutLeavingSettingsOutIsRefusedAndStoresNothing(): void
    {
        $admin = $this->admin();
        $this->requestWithJsonBody('PUT', $admin, $this->grafanaBody(['profilingEnabled' => true]));
        self::assertResponseIsSuccessful();

        $incomplete = $this->grafanaBody(['lokiUsername' => 'tenant7']);
        unset($incomplete['grafanaUrl'], $incomplete['profilingEnabled']);
        $this->requestWithJsonBody('PUT', $admin, $incomplete);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->payload($this->client);
        self::assertSame('validation_error', $problem['type']);
        self::assertIsArray($problem['errors']);
        self::assertSame(['grafanaUrl', 'profilingEnabled'], array_keys($problem['errors']));

        $this->client->request(
            'GET',
            self::GRAFANA,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );
        $stored = $this->payload($this->client);
        self::assertSame('https://cloud.example/grafana', $stored['grafanaUrl']);
        self::assertNull($stored['lokiUsername']);
        self::assertTrue($stored['profilingEnabled']);
    }
```

`tests/Controller/Admin/AdminMailControllerTest.php`:
- Add `'useProxy' => false,` as the last entry of `SAVED_SMTP_ROW`.
- Add after `requestWithJsonBody()`. The defaults are the old DTO defaults, so every body keeps exactly the meaning it had:
```php
    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function mailBody(array $changes): array
    {
        return [
            'enabled' => false,
            'host' => '',
            'port' => 587,
            'username' => null,
            'encryption' => 'starttls',
            'fromAddress' => '',
            'fromName' => '',
            'useProxy' => false,
            ...$changes,
        ];
    }
```
- Replace the PUT literals that are not `self::SAVED_SMTP_ROW`:

| Test | Body |
|---|---|
| `testTestConnectionReachesTheTransportForAnUnreachableServer` | `$this->mailBody(['enabled' => true, 'host' => '127.0.0.1', 'port' => 1, 'fromAddress' => 'from@example.com', 'password' => 'sw0rdfish'])` |
| `testUpdateRejectsAHalfConfiguredAuthenticatedRowOnAFreshDatabase` | `$this->mailBody(['enabled' => true, 'host' => 'smtp.example', 'username' => 'user', 'password' => null])` |
| `testUpdateAcceptsKeepingAStoredPasswordOnAnAlreadyAuthedRow` | first `$this->mailBody(['enabled' => true, 'host' => 'smtp.example', 'username' => 'user', 'password' => 'sw0rdfish'])`, second the same with `'password' => null` |
| `testUpdateRemovesTheStoredPasswordWhenRemovePasswordIsSet` | the second PUT: `$this->mailBody(['host' => 'smtp.example', 'username' => 'user', 'removePassword' => true])` |

- Add before `testResetAsNonAdminIsForbidden()`:
```php
    public function testAPutLeavingSettingsOutIsRefusedAndStoresNothing(): void
    {
        $admin = $this->admin();
        $this->requestWithJsonBody('PUT', $admin, self::SAVED_SMTP_ROW);
        self::assertResponseIsSuccessful();

        $incomplete = self::SAVED_SMTP_ROW;
        unset($incomplete['username'], $incomplete['useProxy']);
        $this->requestWithJsonBody('PUT', $admin, $incomplete);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->payload($this->client);
        self::assertSame('validation_error', $problem['type']);
        self::assertIsArray($problem['errors']);
        self::assertSame(['username', 'useProxy'], array_keys($problem['errors']));

        $this->requestAs($admin, 'GET');
        self::assertSame('user', $this->payload($this->client)['username']);
    }
```

Each new test leaves out one nullable setting and one non-nullable setting. The nullable one fails the test when the serializer context is missing, and the non-nullable one fails it when a default comes back.

- [ ] **Step 2: Run to verify the new tests fail**

Run: `php bin/phpunit tests/Controller/Admin tests/Dto/Admin tests/Service/Proxy tests/Service/Grafana tests/Service/Mail tests/Functional/RequestProfilingTest.php`
Expected:
- The three `testAPutLeavingSettingsOutIsRefusedAndStoresNothing` FAIL (200: the defaults fill the gaps).
- Everything else PASSES, because the factory and the full bodies work against the current DTOs.

- [ ] **Step 3: Implement**

`src/Dto/Admin/ProxySettingsRequest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Enum\ProxyType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Every connection setting is required, so a PUT that leaves one out is a 422, not a reset. The password is an
 * optional three-state intent: null keeps the stored secret, a string replaces it, `removePassword` clears it.
 */
final readonly class ProxySettingsRequest
{
    public function __construct(
        #[Assert\Type('bool')]
        public bool $enabled,
        #[Assert\Type('bool')]
        public bool $directFallback,
        #[Assert\Choice(choices: [ProxyType::Socks5->value, ProxyType::Http->value])]
        public string $type,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $host,
        #[Assert\Range(min: 1, max: 65535)]
        public int $port,
        #[Assert\Length(max: 255)]
        public ?string $username,
        #[Assert\Type('bool')]
        public bool $remoteDns,
        #[Assert\Length(max: 512)]
        public ?string $password = null,
        #[Assert\Type('bool')]
        public bool $removePassword = false,
    ) {
    }
}
```

`src/Dto/Admin/GrafanaSettingsRequest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Every setting is required, so a PUT that leaves one out is a 422, not a reset; a null URL falls back to the env
 * default. The token is an optional three-state intent: null keeps it, a string replaces it, `removeToken` clears it.
 */
final readonly class GrafanaSettingsRequest
{
    public function __construct(
        #[Assert\Length(max: 255)]
        #[Assert\Url(requireTld: false)]
        public ?string $lokiPushUrl,
        #[Assert\Length(max: 255)]
        public ?string $lokiUsername,
        #[Assert\Length(max: 255)]
        #[Assert\Url(requireTld: false)]
        public ?string $grafanaUrl,
        #[Assert\Length(max: 255)]
        #[Assert\Url(requireTld: false)]
        public ?string $pyroscopePushUrl,
        #[Assert\Type('bool')]
        public bool $profilingEnabled,
        #[Assert\Length(max: 512)]
        public ?string $token = null,
        #[Assert\Type('bool')]
        public bool $removeToken = false,
    ) {
    }
}
```

`src/Dto/Admin/MailSettingsRequest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Enum\MailEncryption;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Every setting is required, so a PUT that leaves one out is a 422, not a reset. The password is an optional
 * three-state intent: null keeps the stored secret, a string replaces it, `removePassword` clears it.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") pure data carrier that
 * mirrors the admin mail form field-for-field, not a behavioural method.
 */
final readonly class MailSettingsRequest
{
    public function __construct(
        #[Assert\Type('bool')]
        public bool $enabled,
        #[Assert\Length(max: 255)]
        public string $host,
        #[Assert\Range(min: 1, max: 65535)]
        public int $port,
        #[Assert\Length(max: 255)]
        public ?string $username,
        #[Assert\Choice(choices: [
            MailEncryption::None->value,
            MailEncryption::Starttls->value,
            MailEncryption::Tls->value,
        ])]
        public string $encryption,
        #[Assert\Length(max: 255)]
        #[Assert\Email]
        public string $fromAddress,
        #[Assert\Length(max: 255)]
        public string $fromName,
        #[Assert\Type('bool')]
        public bool $useProxy,
        #[Assert\Length(max: 512)]
        public ?string $password = null,
        #[Assert\Type('bool')]
        public bool $removePassword = false,
    ) {
    }
}
```

The three controllers each add `use App\Http\FullReplacePayload;` after their last `use App\Http\…` import, and each replaces its `update` signature with the matching one below.

`AdminProxyController`:
```php
    public function update(
        #[MapRequestPayload(serializationContext: FullReplacePayload::CONTEXT)] ProxySettingsRequest $request,
    ): JsonResponse {
```

`AdminGrafanaController`:
```php
    public function update(
        #[MapRequestPayload(serializationContext: FullReplacePayload::CONTEXT)] GrafanaSettingsRequest $request,
    ): JsonResponse {
```

`AdminMailController`:
```php
    public function update(
        #[MapRequestPayload(serializationContext: FullReplacePayload::CONTEXT)] MailSettingsRequest $request,
    ): JsonResponse {
```

The DTO unit tests lose their "constructing with no arguments" tests, because there are no defaults left to pin. Each is replaced by a test of the one default that remains.

In `tests/Dto/Admin/ProxySettingsRequestTest.php`, replace `testConstructingWithNoArgumentsDefaultsToDisabledWithFallbackOn()`:
```php
    public function testThePasswordIntentIsOptionalAndKeepsTheStoredSecret(): void
    {
        $request = new ProxySettingsRequest(
            enabled: true,
            directFallback: false,
            type: 'HTTP',
            host: 'proxy.example',
            port: 3128,
            username: null,
            remoteDns: false,
        );

        self::assertNull($request->password);
        self::assertFalse($request->removePassword);
    }
```

In `tests/Dto/Admin/GrafanaSettingsRequestTest.php`, replace `testConstructingWithNoArgumentsKeepsEverythingUnset()`:
```php
    public function testTheTokenIntentIsOptionalAndKeepsTheStoredSecret(): void
    {
        $request = new GrafanaSettingsRequest(
            lokiPushUrl: null,
            lokiUsername: null,
            grafanaUrl: 'https://grafana.example',
            pyroscopePushUrl: null,
            profilingEnabled: true,
        );

        self::assertNull($request->token);
        self::assertFalse($request->removeToken);
    }
```

In `tests/Dto/Admin/MailSettingsRequestTest.php`, replace `testConstructingWithNoArgumentsDefaultsToDisabledStarttlsOnTheSubmissionPort()`:
```php
    public function testThePasswordIntentIsOptionalAndKeepsTheStoredSecret(): void
    {
        $request = new MailSettingsRequest(
            enabled: true,
            host: 'smtp.example',
            port: 2525,
            username: 'user',
            encryption: 'tls',
            fromAddress: 'noreply@example.com',
            fromName: 'Example',
            useProxy: false,
        );

        self::assertNull($request->password);
        self::assertFalse($request->removePassword);
    }
```

Run `git grep -nE "new (Proxy|Grafana|Mail)SettingsRequest\(" -- src tests`. Expected: exactly the three tests above.

- [ ] **Step 4: Run the settings suites**

Run the Step 2 command again, then `php bin/console lint:container`.
Expected: PASS.

- [ ] **Step 5: Deletion checks, gates, commit**

For each of the three controllers in turn, restoring by hand before the next:
- Drop `serializationContext: FullReplacePayload::CONTEXT` from the attribute. That endpoint's `testAPutLeavingSettingsOutIsRefusedAndStoresNothing` must fail, because `errors` then lists only the non-nullable field.
- Give the non-nullable field in that test its old default back (`remoteDns = false`, `profilingEnabled = false`, or `useProxy = false` moved after the password intent). The same test must fail, because `errors` then lists only the nullable field.

Then run `composer check && composer md` and lint the changed PHP in PhpStorm.
```bash
git add src/Dto/Admin/ProxySettingsRequest.php src/Dto/Admin/GrafanaSettingsRequest.php src/Dto/Admin/MailSettingsRequest.php src/Controller/Admin/AdminProxyController.php src/Controller/Admin/AdminGrafanaController.php src/Controller/Admin/AdminMailController.php tests/Support/SettingsRequests.php tests/Controller/Admin/AdminProxyControllerTest.php tests/Controller/Admin/AdminGrafanaControllerTest.php tests/Controller/Admin/AdminMailControllerTest.php tests/Dto/Admin/ProxySettingsRequestTest.php tests/Dto/Admin/GrafanaSettingsRequestTest.php tests/Dto/Admin/MailSettingsRequestTest.php tests/Service/Mail/Settings/MailConnectionTesterTest.php tests/Service/Mail/Settings/MailSettingsTest.php tests/Service/Mail/Transport/DynamicMailTransportTest.php tests/Service/Proxy/ProxyConnectionTesterTest.php tests/Service/Proxy/ProxySettingsTest.php tests/Functional/RequestProfilingTest.php tests/Service/Grafana/GrafanaSettingsTest.php
git commit -m "refactor(#1167): proxy, grafana and mail settings puts require every setting"
```

---

### Task A8: Test helpers stop taking flags

**Files:**
- Modify: `tests/Service/Reader/SearchMarkReadServiceTest.php` (the post-#1164 `stateFor()` and its three callers)
- Modify: `tests/Controller/Api/CatalogFaviconControllerTest.php:33-54` and its three callers
- Modify: `tests/Service/Mail/Digest/SendDueDigestsTest.php:264-276` and its nine callers
- Modify: `tests/Support/FakeOAuthProvider.php` (named constructors)
- Modify: `tests/Controller/Api/OAuthFlowTest.php:867-878` and line 791
- Modify: `tests/Service/OAuth/OAuthCallbackTest.php:144-150` and line 87

**Interfaces:**
- Produces:
  - `FakeOAuthProvider::returning(OAuthIdentity $identity): self`.
  - `FakeOAuthProvider::failingExchange(OAuthIdentity $identity): self`.
  - The constructor becomes private.
- Every other change is private to its test class.

These are test-only refactors. Each suite is its own regression net. A helper split is correct when the suite passes unchanged in count and outcome.

- [ ] **Step 1: Pin the suites**

Run: `php bin/phpunit tests/Service/Reader/SearchMarkReadServiceTest.php tests/Controller/Api/CatalogFaviconControllerTest.php tests/Service/Mail/Digest/SendDueDigestsTest.php tests/Controller/Api/OAuthFlowTest.php tests/Service/OAuth/OAuthCallbackTest.php`
Expected: PASS. Note the test count; Step 4 must report the same count.

- [ ] **Step 2: Split the helpers**

`tests/Service/Reader/SearchMarkReadServiceTest.php`. Replace #1164's `stateFor()` (line 64, the `$isHidden ? $state->hide(…) : $state->markUnread();` body):
```php
    private function unreadStateFor(Entry $entry): EntryState
    {
        return $this->persisted(new EntryState($this->user, $entry));
    }

    private function readStateFor(Entry $entry): EntryState
    {
        $state = new EntryState($this->user, $entry);
        $state->hide(new \DateTimeImmutable('2026-07-05T00:00:00Z'));

        return $this->persisted($state);
    }

    private function persisted(EntryState $state): EntryState
    {
        $this->em->persist($state);
        $this->em->flush();

        return $state;
    }
```
Callers:
```diff
-        $this->stateFor($entry, false);
+        $this->unreadStateFor($entry);
```
That line appears twice, so use `replace_all`.
```diff
-        $existing = $this->stateFor($entry, true);
+        $existing = $this->readStateFor($entry);
```

`tests/Controller/Api/CatalogFaviconControllerTest.php`. Replace `persistFeed()`:
```php
    private function persistFeedWithIcon(): CatalogFeed
    {
        $feed = $this->newFeed();
        $feed->storeFavicon(
            'https://www.theverge.com/favicon.ico',
            'PNGBYTES',
            'image/png',
            new \DateTimeImmutable('2026-07-26 10:00:00'),
        );

        return $this->persisted($feed);
    }

    private function persistFeedWithoutIcon(): CatalogFeed
    {
        return $this->persisted($this->newFeed());
    }

    private function newFeed(): CatalogFeed
    {
        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');

        return new CatalogFeed($category, 'The Verge', 'https://www.theverge.com/rss/index.xml');
    }

    private function persisted(CatalogFeed $feed): CatalogFeed
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $em->persist($feed->getCategory());
        $em->persist($feed);
        $em->flush();

        return $feed;
    }
```
Callers:
```diff
-        $feed = $this->persistFeed(withIcon: true);
+        $feed = $this->persistFeedWithIcon();
-        $feed = $this->persistFeed(withIcon: false);
+        $feed = $this->persistFeedWithoutIcon();
```
The second pair appears twice, so use `replace_all`. `git grep -n "persistFeed(" -- tests/Controller/Api/CatalogFaviconControllerTest.php` must print nothing afterwards.

`tests/Service/Mail/Digest/SendDueDigestsTest.php`. Replace `user()`:
```php
    private function verifiedUser(): User
    {
        $user = $this->unverifiedUser();
        $user->markEmailVerified(new \DateTimeImmutable('2026-07-02T00:00:00Z'));

        return $user;
    }

    private function unverifiedUser(): User
    {
        $email = \sprintf('digest-%s@example.com', uniqid('', true));
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
```
Callers. The first line appears once and the second pattern eight times, at lines 69, 97, 114, 132, 181, 184, 204 and 207:
```diff
-        $user = $this->user(verified: false);
+        $user = $this->unverifiedUser();
```
Then `perl -pi -e 's/\$this->user\(\)/\$this->verifiedUser()/g' tests/Service/Mail/Digest/SendDueDigestsTest.php`. Afterwards `git grep -n '\$this->user(' -- tests/Service/Mail/Digest/SendDueDigestsTest.php` must print nothing.

`tests/Support/FakeOAuthProvider.php`:
- Add `use App\Service\OAuth\Exception\OAuthFailedException;` if it is not already imported. It is, at line 8.
- Replace the constructor:
```php
    private function __construct(
        private readonly OAuthIdentity $identity,
        private readonly ?OAuthFailedException $exchangeFailure,
    ) {
    }

    public static function returning(OAuthIdentity $identity): self
    {
        return new self($identity, null);
    }

    public static function failingExchange(OAuthIdentity $identity): self
    {
        return new self($identity, new OAuthFailedException('fake provider was told to fail'));
    }
```
- In `exchangeCode()`, replace the `if ($this->failExchange) { throw new OAuthFailedException(…); }` block:
```php
        if (null !== $this->exchangeFailure) {
            throw $this->exchangeFailure;
        }
```

`tests/Controller/Api/OAuthFlowTest.php`. Replace `fakeProvider()` and keep its docblock above `installed()`:
```php
    private function fakeProvider(OAuthIdentity $identity): FakeOAuthProvider
    {
        return $this->installed(FakeOAuthProvider::returning($identity));
    }

    private function failingFakeProvider(OAuthIdentity $identity): FakeOAuthProvider
    {
        return $this->installed(FakeOAuthProvider::failingExchange($identity));
    }

    /**
     * Installs a fake provider by replacing the whole registry. MUST be called
     * before the first request of a test — see the class docblock for why, and
     * for why the provider service itself is not the seam.
     */
    private function installed(FakeOAuthProvider $provider): FakeOAuthProvider
    {
        self::getContainer()->set(
            OAuthProviderRegistry::class,
            new OAuthProviderRegistry([$provider]),
        );

        return $provider;
    }
```
The caller at line 791:
```diff
-        $provider = $this->fakeProvider(
-            new OAuthIdentity('google', 'sub-1', null, false),
-            failExchange: true,
-        );
+        $provider = $this->failingFakeProvider(new OAuthIdentity('google', 'sub-1', null, false));
```
The other 17 callers pass one argument and stay as they are.

`tests/Service/OAuth/OAuthCallbackTest.php`. Replace `provider()`:
```php
    private function provider(): FakeOAuthProvider
    {
        return FakeOAuthProvider::returning($this->identity());
    }

    private function failingProvider(): FakeOAuthProvider
    {
        return FakeOAuthProvider::failingExchange($this->identity());
    }

    private function identity(): OAuthIdentity
    {
        return new OAuthIdentity('google', 'sub-callback', 'callback@example.com', true);
    }
```
The caller at line 87:
```diff
-            $this->oauthCallback($this->provider(failExchange: true), $logger),
+            $this->oauthCallback($this->failingProvider(), $logger),
```

- [ ] **Step 3: Confirm no flag remains**

Run: `git grep -nE "stateFor\(|persistFeed\(|failExchange|->user\(verified" -- tests/Service/Reader/SearchMarkReadServiceTest.php tests/Controller/Api/CatalogFaviconControllerTest.php tests/Service/Mail/Digest/SendDueDigestsTest.php tests/Support/FakeOAuthProvider.php tests/Controller/Api/OAuthFlowTest.php tests/Service/OAuth/OAuthCallbackTest.php`
Expected: no output. The grep names the six files: `stateFor(` and `persistFeed(` helpers without a `bool` live elsewhere in `tests/` (A0 Step 3) and stay.

- [ ] **Step 4: Run the suites**

Run the Step 1 command again.
Expected: PASS with the same test count as Step 1.

- [ ] **Step 5: Break checks, gates, commit**

Restore each change by hand before the next:
- In `readStateFor()`, delete the `hide()` call. `testLeavesAnAlreadyReadMatchUnchanged` must fail.
- In `FakeOAuthProvider::failingExchange()`, pass `null` instead of the exception. `testAFailedExchangeRedirectsWithAnErrorRatherThanJson` and `testAFailedExchangeIsRefusedAndLoggedWithItsDetail` must fail.
- In `verifiedUser()`, delete the `markEmailVerified()` call. At least one digest-sending test must fail.

Then run `composer check`. PHPMD does not scan `tests/`.
```bash
git add tests/Service/Reader/SearchMarkReadServiceTest.php tests/Controller/Api/CatalogFaviconControllerTest.php tests/Service/Mail/Digest/SendDueDigestsTest.php tests/Support/FakeOAuthProvider.php tests/Controller/Api/OAuthFlowTest.php tests/Service/OAuth/OAuthCallbackTest.php
git commit -m "refactor(#1167): test helpers split on their flags instead of taking them"
```

---

### Task A9: `BackupLineOrder` replaces `assertOrdered`'s first-line flag

**Files:**
- Create: `src/Service/Backup/BackupLineOrder.php`
- Create: `tests/Service/Backup/BackupLineOrderTest.php`
- Modify: `src/Service/Backup/BackupReader.php` (constants at lines 32-52; `read()` at 74 and 99; `requireGuard()`'s docblock at 150-151; the three header checks at 173-205; `assertOrdered()` at 234-264; `toDto()`'s default arm at 278-280)
- Modify: `src/Service/Backup/Dto/BackupHeader.php` (gains `requireCoherent()`)
- Create: `tests/Service/Backup/Dto/BackupHeaderTest.php`
- Test: `tests/Service/Backup/BackupReaderTest.php`, unchanged. Its 28 tests are the regression net: first line, order, footer, part headers, versions and ceilings.

**Why this split, and why it should leave the file PHPMD-clean.** `assertOrdered(string $kind, bool $isFirstLine, int $currentRank, int $lineNumber)` receives a flag its only caller derives from the other argument (`-1 === $currentRank`). The `-1` is a magic value for "no line read yet". The ruleset is `rulesets/codesize.xml` at its defaults, and the one metric this class is near is `ExcessiveClassComplexity`, whose threshold is 50. Read by hand, the class's methods sum to about 57 today:

| Method | Complexity |
|---|---|
| `read` | 9 |
| `toDto` (its match arms count) | 9 |
| `assertOrdered` | 8 |
| `decodeLine` | 5 |
| `verifyFooter` | 5 |
| the three header checks | 10 |
| the remaining six methods | 11 |

`read()` itself stays below `CyclomaticComplexity` 10 and `NPathComplexity` 200. The split therefore takes out two cohesive pieces, not one:
- **The file grammar**, meaning the rank table, the singleton set and the first-line rule, becomes the immutable `BackupLineOrder`. It is created per read, the way `BackupPartGuard` already is. Its "nothing read yet" is `null`, not `-1`, and no flag crosses a method boundary.
- **The header's part numbering** moves onto `BackupHeader::requireCoherent()`, next to `isFoundation()`, which it is written in terms of.

That leaves the reader at about 37. `composer md` confirms the number; the executor records it in the report.

**Interfaces:**
- Produces:
  - `final readonly class App\Service\Backup\BackupLineOrder`, with `beforeTheFirstLine(): self` and `admit(string $kind, int $lineNumber): self`. `admit` throws `InvalidBackupException` and returns a new instance.
  - `BackupHeader::requireCoherent(): self`, which throws `InvalidBackupException`.
- Unchanged: `BackupReader::read(string): \Generator`, `MAX_ENTRIES_PER_PART`, `MAX_INFLATED_BYTES`, and every exception message.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Backup/BackupLineOrderTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\BackupLineOrder;
use App\Service\Backup\Exception\InvalidBackupException;
use PHPUnit\Framework\TestCase;

final class BackupLineOrderTest extends TestCase
{
    public function testTheFirstLineMustBeAHeader(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('The first line must be a header.');

        BackupLineOrder::beforeTheFirstLine()->admit('account', 1);
    }

    public function testAnUnknownKindIsRefusedBeforeTheFirstLineRule(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('Line 1 has an unknown kind "bogus".');

        BackupLineOrder::beforeTheFirstLine()->admit('bogus', 1);
    }

    public function testAnUnknownKindLaterNamesItsLine(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('Line 3 has an unknown kind "bogus".');

        BackupLineOrder::beforeTheFirstLine()->admit('header', 1)->admit('account', 2)->admit('bogus', 3);
    }

    public function testARankMayNotMoveBackwards(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('Line 4 is out of order.');

        BackupLineOrder::beforeTheFirstLine()
            ->admit('header', 1)
            ->admit('account', 2)
            ->admit('feed', 3)
            ->admit('tag', 4);
    }

    public function testASingletonKindMayNotRepeat(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('Line 3 repeats the singleton kind "account".');

        BackupLineOrder::beforeTheFirstLine()->admit('header', 1)->admit('account', 2)->admit('account', 3);
    }

    public function testARepeatableKindMayRepeatAndTheFooterCloses(): void
    {
        $order = BackupLineOrder::beforeTheFirstLine()
            ->admit('header', 1)
            ->admit('account', 2)
            ->admit('feed', 3)
            ->admit('feed', 4)
            ->admit('footer', 5);

        self::assertInstanceOf(BackupLineOrder::class, $order);
    }

    public function testAdmittingLeavesTheEarlierOrderAsItWas(): void
    {
        $start = BackupLineOrder::beforeTheFirstLine();
        $start->admit('header', 1);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('The first line must be a header.');

        $start->admit('account', 2);
    }
}
```

`tests/Service/Backup/Dto/BackupHeaderTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Service\Backup\Dto\BackupHeader;
use App\Service\Backup\Exception\InvalidBackupException;
use PHPUnit\Framework\TestCase;

final class BackupHeaderTest extends TestCase
{
    public function testANegativePartIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('Header part -1 is negative.');

        self::header(part: -1, parts: null)->requireCoherent();
    }

    public function testAnEntryPartThatDeclaresNeitherIsCoherent(): void
    {
        $header = self::header(part: 2, parts: null);

        self::assertSame($header, $header->requireCoherent());
    }

    public function testAnEntryPartDeclaringAPartsCountIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('Entry part 2 must not declare parts or totals.');

        self::header(part: 2, parts: 3)->requireCoherent();
    }

    public function testAFoundationWithoutItsPartsCountIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('The foundation must declare its parts count and totals.');

        self::header(part: 0, parts: null)->requireCoherent();
    }

    private static function header(int $part, ?int $parts): BackupHeader
    {
        return new BackupHeader(
            schemaVersion: 3,
            createdAt: new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            sourceUrl: null,
            sourceEmail: null,
            backupId: 'backup-1167',
            part: $part,
            parts: $parts,
            totals: null,
        );
    }
}
```
A coherent foundation needs a `BackupTotals`, and `BackupReaderTest::testReadsAMinimalValidFile` and its siblings already cover that path end to end.

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Backup/BackupLineOrderTest.php tests/Service/Backup/Dto/BackupHeaderTest.php`
Expected: FAIL: `Class "App\Service\Backup\BackupLineOrder" not found` and `Call to undefined method App\Service\Backup\Dto\BackupHeader::requireCoherent()`.

- [ ] **Step 3: Implement**

`src/Service/Backup/BackupLineOrder.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Backup\Exception\InvalidBackupException;

/**
 * The file grammar: a header first, kind ranks never moving backwards, header/account/footer at most once.
 */
final readonly class BackupLineOrder
{
    private const array KIND_RANK = [
        BackupSchema::KIND_HEADER => 0,
        BackupSchema::KIND_ACCOUNT => 1,
        BackupSchema::KIND_TAG => 2,
        BackupSchema::KIND_SAVED_SEARCH => 3,
        BackupSchema::KIND_FEED => 4,
        BackupSchema::KIND_SUBSCRIPTION => 5,
        BackupSchema::KIND_ENTRY => 6,
        BackupSchema::KIND_ENTRY_STATE => 7,
        BackupSchema::KIND_FOOTER => 8,
    ];

    private const array SINGLETON_KINDS = [
        BackupSchema::KIND_HEADER,
        BackupSchema::KIND_ACCOUNT,
        BackupSchema::KIND_FOOTER,
    ];

    private function __construct(private ?int $lastRank)
    {
    }

    public static function beforeTheFirstLine(): self
    {
        return new self(null);
    }

    /**
     * @throws InvalidBackupException
     */
    public function admit(string $kind, int $lineNumber): self
    {
        $rank = self::KIND_RANK[$kind]
            ?? throw new InvalidBackupException(sprintf('Line %d has an unknown kind "%s".', $lineNumber, $kind));

        if (null === $this->lastRank) {
            return self::opening($kind, $rank);
        }

        if ($rank < $this->lastRank) {
            throw new InvalidBackupException(sprintf('Line %d is out of order.', $lineNumber));
        }

        if ($rank === $this->lastRank && \in_array($kind, self::SINGLETON_KINDS, true)) {
            throw new InvalidBackupException(sprintf('Line %d repeats the singleton kind "%s".', $lineNumber, $kind));
        }

        return new self($rank);
    }

    /**
     * @throws InvalidBackupException
     */
    private static function opening(string $kind, int $rank): self
    {
        if (BackupSchema::KIND_HEADER !== $kind) {
            throw new InvalidBackupException('The first line must be a header.');
        }

        return new self($rank);
    }
}
```

`src/Service/Backup/Dto/BackupHeader.php`:
- Add `use App\Service\Backup\Exception\InvalidBackupException;` after `namespace App\Service\Backup\Dto;`.
- Add after `isFoundation()`:
```php
    /**
     * @throws InvalidBackupException
     */
    public function requireCoherent(): self
    {
        if ($this->part < 0) {
            throw new InvalidBackupException(sprintf('Header part %d is negative.', $this->part));
        }

        if ($this->isFoundation()) {
            return $this->requireFoundationPartsAndTotals();
        }

        return $this->requireEntryPartDeclaresNeither();
    }

    private function requireFoundationPartsAndTotals(): self
    {
        if (null === $this->parts || $this->parts < 1 || null === $this->totals) {
            throw new InvalidBackupException('The foundation must declare its parts count and totals.');
        }

        return $this;
    }

    private function requireEntryPartDeclaresNeither(): self
    {
        if (null !== $this->parts || null !== $this->totals) {
            throw new InvalidBackupException(sprintf('Entry part %d must not declare parts or totals.', $this->part));
        }

        return $this;
    }
```

`src/Service/Backup/BackupReader.php`:
- Delete the `KIND_RANK` constant and the `SINGLETON_KINDS` constant, including its docblock.
- In `read()`:
```diff
-        $currentRank = -1;
+        $order = BackupLineOrder::beforeTheFirstLine();
```
```diff
-            $currentRank = $this->assertOrdered($kind, -1 === $currentRank, $currentRank, $lineNumber);
+            $order = $order->admit($kind, $lineNumber);
```
```diff
-                $header = $this->assertCoherentHeader(BackupHeader::fromLine($decoded));
+                $header = BackupHeader::fromLine($decoded)->requireCoherent();
```
- `requireGuard()`'s docblock:
```diff
-     * The grammar guarantees a header precedes every other line, so a null
-     * guard here means assertOrdered failed to do its job.
+     * The grammar guarantees a header precedes every other line, so a null
+     * guard here means BackupLineOrder failed to do its job.
```
- Delete `assertCoherentHeader()`, `assertFoundationDeclaresPartsAndTotals()`, `assertEntryPartDeclaresNeither()` and `assertOrdered()` with its docblock.
- In `toDto()`, the default arm:
```diff
-            // Unreachable: read() handles header/footer, and assertOrdered
-            // refuses any other kind. Stays only for match exhaustiveness.
-            default => throw new \LogicException(sprintf('assertOrdered accepted unknown kind "%s".', $kind)),
+            // Unreachable: read() handles header/footer, and BackupLineOrder
+            // refuses any other kind. Stays only for match exhaustiveness.
+            default => throw new \LogicException(sprintf('BackupLineOrder admitted unknown kind "%s".', $kind)),
```

- [ ] **Step 4: Run the backup suites**

Run: `php bin/phpunit tests/Service/Backup tests/Controller/Api/AccountBackupControllerTest.php`
Expected: PASS. `BackupReaderTest` must report the same 28 tests passing as before.

- [ ] **Step 5: Deletion checks, gates, commit**

Restore each change by hand before the next:
- In `admit()`, replace the final `return new self($rank);` with `return $this;`. The order then stays at the header's rank for the rest of the file, so no later line is ever behind it and no singleton ever repeats it. `testARankMayNotMoveBackwards` and `testASingletonKindMayNotRepeat` must fail, and so must `BackupReaderTest::testRefusesKindsOutOfOrder`.
- Delete the `\in_array(…, self::SINGLETON_KINDS, true)` condition's whole `if` block. `testASingletonKindMayNotRepeat` must fail.
- In `requireFoundationPartsAndTotals()`, delete `|| $this->parts < 1`. `BackupReaderTest::testFoundationWithPartsBelowOneButValidTotalsIsRefused` must fail.

Then run `composer check`, `composer md` (paste the `BackupReader.php` line, or its absence, into the report) and the PhpStorm lint on the four changed PHP files.
```bash
git add src/Service/Backup/BackupLineOrder.php src/Service/Backup/BackupReader.php src/Service/Backup/Dto/BackupHeader.php tests/Service/Backup/BackupLineOrderTest.php tests/Service/Backup/Dto/BackupHeaderTest.php
git commit -m "refactor(#1167): backup line order replaces the first-line flag; the header checks its own part numbering"
```

---

### Finishing PR A

- [ ] Run the per-PR gates in order. Paste each summary line into the report.
```bash
php bin/phpunit
docker compose exec php composer test
composer check
composer md
composer infection:diff
docker compose exec -T frontend npm run check
```
Lint every changed PHP file with `mcp__phpstorm__lint_files`.
- [ ] Scan today's dev log for deprecations: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`.
- [ ] Push, then open the PR against `develop`. The body below references the issue without any closing keyword. Do not add "closes", "fixes" or "resolves" anywhere in it, prose included.
```bash
git push -u origin refactor/1167-flags-and-value-objects
gh pr create --base develop --title "refactor(#1167): flags, string modes and missing value objects" --body "$(cat <<'EOF'
Refs #1167

First of two PRs. PR B (repository owner-first ordering) finishes the issue.

- `RefreshRequest::allDue()` loses its two flags; `withoutPruning()` / `ignoringSchedule()` compose them. `RefreshFeedsCommand` depends on `RefreshRunnerInterface`.
- `DigestImageKind` replaces the favicon flag; the embedder no longer signals a failed image with `null`.
- `SlideshowMarkup` marks the first slide eager after rendering, no `bool $eager`.
- `EntryView` enum replaces the string view; `EntryQuery` refuses the for-you view instead of letting it fall through.
- `FeedTagMove::move(Subscription, MoveFeedToTagRequest)`: five parameters become two.
- `CatalogFaviconDueCriteria`: find and count share one query builder.
- Test helpers that branched on a `bool` are split.
- `BackupReader`: `BackupLineOrder` replaces `assertOrdered(…, bool $isFirstLine, …)` and its `-1` sentinel; the header checks its own part numbering.

**Wire change (deliberate):** these four full-replace endpoints, with a setting missing, now answer `422 validation_error` naming each missing field and store nothing. They used to reset the missing field to a constructor default:
- `PUT /api/admin/settings`
- `PUT /api/admin/proxy`
- `PUT /api/admin/grafana`
- `PUT /api/admin/mail`

The one-shot intents stay optional: `invalidateExistingPasskeys`, `password`/`removePassword` and `token`/`removeToken`. The SPA and `bin/e2e.sh` already send every setting.
EOF
)"
```
- [ ] After a merge (the user's call), check that #1167 is still OPEN: `gh issue view 1167 --json state --jq .state`.

### Execution rulings (PR A)

Recorded while executing Tasks A0–A9. Each ruling amends the text above.

- **B1 (A4 Step 6):** the perl sweep misses `EntryListTest.php:762`'s positional `'all'`; it is rewritten by hand, and the check is `git grep -nE "EntryQuery\(.*'(all|unread|favorites|kept|viewed|for-you)'" -- backend/tests`.
- **S1 (A7a):** `InstanceSetting`'s docblock writes `{@see InstanceSettingsUpdate}`, not the qualified name (a PhpStorm WARNING).
- **S2 (A7b):** `docs/local-docker.md`'s Grafana PUT hint says the body carries every setting.
- **S3 (A7a/A7b):** each settings controller test has one data-provider test that leaves out each required key in turn and expects 422 naming exactly that key.
- **S4 (A1):** `RefreshFeedsCommand` compares `getOption(…) === true`, not a `(bool)` cast.
- **S5 (A3):** `infection.json5` ignores `NullSafeMethodCall` on `SlideshowMarkup::loadFirstSlideEagerly` (a slideshow always has 2+ images).
- **R1 (A1):** `RefreshFeedsCommandRequestTest` also pins the requests `--feed` and `--user` build; two `MatchArmRemoval` mutants escaped without it.
- **R2 (A4):** `EntryScopePredicates::applyView()` keeps `default: break;` for the unfiltered views; `case All: case ForYou:` produced unkillable `SharedCaseRemoval` mutants, since `EntryQuery` refuses ForYou.
- **R3 (A4):** `isDateOrderedFanIn()`'s docblock keeps its contrast with `hidesExcludedFeeds()` (tag scope counts for one, not the other).
- **R4 (A5):** `FeedTagMove::move()` keeps the plan's one-line same-list comment.
- **R5 (A9):** `infection.json5` ignores `MatchArmRemoval` on `BackupReader::toDto` for its unreachable default arm. Arm-removal mutants all report at the `match` line, so a line-scoped ignore cannot isolate it; each real arm stays covered behaviourally by the reader tests.
- **R6 (A9):** N2's complexity table is wrong (the reader measured 47 before, already clean); the requirement stays "PHPMD-clean", which holds.
- **R7 (final review):** the settings DTO docblocks point at `FullReplacePayload::CONTEXT` and keep what a null setting means; `SettingsRequests` gains `instance()` and mirrors the DTOs' parameter order; `BackupHeaderTest` pins every arm of `requireCoherent()`; `FakeOAuthProvider` builds its exception in `exchangeCode()`; `OAuthFlowTest::installBeforeTheFirstRequest()` names its precondition; the `RefreshRequest` withers sit below the named constructors.
- **R8 (/simplify):** skipped E1 (reuse `$this` in `BackupLineOrder::admit()` on an unchanged rank: negligible next to per-line gzip and JSON, and it adds an equivalent mutant) and the altitude proposal to pass each slide's `loading` value (S5 settled it).
- **Deferred:** pre-existing `$this->once()/never()` WARNINGs in `CatalogFaviconWarmerTest`, `GrafanaSettingsTest`, `ProxySettingsTest`, `SendDueDigestsTest` → #1169's sweep. Candidate follow-up: a missing settings field's 422 message is Symfony's generic "This value should be of type …".

---

# PR B

**Rule this PR applies** (it goes into `docs/architecture.md` §7 in B3). A repository method scoped to one user takes the owner, a `User` or its id, as its first criterion and is named `…ForUser`. A method's subject stays in front: the query builder, or the rows it enriches. Where moving the owner first would swap two adjacent `int` parameters, the method is renamed as well, so no stale call site can compile against the new order.

| Before | After | Why the name changes |
|---|---|---|
| `TagRepository::findOneOwnedBy(int $id, int $userId): ?Tag` | `findOneForUser(int $userId, int $tagId): ?Tag` | one name; adjacent ints |
| `TagRepository::getOneOwnedBy(int $id, int $userId): Tag` | `getOneForUser(int $userId, int $tagId): Tag` | one name; adjacent ints |
| `SubscriptionRepository::getOneOwnedBy(int $id, int $userId)` | `getOneForUser(int $userId, int $subscriptionId)` | same |
| `SavedSearchRepository::getOneOwnedBy(int $id, int $userId)` | `getOneForUser(int $userId, int $savedSearchId)` | same |
| `AiProviderSettingsRepository::findOwnedById(User $user, int $id)` | `findOneForUser(User $user, int $settingsId)` | one name |
| `RecommendationRunLogRepository::getOwned(int $id, User $user)` | `getOneForUser(User $user, int $logId)` | one name |
| `EntryListRepository::getOneRowForUser(int $entryId, int $userId)` | `getRowForUser(int $userId, int $entryId)` | adjacent ints |
| `EntryListRepository::findOneSubscribedByUser(int $entryId, int $userId)` | `findOneSubscribedForUser(int $userId, int $entryId)` | adjacent ints; `ForUser` |
| `EntryListRepository::getOneSubscribedByUser(int $entryId, int $userId)` | `getOneSubscribedForUser(int $userId, int $entryId)` | same |
| `EntryListRepository::rowsByIdsForUser(array $entryIds, int $userId, ?int $limit = null)` | `rowsByIdsForUser(int $userId, array $entryIds, ?int $limit = null)` | kept: types differ |
| `EntryListRepository::siblingRowsForUser(string $urlHash, int $excludeEntryId, int $userId)` | `siblingRowsForUser(int $userId, string $urlHash, int $excludeEntryId)` | kept: types differ |
| `TagRepository::findAllByIdsForUser(array $ids, int $userId)` | `findAllByIdsForUser(int $userId, array $tagIds)` | kept |
| `OwnedTagsCache::findAllByIdsForUser(array $ids, int $userId)` | `findAllByIdsForUser(int $userId, array $tagIds)` | kept; mirrors the repository |
| `SubscriptionRepository::findAllByIdsForUser(array $ids, int $userId)` | `findAllByIdsForUser(int $userId, array $subscriptionIds)` | kept |
| `SubscriptionRepository::findAllByIdsForUserWithAssociations(array $ids, int $userId)` | `findAllByIdsForUserWithAssociations(int $userId, array $subscriptionIds)` | kept |
| `SavedSearchEntryRepository::unreadMemberIdsSince(int $savedSearchId, int $userId, \DateTimeImmutable $since)` | `unreadMemberIdsSince(int $userId, int $savedSearchId, \DateTimeImmutable $since)` | kept; only two call sites, both listed |
| `SavedSearchEntryRepository::savedSearchesByEntry(array $entryIds, int $userId)` | `savedSearchesByEntry(int $userId, array $entryIds)` | kept |
| `ReaderAuditRepository::detailRows(array $entryIds, int $userId)` | `detailRows(int $userId, array $entryIds)` | kept |

Already owner-first and unchanged: `UserPasskeyRepository::findOneForUser(User, int)`, `TagRepository::existsForUserAndName`, `findOneByNameForUser`, `SubscriptionRepository::existsForUserAndFeed`, `findForUserByTagId`, every `EntryStateRepository`, `ReadingHistoryRepository`, `RecommendationCandidateRepository` and `RecommendationRun*Repository` method, and `SavedSearchEntryRepository::unreadMemberIdsBySavedSearch`/`memberCountsBySavedSearch`/`unreadMemberIdsUpTo`.

### Task B0: Preflight (PR A merged)

**Files:** none changed.

- [ ] **Step 1: Confirm PR A merged and the issue is open**

```bash
git fetch origin
git log origin/develop --oneline | grep -c '(#1167)'
gh issue view 1167 --json state --jq .state
```
Expected:
- A non-zero count. A merge commit keeps the ten `refactor(#1167)` commits of A1–A9, and a squash merge leaves one.
- `OPEN`. If the issue closed, PR A's body carried a closing keyword. Reopen it and fix the merged PR's body before going on.

- [ ] **Step 2: Cut the branch**

```bash
git status --short && git branch --show-current
git switch -c refactor/1167-owner-first-repositories origin/develop
```

- [ ] **Step 3: Re-take the call sites**

```bash
git grep -nE "(getOneOwnedBy|findOneOwnedBy|findOwnedById|getOwned|getOneRowForUser|findOneSubscribedByUser|getOneSubscribedByUser|rowsByIdsForUser|siblingRowsForUser|findAllByIdsForUser|unreadMemberIdsSince|savedSearchesByEntry|detailRows)\(" -- src tests
```
Compare the output with the per-task lists below. At `0f80267b` the command prints 99 lines. PR A changes none of them: A5 keeps `FeedTagMove::ownedTagOrNull()` and its `findOneOwnedBy(` call.
- B1: `getOneOwnedBy(` 23, `findOneOwnedBy(` 3, `findOwnedById(` 4, `getOwned(` 5.
- B2: `getOneRowForUser(` 11, `findOneSubscribedByUser(` 2, `getOneSubscribedByUser(` 5, `rowsByIdsForUser(` 9, `siblingRowsForUser(` 3.
- B3: `findAllByIdsForUser(` 25 (four are docblock mentions of the unchanged name), `unreadMemberIdsSince(` 3, `savedSearchesByEntry(` 4, `detailRows(` 2.

`tests/Service/Reader/EntryStateUpdaterTest.php` has five `getOneRowForUser(` calls at `0f80267b` (lines 111, 122, 134, 146, 158), one of them from #1164. Every extra site gets the same rewrite as its siblings. Record it in the task report.

---

### Task B1: One name for "one owned row", owner first

**Files:**
- Modify: `src/Repository/TagRepository.php:99-114`
- Modify: `src/Repository/SubscriptionRepository.php:141-154`
- Modify: `src/Repository/SavedSearchRepository.php:56-66`
- Modify: `src/Repository/AiProviderSettingsRepository.php:22-25`
- Modify: `src/Repository/RecommendationRunLogRepository.php:18, 142-155`
- Modify (callers): `src/Controller/Api/TagController.php`, `SubscriptionController.php`, `SavedSearchController.php`, `SavedSearchEntriesController.php`, `RecommendationDebugLogController.php`; `src/Service/Reader/MarkReadService.php`; `src/Service/Refresh/UserRefreshScope.php`; `src/Service/Subscription/FeedTagMove.php`; `src/Service/Ai/AiConfigurationForUser.php`
- Test: `tests/Repository/TagRepositoryTest.php`, `SavedSearchRepositoryTest.php`, `SubscriptionRepositoryTest.php`, `AiProviderSettingsRepositoryTest.php`, `RecommendationRunLogRepositoryTest.php`; `tests/Service/Subscription/UnsubscribeAllTest.php`

**Interfaces:**
- Produces:
  - `TagRepository::findOneForUser(int $userId, int $tagId): ?Tag`.
  - `TagRepository::getOneForUser(int $userId, int $tagId): Tag`.
  - `SubscriptionRepository::getOneForUser(int $userId, int $subscriptionId): Subscription`.
  - `SavedSearchRepository::getOneForUser(int $userId, int $savedSearchId): SavedSearch`.
  - `AiProviderSettingsRepository::findOneForUser(User $user, int $settingsId): ?AiProviderSettings`.
  - `RecommendationRunLogRepository::getOneForUser(User $user, int $logId): RecommendationRunLog`.
- The not-found messages are unchanged.

- [ ] **Step 1: Move the tests to the new names**

`tests/Repository/TagRepositoryTest.php`:
```diff
-    public function testGetOneOwnedByReturnsTheOwnersTag(): void
+    public function testGetOneForUserReturnsTheOwnersTag(): void
-        self::assertSame($tag, $this->repo()->getOneOwnedBy($tag->requireId(), $owner->requireId()));
+        self::assertSame($tag, $this->repo()->getOneForUser($owner->requireId(), $tag->requireId()));
-    public function testGetOneOwnedByRefusesAnotherUsersTag(): void
+    public function testGetOneForUserRefusesAnotherUsersTag(): void
-        $this->repo()->getOneOwnedBy($tag->requireId(), $stranger->requireId());
+        $this->repo()->getOneForUser($stranger->requireId(), $tag->requireId());
```

`tests/Repository/SavedSearchRepositoryTest.php`:
```diff
-    public function testGetOneOwnedByReturnsTheOwnersSavedSearch(): void
+    public function testGetOneForUserReturnsTheOwnersSavedSearch(): void
-        self::assertSame($saved, $this->repo()->getOneOwnedBy($saved->requireId(), $owner->requireId()));
+        self::assertSame($saved, $this->repo()->getOneForUser($owner->requireId(), $saved->requireId()));
-    public function testGetOneOwnedByRefusesAnotherUsersSavedSearch(): void
+    public function testGetOneForUserRefusesAnotherUsersSavedSearch(): void
-        $this->repo()->getOneOwnedBy($saved->requireId(), $stranger->requireId());
+        $this->repo()->getOneForUser($stranger->requireId(), $saved->requireId());
```

`tests/Repository/SubscriptionRepositoryTest.php`:
```diff
-    public function testGetOneOwnedByReturnsTheOwnersSubscription(): void
+    public function testGetOneForUserReturnsTheOwnersSubscription(): void
-            $this->repo()->getOneOwnedBy($subscription->requireId(), $owner->requireId()),
+            $this->repo()->getOneForUser($owner->requireId(), $subscription->requireId()),
-    public function testGetOneOwnedByRefusesAnotherUsersSubscription(): void
+    public function testGetOneForUserRefusesAnotherUsersSubscription(): void
-        $this->repo()->getOneOwnedBy($subscription->requireId(), $stranger->requireId());
+        $this->repo()->getOneForUser($stranger->requireId(), $subscription->requireId());
```

`tests/Repository/AiProviderSettingsRepositoryTest.php`:
```diff
-    public function testFindOwnedByIdReturnsARowTheUserOwns(): void
+    public function testFindOneForUserReturnsARowTheUserOwns(): void
-        $found = $this->repository()->findOwnedById($this->first, $ownedId);
+        $found = $this->repository()->findOneForUser($this->first, $ownedId);
-    public function testFindOwnedByIdReturnsNullForAnotherAccountsRow(): void
+    public function testFindOneForUserReturnsNullForAnotherAccountsRow(): void
-        $found = $this->repository()->findOwnedById($this->second, $strangerId);
+        $found = $this->repository()->findOneForUser($this->second, $strangerId);
```

`tests/Repository/RecommendationRunLogRepositoryTest.php`:
```diff
-    public function testGetOwnedReturnsTheCallersRow(): void
+    public function testGetOneForUserReturnsTheCallersRow(): void
-        self::assertSame($mine, $this->logs->getOwned($mineId, $this->user));
+        self::assertSame($mine, $this->logs->getOneForUser($this->user, $mineId));
-    public function testGetOwnedRefusesAnotherUsersRow(): void
+    public function testGetOneForUserRefusesAnotherUsersRow(): void
-        $this->logs->getOwned($theirsId, $this->user);
+        $this->logs->getOneForUser($this->user, $theirsId);
```

`tests/Service/Subscription/UnsubscribeAllTest.php`:
```diff
-        self::assertSame($keptId, $repository->getOneOwnedBy($keptId, $user->requireId())->requireId());
+        self::assertSame($keptId, $repository->getOneForUser($user->requireId(), $keptId)->requireId());
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Repository/TagRepositoryTest.php tests/Repository/SavedSearchRepositoryTest.php tests/Repository/SubscriptionRepositoryTest.php tests/Repository/AiProviderSettingsRepositoryTest.php tests/Repository/RecommendationRunLogRepositoryTest.php tests/Service/Subscription/UnsubscribeAllTest.php`
Expected: FAIL: `Call to undefined method …::getOneForUser()` / `findOneForUser()`.

- [ ] **Step 3: Rename in the repositories**

`src/Repository/TagRepository.php`:
```php
    public function findOneForUser(int $userId, int $tagId): ?Tag
    {
        /** @var Tag|null $row */
        $row = $this->createQueryBuilder('t')
            ->andWhere('t.id = :id')->setParameter('id', $tagId)
            ->andWhere('t.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }

    public function getOneForUser(int $userId, int $tagId): Tag
    {
        return $this->findOneForUser($userId, $tagId) ?? throw new RecordNotFoundException('No such tag.');
    }
```

`src/Repository/SubscriptionRepository.php`: change the signature to `public function getOneForUser(int $userId, int $subscriptionId): Subscription`, and in its body `->setParameter('id', $id)` to `->setParameter('id', $subscriptionId)`.

`src/Repository/SavedSearchRepository.php`: change the signature to `public function getOneForUser(int $userId, int $savedSearchId): SavedSearch`, and in its body `->setParameter('id', $id)` to `->setParameter('id', $savedSearchId)`.

`src/Repository/AiProviderSettingsRepository.php`:
```php
    public function findOneForUser(User $user, int $settingsId): ?AiProviderSettings
    {
        return $this->findOneBy(['id' => $settingsId, 'user' => $user]);
    }
```

`src/Repository/RecommendationRunLogRepository.php`:
- Change the signature to `public function getOneForUser(User $user, int $logId): RecommendationRunLog`, and in its body `->setParameter('id', $id)` to `->setParameter('id', $logId)`.
- In the class docblock, change `bodies load one row at a time via getOwned() when the user expands an` to `bodies load one row at a time via getOneForUser() when the user expands an`.

- [ ] **Step 4: Move the callers**

Each is a one-line edit. Where a line repeats in a file, use `replace_all`.

| File | Before | After |
|---|---|---|
| `src/Controller/Api/TagController.php` (×3) | `$tag = $this->tags->getOneOwnedBy($id, $user->requireId());` | `$tag = $this->tags->getOneForUser($user->requireId(), $id);` |
| `src/Controller/Api/SubscriptionController.php` (×2) | `$sub = $this->subscriptionRepo->getOneOwnedBy($id, $user->requireId());` | `$sub = $this->subscriptionRepo->getOneForUser($user->requireId(), $id);` |
| `src/Controller/Api/SubscriptionController.php` | `$subscription = $this->subscriptionRepo->getOneOwnedBy($id, $user->requireId());` | `$subscription = $this->subscriptionRepo->getOneForUser($user->requireId(), $id);` |
| `src/Controller/Api/SavedSearchController.php` | `$savedSearch = $this->savedSearches->getOneOwnedBy($id, $userId);` | `$savedSearch = $this->savedSearches->getOneForUser($userId, $id);` |
| `src/Controller/Api/SavedSearchController.php` | `$savedSearch = $this->savedSearches->getOneOwnedBy($id, $user->requireId());` | `$savedSearch = $this->savedSearches->getOneForUser($user->requireId(), $id);` |
| `src/Controller/Api/SavedSearchEntriesController.php` | `$savedSearch = $this->savedSearches->getOneOwnedBy($id, $userId);` | `$savedSearch = $this->savedSearches->getOneForUser($userId, $id);` |
| `src/Controller/Api/SavedSearchEntriesController.php` | `$savedSearch = $this->savedSearches->getOneOwnedBy($id, $user->requireId());` | `$savedSearch = $this->savedSearches->getOneForUser($user->requireId(), $id);` |
| `src/Controller/Api/RecommendationDebugLogController.php` | `RecommendationDebugLogJson::detail($this->logs->getOwned($id, $user))` | `RecommendationDebugLogJson::detail($this->logs->getOneForUser($user, $id))` |
| `src/Service/Reader/MarkReadService.php` | `return $this->subscriptions->getOneOwnedBy($id, $userId);` | `return $this->subscriptions->getOneForUser($userId, $id);` |
| `src/Service/Reader/MarkReadService.php` | `return $this->tags->getOneOwnedBy($id, $userId)->requireId();` | `return $this->tags->getOneForUser($userId, $id)->requireId();` |
| `src/Service/Refresh/UserRefreshScope.php` | `$tag = $this->tags->getOneOwnedBy($tagId, $userId);` | `$tag = $this->tags->getOneForUser($userId, $tagId);` |
| `src/Service/Subscription/FeedTagMove.php` | `return $this->tags->findOneOwnedBy($tagId, $userId)` | `return $this->tags->findOneForUser($userId, $tagId)` |
| `src/Service/Ai/AiConfigurationForUser.php` | `return $this->repository->findOwnedById($user, $id)` | `return $this->repository->findOneForUser($user, $id)` |

Run: `git grep -nE "OwnedBy\(|findOwnedById|getOwned\(" -- src tests`
Expected: no output.

- [ ] **Step 5: Run the affected suites**

Run: `php bin/phpunit tests/Repository tests/Controller/Api/TagControllerTest.php tests/Controller/Api/SavedSearchControllerTest.php tests/Controller/Api/SubscriptionControllerTest.php tests/Controller/Api/RecommendationDebugLogControllerTest.php tests/Controller/Api/MoveFeedToTagTest.php tests/Service/Reader/MarkReadServiceTest.php tests/Service/Subscription tests/Service/Refresh tests/Service/Ai`
Expected: PASS.

- [ ] **Step 6: Gates, commit**

Run `composer check && composer md` and the PhpStorm lint.
```bash
git add src/Repository/TagRepository.php src/Repository/SubscriptionRepository.php src/Repository/SavedSearchRepository.php src/Repository/AiProviderSettingsRepository.php src/Repository/RecommendationRunLogRepository.php src/Controller/Api/TagController.php src/Controller/Api/SubscriptionController.php src/Controller/Api/SavedSearchController.php src/Controller/Api/SavedSearchEntriesController.php src/Controller/Api/RecommendationDebugLogController.php src/Service/Reader/MarkReadService.php src/Service/Refresh/UserRefreshScope.php src/Service/Subscription/FeedTagMove.php src/Service/Ai/AiConfigurationForUser.php tests/Repository/TagRepositoryTest.php tests/Repository/SavedSearchRepositoryTest.php tests/Repository/SubscriptionRepositoryTest.php tests/Repository/AiProviderSettingsRepositoryTest.php tests/Repository/RecommendationRunLogRepositoryTest.php tests/Service/Subscription/UnsubscribeAllTest.php
git commit -m "refactor(#1167): owned-row lookups are findOneForUser/getOneForUser with the owner first"
```

---

### Task B2: `EntryListRepository` owner first

**Files:**
- Modify: `src/Repository/EntryListRepository.php:148, 158-160, 176, 199, 217-236`
- Modify (callers): `src/Controller/Api/EntryController.php`, `EntryCommentsController.php`, `EntryReaderController.php`; `src/Service/Reader/EntryStateUpdater.php:70`; `src/Service/Mail/Digest/DigestEntryFinder.php:40`; `src/Service/Search/IndexedEntrySearch.php:61`
- Test: `tests/Repository/EntryListTest.php`, `tests/Repository/EntryRowsByIdsTest.php`, `tests/Repository/DuplicateCollapseTest.php`, `tests/Service/Reader/EntryStateResolverTest.php`, `tests/Service/Reader/EntryStateUpdaterTest.php`, `tests/Service/Tracing/TracedServiceMethodsTest.php`

**Interfaces:**
- Produces:
  - `EntryListRepository::rowsByIdsForUser(int $userId, array $entryIds, ?int $limit = null): list<EntryListRow>`.
  - `EntryListRepository::getRowForUser(int $userId, int $entryId): EntryListRow`.
  - `EntryListRepository::siblingRowsForUser(int $userId, string $urlHash, int $excludeEntryId): list<EntryListRow>`.
  - `EntryListRepository::findOneSubscribedForUser(int $userId, int $entryId): ?Entry`, keeping `#[WithSpan]`.
  - `EntryListRepository::getOneSubscribedForUser(int $userId, int $entryId): Entry`.
- The span of the ownership lookup is renamed with its method. `TracedServiceMethodsTest` pins it. No dashboard in `docker/grafana/dashboards/` names it.

- [ ] **Step 1: Move the tests**

`EntryStateUpdaterTest` carries #1164's extra `getOneRowForUser(` call in `testUnfavouritingAndUnkeepingClearBothFlags`. Its text matches the other four, so the `replace_all` below covers all five.

| File | Before | After |
|---|---|---|
| `tests/Repository/EntryListTest.php` | `public function testGetOneRowForUserReturnsTheRowOfASubscribedEntry(): void` | `public function testGetRowForUserReturnsTheRowOfASubscribedEntry(): void` |
| same | `public function testGetOneRowForUserRefusesAnEntryOfAFeedTheUserDoesNotSubscribeTo(): void` | `public function testGetRowForUserRefusesAnEntryOfAFeedTheUserDoesNotSubscribeTo(): void` |
| same (×2, `replace_all`) | `$this->repo()->getOneRowForUser($entry->requireId(), $this->user->requireId());` | `$this->repo()->getRowForUser($this->user->requireId(), $entry->requireId());` |
| same | `public function testGetOneSubscribedByUserReturnsASubscribedEntry(): void` | `public function testGetOneSubscribedForUserReturnsASubscribedEntry(): void` |
| same | `public function testGetOneSubscribedByUserRefusesAnEntryOfAFeedTheUserDoesNotSubscribeTo(): void` | `public function testGetOneSubscribedForUserRefusesAnEntryOfAFeedTheUserDoesNotSubscribeTo(): void` |
| same (×2, `replace_all`) | `$this->repo()->getOneSubscribedByUser($entry->requireId(), $this->user->requireId())` | `$this->repo()->getOneSubscribedForUser($this->user->requireId(), $entry->requireId())` |
| `tests/Service/Reader/EntryStateResolverTest.php` | `$row = $this->rows()->getOneRowForUser($entry->requireId(), $this->user->requireId());` | `$row = $this->rows()->getRowForUser($this->user->requireId(), $entry->requireId());` |
| `tests/Service/Reader/EntryStateUpdaterTest.php` (×5, `replace_all`) | `$row = $this->rows()->getOneRowForUser($target->requireId(), $user->requireId());` | `$row = $this->rows()->getRowForUser($user->requireId(), $target->requireId());` |
| `tests/Repository/EntryRowsByIdsTest.php` | `$rows = $this->repo()->rowsByIdsForUser($ids, $this->user->requireId());` | `$rows = $this->repo()->rowsByIdsForUser($this->user->requireId(), $ids);` |
| same | `$this->repo()->rowsByIdsForUser($ids, $this->user->requireId(), 2),` | `$this->repo()->rowsByIdsForUser($this->user->requireId(), $ids, 2),` |
| same | `self::assertCount(3, $this->repo()->rowsByIdsForUser($ids, $this->user->requireId()));` | `self::assertCount(3, $this->repo()->rowsByIdsForUser($this->user->requireId(), $ids));` |
| same | `self::assertSame([], $this->repo()->rowsByIdsForUser([], $this->user->requireId()));` | `self::assertSame([], $this->repo()->rowsByIdsForUser($this->user->requireId(), []));` |
| `tests/Repository/DuplicateCollapseTest.php` | `$rows = $this->repo()->rowsByIdsForUser([$higher->requireId()], $this->user->requireId());` | `$rows = $this->repo()->rowsByIdsForUser($this->user->requireId(), [$higher->requireId()]);` |
| `tests/Service/Tracing/TracedServiceMethodsTest.php` | `yield 'reader, ownership lookup' => [EntryListRepository::class, 'findOneSubscribedByUser'];` | `yield 'reader, ownership lookup' => [EntryListRepository::class, 'findOneSubscribedForUser'];` |

Two multi-line calls change too. In `tests/Repository/DuplicateCollapseTest.php`:
```diff
         $rows = $this->repo()->rowsByIdsForUser(
-            [$lower->requireId(), $higher->requireId()],
             $this->user->requireId(),
+            [$lower->requireId(), $higher->requireId()],
         );
```
In `tests/Service/Reader/EntryStateUpdaterTest.php`:
```diff
         $siblingRows = $this->rows()->siblingRowsForUser(
+            $user->requireId(),
             'shared-url-hash',
             $target->requireId(),
-            $user->requireId(),
         );
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Repository/EntryListTest.php tests/Repository/EntryRowsByIdsTest.php tests/Repository/DuplicateCollapseTest.php tests/Service/Reader/EntryStateResolverTest.php tests/Service/Reader/EntryStateUpdaterTest.php tests/Service/Tracing/TracedServiceMethodsTest.php`
Expected:
- FAIL: `Call to undefined method …::getRowForUser()` / `getOneSubscribedForUser()`.
- A `TypeError` on `rowsByIdsForUser()`/`siblingRowsForUser()`: argument 1 must be of type array/string.
- The traced-method assertion fails for `findOneSubscribedForUser`.

- [ ] **Step 3: Implement**

`src/Repository/EntryListRepository.php`:
- `rowsByIdsForUser`: change the signature to `public function rowsByIdsForUser(int $userId, array $entryIds, ?int $limit = null): array`. The body is unchanged, because it already reads both by name.
- `getOneRowForUser` becomes:
```php
    public function getRowForUser(int $userId, int $entryId): EntryListRow
```
  with its body unchanged.
- `siblingRowsForUser`: change the signature to `public function siblingRowsForUser(int $userId, string $urlHash, int $excludeEntryId): array`. The body is unchanged.
- Replace the two subscribed lookups:
```php
    #[WithSpan]
    public function findOneSubscribedForUser(int $userId, int $entryId): ?Entry
    {
        /** @var Entry|null $entry */
        $entry = $this->createQueryBuilder('e')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere('e.id = :id')
            ->setParameter('id', $entryId)
            ->setParameter('user', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        return $entry;
    }

    public function getOneSubscribedForUser(int $userId, int $entryId): Entry
    {
        return $this->findOneSubscribedForUser($userId, $entryId)
            ?? throw new RecordNotFoundException('No such entry.');
    }
```
  Keep the docblock above `findOneSubscribedForUser` as it is.

Callers:

| File | Before | After |
|---|---|---|
| `src/Controller/Api/EntryController.php` (×2, `replace_all`) | `$row = $this->entryList->getOneRowForUser($id, $user->requireId());` | `$row = $this->entryList->getRowForUser($user->requireId(), $id);` |
| `src/Controller/Api/EntryCommentsController.php` | `$entry = $this->entryList->getOneSubscribedByUser($id, $user->requireId());` | `$entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);` |
| `src/Controller/Api/EntryReaderController.php` | `$entry = $this->entryList->getOneSubscribedByUser($id, $user->requireId());` | `$entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);` |
| `src/Service/Reader/EntryStateUpdater.php` | `$siblings = $this->rows->siblingRowsForUser($hash, $row->entry->requireId(), $user->requireId());` | `$siblings = $this->rows->siblingRowsForUser($user->requireId(), $hash, $row->entry->requireId());` |
| `src/Service/Mail/Digest/DigestEntryFinder.php` | `return new DigestSearchMatches($this->entries->rowsByIdsForUser($newestIds, $userId), \count($ids));` | `return new DigestSearchMatches($this->entries->rowsByIdsForUser($userId, $newestIds), \count($ids));` |
| `src/Service/Search/IndexedEntrySearch.php` | `$this->entries->rowsByIdsForUser($matches->entryIds, $query->userId),` | `$this->entries->rowsByIdsForUser($query->userId, $matches->entryIds),` |

Run: `git grep -nE "getOneRowForUser|SubscribedByUser" -- src tests`
Expected: no output.

- [ ] **Step 4: Run the entry, reader, digest and search suites**

Run: `php bin/phpunit tests/Repository tests/Service/Reader tests/Service/Mail/Digest tests/Service/Search tests/Service/Tracing tests/Controller/Api/EntryControllerTest.php tests/Controller/Api/EntryCommentsControllerTest.php tests/Controller/Api/EntryReaderControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Gates, commit**

Run `composer check && composer md` and the PhpStorm lint.
```bash
git add src/Repository/EntryListRepository.php src/Controller/Api/EntryController.php src/Controller/Api/EntryCommentsController.php src/Controller/Api/EntryReaderController.php src/Service/Reader/EntryStateUpdater.php src/Service/Mail/Digest/DigestEntryFinder.php src/Service/Search/IndexedEntrySearch.php tests/Repository/EntryListTest.php tests/Repository/EntryRowsByIdsTest.php tests/Repository/DuplicateCollapseTest.php tests/Service/Reader/EntryStateResolverTest.php tests/Service/Reader/EntryStateUpdaterTest.php tests/Service/Tracing/TracedServiceMethodsTest.php
git commit -m "refactor(#1167): entry list lookups take the owner first"
```

---

### Task B3: Id-list lookups owner first; architecture.md records the rule

**Files:**
- Modify: `src/Repository/TagRepository.php:31` (`findAllByIdsForUser`)
- Modify: `src/Repository/SubscriptionRepository.php:76, 107`
- Modify: `src/Service/Subscription/OwnedTagsCache.php:50-78`
- Modify: `src/Repository/SavedSearchEntryRepository.php:160, 180`
- Modify: `src/Repository/ReaderAuditRepository.php:53`
- Modify (callers): `src/Controller/Api/SubscriptionController.php:68`, `src/Service/Subscription/BulkSubscriptionUpdater.php:88`, `src/Service/Subscription/OwnedSubscriptions.php:29, 43`, `src/Service/Subscription/SubscriptionTagSync.php:32`, `src/Service/Mail/Digest/DigestEntryFinder.php:29`, `src/Repository/SavedSearchMembershipLoader.php:29`, `src/Service/ReaderAudit/AuditSampler.php:93`
- Test: `tests/Service/Subscription/OwnedTagsCacheTest.php`, `tests/Repository/SavedSearchMembershipReadsTest.php`, `tests/Repository/SavedSearchMembershipLoaderTest.php`
- Modify: `docs/architecture.md` §7

**Interfaces:**
- Produces:
  - `TagRepository::findAllByIdsForUser(int $userId, array $tagIds): list<Tag>`.
  - `OwnedTagsCache::findAllByIdsForUser(int $userId, array $tagIds): list<Tag>`.
  - `SubscriptionRepository::findAllByIdsForUser(int $userId, array $subscriptionIds)`.
  - `SubscriptionRepository::findAllByIdsForUserWithAssociations(int $userId, array $subscriptionIds)`.
  - `SavedSearchEntryRepository::unreadMemberIdsSince(int $userId, int $savedSearchId, \DateTimeImmutable $since): list<int>`.
  - `SavedSearchEntryRepository::savedSearchesByEntry(int $userId, array $entryIds)`.
  - `ReaderAuditRepository::detailRows(int $userId, array $entryIds)`.

- [ ] **Step 1: Move the tests**

`tests/Service/Subscription/OwnedTagsCacheTest.php`. Single-line sites:

| Before | After |
|---|---|
| `$resolved = $this->cache->findAllByIdsForUser([$foreignTag->requireId()], $mine->requireId());` | `$resolved = $this->cache->findAllByIdsForUser($mine->requireId(), [$foreignTag->requireId()]);` |
| `$resolved = $this->cache->findAllByIdsForUser([999_999], $user->requireId());` | `$resolved = $this->cache->findAllByIdsForUser($user->requireId(), [999_999]);` |
| `$this->cache->findAllByIdsForUser([$newsId], $userId);` (×3, `replace_all`) | `$this->cache->findAllByIdsForUser($userId, [$newsId]);` |
| `$this->cache->findAllByIdsForUser([$news->requireId()], $userId);` | `$this->cache->findAllByIdsForUser($userId, [$news->requireId()]);` |
| `$this->cache->findAllByIdsForUser([$newsId, $newsId], $user->requireId());` | `$this->cache->findAllByIdsForUser($user->requireId(), [$newsId, $newsId]);` |
| `$this->cache->findAllByIdsForUser([$sameId->requireId()], $mine->requireId());` | `$this->cache->findAllByIdsForUser($mine->requireId(), [$sameId->requireId()]);` |

Multi-line sites. Swap the two argument lines in each of the four calls, located by their first argument:
```diff
         $resolved = $this->cache->findAllByIdsForUser(
-            [$news->requireId(), $tech->requireId()],
             $user->requireId(),
+            [$news->requireId(), $tech->requireId()],
         );
```
```diff
         $resolved = $this->cache->findAllByIdsForUser(
-            [$news->requireId(), $tech->requireId()],
             $userId,
+            [$news->requireId(), $tech->requireId()],
         );
```
```diff
         $resolved = $this->cache->findAllByIdsForUser(
-            [$news->requireId(), 999_999, $tech->requireId()],
             $user->requireId(),
+            [$news->requireId(), 999_999, $tech->requireId()],
         );
```
```diff
         $resolvedForStranger = $this->cache->findAllByIdsForUser(
-            [$sameId->requireId()],
             $theirs->requireId(),
+            [$sameId->requireId()],
         );
```

`tests/Repository/SavedSearchMembershipReadsTest.php`:
```diff
         $ids = $this->repo()->unreadMemberIdsSince(
-            $climate->requireId(),
             $this->user->requireId(),
+            $climate->requireId(),
             new \DateTimeImmutable('2026-07-08T00:00:00Z'),
         );
```
`unreadMemberIdsSince` keeps its name while two `int`s swap, so add a test that fails on a swap whatever ids the database hands out. Put it directly after the existing `…Since…` test. `setUp()` persists `$this->user` and then `$this->stranger`, and only `$this->user` subscribes to the feed. A swapped call therefore asks either for the stranger's members, who see nothing, or for an id that owns no search. The decoy search moves `climate`'s id off `$this->user`'s, and the `assertNotSame` proves it did.
```php
    public function testUnreadMemberIdsSinceReadsTheOwnerBeforeTheSearch(): void
    {
        $this->search('decoy');
        $climate = $this->search('climate');
        $entry = $this->entry('z', '2026-07-10T00:00:00Z');
        $this->member($climate, $entry);
        self::assertNotSame($this->user->requireId(), $climate->requireId(), 'Equal ids would hide a swap.');

        $ids = $this->repo()->unreadMemberIdsSince(
            $this->user->requireId(),
            $climate->requireId(),
            new \DateTimeImmutable('2026-07-08T00:00:00Z'),
        );

        self::assertSame([$entry->getId()], $ids);
    }
```

`tests/Repository/SavedSearchMembershipLoaderTest.php`:
```diff
-        $byEntry = $this->repository()->savedSearchesByEntry([$entry->requireId()], $user->requireId());
+        $byEntry = $this->repository()->savedSearchesByEntry($user->requireId(), [$entry->requireId()]);
-        $byEntry = $this->repository()->savedSearchesByEntry([$entry->requireId()], $owner->requireId());
+        $byEntry = $this->repository()->savedSearchesByEntry($owner->requireId(), [$entry->requireId()]);
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/Subscription/OwnedTagsCacheTest.php tests/Repository/SavedSearchMembershipLoaderTest.php tests/Repository/SavedSearchMembershipReadsTest.php`
Expected:
- `OwnedTagsCacheTest` and `SavedSearchMembershipLoaderTest` FAIL with `TypeError`: argument #1 must be of type array, int given.
- `testUnreadMemberIdsSinceReadsTheOwnerBeforeTheSearch` FAILS with `[]` instead of the entry's id.
- The existing `…Since…` test may PASS if the user and the search got the same id. That is why the new test exists.

- [ ] **Step 3: Implement**

`src/Repository/TagRepository.php`, `findAllByIdsForUser`:
```php
    /**
     * The user's tags matching the given ids. Fewer results than ids means one
     * or more ids were invalid or belonged to another user.
     *
     * @param list<int> $tagIds
     *
     * @return list<Tag>
     */
    public function findAllByIdsForUser(int $userId, array $tagIds): array
    {
        if ([] === $tagIds) {
            return [];
        }

        /** @var list<Tag> $rows */
        $rows = $this->createQueryBuilder('t')
            ->andWhere('t.id IN (:ids)')->setParameter('ids', $tagIds)
            ->andWhere('t.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();

        return $rows;
    }
```

`src/Repository/SubscriptionRepository.php`: in both `findAllByIdsForUser` and `findAllByIdsForUserWithAssociations`:
- The signature becomes `(int $userId, array $subscriptionIds)`.
- `@param list<int> $ids` becomes `@param list<int> $subscriptionIds`.
- `if ([] === $ids)` becomes `if ([] === $subscriptionIds)`.
- `->setParameter('ids', $ids)` becomes `->setParameter('ids', $subscriptionIds)`.

`src/Service/Subscription/OwnedTagsCache.php`:
```php
    /**
     * @param list<int> $tagIds
     *
     * @return list<Tag>
     */
    public function findAllByIdsForUser(int $userId, array $tagIds): array
    {
        $this->resolveMissing($userId, $tagIds);

        $resolved = $this->resolvedByUser[$userId] ?? [];

        return array_values(array_filter(array_map(
            static fn (int $id): ?Tag => $resolved[$id] ?? null,
            $tagIds,
        )));
    }

    /**
     * @param list<int> $tagIds
     */
    private function resolveMissing(int $userId, array $tagIds): void
    {
        $known = $this->resolvedByUser[$userId] ?? [];
        $missing = array_values(array_unique(
            array_filter($tagIds, static fn (int $id): bool => !isset($known[$id])),
        ));
        if ([] === $missing) {
            return;
        }

        foreach ($this->tags->findAllByIdsForUser($userId, $missing) as $tag) {
            $this->resolvedByUser[$userId][$tag->requireId()] = $tag;
        }
    }
```

`src/Repository/SavedSearchEntryRepository.php`:
- The signature becomes `public function unreadMemberIdsSince(int $userId, int $savedSearchId, \DateTimeImmutable $since): array`. The body is unchanged.
- The signature becomes `public function savedSearchesByEntry(int $userId, array $entryIds): array`. The body is unchanged.

`src/Repository/ReaderAuditRepository.php`: the signature becomes `public function detailRows(int $userId, array $entryIds): array`. The body is unchanged.

Callers:

| File | Before | After |
|---|---|---|
| `src/Controller/Api/SubscriptionController.php` | `$tags = $this->tags->findAllByIdsForUser($request->tagIds, $user->requireId());` | `$tags = $this->tags->findAllByIdsForUser($user->requireId(), $request->tagIds);` |
| `src/Service/Subscription/BulkSubscriptionUpdater.php` | `$owned = $this->tags->findAllByIdsForUser($tagIds, $userId);` | `$owned = $this->tags->findAllByIdsForUser($userId, $tagIds);` |
| `src/Service/Subscription/OwnedSubscriptions.php` | `return $this->keyedById($this->subscriptions->findAllByIdsForUser($ids, $userId), $ids);` | `return $this->keyedById($this->subscriptions->findAllByIdsForUser($userId, $ids), $ids);` |
| `src/Service/Subscription/OwnedSubscriptions.php` | `$owned = $this->subscriptions->findAllByIdsForUserWithAssociations($ids, $userId);` | `$owned = $this->subscriptions->findAllByIdsForUserWithAssociations($userId, $ids);` |
| `src/Service/Subscription/SubscriptionTagSync.php` | `$resolved = $this->tags->findAllByIdsForUser($requestedTagIds, $userId);` | `$resolved = $this->tags->findAllByIdsForUser($userId, $requestedTagIds);` |
| `src/Service/Mail/Digest/DigestEntryFinder.php` | `$ids = $this->members->unreadMemberIdsSince($search->requireId(), $userId, $since);` | `$ids = $this->members->unreadMemberIdsSince($userId, $search->requireId(), $since);` |
| `src/Repository/SavedSearchMembershipLoader.php` | `$byEntryId = $this->memberships->savedSearchesByEntry($this->entryIdsOf($rows), $userId);` | `$byEntryId = $this->memberships->savedSearchesByEntry($userId, $this->entryIdsOf($rows));` |
| `src/Service/ReaderAudit/AuditSampler.php` | `foreach ($this->audit->detailRows($entryIds, $userId) as $row) {` | `foreach ($this->audit->detailRows($userId, $entryIds) as $row) {` |

`docs/architecture.md` §7: insert this bullet directly after the `**No hidden side effects.**` bullet:
```markdown
- **The owner comes first.** A method scoped to one user takes the user, or its id, as its first criterion and is
  named `…ForUser`: `getOneForUser(int $userId, int $tagId)`, `findAllByIdsForUser(int $userId, array $tagIds)`.
  The subject a method works on stays in front (`EntryListRowEnricher::enrich($rows, $userId)`). Decided in #1167.
```

- [ ] **Step 4: Guard the digest call site against a swap**

`DigestEntryFinder::matchesSince()` is the only production caller of `unreadMemberIdsSince()`, and a swapped call there would still type-check. The fixture:
- One reader owns one saved search, which has one unread member on a feed the reader subscribes to.
- Before that search, two throwaway searches are created for another user. They advance the search ids, so the reader's search id and user id differ without touching any key by hand.
- An explicit `assertNotSame` precondition proves that they differ.

The swapped call `unreadMemberIdsSince($search->requireId(), $userId, …)` asks for user = the search id and search = the reader's id. That user owns no search with that id, so the swapped call returns nothing and the assertion on the entry fails.

Add to `tests/Service/Mail/Digest/DigestEntryFinderTest.php`:
- the import `use App\Tests\Support\SavedSearchMatchFixture;` after `use App\Tests\DbTestCase;`,
- this test after `testNoMatchesReturnsEmptyWithoutHydrating()`:
```php
    public function testReadsTheOwnersSearchNotTheOneTheIdsWouldNameSwapped(): void
    {
        $searches = new SavedSearchMatchFixture($this->em);
        $stranger = new User('stranger@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($stranger);
        $reader = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($reader);
        $this->em->flush();
        $searches->search($stranger, 'throwaway-one');
        $searches->search($stranger, 'throwaway-two');
        $readersSearch = $searches->search($reader, 'klima');
        $readersEntry = $searches->member($reader, $readersSearch, new \DateTimeImmutable('2026-07-20T00:00:00Z'));
        self::assertNotSame($reader->requireId(), $readersSearch->requireId(), 'Equal ids would hide a swap.');

        $matches = $this->finder()->matchesSince($readersSearch, $reader->requireId(), $this->since);

        self::assertSame(1, $matches->totalCount);
        self::assertSame([$readersEntry->getId()], $this->ids($matches->entries));
    }
```

Run: `php bin/phpunit tests/Service/Mail/Digest/DigestEntryFinderTest.php`.
Expected: PASS. After Step 3 the call site reads `($userId, $search->requireId(), $since)`.

Deletion check:
- Swap the call in `DigestEntryFinder::matchesSince()` to `unreadMemberIdsSince($search->requireId(), $userId, $since)`.
- Run the test again. It must fail with `totalCount` 0.
- Restore the call by hand and paste both outputs.

- [ ] **Step 5: Run the suites**

Run: `php bin/phpunit tests/Repository tests/Service/Subscription tests/Service/Mail/Digest tests/Service/ReaderAudit tests/Controller/Api/SubscriptionBulkTest.php`
Expected: PASS.

Then run `git grep -nE "findAllByIdsForUser(WithAssociations)?\(\[|findAllByIdsForUser(WithAssociations)?\(\\\$(ids|tagIds|requestedTagIds|missing|request)" -- src tests`. Expected: no output.

- [ ] **Step 6: Gates, commit**

Run `composer check && composer md` and the PhpStorm lint.
```bash
git add src/Repository/TagRepository.php src/Repository/SubscriptionRepository.php src/Service/Subscription/OwnedTagsCache.php src/Repository/SavedSearchEntryRepository.php src/Repository/ReaderAuditRepository.php src/Controller/Api/SubscriptionController.php src/Service/Subscription/BulkSubscriptionUpdater.php src/Service/Subscription/OwnedSubscriptions.php src/Service/Subscription/SubscriptionTagSync.php src/Service/Mail/Digest/DigestEntryFinder.php src/Repository/SavedSearchMembershipLoader.php src/Service/ReaderAudit/AuditSampler.php tests/Service/Subscription/OwnedTagsCacheTest.php tests/Repository/SavedSearchMembershipReadsTest.php tests/Repository/SavedSearchMembershipLoaderTest.php tests/Service/Mail/Digest/DigestEntryFinderTest.php ../docs/architecture.md
git commit -m "refactor(#1167): id-list lookups take the owner first; architecture records the rule"
```

---

### Finishing PR B

- [ ] Run the per-PR gates in order and paste each summary line:
```bash
php bin/phpunit
docker compose exec php composer test
composer check
composer md
composer infection:diff
```
Lint every changed PHP file with `mcp__phpstorm__lint_files`.
- [ ] Scan today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`.
- [ ] Push and open the PR:
```bash
git push -u origin refactor/1167-owner-first-repositories
gh pr create --base develop --title "refactor(#1167): user-scoped repository methods take the owner first" --body "$(cat <<'EOF'
Closes #1167

Second of two PRs; the first one covered the flags, string modes and missing value objects.

- One name per concept: `findOneOwnedBy` / `getOneOwnedBy` / `findOwnedById` / `getOwned` become `findOneForUser` / `getOneForUser`.
- A user-scoped repository method takes the owner first. Where that would have swapped two adjacent ints, the method is renamed too (`getRowForUser`, `findOneSubscribedForUser`, `getOneSubscribedForUser`), so no stale call can compile against the new order.
- `docs/architecture.md` §7 records the rule.

No wire change. The tracing span of the reader's ownership lookup is renamed with its method (`findOneSubscribedForUser`).
EOF
)"
```
- [ ] After a merge (the user's call), confirm that #1167 closed: `gh issue view 1167 --json state --jq .state`. Do not close it by hand.

### Execution rulings (PR B)

Recorded while executing Tasks B0–B3. Each ruling amends the text above.

- **PB1 (B3):** `SavedSearchEntryRepository::unreadMemberIdsSince` is renamed to `unreadMemberIdsForUserSince(int $userId, int $savedSearchId, \DateTimeImmutable $since)`. Its reorder swaps two adjacent ints, so the rename rule applies; the table's "kept" cell contradicted it. B3's swap-guard tests stay as the behavioural check.
- **PS1 (B2):** `AbstractEntryProjectionRepository`'s docblock names `getRowForUser`; Step 3's check greps `getOneRowForUser|(find|get)OneSubscribedByUser` (the plan's version also matched `idsSubscribedByUser`).
- **PS2 (B3):** the `docs/architecture.md` bullet says owned-row lookups by id are named `…ForUser`, not every user-scoped method; `savedSearchesByEntry` and `detailRows` keep their names.
- **PS3 (B1/B3):** the `RecommendationRunLogRepository` class docblock and `findAllByIdsForUserWithAssociations`'s docblock are trimmed to three lines or fewer.
- **PS4:** B1's run adds `SavedSearchEntriesControllerTest`; B3's adds `SubscriptionControllerTest`.
- **N4/N5/N8:** B2's expected `ReflectionException` did not occur (the rename keeps the method resolvable); the swap tests are named for behaviour; B3's red run is its failing proof.
- **R1 (B2):** `EntryListTest` calls `findOneSubscribedForUser` directly, which kills a `PublicVisibility` mutant; the method is public for its `#[WithSpan]`.
- **R2 (B3):** an `UnwrapArrayValues` mutant in `OwnedTagsCache::resolveMissing()` is equivalent (the keys never reach the query) and is accepted without an ignore.
- **R3 (final review, /simplify):** `OwnedSubscriptions::resolve()` stays ids-first, because it is a service wrapper outside the repository rule. The digest swap test keeps its separate owner for the throwaway searches. A shared id-divergence test helper waits for a third use.
