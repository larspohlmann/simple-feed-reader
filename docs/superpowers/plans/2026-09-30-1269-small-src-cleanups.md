# Small `src` Cleanups (#1269) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close #1269 in one PR with five small cleanups found during #1171, none of which changes behaviour:
1. `CardFields::MAX_TEASER_LENGTH` moves to `HtmlItemExtractor`, its only user.
2. `EntryCursor::inclusiveUpperBound()` and its test go. Nothing has called it since #1116.
3. `EntryListRepository::rowsByIdsForUser()` loses `$limit`, which no caller passes.
4. `EffectiveRecommendationSettingsModel` loses the defaults that only test builders relied on. Each builder passes the values explicitly.
5. `FeedOutcome` declares `case Aborted` with the other cases, above `broughtContent()`.

**Architecture:** Five independent edits, one task and one commit each. Only item 1 touches an assertion, and it strengthens it (a deletion check guards it). Items 2 and 3 delete a test each, with the code that test covered. The rest changes no test outcome.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM 3, PHPUnit 12, PHP_CodeSniffer (PSR-12), PHPStan level max, PHPMD, phptramp, Infection.

**Spec:** GitHub issue #1269 (`gh issue view 1269`); CLAUDE.md, "PHP code style — Clean Code is mandatory".

## Survey at `bf742411`

Each claim was checked with `git grep` over `backend/src` and `backend/tests` at `bf742411`, with a positive control for each grep.

**Item 1: `MAX_TEASER_LENGTH` has one `src` user.**
```
$ git grep -n -E 'MAX_TEASER_LENGTH' bf742411 -- backend/src backend/tests
bf742411:backend/src/Service/Scraper/HtmlItemExtractor.php:129:            : mb_substr($item->teaser, 0, CardFields::MAX_TEASER_LENGTH);
bf742411:backend/src/Service/Scraper/Pass/CardFields.php:21:    public const int MAX_TEASER_LENGTH = 1000;
bf742411:backend/tests/Service/Scraper/HtmlItemExtractorTest.php:104:        self::assertSame(CardFields::MAX_TEASER_LENGTH, mb_strlen((string) $parsed->entries[0]->summary));
```
Positive control: the declaration at `CardFields.php:21` is itself a hit. `CardFields` never reads the constant. The other three constants stay, because `JsonLdArticles` uses them: `git grep -n -E 'CardFields::M(IN|AX)_' bf742411 -- backend/src` prints `Pass/JsonLdArticles.php:176`, `:180` and `:192` (`MIN_TITLE_LENGTH`, `MAX_TITLE_LENGTH`, `MIN_TEASER_LENGTH`).

