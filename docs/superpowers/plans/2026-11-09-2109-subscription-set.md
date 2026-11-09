# SubscriptionSet and the SubscriptionFeedUrls fold-back (#2109) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `SubscriptionRepository` names the subscription sets it serves once, in a `SubscriptionSet` enum, and takes back the feed-URL projection that was split out only to dodge PHPMD's public-method count.

**Architecture:** The three `find…WithTags` methods are one query under three exclusion policies. A pure enum `App\Enum\SubscriptionSet { All, OpmlExport, Backup }` owns the policy (`excludedFormats()`), and one `findForUserWithTags(int $userId, SubscriptionSet $set)` replaces the three. `SubscriptionFeedUrls::forUser()` moves into the repository as `findFeedUrlsForUser()`; the class and its test are deleted. The public-method count goes from 10 to 9, so PHPMD's `TooManyPublicMethods` (threshold 10) stays clean through consolidation, not a split.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM, PHPUnit 12.

**Spec:** GitHub issue #2109 (`gh issue view 2109`).

## Global Constraints

- Branch `refactor/2109-subscription-set` is already checked out. Commit format: `refactor(#2109): summary` (`test(#2109): …` for test-only commits). No Claude attribution in commits.
- `declare(strict_types=1)` in every file; PSR-12; PHPStan level max with no new ignores or baselines; no edits under `tests/PhpStan/`.
- The enum lives in `App\Enum` because a repository uses it (docs/architecture.md §8, `PersistenceKnowsNoServiceRule`).
- Queries stay in `src/Repository/` (§7). A class that runs queries is a `*Repository`; `SubscriptionFeedUrls` broke that rule too.
- Comment bar is very high: one line, only for what a reader would otherwise get wrong. Delete comments that restate code. The OPML/backup domain knowledge moves from repository docblocks onto the enum cases, one line each.
- `SubscriptionRepository` must be PHPMD-clean. `TooManyPublicMethods`: `maxmethods=10`, ignore pattern `(^(set|get|is|has|with))i`; `__construct` counts. Today: `__construct`, `existsForUserAndFeed`, `findForUserWithTags`, `findExportableForUserWithTags`, `findBackedUpForUserWithTags`, `findOneForUserAndFeedUrl`, `countForUser`, `findAllByIdsForUser`, `findAllByIdsForUserWithAssociations`, `nextPositionForUser` = 10 (`getOneForUser` is ignored). After Task 1: 8. After Task 2: 9. Never pick a `get`/`with`/`is` prefix to slip under the count.
- Names reveal intent; no abbreviations; `$left`/`$right`, `$exception` etc. in closures.

### Decision: the `findAllByIds…` pair stays as it is

Callers: `OwnedSubscriptions::resolve()` (reorder, bulk unsubscribe: write through the ids, never serialize), `OwnedSubscriptions::resolveWithAssociations()` (serializes), and `PrivateFeedSyncApplier` (no associations). Merging them needs a two-case enum argument whose only job is "join or don't": a boolean flag parameter in disguise, which CLAUDE.md forbids. `OwnedSubscriptions` would still keep its two public methods, so the merge moves the split one layer down without removing it. The two names already read as a sentence each, and PHPMD does not need the slot (9 of 10). Leave it.

---

### Task 1: One `findForUserWithTags` over a `SubscriptionSet`

**Files:**
- Create: `backend/src/Enum/SubscriptionSet.php`
- Create: `backend/tests/Enum/SubscriptionSetTest.php`
- Modify: `backend/src/Repository/SubscriptionRepository.php` (lines 36–110: the three `find…WithTags` methods and the private `withTagsExcluding` / `withTagsForUser`)
- Modify: `backend/src/Repository/PublicOrAuthorFeedDql.php` (`restrict()`)
- Modify: `backend/src/Controller/Api/SubscriptionController.php:57`
- Modify: `backend/src/Controller/Admin/AdminUserController.php:73`
- Modify: `backend/src/Service/Subscription/FeedTagMove.php:116`
- Modify: `backend/src/Service/Opml/OpmlExporter.php:33`
- Modify: `backend/src/Service/Backup/AccountBackupExporter.php:71`
- Modify tests: `backend/tests/Repository/ExportableSubscriptionsTest.php`, `backend/tests/Repository/SubscriptionPositionAndLookupsTest.php:67-80`, `backend/tests/Service/Subscription/UnsubscribeAllTest.php:74`, `backend/tests/Service/Social/SocialImporterTest.php:97`, `backend/tests/Service/Backup/AccountRestorerTest.php:329`

