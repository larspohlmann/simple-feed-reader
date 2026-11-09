# RefreshTarget as a tagged variant — Implementation Plan (#2115)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `App\Repository\RefreshTarget`, which stores a sum type as a product (five nullable or boolean fields plus a null-ordering `match`), with one type per variant. Each variant owns its own query restriction and run key, so adding a scope means adding one class.

**Architecture:** PHP has no tagged unions, so the variant is an interface with one `final readonly` implementation per case, in `src/Repository/RefreshTarget/`. Each case narrows the due-feed `QueryBuilder` itself, through the existing `…Dql` helpers, and names its own run key. `FeedRepository::dueQueryBuilder` stops asking "is my field set?" five times and calls `$criteria->target->restrict(...)` once. This mirrors the frontend `RefreshScope` union from #2114. Lands on PR #2114 (branch `fix/2106-scope-social-import-refresh`), at the user's choice.

**Tech Stack:** Symfony 7.4, Doctrine QueryBuilder, PHPUnit 12.

**Spec:** GitHub issue #2115.

## Global Constraints

- **Pure refactor:** every scope selects exactly the feeds it selects today, and every run key stays byte-identical (`all`, `feed-N`, `tag-N`, `set-network-<NetworkScope::toRunKey()>`, `group-<value>`, `never-fetched`). Cached runs are keyed by them.
- **Query code stays in `src/Repository`.** `App\Repository` imports no `App\Service`. Every class in `src/Repository` is `final`.
- CLAUDE.md Clean Code rules: no comments unless a reader would get the code wrong without one, and no abbreviations. `composer check`, `composer md` and `composer tramp` stay clean. phptramp may show only the two warnings that predate the branch (`$postUrl`, `$durationInSeconds`).
- Commit format `refactor(#2115): lower-case summary`; no Claude attribution.

## Design

```php
namespace App\Repository\RefreshTarget;

interface RefreshTargetInterface
{
    public function restrict(QueryBuilder $qb, string $feedAlias, ?int $userId): void;

    public function runKey(): string;
}
```

| Case | Replaces | `restrict` | `runKey` |
|---|---|---|---|
| `EveryFeedTarget` | `RefreshTarget::everyFeed()` | nothing | `all` |
| `FeedTarget(int $feedId)` | `feed()` | `f.id = :feedId` | `feed-N` |
| `TagTarget(int $tagId)` | `tag()` | the tag `EXISTS` now in `restrictToOwnership` (`TagScopeDql::predicate`) | `tag-N` |
| `NetworkTarget(NetworkScope $networkScope)` | `network()` | `NetworkScopeDql::restrict` | `set-network-…` |
| `FeedGroupTarget(FeedGroup $feedGroup)` | `feedGroup()` | the group `EXISTS` subquery now in `restrictToFeedGroup` (`FeedGroupDql::restrict`) | `group-<value>` |
| `NeverFetchedTarget` | `neverFetchedFeeds()` | `<alias>.fetchSchedule.lastFetchedAt IS NULL` | `never-fetched` |

- **The `:userId` coupling:** `TagTarget` and `FeedGroupTarget` bind `:userId` themselves from the `$userId` they are given. Today they lean on `restrictToOwnership` having bound it. Both refuse a null `$userId` with a `\LogicException`, because a tag or a group always belongs to a user. A CLI or maintenance sweep never builds either.
- **The feed target replaces the schedule rule** ("exactly this feed, gone included, schedule ignored"). `FeedRepository::restrictToSchedule` keeps its early return, now `if ($criteria->target instanceof FeedTarget)`, and keeps the existing comment that explains why. The `f.id` predicate moves into `FeedTarget::restrict`. A `bool` capability method on the interface was rejected, because five of the six cases would answer "no" to a question only one of them has.
- **Call sites:** each builds its case directly (`new TagTarget($tag->requireId())`), as for any value object. `RefreshRequestModel` and `DueFeedCriteria` type the field as `RefreshTargetInterface $target`.

## Files

- Create: `backend/src/Repository/RefreshTarget/RefreshTargetInterface.php`, `EveryFeedTarget.php`, `FeedTarget.php`, `TagTarget.php`, `NetworkTarget.php`, `FeedGroupTarget.php`, `NeverFetchedTarget.php`
- Delete: `backend/src/Repository/RefreshTarget.php`
- Modify: `backend/src/Repository/DueFeedCriteria.php`, `backend/src/Repository/FeedRepository.php` (`dueQueryBuilder`, `restrictToSchedule`, `restrictToOwnership`; delete `restrictToNetwork`, `restrictToFeedGroup` and `restrictToNeverFetched`), `backend/src/Service/Refresh/Model/RefreshRequestModel.php`, `backend/src/Service/Refresh/UserRefreshScope.php`, `backend/src/Service/Queue/QueueDepths.php`, `backend/src/Command/RefreshFeedsCommand.php`
- Tests: replace `backend/tests/Repository/RefreshTargetTest.php` with `backend/tests/Repository/RefreshTarget/RefreshTargetRunKeyTest.php` (run keys). Update every `RefreshTarget::…` call in `backend/tests` (`grep -rln "RefreshTarget::" backend/tests`), including `tests/Support/QueriesDueFeeds.php`. The repository scope tests (`FeedRepository*ScopeTest`, `FeedRepositoryTest`, `DueSocialFeedsTest`) are the behaviour net. They must pass with only their construction calls changed.