**Item 2: `inclusiveUpperBound()` has no caller in `src`.**
```
$ git grep -n -E 'inclusiveUpperBound|InclusiveUpperBound' bf742411 -- . ':!docs/superpowers'
bf742411:backend/src/Pagination/EntryCursor.php:41:    public static function inclusiveUpperBound(\DateTimeImmutable $until): self
bf742411:backend/tests/Pagination/EntryCursorTest.php:66:    public function testInclusiveUpperBoundAdmitsEveryRealIdAtThatInstant(): void
bf742411:backend/tests/Pagination/EntryCursorTest.php:70:        $cursor = EntryCursor::inclusiveUpperBound($until);
```
Positive control: the declaration line itself. As a second control, the cursor's live statics do have callers: `git grep -n -E 'EntryCursor::(fromRequestValue|encode|decode)' bf742411 -- backend/src` prints `Controller/Api/EntryController.php:78`, `Http/EntryPage.php:64` and others. `git log -S'inclusiveUpperBound(' bf742411 -- backend/src` names `162104c6 refactor(#1116)` as the commit that removed the last caller. The only other mentions are in historical plans under `docs/superpowers/`, which stay as written.

**Item 3: no caller passes `$limit`.**
```
$ git grep -n -E 'rowsByIdsForUser' bf742411 -- backend/src backend/tests
backend/src/Repository/EntryListRepository.php:126:    public function rowsByIdsForUser(int $userId, array $entryIds, ?int $limit = null): array
backend/src/Service/Mail/Digest/DigestEntryFinder.php:38:        return new DigestSearchMatchesModel($this->entries->rowsByIdsForUser($userId, $newestIds), \count($ids));
backend/src/Service/Search/EntrySearch/IndexedEntrySearch.php:17: * EntryListRepository::rowsByIdsForUser, the access check that has the last word. The unread refinement drops read
backend/src/Service/Search/EntrySearch/IndexedEntrySearch.php:45:            $this->entries->rowsByIdsForUser($query->userId, $matches->entryIds),
backend/tests/Repository/DuplicateCollapseTest.php:126:        $rows = $this->repository()->rowsByIdsForUser($this->user->requireId(), [$higher->requireId()]);
backend/tests/Repository/DuplicateCollapseTest.php:159:        $rows = $this->repository()->rowsByIdsForUser(
backend/tests/Repository/EntryRowsByIdsTest.php:16: * rowsByIdsForUser(), which turns a search index's ids back into list rows through the entry list's own projection
backend/tests/Repository/EntryRowsByIdsTest.php:73:        $rows = $this->repository()->rowsByIdsForUser($this->user->requireId(), $ids);
backend/tests/Repository/EntryRowsByIdsTest.php:114:                $this->repository()->rowsByIdsForUser($this->user->requireId(), $ids, 2),
backend/tests/Repository/EntryRowsByIdsTest.php:118:        self::assertCount(3, $this->repository()->rowsByIdsForUser($this->user->requireId(), $ids));
backend/tests/Repository/EntryRowsByIdsTest.php:148:        self::assertSame([], $this->repository()->rowsByIdsForUser($this->user->requireId(), []));
```
(The `bf742411:` prefix is dropped above.) Positive control: `EntryRowsByIdsTest.php:114`, the one call that passes a third argument, which is `testTheLimitCapsHydrationToTheNewestRows`. The multi-line call at `DuplicateCollapseTest.php:159` passes two arguments (`git grep -n -E -A3 'rowsByIdsForUser\($' …` prints lines 160–162: the user id, the id list and `);`). The last production caller that passed a limit was the combined saved-search list. #1116 deleted it: `git show 162104c6 -- backend/src` contains `-        $page = $this->entries->rowsByIdsForUser(array_keys($firstMatch), $query->userId, $query->limit);`.

**Item 4: the defaults exist only for test builders.**
```
$ git grep -n -E '\$(autoGenerateIntervalHours|profileText|showReasons) = ' bf742411 -- backend/src/Service/Recommendation/Settings/Model/EffectiveRecommendationSettingsModel.php
…/EffectiveRecommendationSettingsModel.php:24:        public ?int $autoGenerateIntervalHours = null,
…/EffectiveRecommendationSettingsModel.php:25:        public ?string $profileText = null,
…/EffectiveRecommendationSettingsModel.php:26:        public bool $showReasons = false,
$ git grep -n -E 'new EffectiveRecommendationSettingsModel' bf742411 -- backend/src backend/tests
backend/src/Service/Recommendation/Settings/RecommendationSettingsResolver.php:39:        return new EffectiveRecommendationSettingsModel(
backend/tests/Http/RecommendationSettingsJsonTest.php:118:        return new EffectiveRecommendationSettingsModel(
backend/tests/Service/Recommendation/Prompt/RecommendationHistoryLoaderTest.php:233:        return new EffectiveRecommendationSettingsModel(
backend/tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php:957:        return new EffectiveRecommendationSettingsModel(
backend/tests/Service/Recommendation/Run/Pass/TickContextTest.php:44:            new EffectiveRecommendationSettingsModel(
$ git grep -n -E '(autoGenerateIntervalHours|profileText|showReasons): ' bf742411 -- <the five files above>
RecommendationSettingsResolver.php:41:            profileText: $row?->values()->profileText,
RecommendationSettingsResolver.php:51:            autoGenerateIntervalHours: $row?->values()->autoGenerateIntervalHours,
RecommendationSettingsResolver.php:52:            showReasons: $row?->values()->showReasons ?? false,
RecommendationSettingsJsonTest.php:21:        $effective = $this->effectiveSettings(profileText: 'Likes Rust and homelab posts.');
RecommendationSettingsJsonTest.php:63:            $this->effectiveSettings(showReasons: true),
RecommendationSettingsJsonTest.php:129:            profileText: $profileText,
RecommendationSettingsJsonTest.php:130:            showReasons: $showReasons,
```
Positive control: the resolver's three lines. The resolver, the only producer in `src`, passes all three fields. The four test constructions rely on the defaults as follows:

| Construction | `autoGenerateIntervalHours` | `profileText` | `showReasons` |
|---|---|---|---|
| `RecommendationSettingsJsonTest::effectiveSettings()` | default | passed | passed |
| `RecommendationHistoryLoaderTest::settings()` | default | default | default |
| `RecommendationPromptBuilderTest::settings()` | default | default | default |
| `TickContextTest::tick()` (inline) | default | default | default |

**Item 5: `case Aborted` comes after the method.**
```
$ git grep -n -E '^[[:space:]]+case [A-Za-z]+;' bf742411 -- backend/src/Service/Refresh/Model/FeedOutcome.php
…/FeedOutcome.php:9:    case Fetched;
…/FeedOutcome.php:10:    case NotModified;
…/FeedOutcome.php:11:    case Failed;
…/FeedOutcome.php:13:    case Throttled;
…/FeedOutcome.php:24:    case Aborted;
```
Positive control: lines 9–13. `broughtContent()` sits at lines 15–22. Moving the case changes the order `cases()` returns. Nothing reads it: `git grep -n -E 'FeedOutcome::cases\(\)|FeedOutcome::from|FeedOutcome::tryFrom' bf742411 -- backend/src backend/tests` prints nothing, and the file has no `self::cases()`. The positive control `git grep -n -E '[A-Za-z]+::cases\(\)' bf742411 -- backend/src` prints `Enum/EntryView.php:38`, `Enum/RunStatus.php:35` and others. `FeedOutcome` is a pure enum, so there is no backing value to reorder.

## Decisions

- **D-1 (item 3): drop `$limit` instead of having the digest pass it.** The digest already caps its input before hydration, and it caps the ids, not the rows. `DigestEntryFinder.php:34–36` reads: `// Hydrate only the newest PER_SEARCH (the ids arrive newest-first): a wide window matches hundreds, and` … `$newestIds = \array_slice($ids, 0, self::PER_SEARCH);`. The ids come newest-first from `SavedSearchEntryRepository::unreadMemberIdsForUserSince()`, which returns `scalarIds($this->projection->newestFirst($qb))` at `:168`. Passing `PER_SEARCH` as an SQL `LIMIT` would cap the rows left after the duplicate collapse, not the ids. That is a different set, which would be a behaviour change. The digest needs no second cap, and dropping the parameter removes the branch and the only test of it (`testTheLimitCapsHydrationToTheNewestRows`).
- **D-2 (item 4): `$autoGenerateIntervalHours = null` goes too.** It is the same case as the two defaults the issue names. The resolver passes it at `RecommendationSettingsResolver.php:51`, and three of the four test constructions omit it. Following "always the general solution", every default on the model goes, and every construction passes all eight arguments.
- **D-3 (item 1): the constant becomes `private` in `HtmlItemExtractor`, and the test pins the cap's value, `1000`.** The test was the only other reader of the constant. Asserting `CardFields::MAX_TEASER_LENGTH` against itself would pass whatever the value, and after the move, `composer infection:diff` mutates the touched declaration line. The literal assertion catches an `IncrementInteger`/`DecrementInteger` mutant there. A deletion check proves it.
- **D-4 (item 1): the funnel comment in `HtmlItemExtractor::toEntry()` stays unchanged.** It says why the cap sits at the funnel, not in a layer, and that stays true now that the constant lives in the same class. The test's docblock (`HtmlItemExtractorTest.php:82–86`) names `CardFields` in prose only, stays accurate, and is left alone.
- **D-5 (item 2): the historical plans under `docs/superpowers/` that name `inclusiveUpperBound` stay untouched.** They record what was planned at the time.

## Questions for the planner

None.

## Status

| Task | State |
|---|---|
| Task 0: Preflight, branch, plan copy | ⬜ |
| Task 1: `MAX_TEASER_LENGTH` lives in `HtmlItemExtractor` | ⬜ |
| Task 2: Delete `EntryCursor::inclusiveUpperBound()` | ⬜ |
| Task 3: `rowsByIdsForUser()` takes no `$limit` | ⬜ |
| Task 4: `EffectiveRecommendationSettingsModel` has no defaults | ⬜ |
| Task 5: `FeedOutcome` declares `Aborted` with its cases | ⬜ |
| Finishing: gates, review, PR | ⬜ |

## Global Constraints

- **Paths and commands are relative to `backend/`** unless a step says "from the repository root".
- **Read before you write.** Every edit names the exact text it replaces. If that text is missing, stop and report the file and the text you found.
- **No behaviour change.** Only D-3's assertion changes, and it pins the value the code already has.
- **Clean Code (CLAUDE.md):** no new comments. The one moved comment (`FeedOutcome::Aborted`) keeps its wording.
- **Every grep has a positive control** named in its step. Use `git grep -E`, never `\b` (use `(^|[^A-Za-z0-9_])`), never `-F` with a backslash.
- **Deletion checks:** the implementer runs D-3's check, quotes the FAIL, and restores the code with the Edit tool, never `git checkout --`.
- **Gates per task (lean):** `php -l` and `vendor/bin/phpcs` on the changed files, plus `php bin/phpunit` on the touched test files. The steps give the exact commands.
- **Commits:** `refactor(#1269): <lower-case summary>`, one per task. The plan copy is `docs(#1269): add plan`. No attribution or co-author lines.
- **The checkout is shared.** Run `git status --short && git branch --show-current` before any `checkout`, `switch`, `reset` or `stash`. Another session may be mid-edit. Work in place, no worktrees.
- **Every command runs in the foreground.** No background jobs and no poll loops.

---

### Task 0: Preflight, branch, plan copy

**Files:**
- Create: `docs/superpowers/plans/2026-09-30-1269-small-src-cleanups.md` (this plan)

- [ ] **Step 1: The checkout is free (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
gh issue view 1269 --json state --jq .state
```
Expected: a clean tree, or only another session's files, which you leave alone. The issue state is `OPEN`.

- [ ] **Step 2: Develop still matches the survey (from the repository root)**

```bash
git log --oneline bf742411..origin/develop -- backend/src/Service/Scraper/Pass/CardFields.php backend/src/Service/Scraper/HtmlItemExtractor.php backend/tests/Service/Scraper/HtmlItemExtractorTest.php backend/src/Pagination/EntryCursor.php backend/tests/Pagination/EntryCursorTest.php backend/src/Repository/EntryListRepository.php backend/tests/Repository/EntryRowsByIdsTest.php backend/src/Service/Recommendation/Settings/Model/EffectiveRecommendationSettingsModel.php backend/tests/Http/RecommendationSettingsJsonTest.php backend/tests/Service/Recommendation/Prompt/RecommendationHistoryLoaderTest.php backend/tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php backend/tests/Service/Recommendation/Run/Pass/TickContextTest.php backend/src/Service/Refresh/Model/FeedOutcome.php
git grep -n -E 'MAX_TEASER_LENGTH|inclusiveUpperBound|rowsByIdsForUser\(.*, [0-9]+\)|new EffectiveRecommendationSettingsModel' origin/develop -- backend/src backend/tests
```
Expected: the first command prints nothing. The second prints these eleven lines:
- `HtmlItemExtractor.php:129`, `CardFields.php:21`, `HtmlItemExtractorTest.php:104` (item 1)
- `EntryCursor.php:41`, `EntryCursorTest.php:70` (item 2)
- `EntryRowsByIdsTest.php:114` (item 3; the positive control for `, [0-9]+\)`)
- `RecommendationSettingsResolver.php:39`, `RecommendationSettingsJsonTest.php:118`, `RecommendationHistoryLoaderTest.php:233`, `RecommendationPromptBuilderTest.php:957`, `TickContextTest.php:44` (item 4)

If a commit is listed, read it. If it touches a line a task replaces, stop and report it. Otherwise name it in the PR body and go on. The task edits carry the exact text, so they catch any drift.

- [ ] **Step 3: Branch and commit the plan copy (from the repository root)**

```bash
git switch -c chore/1269-small-src-cleanups origin/develop
cp <the plan file the planner handed you> docs/superpowers/plans/2026-09-30-1269-small-src-cleanups.md
git add docs/superpowers/plans/2026-09-30-1269-small-src-cleanups.md
git commit -m "docs(#1269): add plan"
```

---

### Task 1: `MAX_TEASER_LENGTH` lives in `HtmlItemExtractor`

**Files:**
- Modify: `src/Service/Scraper/Pass/CardFields.php`
- Modify: `src/Service/Scraper/HtmlItemExtractor.php`
- Modify: `tests/Service/Scraper/HtmlItemExtractorTest.php`

- [ ] **Step 1: Remove the constant from `CardFields`**

`src/Service/Scraper/Pass/CardFields.php`, Before:
```php
    public const int MIN_TEASER_LENGTH = 40;
    public const int MAX_TEASER_LENGTH = 1000;

    private const array NON_LEAF_CHILDREN
```
After:
```php
    public const int MIN_TEASER_LENGTH = 40;

    private const array NON_LEAF_CHILDREN
```

- [ ] **Step 2: Declare it in `HtmlItemExtractor` and read it there**

`src/Service/Scraper/HtmlItemExtractor.php`, first edit. Before:
```php
use App\Service\Scraper\Model\ScrapedItemModel;
use App\Service\Scraper\Pass\CardFields;
use App\Service\Scraper\ScrapeLayer\ScrapeLayerInterface;
```
After:
```php
use App\Service\Scraper\Model\ScrapedItemModel;
use App\Service\Scraper\ScrapeLayer\ScrapeLayerInterface;
```

Second edit. Before:
```php
    private const int MIN_ITEMS = 3;
    private const int MAX_ITEMS = 50;
```
After:
```php
    private const int MIN_ITEMS = 3;
    private const int MAX_ITEMS = 50;
    private const int MAX_TEASER_LENGTH = 1000;
```

Third edit. Before:
```php
            : mb_substr($item->teaser, 0, CardFields::MAX_TEASER_LENGTH);
```
After:
```php
            : mb_substr($item->teaser, 0, self::MAX_TEASER_LENGTH);
```

- [ ] **Step 3: The test pins the cap's value (D-3)**

`tests/Service/Scraper/HtmlItemExtractorTest.php`, first edit. Before:
```php
use App\Service\Scraper\HtmlItemExtractor;
use App\Service\Scraper\Pass\CardFields;
use App\Service\Scraper\ScrapeLayer\ClusterLayer;
```
After:
```php
use App\Service\Scraper\HtmlItemExtractor;
use App\Service\Scraper\ScrapeLayer\ClusterLayer;
```

Second edit. Before:
```php
        self::assertSame(CardFields::MAX_TEASER_LENGTH, mb_strlen((string) $parsed->entries[0]->summary));
```
After:
```php
        self::assertSame(1000, mb_strlen((string) $parsed->entries[0]->summary));
```

- [ ] **Step 4: The grep**

```bash
git grep -n -E 'MAX_TEASER_LENGTH' -- src tests
git grep -n -E 'CardFields::M(IN|AX)_' -- src
```
Expected from the first command: exactly two lines, both in `src/Service/Scraper/HtmlItemExtractor.php`: the declaration (`private const int MAX_TEASER_LENGTH = 1000;`) and the `mb_substr` line. The declaration is the positive control.
Expected from the second: `src/Service/Scraper/Pass/JsonLdArticles.php` at 176, 180 and 192 (the positive control), and no `MAX_TEASER_LENGTH`.

- [ ] **Step 5: Lint and test**

```bash
php -l src/Service/Scraper/Pass/CardFields.php && php -l src/Service/Scraper/HtmlItemExtractor.php && php -l tests/Service/Scraper/HtmlItemExtractorTest.php
vendor/bin/phpcs src/Service/Scraper/Pass/CardFields.php src/Service/Scraper/HtmlItemExtractor.php tests/Service/Scraper/HtmlItemExtractorTest.php
php bin/phpunit tests/Service/Scraper
```
Expected: `No syntax errors detected` three times, no phpcs output, and phpunit `OK`.

- [ ] **Step 6: Deletion check**

In `src/Service/Scraper/HtmlItemExtractor.php`, replace `    private const int MAX_TEASER_LENGTH = 1000;` with `    private const int MAX_TEASER_LENGTH = 999;`, then run:
```bash
php bin/phpunit --filter testTeaserCapAppliesToJsonLdDescriptionsAtTheFunnel tests/Service/Scraper/HtmlItemExtractorTest.php
```
Expected FAIL: `Failed asserting that 999 is identical to 1000.` Quote it in the task report.
Restore with the Edit tool: replace `    private const int MAX_TEASER_LENGTH = 999;` with `    private const int MAX_TEASER_LENGTH = 1000;`. Re-run the command and expect `OK (1 test, 2 assertions)`. Then run `git diff --stat`; it must list only the three files of this task.

- [ ] **Step 7: Commit**

```bash
git add src/Service/Scraper/Pass/CardFields.php src/Service/Scraper/HtmlItemExtractor.php tests/Service/Scraper/HtmlItemExtractorTest.php
git commit -m "refactor(#1269): the teaser cap lives in HtmlItemExtractor, its only user"
```

---

### Task 2: Delete `EntryCursor::inclusiveUpperBound()`

**Files:**
- Modify: `src/Pagination/EntryCursor.php`
- Modify: `tests/Pagination/EntryCursorTest.php`

- [ ] **Step 1: Delete the method**

`src/Pagination/EntryCursor.php`. Before:
```php
            throw new ValidationException(['cursor' => ['The cursor is malformed.']]);
        }
    }

    /**
     * An upper bound that admits every row AT $until, not only those before it: the keyset predicate is strict, so
     * the max int id stands in for the id. Never encoded for a client.
     */
    public static function inclusiveUpperBound(\DateTimeImmutable $until): self
    {
        return new self($until, PHP_INT_MAX);
    }

    public static function encode(\DateTimeImmutable $sortInstant, int $id): string