**Interfaces:**
- Produces: `enum App\Enum\SubscriptionSet { case All; case OpmlExport; case Backup; public function excludedFormats(): array /* list<string> */ }`
- Produces: `SubscriptionRepository::findForUserWithTags(int $userId, SubscriptionSet $set): array` (`list<Subscription>`, creation order then id, feed and tags eager-loaded)
- Removes: `findExportableForUserWithTags()`, `findBackedUpForUserWithTags()`

- [ ] **Step 1: Write the enum test**

Create `backend/tests/Enum/SubscriptionSetTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\SourceFormat;
use App\Enum\SubscriptionSet;
use PHPUnit\Framework\TestCase;

final class SubscriptionSetTest extends TestCase
{
    public function testEverySubscriptionExcludesNoFormat(): void
    {
        self::assertSame([], SubscriptionSet::All->excludedFormats());
    }

    public function testAnOpmlExportLeavesOutWhatOnlyThisReaderCanRead(): void
    {
        self::assertSame(SourceFormat::NOT_IN_OPML, SubscriptionSet::OpmlExport->excludedFormats());
    }

    public function testABackupLeavesOutOnlyThePrivateFeeds(): void
    {
        self::assertSame(SourceFormat::SOCIAL, SubscriptionSet::Backup->excludedFormats());
    }
}
```

- [ ] **Step 2: Move the repository tests to the new signature**

In `backend/tests/Repository/ExportableSubscriptionsTest.php`, add `use App\Enum\SubscriptionSet;` after `use App\Enum\SourceFormat;`, then replace the eight calls:

```php
        self::assertSame([$rss], $this->subscriptions()->findExportableForUserWithTags($user->requireId()));
        self::assertSame([$rss, $private], $this->subscriptions()->findForUserWithTags($user->requireId()));
```
becomes
```php
        self::assertSame([$rss], $this->setOf($user, SubscriptionSet::OpmlExport));
        self::assertSame([$rss, $private], $this->setOf($user, SubscriptionSet::All));
```

```php
        self::assertSame([$author], $this->subscriptions()->findExportableForUserWithTags($user->requireId()));
        self::assertSame([$author], $this->subscriptions()->findBackedUpForUserWithTags($user->requireId()));
```
becomes
```php
        self::assertSame([$author], $this->setOf($user, SubscriptionSet::OpmlExport));
        self::assertSame([$author], $this->setOf($user, SubscriptionSet::Backup));
```

```php
        self::assertSame([$rss], $this->subscriptions()->findExportableForUserWithTags($user->requireId()));
        self::assertSame([$rss, $saved], $this->subscriptions()->findBackedUpForUserWithTags($user->requireId()));
```
becomes
```php
        self::assertSame([$rss], $this->setOf($user, SubscriptionSet::OpmlExport));
        self::assertSame([$rss, $saved], $this->setOf($user, SubscriptionSet::Backup));
```

```php
        self::assertSame([$rss], $this->subscriptions()->findExportableForUserWithTags($user->requireId()));
        self::assertSame([$rss, $scraped], $this->subscriptions()->findBackedUpForUserWithTags($user->requireId()));
```
becomes
```php
        self::assertSame([$rss], $this->setOf($user, SubscriptionSet::OpmlExport));
        self::assertSame([$rss, $scraped], $this->setOf($user, SubscriptionSet::Backup));
```

Add this helper above `private function subscriptions()`:

```php
    /** @return list<Subscription> */
    private function setOf(User $user, SubscriptionSet $set): array
    {
        return $this->subscriptions()->findForUserWithTags($user->requireId(), $set);
    }
```

In `backend/tests/Repository/SubscriptionPositionAndLookupsTest.php`, add `use App\Enum\SubscriptionSet;` before `use App\Repository\SubscriptionRepository;`, delete the five-line docblock above `testFindForUserWithTagsReturnsEveryOwnedSubscription` (the two-row assertion says it), and change