---

### Task 1: Introduce the variants and switch every caller

One atomic change. The old class can't survive beside the new interface without two parallel shapes.

- [ ] **Step 1: Write the run-key test** — `backend/tests/Repository/RefreshTarget/RefreshTargetRunKeyTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository\RefreshTarget;

use App\Enum\FeedGroup;
use App\Enum\SocialNetwork;
use App\Repository\NetworkScope;
use App\Repository\RefreshTarget\EveryFeedTarget;
use App\Repository\RefreshTarget\FeedGroupTarget;
use App\Repository\RefreshTarget\FeedTarget;
use App\Repository\RefreshTarget\NetworkTarget;
use App\Repository\RefreshTarget\NeverFetchedTarget;
use App\Repository\RefreshTarget\RefreshTargetInterface;
use App\Repository\RefreshTarget\TagTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefreshTargetRunKeyTest extends TestCase
{
    /** @return iterable<string, array{RefreshTargetInterface, string}> */
    public static function targets(): iterable
    {
        $networkScope = NetworkScope::whole(SocialNetwork::Mastodon);

        yield 'every feed' => [new EveryFeedTarget(), 'all'];
        yield 'one feed' => [new FeedTarget(7), 'feed-7'];
        yield 'one tag' => [new TagTarget(7), 'tag-7'];
        yield 'one network' => [new NetworkTarget($networkScope), 'set-network-' . $networkScope->toRunKey()];
        yield 'one group' => [new FeedGroupTarget(FeedGroup::Tags), 'group-tags'];
        yield 'never fetched' => [new NeverFetchedTarget(), 'never-fetched'];
    }

    #[DataProvider('targets')]
    public function testEachTargetKeepsItsRunKey(RefreshTargetInterface $target, string $runKey): void
    {
        self::assertSame($runKey, $target->runKey());
    }
}
```

These are the exact strings today's `RefreshTarget::runKey()` returns; read it before deleting it.

- [ ] **Step 2: Run it to see it fail** — `php bin/phpunit tests/Repository/RefreshTarget`; expected: classes not found.

- [ ] **Step 3: Create the interface and the six cases** following the Design table. Each case is `final readonly`, with constructor promotion. `TagTarget` and `FeedGroupTarget` move their `EXISTS` bodies verbatim out of `FeedRepository::restrictToOwnership` and `restrictToFeedGroup`, using `$qb->getEntityManager()->createQueryBuilder()` for the group subquery and binding `userId`, `tagId` and `feedId` as today. Each uses `$feedAlias`, never a hard-coded `f`.

- [ ] **Step 4: Rewire `FeedRepository`.**
  - `dueQueryBuilder` calls `$criteria->target->restrict($qb, 'f', $criteria->userId)` once, in the slot where the network, group and never-fetched calls were. `restrictToOwnership` keeps only the `userId` `EXISTS`.
  - `restrictToSchedule` returns early on `$criteria->target instanceof FeedTarget` and keeps its comment. Delete the three emptied `restrictTo…` methods.
  - Switch `DueFeedCriteria`, `RefreshRequestModel`, `UserRefreshScope`, `QueueDepths` and `RefreshFeedsCommand` to the new types. Delete `src/Repository/RefreshTarget.php` and `tests/Repository/RefreshTargetTest.php`. Update the test call sites.

- [ ] **Step 5: Verify**
  - `php bin/phpunit tests/Repository tests/Service/Refresh tests/Service/Queue tests/Command tests/Controller/Api/RefreshControllerTest.php`, then the full `php bin/phpunit`.
  - `composer cs`, `composer stan`, `composer md`, `composer tramp` (only the two warnings that predate the branch), and `composer infection:diff` after committing.
  - `grep -rn "App\\\\Repository\\\\RefreshTarget;" backend` returns nothing.

- [ ] **Step 6: Commit**

```bash
git add backend/src backend/tests
git commit -m "refactor(#2115): make RefreshTarget one type per variant"
```

### Task 2: Gates and PR

- [ ] MySQL leg: `docker compose exec php composer test:parallel`.
- [ ] Push to `fix/2106-scope-social-import-refresh`; add `Closes #2115` and a line on the variant design to PR #2114's body.