```
After:
```php
            throw new ValidationException(['cursor' => ['The cursor is malformed.']]);
        }
    }

    public static function encode(\DateTimeImmutable $sortInstant, int $id): string
```

- [ ] **Step 2: Delete its test**

`tests/Pagination/EntryCursorTest.php`. Before:
```php
        EntryCursor::decode($cursor);
    }

    public function testInclusiveUpperBoundAdmitsEveryRealIdAtThatInstant(): void
    {
        $until = new \DateTimeImmutable('2026-07-12T00:00:00Z');

        $cursor = EntryCursor::inclusiveUpperBound($until);

        self::assertSame($until, $cursor->sortInstant);
        self::assertSame(PHP_INT_MAX, $cursor->id);
    }
}
```
After:
```php
        EntryCursor::decode($cursor);
    }
}
```

- [ ] **Step 3: The grep**

```bash
git grep -n -E 'inclusiveUpperBound|InclusiveUpperBound|PHP_INT_MAX' -- src/Pagination tests/Pagination
git grep -n -E 'public static function (fromRequestValue|encode|decode)\(' -- src/Pagination/EntryCursor.php
```
Expected: the first command prints nothing. The second prints three lines (`fromRequestValue`, `encode`, `decode`), which is the positive control.

- [ ] **Step 4: Lint and test**

```bash
php -l src/Pagination/EntryCursor.php && php -l tests/Pagination/EntryCursorTest.php
vendor/bin/phpcs src/Pagination/EntryCursor.php tests/Pagination/EntryCursorTest.php
php bin/phpunit tests/Pagination/EntryCursorTest.php
```
Expected: no syntax errors, no phpcs output, and `OK (9 tests, …)`: four plain tests plus five `malformedCursors` cases.

- [ ] **Step 5: Commit**

```bash
git add src/Pagination/EntryCursor.php tests/Pagination/EntryCursorTest.php
git commit -m "refactor(#1269): delete EntryCursor::inclusiveUpperBound(), unused since #1116"
```

---

### Task 3: `rowsByIdsForUser()` takes no `$limit`

**Files:**
- Modify: `src/Repository/EntryListRepository.php`
- Modify: `tests/Repository/EntryRowsByIdsTest.php`

- [ ] **Step 1: Drop the parameter and its branch (D-1)**

`src/Repository/EntryListRepository.php`. Before:
```php
    /**
     * The given ids as list rows, ordered like the entry list and never in the order asked for; $limit keeps the
     * newest. The subscription join is the access gate: an id from a feed the caller does not follow is dropped,
     * even when a search index returned it.
     *
     * @param list<int> $entryIds
     *
     * @return list<EntryListRow>
     */
    public function rowsByIdsForUser(int $userId, array $entryIds, ?int $limit = null): array
    {
        if ($entryIds === []) {
            return [];
        }

        $applyScope = function (QueryBuilder $qb, EntryAliases $aliases) use ($entryIds): void {
            $this->scope->applyIds($qb, $aliases, $entryIds);
        };
        $rowQuery = $this->projection->newestFirst($this->projection->rowQueryBuilder($userId));
        $applyScope($rowQuery, EntryAliases::primary());
        $this->collapse->apply($rowQuery, $applyScope, $userId);
        if ($limit !== null) {
            $rowQuery->setMaxResults($limit);
        }

        /** @var list<array<array-key, mixed>> $rows */
```
After:
```php
    /**
     * The given ids as list rows, ordered like the entry list and never in the order asked for. The subscription
     * join is the access gate: an id from a feed the caller does not follow is dropped, even when a search index
     * returned it.
     *
     * @param list<int> $entryIds
     *
     * @return list<EntryListRow>
     */
    public function rowsByIdsForUser(int $userId, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $applyScope = function (QueryBuilder $qb, EntryAliases $aliases) use ($entryIds): void {
            $this->scope->applyIds($qb, $aliases, $entryIds);
        };
        $rowQuery = $this->projection->newestFirst($this->projection->rowQueryBuilder($userId));
        $applyScope($rowQuery, EntryAliases::primary());
        $this->collapse->apply($rowQuery, $applyScope, $userId);

        /** @var list<array<array-key, mixed>> $rows */
```

- [ ] **Step 2: Delete the limit's test**

`tests/Repository/EntryRowsByIdsTest.php`. Before:
```php
        self::assertSame(['newer', 'older'], $this->rowsByIds([$olderId, $newerId]));
    }

    public function testTheLimitCapsHydrationToTheNewestRows(): void
    {
        $oldest = $this->entry('oldest', '2026-07-10T00:00:00Z');
        $middle = $this->entry('middle', '2026-07-11T00:00:00Z');
        $newest = $this->entry('newest', '2026-07-12T00:00:00Z');
        $ids = [$oldest->requireId(), $middle->requireId(), $newest->requireId()];

        // A limit keeps only the newest rows, still in newest-first order — the
        // tail past the limit is never hydrated.
        self::assertSame(
            ['newest', 'middle'],
            array_map(
                static fn ($row) => $row->entry->getGuid(),
                $this->repository()->rowsByIdsForUser($this->user->requireId(), $ids, 2),
            ),
        );
        // No limit hydrates every given id.
        self::assertCount(3, $this->repository()->rowsByIdsForUser($this->user->requireId(), $ids));
    }

    public function testDropsAnIdInAFeedTheUserDoesNotSubscribeTo(): void
```
After:
```php
        self::assertSame(['newer', 'older'], $this->rowsByIds([$olderId, $newerId]));
    }

    public function testDropsAnIdInAFeedTheUserDoesNotSubscribeTo(): void