```php
        $rows = $this->subscriptions->findForUserWithTags($user->requireId());
```
to
```php
        $rows = $this->subscriptions->findForUserWithTags($user->requireId(), SubscriptionSet::All);
```

In `backend/tests/Service/Subscription/UnsubscribeAllTest.php`, add `use App\Enum\SubscriptionSet;` after `use App\Entity\User;` and change line 74 to:

```php
        self::assertCount(1, $repository->findForUserWithTags($user->requireId(), SubscriptionSet::All));
```

In `backend/tests/Service/Social/SocialImporterTest.php`, add `use App\Enum\SubscriptionSet;` after `use App\Enum\SourceFormat;` and change line 97 to:

```php
        foreach ($subscriptions->findForUserWithTags($this->user->requireId(), SubscriptionSet::All) as $subscription) {
```

In `backend/tests/Service/Backup/AccountRestorerTest.php`, add `use App\Enum\SubscriptionSet;` after `use App\Enum\SocialNetwork;` and change line 329 to:

```php
        return $repository->findForUserWithTags($userId, SubscriptionSet::All);
```

- [ ] **Step 3: Run the tests to see them fail**

Run (from `backend/`): `php bin/phpunit tests/Enum/SubscriptionSetTest.php tests/Repository/ExportableSubscriptionsTest.php tests/Repository/SubscriptionPositionAndLookupsTest.php`
Expected: errors, `Class "App\Enum\SubscriptionSet" not found`.

- [ ] **Step 4: Create the enum**

Create `backend/src/Enum/SubscriptionSet.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum SubscriptionSet
{
    case All;

    /** A private feed needs an account the file does not hold; saved and scraped feeds are this reader's own. */
    case OpmlExport;

    /** Everything but the private feeds; saved feeds are restored as saved feeds (#1666). */
    case Backup;

    /** @return list<string> the formats left out; a private feed following one person stays in either way */
    public function excludedFormats(): array
    {
        return match ($this) {
            self::All => [],
            self::OpmlExport => SourceFormat::NOT_IN_OPML,
            self::Backup => SourceFormat::SOCIAL,
        };
    }
}
```

- [ ] **Step 5: Let an empty exclusion exclude nothing**

`NOT IN ()` is invalid SQL, and `SubscriptionSet::All` excludes nothing. In `backend/src/Repository/PublicOrAuthorFeedDql.php`, make `restrict()` total by opening it with a guard:

```php
    /** @param list<string> $excludedFormats */
    public static function restrict(QueryBuilder $qb, string $feedAlias, array $excludedFormats): void
    {
        if ([] === $excludedFormats) {
            return;
        }

        $qb->andWhere(\sprintf(
```

(the rest of the method is unchanged). Its other caller, `NetworkScopeDql`, always passes `SourceFormat::NOT_PROFILES`, so it is unaffected.

- [ ] **Step 6: Replace the three repository methods with one**

In `backend/src/Repository/SubscriptionRepository.php`, add `use App\Enum\SubscriptionSet;` after `use App\Enum\SourceFormat;`, then delete `use App\Enum\SourceFormat;` (no longer used once the two policy methods go) and `use Doctrine\ORM\QueryBuilder;` (no longer used once `withTagsForUser` is inlined).

Replace everything from the docblock `A user's subscriptions with their feed and tags eager-loaded (no N+1),` through the end of `findBackedUpForUserWithTags()` with:

```php
    /** @return list<Subscription> feed and tags eager-loaded, in creation order */
    public function findForUserWithTags(int $userId, SubscriptionSet $set): array
    {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.feed', 'f')->addSelect('f')
            ->leftJoin('s.subscriptionTags', 'st')->addSelect('st')
            ->leftJoin('st.tag', 't')->addSelect('t')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->orderBy('s.createdAt', 'ASC')
            ->addOrderBy('s.id', 'ASC');
        PublicOrAuthorFeedDql::restrict($qb, 'f', $set->excludedFormats());
        /** @var list<Subscription> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }
```

