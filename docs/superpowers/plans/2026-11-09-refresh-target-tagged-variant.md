# RefreshTarget as a tagged variant — Implementation Plan (#2115)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `App\Repository\RefreshTarget`, which stores a sum type as a product (five nullable or boolean fields plus a null-ordering `match`), with one data type per variant, the backend twin of the frontend `RefreshScope` union from #2114.

**Architecture (revised after review):** PHP has no tagged unions, so the variant is an interface with one `final readonly` model per case. The cases are **domain data only**: their payload plus `runKey()`. They live in `src/Service/Refresh/Model/RefreshTarget/`, next to `RefreshRequestModel`, which holds one. Turning a target into SQL stays repository work: `FeedRepository` applies a target through one `restrictToTarget()` that matches on the case type and calls a typed `restrictTo…` helper per case. A repository may name a Service model (`docs/architecture.md` §8), so `DueFeedCriteria` holds the interface too. An earlier draft put a `restrict(QueryBuilder)` method on each variant in `src/Repository/RefreshTarget/`. That was dropped: the PHPStan role rule reads an interface held by a model as a service, and query building inside a model contradicts "a model keeps the measurement, its user applies the verdict" (§10). The user ruled that the PHPStan rules are not changed. This lands on PR #2114 (branch `fix/2106-scope-social-import-refresh`).

**Tech Stack:** Symfony 7.4, Doctrine QueryBuilder, PHPUnit 12.

**Spec:** GitHub issue #2115.

## Global Constraints

- **Pure refactor:** every scope selects exactly the feeds it selects today, and every run key stays byte-identical (`all`, `feed-N`, `tag-N`, `set-network-<NetworkScope::toRunKey()>`, `group-<value>`, `never-fetched`). Cached runs are keyed by them.
- **Query code stays in `src/Repository`**; the models hold no `QueryBuilder` and no DQL.
- **Do not change anything under `backend/tests/PhpStan`** (user ruling), and add no PHPStan ignores.
- CLAUDE.md Clean Code rules: no comments unless a reader would get the code wrong without one, and no abbreviations. `composer check`, `composer md` and `composer tramp` stay clean. phptramp may show only the two warnings that predate the branch (`$postUrl`, `$durationInSeconds`).
- Commit format `refactor(#2115): lower-case summary`; no Claude attribution.

## Design

```php
namespace App\Service\Refresh\Model\RefreshTarget;

interface RefreshTargetModelInterface
{
    public function runKey(): string;
}
```

| Case (`final readonly`, in `Service/Refresh/Model/RefreshTarget/`) | Replaces | Payload | `runKey` |
|---|---|---|---|
| `EveryFeedTargetModel` | `RefreshTarget::everyFeed()` | none | `all` |
| `FeedTargetModel` | `feed()` | `int $feedId` | `feed-N` |
| `TagTargetModel` | `tag()` | `int $tagId` | `tag-N` |
| `NetworkTargetModel` | `network()` | `NetworkScope $networkScope` | `set-network-…` |
| `FeedGroupTargetModel` | `feedGroup()` | `FeedGroup $feedGroup` | `group-<value>` |
| `NeverFetchedTargetModel` | `neverFetchedFeeds()` | none | `never-fetched` |

These sit in a subfolder named after the interface without its suffix, because `Model/` already holds `RefreshRequestModel` and others that implement it (§10, "Interfaces in a role folder").

**`FeedRepository`:**
- `dueQueryBuilder` keeps `restrictToFetchable`, `restrictToSchedule`, the user-ownership `EXISTS`, `restrictToUnhandled` and `restrictToConnectedAccounts`. It replaces the separate tag, network, group and never-fetched calls with one `restrictToTarget($qb, $criteria)`.
- `restrictToTarget` is a `match (true)` over `$criteria->target instanceof …TargetModel`. Each arm calls a private helper that takes the typed model: `restrictToTag(QueryBuilder, TagTargetModel, DueFeedCriteria)`, `restrictToNetwork(…, NetworkTargetModel)`, `restrictToFeedGroup(…, FeedGroupTargetModel, DueFeedCriteria)`, `restrictToNeverFetched(QueryBuilder)`. The `EveryFeed` arm does nothing. The `Feed` arm also does nothing, because `restrictToSchedule` handles it (next point).
- The bodies move unchanged out of today's methods. `restrictToOwnership` loses its tag half.
- Exhaustiveness: the `match` has no `default`. An unknown case throws `\UnhandledMatchError`, which surfaces in tests the moment a seventh case is added without an arm.
- **The feed target replaces the schedule rule** ("exactly this feed, gone included, schedule ignored"). `restrictToSchedule` keeps its early return, now `if ($criteria->target instanceof FeedTargetModel)`, with the `f.id = :feedId` predicate, and keeps its comment.
- **Call sites** build their case directly (`new TagTargetModel($tag->requireId())`). `RefreshRequestModel` and `DueFeedCriteria` type the field as `RefreshTargetModelInterface $target`.

## Files