```

- [ ] **Step 3: The grep**

```bash
git grep -n -E '\$limit' -- src/Repository/EntryListRepository.php
git grep -n -E 'setMaxResults\(' -- src/Repository/EntryListRepository.php
git grep -n -E 'rowsByIdsForUser\(.*, [0-9]+\)' -- src tests
git grep -n -E -A3 'rowsByIdsForUser\($' -- src tests
```
Expected:
- First command: nothing. At `bf742411`, the same command printed lines 118, 126, 138 and 139, which is its positive control.
- Second: lines 53 and 88, both `->setMaxResults($query->limit);`. `listForUser()` and `searchForUser()` keep their limits.
- Third: nothing. In Task 0 Step 2 the pattern matched `EntryRowsByIdsTest.php:114`, which is its positive control.
- Fourth: `tests/Repository/DuplicateCollapseTest.php:159`, followed by two argument lines and `);`.

- [ ] **Step 4: Lint and test**

```bash
php -l src/Repository/EntryListRepository.php && php -l tests/Repository/EntryRowsByIdsTest.php
vendor/bin/phpcs src/Repository/EntryListRepository.php tests/Repository/EntryRowsByIdsTest.php
php bin/phpunit tests/Repository/EntryRowsByIdsTest.php tests/Repository/DuplicateCollapseTest.php tests/Service/Mail/Digest/DigestEntryFinderTest.php tests/Service/Search/EntrySearch
```
Expected: no syntax errors, no phpcs output, and phpunit `OK`.

- [ ] **Step 5: Commit**

```bash
git add src/Repository/EntryListRepository.php tests/Repository/EntryRowsByIdsTest.php
git commit -m "refactor(#1269): rowsByIdsForUser() takes no limit; no caller passes one"
```

---

### Task 4: `EffectiveRecommendationSettingsModel` has no defaults

**Files:**
- Modify: `src/Service/Recommendation/Settings/Model/EffectiveRecommendationSettingsModel.php`
- Modify: `tests/Http/RecommendationSettingsJsonTest.php`
- Modify: `tests/Service/Recommendation/Prompt/RecommendationHistoryLoaderTest.php`
- Modify: `tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php`
- Modify: `tests/Service/Recommendation/Run/Pass/TickContextTest.php`

`RecommendationSettingsResolver` already passes every argument by name, so it is unchanged.

- [ ] **Step 1: The model (D-2)**

`src/Service/Recommendation/Settings/Model/EffectiveRecommendationSettingsModel.php`. Before:
```php
        public bool $debugEnabled,
        public ?int $autoGenerateIntervalHours = null,
        public ?string $profileText = null,
        public bool $showReasons = false,
    ) {
```
After:
```php
        public bool $debugEnabled,
        public ?int $autoGenerateIntervalHours,
        public ?string $profileText,
        public bool $showReasons,
    ) {
```

- [ ] **Step 2: `RecommendationSettingsJsonTest::effectiveSettings()`**

`tests/Http/RecommendationSettingsJsonTest.php`. Before:
```php
            debugEnabled: false,
            profileText: $profileText,
            showReasons: $showReasons,
        );