Delete the private methods `withTagsExcluding()` and `withTagsForUser()` (between `findOneForUserAndFeedUrl()` and `countForUser()`), including their docblocks.

- [ ] **Step 7: Update the five production callers**

`backend/src/Controller/Api/SubscriptionController.php`: add `use App\Enum\SubscriptionSet;` after `use App\Enum\TrendingWindow;`; line 57 becomes

```php
            $this->subscriptions->findForUserWithTags($user->requireId(), SubscriptionSet::All),
```

`backend/src/Controller/Admin/AdminUserController.php`: add `use App\Enum\SubscriptionSet;` before `use App\Enum\UserStatus;`; line 73 becomes

```php
        $subscriptions = AdminUserJson::positionOrdered(
            $this->subscriptions->findForUserWithTags($userId, SubscriptionSet::All),
        );
```

`backend/src/Service/Subscription/FeedTagMove.php`: add `use App\Enum\SubscriptionSet;` after `use App\Entity\Tag;`; line 116 becomes

```php
            $this->subscriptions->findForUserWithTags($userId, SubscriptionSet::All),
```

`backend/src/Service/Opml/OpmlExporter.php`: add `use App\Enum\SubscriptionSet;` after `use App\Enum\SourceFormat;`; line 33 becomes

```php
            $this->subscriptions->findForUserWithTags($user->requireId(), SubscriptionSet::OpmlExport),
```

`backend/src/Service/Backup/AccountBackupExporter.php`: add `use App\Enum\SubscriptionSet;` after `use App\Entity\User;`; line 71 becomes

```php
        $subscriptions = ExportedFeed::withoutRepeats(
            $this->subscriptions->findForUserWithTags($userId, SubscriptionSet::Backup),
        );
```

Then confirm nothing still calls a removed name:

Run: `grep -rn "findExportableForUserWithTags\|findBackedUpForUserWithTags\|withTagsExcluding" backend/src backend/tests`
Expected: no output.

- [ ] **Step 8: Run the affected tests**

Run (from `backend/`): `php bin/phpunit tests/Enum/SubscriptionSetTest.php tests/Repository/ExportableSubscriptionsTest.php tests/Repository/SubscriptionPositionAndLookupsTest.php tests/Service/Subscription tests/Service/Opml tests/Service/Backup tests/Service/Social/SocialImporterTest.php tests/Controller`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add backend/src/Enum/SubscriptionSet.php backend/tests/Enum/SubscriptionSetTest.php backend/src/Repository/SubscriptionRepository.php backend/src/Repository/PublicOrAuthorFeedDql.php backend/src/Controller/Api/SubscriptionController.php backend/src/Controller/Admin/AdminUserController.php backend/src/Service/Subscription/FeedTagMove.php backend/src/Service/Opml/OpmlExporter.php backend/src/Service/Backup/AccountBackupExporter.php backend/tests/Repository/ExportableSubscriptionsTest.php backend/tests/Repository/SubscriptionPositionAndLookupsTest.php backend/tests/Service/Subscription/UnsubscribeAllTest.php backend/tests/Service/Social/SocialImporterTest.php backend/tests/Service/Backup/AccountRestorerTest.php
git commit -m "refactor(#2109): name the subscription sets once in SubscriptionSet"
```

---

### Task 2: Fold `SubscriptionFeedUrls` back into `SubscriptionRepository`

**Files:**
- Modify: `backend/src/Repository/SubscriptionRepository.php` (new public method after `findOneForUserAndFeedUrl()`)
- Modify: `backend/src/Service/Social/ImportCandidates.php` (constructor, `profileFeedsOf()`)
- Modify: `backend/tests/Repository/SubscriptionRepositoryTest.php`
- Modify: `backend/tests/Service/Social/ImportCandidatesTest.php` (import, `importCandidates()`)
- Delete: `backend/src/Repository/SubscriptionFeedUrls.php`, `backend/tests/Repository/SubscriptionFeedUrlsTest.php`

**Interfaces:**
- Consumes: `SubscriptionRepository` from Task 1 (8 public methods by PHPMD's count).
- Produces: `SubscriptionRepository::findFeedUrlsForUser(int $userId): array` returning `list<array{id: int, customTitle: ?string, feedUrl: string, feedTitle: ?string, feedImageUrl: ?string}>` in creation order then id. Public-method count becomes 9.

`ImportCandidates` depends on the concrete `SubscriptionFeedUrls` today, with no interface; its sibling `PrivateFeedSyncApplier` in the same module already takes the concrete `SubscriptionRepository`, and `ImportCandidatesTest` takes the real one from the container. So the consumer takes `SubscriptionRepository` directly, which keeps the style the module uses. Services are autowired; `config/` names neither class, so no DI file changes.

- [ ] **Step 1: Move the test case into `SubscriptionRepositoryTest`**

In `backend/tests/Repository/SubscriptionRepositoryTest.php`, give the existing `subscriptionToFeed()` helper a creation time (existing callers keep the default):

```php
    private function subscriptionToFeed(
        User $owner,
        string $feedUrl,
        string $createdAt = '2026-07-01T00:00:00Z',
    ): Subscription {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $subscription = new Subscription($owner, $feed, new \DateTimeImmutable($createdAt));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }
```

Add this test after `testFindIncludedInAllItemsForUserSkipsHiddenFeedsAndOtherUsers()`. The later-dated row is created first, so the order proves `createdAt` beats id:

```php
    public function testFindFeedUrlsForUserReadsTheOwnersFeedsInCreationOrder(): void
    {
        $owner = $this->userFactory()->create('feed-urls-owner@example.com');
        $later = $this->subscriptionToFeed($owner, 'https://example.com/later.xml', '2026-07-02T00:00:00Z');
        $titled = $this->subscriptionToFeed($owner, 'https://example.com/titled.xml');
        $titled->getFeed()->setTitle('Feed title');
        $titled->getFeed()->setImageUrl('https://cdn.test/titled.png');
        $titled->setCustomTitle('My title');
        $this->subscriptionToFeed(
            $this->userFactory()->create('feed-urls-other@example.com'),
            'https://example.com/foreign.xml',
        );
        $this->entityManager->flush();

        self::assertSame(
            [
                [
                    'id' => $titled->requireId(),
                    'customTitle' => 'My title',
                    'feedUrl' => 'https://example.com/titled.xml',
                    'feedTitle' => 'Feed title',
                    'feedImageUrl' => 'https://cdn.test/titled.png',
                ],
                [
                    'id' => $later->requireId(),
                    'customTitle' => null,
                    'feedUrl' => 'https://example.com/later.xml',
                    'feedTitle' => null,
                    'feedImageUrl' => null,
                ],
            ],
            $this->repository()->findFeedUrlsForUser($owner->requireId()),
        );
    }
```

Delete `backend/tests/Repository/SubscriptionFeedUrlsTest.php`:

```bash
git rm backend/tests/Repository/SubscriptionFeedUrlsTest.php
```

- [ ] **Step 2: Point `ImportCandidatesTest` at the repository**

In `backend/tests/Service/Social/ImportCandidatesTest.php`, replace `use App\Repository\SubscriptionFeedUrls;` with `use App\Repository\SubscriptionRepository;`. In `importCandidates()`, replace

```php
        $subscriptionFeedUrls = self::getContainer()->get(SubscriptionFeedUrls::class);
        self::assertInstanceOf(SubscriptionFeedUrls::class, $subscriptionFeedUrls);
```
with
```php
        $subscriptions = self::getContainer()->get(SubscriptionRepository::class);
        self::assertInstanceOf(SubscriptionRepository::class, $subscriptions);