- Create: `backend/src/Service/Refresh/Model/RefreshTarget/RefreshTargetModelInterface.php` and the six `…TargetModel.php` cases
- Delete: `backend/src/Repository/RefreshTarget.php` (and any `src/Repository/RefreshTarget/` or `src/Repository/Model/` folder left from the earlier draft)
- Modify: `backend/src/Repository/DueFeedCriteria.php`, `backend/src/Repository/FeedRepository.php`, `backend/src/Service/Refresh/Model/RefreshRequestModel.php`, `backend/src/Service/Refresh/UserRefreshScope.php`, `backend/src/Service/Queue/QueueDepths.php`, `backend/src/Command/RefreshFeedsCommand.php`
- Tests: `backend/tests/Service/Refresh/Model/RefreshTarget/RefreshTargetRunKeyTest.php` (run keys, data provider over the six cases) replaces `backend/tests/Repository/RefreshTargetTest.php`. Update every target construction in `backend/tests`, including `tests/Support/QueriesDueFeeds.php`. The repository scope tests (`FeedRepository*ScopeTest`, `FeedRepositoryTest`, `DueSocialFeedsTest`) and `RefreshControllerTest` are the behaviour net. They must pass with only their construction calls changed.

---

### Task 1: Data-only target models, queries in FeedRepository

One atomic change. The old class can't survive beside the new interface without two parallel shapes. The working tree may still hold an uncommitted earlier draft (`src/Repository/RefreshTarget/…Target` classes with `restrict()`, possibly mid-rename into `src/Repository/Model/`). Reuse its caller and test edits, and remove its classes.

- [ ] **Step 1: Write the run-key test** — `backend/tests/Service/Refresh/Model/RefreshTarget/RefreshTargetRunKeyTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Model\RefreshTarget;

use App\Enum\FeedGroup;
use App\Enum\SocialNetwork;
use App\Repository\NetworkScope;
use App\Service\Refresh\Model\RefreshTarget\EveryFeedTargetModel;
use App\Service\Refresh\Model\RefreshTarget\FeedGroupTargetModel;
use App\Service\Refresh\Model\RefreshTarget\FeedTargetModel;
use App\Service\Refresh\Model\RefreshTarget\NetworkTargetModel;
use App\Service\Refresh\Model\RefreshTarget\NeverFetchedTargetModel;
use App\Service\Refresh\Model\RefreshTarget\RefreshTargetModelInterface;
use App\Service\Refresh\Model\RefreshTarget\TagTargetModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefreshTargetRunKeyTest extends TestCase
{
    /** @return iterable<string, array{RefreshTargetModelInterface, string}> */
    public static function targets(): iterable
    {
        $networkScope = NetworkScope::whole(SocialNetwork::Mastodon);

        yield 'every feed' => [new EveryFeedTargetModel(), 'all'];
        yield 'one feed' => [new FeedTargetModel(7), 'feed-7'];
        yield 'one tag' => [new TagTargetModel(7), 'tag-7'];
        yield 'one network' => [new NetworkTargetModel($networkScope), 'set-network-' . $networkScope->toRunKey()];
        yield 'one group' => [new FeedGroupTargetModel(FeedGroup::Tags), 'group-tags'];
        yield 'never fetched' => [new NeverFetchedTargetModel(), 'never-fetched'];
    }

    #[DataProvider('targets')]
    public function testEachTargetKeepsItsRunKey(RefreshTargetModelInterface $target, string $runKey): void
    {
        self::assertSame($runKey, $target->runKey());
    }
}
```

These are the exact strings `RefreshTarget::runKey()` returns at HEAD (`git show HEAD:backend/src/Repository/RefreshTarget.php`).

- [ ] **Step 2: Run it to see it fail** — `php bin/phpunit tests/Service/Refresh/Model/RefreshTarget`; expected: classes not found.

- [ ] **Step 3: Create the interface and the six models** per the Design table: `final readonly`, constructor promotion, `runKey()` only. They hold no `QueryBuilder`, no DQL and no `App\Repository` import except `NetworkScope`.

- [ ] **Step 4: Rewire `FeedRepository` per the Design.** One `restrictToTarget()` with a `match (true)` over the case types and no `default`, plus typed private helpers whose bodies move unchanged out of today's methods. `restrictToOwnership` keeps only the user `EXISTS`. `restrictToSchedule` keeps its early return on `FeedTargetModel` and its comment. Switch `DueFeedCriteria`, `RefreshRequestModel`, `UserRefreshScope`, `QueueDepths` and `RefreshFeedsCommand` to the model types. Delete `src/Repository/RefreshTarget.php`, `tests/Repository/RefreshTargetTest.php` and any leftover draft classes or folders. Update the test call sites.

- [ ] **Step 5: Verify**
  - `php bin/phpunit tests/Repository tests/Service/Refresh tests/Service/Queue tests/Command tests/Controller/Api/RefreshControllerTest.php`, then the full `php bin/phpunit`.
  - `composer cs`, `composer stan` (green with `tests/PhpStan` untouched), `composer md`, `composer tramp` (only the two warnings that predate the branch), and `composer infection:diff` after committing.
  - `git status --short backend/tests/PhpStan` is empty, and `grep -rn "Repository\\\\RefreshTarget" backend/src backend/tests` returns nothing.

- [ ] **Step 6: Commit**

```bash
git add -A backend/src backend/tests
git commit -m "refactor(#2115): make RefreshTarget one data model per variant"
```

### Task 2: Gates and PR

- [ ] MySQL leg: `docker compose exec php composer test:parallel`.
- [ ] Push to `fix/2106-scope-social-import-refresh`; add `Closes #2115` and a line on the variant design to PR #2114's body.