```
After:
```php
            debugEnabled: false,
            autoGenerateIntervalHours: null,
            profileText: $profileText,
            showReasons: $showReasons,
        );
```

- [ ] **Step 3: `RecommendationHistoryLoaderTest::settings()`**

`tests/Service/Recommendation/Prompt/RecommendationHistoryLoaderTest.php`. Before:
```php
                contextWindow: 32768,
                contextWindowSource: 'fallback',
                batchSize: RecommendationBatchSize::Medium,
                maximumBatchSize: RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE,
            ),
            debugEnabled: false,
        );
    }
```
After:
```php
                contextWindow: 32768,
                contextWindowSource: 'fallback',
                batchSize: RecommendationBatchSize::Medium,
                maximumBatchSize: RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE,
            ),
            debugEnabled: false,
            autoGenerateIntervalHours: null,
            profileText: null,
            showReasons: false,
        );
    }
```

- [ ] **Step 4: `RecommendationPromptBuilderTest::settings()`**

`tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php`. Before:
```php
                contextWindow: $contextWindow,
                contextWindowSource: 'default',
                batchSize: $batchSize,
                maximumBatchSize: $maximumBatchSize,
            ),
            debugEnabled: false,
        );
    }
```
After:
```php
                contextWindow: $contextWindow,
                contextWindowSource: 'default',
                batchSize: $batchSize,
                maximumBatchSize: $maximumBatchSize,
            ),
            debugEnabled: false,
            autoGenerateIntervalHours: null,
            profileText: null,
            showReasons: false,
        );
    }