```

and in the `new ImportCandidates(...)` argument list replace `$subscriptionFeedUrls,` with `$subscriptions,`.

- [ ] **Step 3: Run the tests to see them fail**

Run (from `backend/`): `php bin/phpunit tests/Repository/SubscriptionRepositoryTest.php tests/Service/Social/ImportCandidatesTest.php`
Expected: `SubscriptionRepositoryTest` errors with `Call to undefined method App\Repository\SubscriptionRepository::findFeedUrlsForUser()`; `ImportCandidatesTest` errors with a `TypeError` (`ImportCandidates::__construct()` argument #2 must be of type `SubscriptionFeedUrls`).

- [ ] **Step 4: Add `findFeedUrlsForUser()` to the repository**

In `backend/src/Repository/SubscriptionRepository.php`, insert after `findOneForUserAndFeedUrl()`:

```php
    /** @return list<array{id: int, customTitle: ?string, feedUrl: string, feedTitle: ?string, feedImageUrl: ?string}> creation order */
    public function findFeedUrlsForUser(int $userId): array
    {
        /** @var list<array{id: int, customTitle: ?string, feedUrl: string, feedTitle: ?string, feedImageUrl: ?string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('s.id', 's.customTitle', 'f.url AS feedUrl', 'f.title AS feedTitle', 'f.imageUrl AS feedImageUrl')
            ->join('s.feed', 'f')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->orderBy('s.createdAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }
```

`select()` replaces the entity select that `createQueryBuilder('s')` starts with, so the result is the same scalar projection the old class built from `EntityManagerInterface::createQueryBuilder()->from(...)`.

- [ ] **Step 5: Switch `ImportCandidates` to the repository and delete the class**

In `backend/src/Service/Social/ImportCandidates.php`: replace `use App\Repository\SubscriptionFeedUrls;` with `use App\Repository\SubscriptionRepository;` (keep the `use` list alphabetical: it goes after `use App\Repository\SubscriptionDisplayTitle;`). In the constructor replace

```php
        private SubscriptionFeedUrls $subscriptionFeedUrls,
```
with
```php
        private SubscriptionRepository $subscriptions,
```

In `profileFeedsOf()` replace

```php
        foreach ($this->subscriptionFeedUrls->forUser($account->getUser()->requireId()) as $subscription) {
```
with
```php
        foreach ($this->subscriptions->findFeedUrlsForUser($account->getUser()->requireId()) as $subscription) {
```

Delete the class:

```bash
git rm backend/src/Repository/SubscriptionFeedUrls.php
```

Run: `grep -rn "SubscriptionFeedUrls" backend/src backend/tests backend/config`
Expected: no output.

- [ ] **Step 6: Run the tests to see them pass**

Run (from `backend/`): `php bin/phpunit tests/Repository/SubscriptionRepositoryTest.php tests/Service/Social`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Repository/SubscriptionRepository.php backend/src/Service/Social/ImportCandidates.php backend/tests/Repository/SubscriptionRepositoryTest.php backend/tests/Service/Social/ImportCandidatesTest.php
git commit -m "refactor(#2109): fold SubscriptionFeedUrls back into SubscriptionRepository"
```

---

### Task 3: Gates

**Files:** none new; fixes only where a gate points.

- [ ] **Step 1: Static gates** (from `backend/`, the four can run in parallel)

```bash
composer cs
bin/console cache:warmup && composer stan
composer md
composer tramp
```

Expected: all clean. In `composer md` output, `SubscriptionRepository` must not appear (9 public methods by PHPMD's count, threshold 10). If PHPStan flags the `list<string>` return of `SubscriptionSet::excludedFormats()` (it reads `SourceFormat::NOT_IN_OPML` as a constant array), wrap the arms in `array_values(...)`, not an ignore. If `composer tramp` goes red with no hop through the changed files, check `composer show larspohlmann/phptramp` before suspecting this branch.

- [ ] **Step 2: PhpStorm inspections** on the changed PHP files (`mcp__phpstorm__lint_files`): block on ERROR and WARNING.

- [ ] **Step 3: Tests**

```bash
php bin/phpunit tests/Enum/SubscriptionSetTest.php tests/Repository tests/Service/Social tests/Service/Subscription tests/Service/Opml tests/Service/Backup tests/Controller
php bin/phpunit
```

Expected: PASS. Then the MySQL leg (the restrict guard and the projection are dialect-neutral, but CLAUDE.md asks for both legs before a PR): `docker compose exec php composer test:parallel` from the repo root.

- [ ] **Step 4: Mutation gate on the changed files**: `composer infection:diff` (needs pcov or xdebug). The `[] === $excludedFormats` guard is killed by any `SubscriptionSet::All` test (without it the query is invalid SQL); the enum arms are killed by `SubscriptionSetTest`.

- [ ] **Step 5: Commit any gate fixes** as `refactor(#2109): …`, one commit per concern.