```

- [ ] **Step 5: `TickContextTest::tick()`**

`tests/Service/Recommendation/Run/Pass/TickContextTest.php`. Before:
```php
                    maximumBatchSize: RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE,
                ),
                debugEnabled: false,
            ),
            $driver,
```
After:
```php
                    maximumBatchSize: RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE,
                ),
                debugEnabled: false,
                autoGenerateIntervalHours: null,
                profileText: null,
                showReasons: false,
            ),
            $driver,
```

- [ ] **Step 6: The grep**

```bash
git grep -n -E '\$(autoGenerateIntervalHours|profileText|showReasons) = ' -- src/Service/Recommendation/Settings/Model/EffectiveRecommendationSettingsModel.php
git grep -n -E 'public const int FALLBACK_CONTEXT_WINDOW = ' -- src/Service/Recommendation/Settings/Model/EffectiveRecommendationSettingsModel.php
git grep -n -E 'new EffectiveRecommendationSettingsModel' -- src tests
git grep -c -E '^[[:space:]]+(autoGenerateIntervalHours|profileText|showReasons): ' -- src/Service/Recommendation/Settings/RecommendationSettingsResolver.php tests/Http/RecommendationSettingsJsonTest.php tests/Service/Recommendation/Prompt/RecommendationHistoryLoaderTest.php tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php tests/Service/Recommendation/Run/Pass/TickContextTest.php
```
Expected:
- First command: nothing.
- Second: one line, `:16`. This is the positive control that the path is read.
- Third: the same five constructions as the survey. No new one was added.
- Fourth: `3` for each of the five files. The pattern counts only named arguments at the start of a line, so the calls `effectiveSettings(profileText: …)` and `effectiveSettings(showReasons: …)` at `RecommendationSettingsJsonTest.php:21` and `:63` do not count. The resolver's `3` is the positive control. At `bf742411` it was already `3`, and `RecommendationSettingsJsonTest.php` was `2`.

- [ ] **Step 7: Lint and test**

```bash
php -l src/Service/Recommendation/Settings/Model/EffectiveRecommendationSettingsModel.php && php -l tests/Http/RecommendationSettingsJsonTest.php && php -l tests/Service/Recommendation/Prompt/RecommendationHistoryLoaderTest.php && php -l tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php && php -l tests/Service/Recommendation/Run/Pass/TickContextTest.php
vendor/bin/phpcs src/Service/Recommendation/Settings/Model/EffectiveRecommendationSettingsModel.php tests/Http/RecommendationSettingsJsonTest.php tests/Service/Recommendation/Prompt/RecommendationHistoryLoaderTest.php tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php tests/Service/Recommendation/Run/Pass/TickContextTest.php
php bin/phpunit tests/Http/RecommendationSettingsJsonTest.php tests/Service/Recommendation/Prompt/RecommendationHistoryLoaderTest.php tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php tests/Service/Recommendation/Run/Pass/TickContextTest.php tests/Service/Recommendation/Settings
```
Expected: no syntax errors, no phpcs output, and phpunit `OK`. A construction the survey missed would fail here with `ArgumentCountError`, or in `composer stan`, which reports a missing parameter. Fix it by passing all three values.

- [ ] **Step 8: Commit**

```bash
git add src/Service/Recommendation/Settings/Model/EffectiveRecommendationSettingsModel.php tests/Http/RecommendationSettingsJsonTest.php tests/Service/Recommendation/Prompt/RecommendationHistoryLoaderTest.php tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php tests/Service/Recommendation/Run/Pass/TickContextTest.php
git commit -m "refactor(#1269): EffectiveRecommendationSettingsModel has no test-only defaults"
```

---

### Task 5: `FeedOutcome` declares `Aborted` with its cases

**Files:**
- Modify: `src/Service/Refresh/Model/FeedOutcome.php`

- [ ] **Step 1: Move the case**

`src/Service/Refresh/Model/FeedOutcome.php`. Before:
```php
    /** The site is rationing requests; the feed is healthy and will be asked again shortly. */
    case Throttled;

    /**
     * Whether the feed answered with something to show. Only those earn a favicon lookup: for any other, a homepage
     * round trip goes to a feed that may never recover, or to a host that just asked for fewer requests.
     */
    public function broughtContent(): bool
    {
        return self::Fetched === $this || self::NotModified === $this;
    }
    /** Persistence failed; the EntityManager may be closed, so the run must stop. */
    case Aborted;
}
```
After:
```php
    /** The site is rationing requests; the feed is healthy and will be asked again shortly. */
    case Throttled;
    /** Persistence failed; the EntityManager may be closed, so the run must stop. */
    case Aborted;

    /**
     * Whether the feed answered with something to show. Only those earn a favicon lookup: for any other, a homepage
     * round trip goes to a feed that may never recover, or to a host that just asked for fewer requests.
     */
    public function broughtContent(): bool
    {
        return self::Fetched === $this || self::NotModified === $this;
    }
}
```

- [ ] **Step 2: The grep**

```bash
git grep -n -E '^[[:space:]]+case [A-Za-z]+;|function broughtContent' -- src/Service/Refresh/Model/FeedOutcome.php
```
Expected: `:9 case Fetched;`, `:10 case NotModified;`, `:11 case Failed;`, `:13 case Throttled;`, `:15 case Aborted;`, `:21 public function broughtContent(): bool`. Every case comes before the method, and the first four are the positive control.

- [ ] **Step 3: Lint and test**

```bash
php -l src/Service/Refresh/Model/FeedOutcome.php
vendor/bin/phpcs src/Service/Refresh/Model/FeedOutcome.php
php bin/phpunit tests/Service/Refresh
```
Expected: no syntax errors, no phpcs output, and phpunit `OK`.

- [ ] **Step 4: Commit**

```bash
git add src/Service/Refresh/Model/FeedOutcome.php
git commit -m "refactor(#1269): FeedOutcome declares Aborted with the other cases"
```

---

### Finishing

- [ ] **Step 1: Gates per PR (from `backend/`)**

```bash
composer cs
bin/console cache:warmup && composer stan
composer md
composer tramp
php bin/phpunit
```
Wait until the native leg has finished, then run the MySQL leg. Check first that the Docker stack runs this checkout's code (`docker compose ps`; the `php` service bind-mounts this checkout):
```bash
docker compose exec php rm -rf var/cache/test*
docker compose exec php composer test
composer infection:diff
```
Expected: every command exits 0. `composer infection:diff` reports MSI at or above `minMsi` (80). If a mutant escapes on a touched line, add a killing test in its own commit (`test(#1269): <what it pins>`). Never add an `ignore`, and never lower `minMsi`. If only `composer tramp` fails, run `composer show larspohlmann/phptramp` before looking at application code.

- [ ] **Step 2: PhpStorm inspections**

Run `mcp__phpstorm__lint_files` on all 13 changed PHP files: `CardFields.php`, `HtmlItemExtractor.php`, `HtmlItemExtractorTest.php`, `EntryCursor.php`, `EntryCursorTest.php`, `EntryListRepository.php`, `EntryRowsByIdsTest.php`, `EffectiveRecommendationSettingsModel.php`, `RecommendationSettingsJsonTest.php`, `RecommendationHistoryLoaderTest.php`, `RecommendationPromptBuilderTest.php`, `TickContextTest.php` and `FeedOutcome.php`, with the paths from each task's **Files**. ERROR and WARNING block; weak warnings are advisory.

- [ ] **Step 3: Today's dev log**

```bash
ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level >= 300)'
```
Expected: nothing from this branch's code.

- [ ] **Step 4: Final review**

Dispatch one fresh reviewer subagent with this plan, the issue and `git diff origin/develop...HEAD`. It reports findings and does not fix them. Attack points:
1. **No behaviour change:** the only changed assertion is D-3's. Items 2 and 3 delete only the code and the test named here. The `FeedOutcome` reorder has no `cases()` reader (re-run the survey's grep).
2. **D-1 holds:** `DigestEntryFinder` is unchanged, and no caller passed a third argument.
3. **D-2 is complete:** every `new EffectiveRecommendationSettingsModel(` passes all eight arguments.
4. **Deletion check:** re-run Task 1 Step 6 and quote the FAIL.

Fix each finding rated Important or above in its own commit (`refactor(#1269): review — <finding>`), re-run the affected gates, and record the rest in the PR body.

- [ ] **Step 5: Push and open the PR (from the repository root)**

```bash
git push -u origin chore/1269-small-src-cleanups
gh pr create --base develop --title "refactor(#1269): small src cleanups" --body-file <body file>
```
Body:
```markdown
Five small `src` cleanups found during #1171. None changes behaviour.

- `MAX_TEASER_LENGTH` moves from `CardFields` to `HtmlItemExtractor`, its only user, and becomes private. The funnel test now pins the cap's value (1000) instead of comparing the constant with itself.
- `EntryCursor::inclusiveUpperBound()` and its test are deleted. Nothing has called the method since #1116.
- `EntryListRepository::rowsByIdsForUser()` drops `$limit`. No caller passed it after #1116. The digest caps the ids before hydration, and an SQL limit would cap the collapsed rows instead, so the digest keeps its own cap.
- `EffectiveRecommendationSettingsModel` has no defaults: `autoGenerateIntervalHours`, `profileText` and `showReasons` are required. The resolver already passed them, and the four test constructions now pass them explicitly.
- `FeedOutcome` declares `case Aborted` with the other cases, above `broughtContent()`.

Closes #1269
```
- [ ] **Merge when CI is green, then report.** Watch CI with a Monitor, merge with `gh pr merge --merge` (never `--auto`) once every check is green. Before merging, check `gh pr view <PR> --json closingIssuesReferences` lists #1269; if it is empty, re-save the body with `gh pr edit <PR> --body-file <file>` and check again. After the merge, verify #1269 is CLOSED (COMPLETED); if GitHub still did not close it, close it by hand with `--reason completed` and a comment naming the PR and merge SHA. Report the PR URL, the merge SHA, the quoted deletion-check FAILs and the gate results to the planner.
