# Delete a User Account and Reclaim Orphaned Feeds Implementation Plan (#246)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hard-delete a user account with everything it owns, from an admin endpoint and from a self-service endpoint, and delete every feed that loses its last subscriber.

**Architecture:** Two independent units. `OrphanedFeedReclaimer` (next to `EntryPruner`) is the single place a feed with no subscription dies; it exposes `reclaim(int $feedId)` for the immediate path and `reclaimAll()` for the sweep, and both funnel into one conditional DQL `DELETE` that re-checks the no-subscriber condition inside the statement, so a concurrent subscribe cannot lose its subscription to a race. `AccountDeleter` removes the `User` through the ORM and lets the existing FK `ON DELETE CASCADE` take the child rows, then reclaims the feeds the account was the last subscriber of. Two thin controller actions call it; two guards (self-delete, last-admin) reject before anything is removed.

**Tech Stack:** Symfony 7.4, Doctrine ORM 3.6, PHPUnit, Angular 20 (standalone components + signals), CDK Dialog, Transloco, Jest.

**Issue:** https://github.com/larspohlmann/simple-feed-reader/issues/246

## Global Constraints

- `declare(strict_types=1)` in every PHP file; PSR-12 (`composer cs`, `composer cs:fix` autofixes); PHPStan level max (`composer stan`, warm the cache first with `bin/console cache:warmup`).
- Every touched `src` file must be PHPMD-clean before commit (`composer md`) — fix the design, not the threshold.
- Clean Code is mandatory: `final readonly class` with constructor promotion, guard clauses over nesting, no boolean flag parameters, no private methods on controllers that carry responsibility (`ThinControllerRule` fails the build).
- Every deliberate error is a typed exception extending `App\Exception\ApiException`, which `ApiExceptionListener` renders as `application/problem+json`. Never signal failure with `null`.
- Frontend: no hex colours and no raw `px` spacing in `.scss` outside `src/app/theme/`; component styles live in a sibling `.scss` file via `styleUrl`, never inline; Prettier wraps at 100 columns. `npm run check` from `frontend/` is the gate.
- All backend commands run from `backend/`, all frontend commands from `frontend/`.
- Commit messages: `feat(#246): <what>`, `test(#246): <what>`, `refactor(#246): <what>`, `docs(#246): <what>`.
- **No migration is needed.** This change adds no column, no table and no index. Every cascade it relies on already exists in the schema.
- Branch: `feature/246-delete-user-and-orphaned-feeds`, off `develop`.

## Facts Established Before Planning

Read these before starting; they are why the tasks look the way they do.

- **The FK cascade already covers a user's children.** `ON DELETE CASCADE` is declared on `subscription.user_id`, `subscription.feed_id`, `tag.user_id`, `subscription_tag.subscription_id`, `subscription_tag.tag_id`, `entry_state.user_id`, `entry_state.entry_id`, `preferences.user_id`, `user_identity.user_id`, `action_token.user_id` and `entry.feed_id`. `PurgeUnverifiedUsersCommand` and `E2ePurgeUsersCommand` already depend on it.
- **SQLite enforces those FKs in tests.** `src/Doctrine/SqliteForeignKeysMiddleware.php` issues `PRAGMA foreign_keys = ON` outside any transaction. Without it SQLite ignores FKs entirely; do not remove it, and do not add a redundant PRAGMA.
- **`catalog_feed` holds no FK to `feed`.** It matches by URL. Deleting an orphaned feed leaves the catalog suggestion intact.
- **`prune` is true only on `RefreshRequest::allDue()`**, which only `RefreshFeedsCommand` builds when it is given neither `--user` nor `--feed`. A user-triggered refresh through `RefreshController` never prunes. The sweep therefore rides on the maintenance refresh; the immediate path in Task 2 is what keeps the database clean between them. This is deliberate — do not widen `prune`.
- **`SubscriptionController::delete()` currently calls `$this->em->remove($sub)` in the action.** Adding an orphan check there would fail `ThinControllerRule`. Task 2 moves it.
- **`ConfirmDialogComponent` lives at `frontend/src/app/reader/manage/confirm-dialog.component.ts`** and is already imported by `admin-user-detail.component.ts` and `admin-users.component.ts`. It has no typed-confirmation input. Task 7 moves it to `shared/` and adds one.

---

### Task 1: `OrphanedFeedReclaimer` — the one place an unsubscribed feed dies

The core unit. Nothing wires it up yet; this task proves the deletion and the race guard in isolation.

**Files:**
- Create: `backend/src/Service/OrphanedFeedReclaimer.php`
- Create: `backend/tests/Service/OrphanedFeedReclaimerTest.php`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: `App\Service\OrphanedFeedReclaimer` with
  `reclaim(int $feedId): bool` (true when the feed was deleted) and
  `reclaimAll(): int` (number of feeds deleted). Tasks 2, 3 and 4 call these.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/OrphanedFeedReclaimerTest.php`. `DbTestCase` gives a booted kernel and an `$this->em` against the ORM-metadata schema — copy the setup shape from `backend/tests/Service/EntryPrunerTest.php`.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\OrphanedFeedReclaimer;
use App\Tests\DbTestCase;

final class OrphanedFeedReclaimerTest extends DbTestCase
{
    private OrphanedFeedReclaimer $reclaimer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reclaimer = new OrphanedFeedReclaimer($this->em);
    }

    public function testReclaimDeletesAFeedNobodySubscribesTo(): void
    {
        $feed = $this->feed('https://orphan.example.com/rss');

        self::assertTrue($this->reclaimer->reclaim((int) $feed->getId()));
        self::assertNull($this->em->getRepository(Feed::class)->find($feed->getId()));
    }

    public function testReclaimKeepsAFeedThatStillHasASubscriber(): void
    {
        $feed = $this->feed('https://kept.example.com/rss');
        $this->subscribe($this->user('keeper@example.com'), $feed);

        self::assertFalse($this->reclaimer->reclaim((int) $feed->getId()));
        self::assertNotNull($this->em->getRepository(Feed::class)->find($feed->getId()));
    }

    public function testReclaimTakesTheFeedsEntriesWithIt(): void
    {
        $feed = $this->feed('https://withentries.example.com/rss');
        $this->em->persist(new Entry($feed, 'guid-1', 'Title', 'https://withentries.example.com/1'));
        $this->em->flush();

        $this->reclaimer->reclaim((int) $feed->getId());
        $this->em->clear();

        self::assertSame(0, (int) $this->em->createQuery(
            'SELECT COUNT(e.id) FROM App\Entity\Entry e',
        )->getSingleScalarResult());
    }

    public function testReclaimAllDeletesOnlyTheOrphans(): void
    {
        $orphanOne = $this->feed('https://orphan-1.example.com/rss');
        $orphanTwo = $this->feed('https://orphan-2.example.com/rss');
        $kept = $this->feed('https://kept-2.example.com/rss');
        $this->subscribe($this->user('keeper-2@example.com'), $kept);

        self::assertSame(2, $this->reclaimer->reclaimAll());

        $this->em->clear();
        $repository = $this->em->getRepository(Feed::class);
        self::assertNull($repository->find($orphanOne->getId()));
        self::assertNull($repository->find($orphanTwo->getId()));
        self::assertNotNull($repository->find($kept->getId()));
    }

    public function testReclaimAllOnACleanDatabaseDeletesNothing(): void
    {
        self::assertSame(0, $this->reclaimer->reclaimAll());
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->em->persist($feed);
        $this->em->flush();

        return $feed;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01 10:00:00'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function subscribe(User $user, Feed $feed): void
    {
        $this->em->persist(new Subscription($user, $feed));
        $this->em->flush();
    }
}
```

Before running, open `backend/src/Entity/Feed.php`, `Entry.php`, `Subscription.php` and `User.php` and match the real constructor signatures. If `new Entry(...)` or `new Subscription(...)` takes different arguments, fix the helpers here — do not change the entities.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Service/OrphanedFeedReclaimerTest.php`
Expected: FAIL — `Class "App\Service\OrphanedFeedReclaimer" not found`.

- [ ] **Step 3: Write the implementation**

Create `backend/src/Service/OrphanedFeedReclaimer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Feed;
use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A feed nobody subscribes to is nobody's content: it costs storage, and the
 * refresh run would keep fetching it forever. This is the only place such a
 * feed is deleted, so the immediate path (the last unsubscribe, a user
 * deletion) and the sweep cannot drift apart.
 *
 * The no-subscriber condition is re-checked INSIDE the DELETE rather than
 * trusted from the preceding SELECT. Between selecting a candidate and
 * deleting it, another user can subscribe; without the guard that subscription
 * row would be silently taken by `subscription.feed_id`'s ON DELETE CASCADE —
 * a lost subscription, which is far worse than a feed that survives one sweep.
 * Correlating against `subscription` (a different table from the DELETE
 * target) is legal on both MySQL and SQLite.
 *
 * The feed's entries and their read state follow through the FK cascade on
 * `entry.feed_id` and `entry_state.entry_id`.
 *
 * Bulk DQL bypasses the unit of work, so a Feed the caller still holds is
 * stale once this returns. Callers pass an id and must not touch that entity
 * afterwards.
 */
final readonly class OrphanedFeedReclaimer
{
    /** Same chunking as EntryPruner: keeps the IN() list off the parameter limit. */
    private const int DELETE_CHUNK_SIZE = 500;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** True when the feed had no subscriber left and was deleted. */
    public function reclaim(int $feedId): bool
    {
        return $this->deleteOrphans([$feedId]) > 0;
    }

    /** The safety net: every orphan currently in the database. */
    public function reclaimAll(): int
    {
        /** @var list<int> $feedIds */
        $feedIds = $this->entityManager->createQuery(sprintf(
            'SELECT f.id FROM %s f WHERE %s',
            Feed::class,
            $this->hasNoSubscriberDql(),
        ))->getSingleColumnResult();

        return $this->deleteOrphans($feedIds);
    }

    /**
     * @param list<int> $feedIds
     */
    private function deleteOrphans(array $feedIds): int
    {
        if ([] === $feedIds) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($feedIds, self::DELETE_CHUNK_SIZE) as $chunk) {
            $deleted += (int) $this->entityManager->createQuery(sprintf(
                'DELETE FROM %s f WHERE f.id IN (:feedIds) AND %s',
                Feed::class,
                $this->hasNoSubscriberDql(),
            ))
                ->setParameter('feedIds', $chunk)
                ->execute();
        }

        return $deleted;
    }

    private function hasNoSubscriberDql(): string
    {
        return sprintf(
            'NOT EXISTS (SELECT s.id FROM %s s WHERE s.feed = f)',
            Subscription::class,
        );
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php bin/phpunit tests/Service/OrphanedFeedReclaimerTest.php`
Expected: PASS, 5 tests.

If the DQL parser rejects the correlated `NOT EXISTS` inside `DELETE`, do **not** drop the guard. Fall back to one raw DBAL statement, identical text on both dialects:
`DELETE FROM feed WHERE id IN (?) AND NOT EXISTS (SELECT 1 FROM subscription WHERE subscription.feed_id = feed.id)` via `$this->entityManager->getConnection()->executeStatement(...)` with `ArrayParameterType::INTEGER`. Record the fallback in the class docblock.

- [ ] **Step 5: Run the same test against MySQL**

The FK cascade and the correlated subquery are dialect-sensitive, and the native run only proves SQLite.

Run: `docker compose exec php vendor/bin/phpunit tests/Service/OrphanedFeedReclaimerTest.php` (from the repo root, stack up)
Expected: PASS, 5 tests. If it fails here and passed natively, the DQL is not portable — apply the raw-DBAL fallback from Step 4 and re-run both legs.

- [ ] **Step 6: Static checks**

Run: `bin/console cache:warmup && composer check && composer md`
Expected: no findings.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/OrphanedFeedReclaimer.php backend/tests/Service/OrphanedFeedReclaimerTest.php
git commit -m "feat(#246): reclaim feeds that have no subscriber left"
```

---

### Task 2: Move unsubscribe into `SubscriptionService` and reclaim the feed

`SubscriptionController::delete()` removes the subscription in the action itself. The orphan check is real work and cannot live there.

**Files:**
- Modify: `backend/src/Service/Subscription/SubscriptionService.php` (add `unsubscribe()`)
- Modify: `backend/src/Controller/Api/SubscriptionController.php` (the `delete()` action, and drop now-unused constructor dependencies only if nothing else uses them)
- Modify: `backend/tests/Controller/Api/SubscriptionControllerTest.php`

**Interfaces:**
- Consumes: `OrphanedFeedReclaimer::reclaim(int $feedId): bool` from Task 1.
- Produces: `SubscriptionService::unsubscribe(Subscription $subscription): void`. Task 4 does **not** call this — a user deletion removes subscriptions by cascade, not one at a time.

- [ ] **Step 1: Write the failing tests**

Add to `backend/tests/Controller/Api/SubscriptionControllerTest.php`. Match the file's existing helpers for creating a user, minting a token and subscribing — read them first rather than inventing new ones.

```php
public function testUnsubscribingAsTheOnlySubscriberDeletesTheFeed(): void
{
    $user = $this->activeUser('solo@example.com');
    $subscription = $this->subscribe($user, 'https://solo.example.com/rss');
    $feedId = (int) $subscription->getFeed()->getId();

    $this->client->request(
        'DELETE',
        '/api/subscriptions/' . $subscription->getId(),
        server: $this->authHeaders($user),
    );

    self::assertResponseStatusCodeSame(204);
    self::assertNull($this->em()->getRepository(Feed::class)->find($feedId));
}

public function testUnsubscribingKeepsAFeedAnotherUserStillReads(): void
{
    $leaver = $this->activeUser('leaver@example.com');
    $stayer = $this->activeUser('stayer@example.com');
    $url = 'https://shared.example.com/rss';
    $leaving = $this->subscribe($leaver, $url);
    $this->subscribe($stayer, $url);
    $feedId = (int) $leaving->getFeed()->getId();

    $this->client->request(
        'DELETE',
        '/api/subscriptions/' . $leaving->getId(),
        server: $this->authHeaders($leaver),
    );

    self::assertResponseStatusCodeSame(204);
    self::assertNotNull($this->em()->getRepository(Feed::class)->find($feedId));
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Controller/Api/SubscriptionControllerTest.php`
Expected: the first new test FAILS (`assertNull` sees a surviving Feed); the second PASSES already.

- [ ] **Step 3: Add `unsubscribe()` to `SubscriptionService`**

Inject `OrphanedFeedReclaimer` into the existing constructor and add:

```php
/**
 * Removes one subscription and reclaims the feed if that was the last one.
 * The removal is flushed before the reclaim so the DELETE's no-subscriber
 * guard sees the row is gone; reclaim() is a no-op when anybody else still
 * subscribes.
 */
public function unsubscribe(Subscription $subscription): void
{
    $feedId = (int) $subscription->getFeed()->getId();

    $this->entityManager->remove($subscription);
    $this->entityManager->flush();

    $this->orphanedFeeds->reclaim($feedId);
}
```

Use the constructor's existing EntityManager property name — read the class before writing.

- [ ] **Step 4: Make the controller action delegate**

In `backend/src/Controller/Api/SubscriptionController.php`, replace the body of `delete()`:

```php
#[Route('/{id}', name: 'api_subscriptions_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
public function delete(int $id, #[CurrentUser] User $user): JsonResponse
{
    $subscription = $this->subscriptionRepo->findOneOwnedBy($id, (int) $user->getId())
        ?? throw new NotFoundHttpException('No such subscription.');

    $this->subscriptions->unsubscribe($subscription);

    return new JsonResponse(null, Response::HTTP_NO_CONTENT);
}
```

`$this->subscriptions` is the already-injected `SubscriptionService`. Leave the other actions alone.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Controller/Api/SubscriptionControllerTest.php`
Expected: PASS, including both new tests.

- [ ] **Step 6: Run the whole SQLite suite**

Run: `php bin/phpunit`
Expected: PASS. Other suites subscribe and unsubscribe; a feed disappearing where it used to linger can surface here.

- [ ] **Step 7: Static checks**

Run: `bin/console cache:warmup && composer check && composer md`
Expected: no findings. `composer stan` runs `ThinControllerRule` — a failure here means real work is still sitting in the action.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Service/Subscription/SubscriptionService.php backend/src/Controller/Api/SubscriptionController.php backend/tests/Controller/Api/SubscriptionControllerTest.php
git commit -m "refactor(#246): move unsubscribe into SubscriptionService and reclaim the feed"
```

---

### Task 3: Sweep orphans at the start of a pruning refresh

The safety net, and the only thing that clears the orphans already in the production database.

**Files:**
- Modify: `backend/src/Service/Refresh/RefreshRunner.php`
- Modify: `backend/tests/Service/Refresh/RefreshRunnerTest.php:81` (constructor call)
- Modify: `backend/tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php:114` (constructor call)
- Create: `backend/tests/Service/Refresh/RefreshRunnerOrphanSweepTest.php`

**Interfaces:**
- Consumes: `OrphanedFeedReclaimer::reclaimAll(): int` from Task 1.
- Produces: nothing other tasks depend on. `RefreshReport`'s shape is unchanged, so the API response and the frontend poll loop stay as they are.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Refresh/RefreshRunnerOrphanSweepTest.php`. Read `RefreshRunnerTest.php` first and copy how it assembles the runner and its collaborators verbatim — the constructor has eleven dependencies and this test must not diverge.

```php
public function testAPruningRefreshDeletesAnOrphanedFeed(): void
{
    $orphan = new Feed('https://orphan.example.com/rss');
    $this->em->persist($orphan);
    $this->em->flush();
    $orphanId = (int) $orphan->getId();

    $this->runner->run(RefreshRequest::allDue(budgetSeconds: 30));

    $this->em->clear();
    self::assertNull($this->em->getRepository(Feed::class)->find($orphanId));
}

public function testAUserRefreshLeavesAnOrphanedFeedAlone(): void
{
    $orphan = new Feed('https://orphan-2.example.com/rss');
    $this->em->persist($orphan);
    $this->em->flush();
    $orphanId = (int) $orphan->getId();

    $this->runner->run(RefreshRequest::forUser(userId: 1, budgetSeconds: 30));

    $this->em->clear();
    self::assertNotNull($this->em->getRepository(Feed::class)->find($orphanId));
}
```

The second test is the one that pins the deliberate limit: only `allDue()` sets `prune`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Service/Refresh/RefreshRunnerOrphanSweepTest.php`
Expected: the first test FAILS (the orphan survives); the second PASSES.

- [ ] **Step 3: Wire the reclaimer into `RefreshRunner`**

Add `private readonly OrphanedFeedReclaimer $orphanedFeeds,` to the promoted constructor, after `EntryPruner $pruner`. Then, in `refresh()`, before `$feeds = $this->feedRepository->findDue(...)`:

```php
// Before findDue(), not after: a feed nobody subscribes to must not cost the
// run an HTTP request. Gated on the same flag as the entry prune, so only the
// maintenance refresh sweeps — a user-triggered refresh stays fast.
if ($request->prune) {
    $reclaimed = $this->orphanedFeeds->reclaimAll();
    if ($reclaimed > 0) {
        $this->logger->info('Reclaimed orphaned feeds', ['count' => $reclaimed]);
    }
}
```

The count is logged rather than added to `RefreshReport`: the report is a client-facing contract and nothing in the frontend has a use for the number.

- [ ] **Step 4: Fix the two existing constructor calls**

`tests/Service/Refresh/RefreshRunnerTest.php:81` and `tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php:114` build the runner by hand. Add `new OrphanedFeedReclaimer($this->em),` in the same position as the new constructor parameter, and import `App\Service\OrphanedFeedReclaimer` in both files.

- [ ] **Step 5: Run the refresh tests to verify they pass**

Run: `php bin/phpunit tests/Service/Refresh`
Expected: PASS, all three files.

- [ ] **Step 6: Run the whole SQLite suite and the static checks**

Run: `php bin/phpunit && bin/console cache:warmup && composer check && composer md`
Expected: PASS, no findings. `RefreshRunner` is a large class — if PHPMD now complains about it, extract the sweep into a small private method rather than raising a threshold.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Refresh/RefreshRunner.php backend/tests/Service/Refresh
git commit -m "feat(#246): sweep orphaned feeds at the start of a pruning refresh"
```

---

### Task 4: `AccountDeleter` and its two guards

The domain unit for deleting a user. No HTTP yet.

**Files:**
- Create: `backend/src/Service/Account/AccountDeleter.php`
- Create: `backend/src/Exception/LastAdminException.php`
- Modify: `backend/src/Service/Admin/SelfActionGuard.php`
- Modify: `backend/src/Repository/UserRepository.php` (add `countAdmins()`)
- Modify: `backend/src/Repository/SubscriptionRepository.php` (add `feedIdsForUser()`)
- Create: `backend/tests/Service/Account/AccountDeleterTest.php`

**Interfaces:**
- Consumes: `OrphanedFeedReclaimer::reclaim(int $feedId): bool` from Task 1.
- Produces:
  - `App\Service\Account\AccountDeleter::deleteAsAdmin(User $target, User $admin): void`
  - `App\Service\Account\AccountDeleter::deleteSelf(User $user): void`
  - `App\Exception\LastAdminException` (409, type `last_admin`)
  - `App\Service\Admin\SelfActionGuard::ensureNotSelfDeletion(User $target, User $admin): void`
  - `App\Repository\UserRepository::countAdmins(): int`
  - `App\Repository\SubscriptionRepository::feedIdsForUser(int $userId): array` returning `list<int>`

  Tasks 5 and 6 call the two `AccountDeleter` methods and nothing else.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Account/AccountDeleterTest.php`. Use `UserFactory` (`tests/Support/UserFactory.php`) for users — it hashes a password and flushes.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Account;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Exception\LastAdminException;
use App\Exception\ValidationException;
use App\Service\Account\AccountDeleter;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;

final class AccountDeleterTest extends DbTestCase
{
    private AccountDeleter $deleter;
    private UserFactory $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deleter = self::getContainer()->get(AccountDeleter::class);
        $this->users = new UserFactory(
            $this->em,
            self::getContainer()->get('security.user_password_hasher'),
        );
    }

    public function testAdminDeletionRemovesTheAccount(): void
    {
        $admin = $this->users->create('admin@example.com', roles: ['ROLE_ADMIN']);
        $target = $this->users->create('target@example.com');
        $targetId = (int) $target->getId();

        $this->deleter->deleteAsAdmin($target, $admin);

        $this->em->clear();
        self::assertNull($this->em->getRepository(User::class)->find($targetId));
    }

    public function testDeletionTakesTheAccountsSubscriptionsAndItsSoleFeed(): void
    {
        $admin = $this->users->create('admin-2@example.com', roles: ['ROLE_ADMIN']);
        $target = $this->users->create('target-2@example.com');
        $feed = new Feed('https://only-theirs.example.com/rss');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($target, $feed));
        $this->em->flush();
        $feedId = (int) $feed->getId();

        $this->deleter->deleteAsAdmin($target, $admin);

        $this->em->clear();
        self::assertNull($this->em->getRepository(Feed::class)->find($feedId));
        self::assertSame(0, (int) $this->em->createQuery(
            'SELECT COUNT(s.id) FROM App\Entity\Subscription s',
        )->getSingleScalarResult());
    }

    public function testDeletionKeepsAFeedAnotherUserStillReads(): void
    {
        $admin = $this->users->create('admin-3@example.com', roles: ['ROLE_ADMIN']);
        $target = $this->users->create('target-3@example.com');
        $stayer = $this->users->create('stayer@example.com');
        $feed = new Feed('https://shared-2.example.com/rss');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($target, $feed));
        $this->em->persist(new Subscription($stayer, $feed));
        $this->em->flush();
        $feedId = (int) $feed->getId();

        $this->deleter->deleteAsAdmin($target, $admin);

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Feed::class)->find($feedId));
    }

    public function testAnAdminCannotDeleteThemselves(): void
    {
        $admin = $this->users->create('self@example.com', roles: ['ROLE_ADMIN']);

        $this->expectException(ValidationException::class);
        $this->deleter->deleteAsAdmin($admin, $admin);
    }

    public function testTheLastAdminCannotBeDeletedByAnotherAdmin(): void
    {
        $soleAdmin = $this->users->create('sole@example.com', roles: ['ROLE_ADMIN']);
        $other = $this->users->create('other@example.com', roles: ['ROLE_ADMIN']);
        $this->deleter->deleteAsAdmin($other, $soleAdmin);
        $this->em->clear();

        $reloaded = $this->em->getRepository(User::class)->find($soleAdmin->getId());

        $this->expectException(LastAdminException::class);
        $this->deleter->deleteSelf($reloaded);
    }

    public function testTheLastAdminCannotDeleteThemselves(): void
    {
        $soleAdmin = $this->users->create('sole-2@example.com', roles: ['ROLE_ADMIN']);

        $this->expectException(LastAdminException::class);
        $this->deleter->deleteSelf($soleAdmin);
    }

    public function testSelfDeletionRemovesTheAccount(): void
    {
        $this->users->create('keeper-admin@example.com', roles: ['ROLE_ADMIN']);
        $user = $this->users->create('leaving@example.com');
        $userId = (int) $user->getId();

        $this->deleter->deleteSelf($user);

        $this->em->clear();
        self::assertNull($this->em->getRepository(User::class)->find($userId));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Service/Account/AccountDeleterTest.php`
Expected: FAIL — `App\Service\Account\AccountDeleter` not found.

- [ ] **Step 3: Add `LastAdminException`**

Create `backend/src/Exception/LastAdminException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Deleting the last administrator would leave the instance with nobody able to
 * approve an account — and would re-open first-run setup, the invariant
 * UserRepository::hasAnyAdmin() exists to protect. 409, not 422: the request is
 * well-formed, the instance's state is what forbids it.
 */
final class LastAdminException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'last_admin',
            409,
            'Last administrator',
            'This is the only administrator account. Promote another account first.',
        );
    }
}
```

- [ ] **Step 4: Add `countAdmins()` to `UserRepository`**

Append to `backend/src/Repository/UserRepository.php`. The in-PHP recheck is not optional — `roles` is JSON-as-text on both dialects and a bare `LIKE` matches `ROLE_ADMINISTRATOR`, exactly as `findActiveAdmins()` and `hasAnyAdmin()` already document:

```php
/**
 * How many administrators exist, any status. Status-blind on purpose, the same
 * reasoning as hasAnyAdmin(): counting only Active admins would let a
 * suspension turn a two-admin instance into a deletable one-admin instance.
 *
 * The LIKE narrows the hydration set but STILL needs the in-PHP recheck to
 * reject a `ROLE_ADMINISTRATOR` substring match.
 */
public function countAdmins(): int
{
    /** @var list<User> $candidates */
    $candidates = $this->createQueryBuilder('u')
        ->where('u.roles LIKE :role')
        ->setParameter('role', '%ROLE_ADMIN%')
        ->getQuery()
        ->getResult();

    return \count(array_filter(
        $candidates,
        static fn (User $candidate): bool => \in_array('ROLE_ADMIN', $candidate->getRoles(), true),
    ));
}
```

- [ ] **Step 5: Add `feedIdsForUser()` to `SubscriptionRepository`**

The ids must be collected **before** the user is deleted; afterwards the subscription rows are gone. Append to `backend/src/Repository/SubscriptionRepository.php`:

```php
/**
 * The feeds this user subscribes to, as ids. Read before deleting the account:
 * once the subscription rows cascade away there is nothing left to ask.
 *
 * @return list<int>
 */
public function feedIdsForUser(int $userId): array
{
    /** @var list<int> $feedIds */
    $feedIds = $this->createQueryBuilder('s')
        ->select('IDENTITY(s.feed)')
        ->where('s.user = :userId')
        ->setParameter('userId', $userId)
        ->getQuery()
        ->getSingleColumnResult();

    return array_map(intval(...), $feedIds);
}
```

- [ ] **Step 6: Extend `SelfActionGuard`**

`ensureNotSelf()`'s message says "change your own account status", which is wrong for a deletion. Add a second method rather than reword the existing one — reject and suspend still mean what they said:

```php
public function ensureNotSelfDeletion(User $target, User $admin): void
{
    if ($target->getId() === $admin->getId()) {
        throw new ValidationException(['id' => ['You cannot delete your own account here. Use account settings.']]);
    }
}
```

- [ ] **Step 7: Write `AccountDeleter`**

Create `backend/src/Service/Account/AccountDeleter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\User;
use App\Exception\LastAdminException;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\Admin\SelfActionGuard;
use App\Service\OrphanedFeedReclaimer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Hard deletion of an account and everything it owns. Two entry points because
 * the guards differ: an admin must not delete themselves through the admin API,
 * while a user deleting their own account is the whole point of deleteSelf().
 * Both refuse to remove the last administrator.
 *
 * remove(), not a bulk DQL DELETE: going through the ORM keeps the unit of work
 * aware of what left, the same reasoning recorded on E2ePurgeUsersCommand. The
 * account's subscriptions, tags, read state, preferences, identities and action
 * tokens follow through their FK ON DELETE CASCADE.
 *
 * Feeds are NOT the user's content — other people read them — so they are not
 * cascaded. Only the feeds this account was the last subscriber of are
 * reclaimed, and that decision belongs to OrphanedFeedReclaimer, which
 * re-checks it inside its DELETE.
 */
final readonly class AccountDeleter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private SubscriptionRepository $subscriptions,
        private OrphanedFeedReclaimer $orphanedFeeds,
        private SelfActionGuard $selfActionGuard,
    ) {
    }

    public function deleteAsAdmin(User $target, User $admin): void
    {
        $this->selfActionGuard->ensureNotSelfDeletion($target, $admin);
        $this->delete($target);
    }

    public function deleteSelf(User $user): void
    {
        $this->delete($user);
    }

    private function delete(User $user): void
    {
        $this->ensureNotTheLastAdmin($user);

        $feedIds = $this->subscriptions->feedIdsForUser((int) $user->getId());

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        foreach ($feedIds as $feedId) {
            $this->orphanedFeeds->reclaim($feedId);
        }
    }

    private function ensureNotTheLastAdmin(User $user): void
    {
        if (!\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return;
        }

        if ($this->users->countAdmins() > 1) {
            return;
        }

        throw new LastAdminException();
    }
}
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `php bin/phpunit tests/Service/Account/AccountDeleterTest.php`
Expected: PASS, 7 tests.

If the container cannot fetch `AccountDeleter` in the test, the service is not public. Follow how `tests/` already resolves private services (check `config/services_test.yaml`) rather than adding a public alias in `config/services.yaml`.

- [ ] **Step 9: Run the whole suite on both legs and the static checks**

Run: `php bin/phpunit && bin/console cache:warmup && composer check && composer md`
Then, from the repo root: `docker compose exec php vendor/bin/phpunit tests/Service/Account`
Expected: PASS. A known unrelated flake exists in the full MySQL run (order-dependent rate-limiter cases); scope the MySQL command to this directory.

- [ ] **Step 10: Commit**

```bash
git add backend/src/Service/Account backend/src/Exception/LastAdminException.php backend/src/Service/Admin/SelfActionGuard.php backend/src/Repository/UserRepository.php backend/src/Repository/SubscriptionRepository.php backend/tests/Service/Account
git commit -m "feat(#246): AccountDeleter with self-delete and last-admin guards"
```

---

### Task 5: `DELETE /api/admin/users/{id}`

**Files:**
- Modify: `backend/src/Controller/Admin/AdminUserController.php`
- Modify: `backend/tests/Controller/Admin/AdminUserControllerTest.php`

**Interfaces:**
- Consumes: `AccountDeleter::deleteAsAdmin(User $target, User $admin): void` from Task 4.
- Produces: `DELETE /api/admin/users/{id}` → `204 No Content`; `422` on self-deletion, `409` on last admin, `404` on unknown id, `403` for a non-admin.

- [ ] **Step 1: Write the failing tests**

Add to `backend/tests/Controller/Admin/AdminUserControllerTest.php`, following the file's existing `factory()` and `tokenFor()` helpers:

```php
public function testAnAdminDeletesAnotherAccount(): void
{
    $factory = $this->factory();
    $admin = $factory->create('admin-del@example.com', roles: ['ROLE_ADMIN']);
    $target = $factory->create('victim@example.com');
    $targetId = (int) $target->getId();

    $this->client->request('DELETE', self::LIST . '/' . $targetId, server: [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin),
    ]);

    self::assertResponseStatusCodeSame(204);
}

public function testAnAdminCannotDeleteThemselvesThroughTheAdminApi(): void
{
    $admin = $this->factory()->create('self-del@example.com', roles: ['ROLE_ADMIN']);
    $this->factory()->create('spare-admin@example.com', roles: ['ROLE_ADMIN']);

    $this->client->request('DELETE', self::LIST . '/' . $admin->getId(), server: [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin),
    ]);

    self::assertResponseStatusCodeSame(422);
}

public function testDeletingTheLastAdminIsRefused(): void
{
    $factory = $this->factory();
    $soleAdmin = $factory->create('sole-admin@example.com', roles: ['ROLE_ADMIN']);
    $deputy = $factory->create('deputy@example.com', roles: ['ROLE_ADMIN']);

    $this->client->request('DELETE', self::LIST . '/' . $deputy->getId(), server: [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($soleAdmin),
    ]);
    self::assertResponseStatusCodeSame(204);

    $this->client->request('DELETE', self::LIST . '/' . $soleAdmin->getId(), server: [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($deputy),
    ]);
    self::assertResponseStatusCodeSame(404);
}

public function testANonAdminCannotDeleteAnAccount(): void
{
    $factory = $this->factory();
    $factory->create('an-admin@example.com', roles: ['ROLE_ADMIN']);
    $plainUser = $factory->create('plain@example.com');
    $target = $factory->create('other-target@example.com');

    $this->client->request('DELETE', self::LIST . '/' . $target->getId(), server: [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($plainUser),
    ]);

    self::assertResponseStatusCodeSame(403);
}
```

`testDeletingTheLastAdminIsRefused` expects `404` on the second call because `$deputy` deleted themselves out of existence in the first — their token now authenticates nobody. If the firewall answers `401` instead, assert `401`; either is correct, and the point of the case is that the sole remaining admin survives. Add the surviving-admin assertion explicitly:

```php
    self::assertNotNull(
        self::getContainer()->get(\App\Repository\UserRepository::class)->find($soleAdmin->getId()),
    );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Controller/Admin/AdminUserControllerTest.php`
Expected: FAIL with `405 Method Not Allowed` — the route does not exist yet.

- [ ] **Step 3: Add the action**

Add to `backend/src/Controller/Admin/AdminUserController.php`. Inject `private AccountDeleter $accountDeleter,` into the promoted constructor:

```php
/**
 * Hard deletion. The self-delete and last-admin guards live on AccountDeleter,
 * which owns the decision; this action only resolves the target and delegates.
 */
#[Route('/{id}', name: 'api_admin_users_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
public function delete(int $id, #[CurrentUser] User $admin): JsonResponse
{
    $this->accountDeleter->deleteAsAdmin($this->users->getById($id), $admin);

    return new JsonResponse(null, Response::HTTP_NO_CONTENT);
}
```

Import `Symfony\Component\HttpFoundation\Response`. Place the action after `resetPassword()`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Controller/Admin/AdminUserControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Static checks**

Run: `bin/console cache:warmup && composer check && composer md`
Expected: no findings, `ThinControllerRule` included.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Controller/Admin/AdminUserController.php backend/tests/Controller/Admin/AdminUserControllerTest.php
git commit -m "feat(#246): DELETE /api/admin/users/{id}"
```

---

### Task 6: `DELETE /api/me`

**Files:**
- Modify: `backend/src/Controller/Api/MeController.php`
- Modify: `backend/tests/Controller/Api/MeControllerTest.php`

**Interfaces:**
- Consumes: `AccountDeleter::deleteSelf(User $user): void` from Task 4.
- Produces: `DELETE /api/me` → `204 No Content`; `409` when the caller is the last admin; `401` without a token.

- [ ] **Step 1: Write the failing tests**

Add to `backend/tests/Controller/Api/MeControllerTest.php`, using that file's existing helpers for creating a user and minting a token:

```php
public function testAUserDeletesTheirOwnAccount(): void
{
    $user = $this->activeUser('bye@example.com');
    $userId = (int) $user->getId();
    $token = $this->tokenFor($user);

    $this->client->request('DELETE', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    self::assertResponseStatusCodeSame(204);

    $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    self::assertResponseStatusCodeSame(401);

    self::assertNull(
        self::getContainer()->get(\App\Repository\UserRepository::class)->find($userId),
    );
}

public function testTheSoleAdminCannotDeleteTheirOwnAccount(): void
{
    $admin = $this->activeUser('last-admin@example.com', roles: ['ROLE_ADMIN']);

    $this->client->request('DELETE', '/api/me', server: [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin),
    ]);

    self::assertResponseStatusCodeSame(409);
}

public function testDeletingTheAccountNeedsAToken(): void
{
    $this->client->request('DELETE', '/api/me');

    self::assertResponseStatusCodeSame(401);
}
```

The second `GET /api/me` in the first test is the point of the case: the JWT is stateless, so this proves the token stops authenticating because the user row is gone, not because anything was revoked.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Controller/Api/MeControllerTest.php`
Expected: FAIL with `405 Method Not Allowed`.

- [ ] **Step 3: Add the action**

Add to `backend/src/Controller/Api/MeController.php`. Inject `AccountDeleter` — the constructor currently takes only `EntityManagerInterface`, so add a second promoted parameter:

```php
/**
 * Self-service hard deletion. The typed confirmation is a client concern and
 * deliberately not a request field: requiring one would put a browser-only
 * input in the API contract, which the native-iOS constraint forbids.
 */
#[Route('/api/me', name: 'api_me_delete', methods: ['DELETE'])]
public function delete(#[CurrentUser] User $user): JsonResponse
{
    $this->accountDeleter->deleteSelf($user);

    return new JsonResponse(null, Response::HTTP_NO_CONTENT);
}
```

Import `Symfony\Component\HttpFoundation\Response`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Controller/Api/MeControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Full backend gate**

Run: `php bin/phpunit && bin/console cache:warmup && composer check && composer md`
Then check `backend/var/log/dev.log` for deprecations or swallowed errors from this work.
Expected: PASS, no findings, nothing new in the log.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Controller/Api/MeController.php backend/tests/Controller/Api/MeControllerTest.php
git commit -m "feat(#246): DELETE /api/me for self-service account deletion"
```

---

### Task 7: Move the confirm dialog to `shared/` and give it a typed confirmation

`ConfirmDialogComponent` sits in `reader/manage/` but is already imported by two admin components, and Task 9 adds a third consumer in settings. It is a shared component; `docs/design-language.md` says the shared catalog lives in `src/app/shared/`. Moving it is part of the work, not a detour.

**Files:**
- Move: `frontend/src/app/reader/manage/confirm-dialog.component.{ts,html,scss,spec.ts}` → `frontend/src/app/shared/confirm-dialog/`
- Modify: every importer (`reader/manage/manage-actions.service.ts`, `admin/admin-user-detail.component.ts`, `admin/admin-users.component.ts`, and their specs)
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Modify: `docs/design-language.md` (the shared component catalog entry)

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: `ConfirmData` gains one optional field —
  `requireText?: string`. When set, the dialog renders a text input and the
  confirm button stays disabled until the input matches `requireText` exactly.
  The dialog still closes with `boolean`. Tasks 8 and 9 pass `requireText`.

- [ ] **Step 1: Move the files and repoint the imports**

```bash
cd frontend
mkdir -p src/app/shared/confirm-dialog
git mv src/app/reader/manage/confirm-dialog.component.ts src/app/shared/confirm-dialog/
git mv src/app/reader/manage/confirm-dialog.component.html src/app/shared/confirm-dialog/
git mv src/app/reader/manage/confirm-dialog.component.scss src/app/shared/confirm-dialog/
git mv src/app/reader/manage/confirm-dialog.component.spec.ts src/app/shared/confirm-dialog/
```

Fix the relative imports inside the moved `.ts` (`../../shared/button/…` becomes `../button/…`, `../../shared/overlay-panel/…` becomes `../overlay-panel/…`), update the leading path comment, then repoint every importer:

Run: `grep -rn "reader/manage/confirm-dialog" src` and change each hit to `shared/confirm-dialog/confirm-dialog.component`, with the correct number of `../`.

- [ ] **Step 2: Verify the move alone is green**

Run: `npm run check`
Expected: PASS. Nothing behavioural changed yet — a failure here is a wrong import path.

- [ ] **Step 3: Commit the move on its own**

```bash
git add -A frontend/src/app
git commit -m "refactor(#246): move ConfirmDialogComponent into the shared catalog"
```

- [ ] **Step 4: Write the failing test for the typed confirmation**

Add to `frontend/src/app/shared/confirm-dialog/confirm-dialog.component.spec.ts`, matching the file's existing TestBed setup:

```ts
it('keeps confirm disabled until the required text matches', () => {
  const fixture = render({
    title: 'Delete account',
    message: 'This cannot be undone.',
    confirmLabel: 'Delete',
    danger: true,
    requireText: 'user@example.com',
  });

  const confirmButton = () =>
    fixture.nativeElement.querySelector('app-button[data-testid="confirm"] button');

  expect(confirmButton().disabled).toBe(true);

  fixture.componentInstance.typed.set('user@example.co');
  fixture.detectChanges();
  expect(confirmButton().disabled).toBe(true);

  fixture.componentInstance.typed.set('user@example.com');
  fixture.detectChanges();
  expect(confirmButton().disabled).toBe(false);
});

it('enables confirm immediately when no text is required', () => {
  const fixture = render({
    title: 'Remove tag',
    message: 'Sure?',
    confirmLabel: 'Remove',
  });

  const confirmButton =
    fixture.nativeElement.querySelector('app-button[data-testid="confirm"] button');
  expect(confirmButton.disabled).toBe(false);
});
```

Write the `render(data: ConfirmData)` helper to configure `DIALOG_DATA` and a `DialogRef` stub, following whatever the existing spec already does.

- [ ] **Step 5: Run the test to verify it fails**

Run: `npx jest src/app/shared/confirm-dialog`
Expected: FAIL — `typed` does not exist on the component.

- [ ] **Step 6: Extend the component**

`frontend/src/app/shared/confirm-dialog/confirm-dialog.component.ts`:

```ts
export interface ConfirmData {
  title: string;
  message: string;
  confirmLabel: string;
  danger?: boolean;
  /**
   * When set, the user must type this exact string before the confirm button
   * enables. For deletions that take content with them and cannot be undone —
   * a single click is too cheap for that.
   */
  requireText?: string;
}

@Component({
  selector: 'app-confirm-dialog',
  imports: [A11yModule, FormsModule, TranslocoPipe, ButtonComponent, OverlayPanelComponent],
  templateUrl: './confirm-dialog.component.html',
  styleUrl: './confirm-dialog.component.scss',
})
export class ConfirmDialogComponent {
  readonly ref = inject<DialogRef<boolean>>(DialogRef);
  readonly data = inject<ConfirmData>(DIALOG_DATA);

  readonly typed = signal('');

  readonly canConfirm = computed(
    () => !this.data.requireText || this.typed() === this.data.requireText,
  );
}
```

Import `computed` and `signal` from `@angular/core`, and `FormsModule` from `@angular/forms`.

`confirm-dialog.component.html` — add the input block and gate the confirm button. Keep the existing cancel button untouched:

```html
<app-overlay-panel [heading]="data.title" cdkTrapFocus>
  <p class="msg">{{ data.message }}</p>

  @if (data.requireText) {
    <label class="confirm-field">
      <span class="confirm-field__label">
        {{ 'dialog.typeToConfirm' | transloco: { text: data.requireText } }}
      </span>
      <input
        class="confirm-field__input"
        type="text"
        autocomplete="off"
        spellcheck="false"
        [ngModel]="typed()"
        (ngModelChange)="typed.set($event)"
      />
    </label>
  }

  <app-button footer (click)="ref.close(false)">
    {{ 'dialog.cancel' | transloco }}
  </app-button>
  <app-button
    footer
    focusInitial
    data-testid="confirm"
    [disabled]="!canConfirm()"
    [variant]="data.danger ? 'danger' : 'primary'"
    (click)="ref.close(true)"
  >
    {{ data.confirmLabel }}
  </app-button>
</app-overlay-panel>
```

`focusInitial` stays on the confirm button so existing dialogs are unchanged. Where `requireText` is set, that button starts disabled; move `focusInitial` onto the input inside the `@if` block if focus lands nowhere — check it in Step 9.

`confirm-dialog.component.scss` — add the field styles. No hex colours, no raw `px`; use the tokens `docs/design-language.md` documents for form fields (read the `<app-field>` entry and reuse those variables):

```scss
.confirm-field {
  display: block;
  margin-block-start: var(--sp-3);
}

.confirm-field__label {
  display: block;
  margin-block-end: var(--sp-1);
  font-size: var(--fs-sm);
  color: var(--text-muted);
}

.confirm-field__input {
  width: 100%;
}
```

Confirm every custom property name against `src/app/theme/` before committing — Stylelint does not catch a token that does not exist, but the rendering does.

- [ ] **Step 7: Add the translation key**

`frontend/public/i18n/en.json`, under `dialog`:

```json
"typeToConfirm": "Type {{text}} to confirm"
```

`frontend/public/i18n/de.json`, under `dialog`:

```json
"typeToConfirm": "Tippen Sie {{text}}, um zu bestätigen"
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `npx jest src/app/shared/confirm-dialog`
Expected: PASS.

- [ ] **Step 9: Check it in the running app**

Start the stack and the dev server, open any existing confirm dialog (reader → manage → delete a tag), and confirm nothing regressed: cancel works, Escape closes, focus lands sensibly. The typed variant has no consumer until Task 8 — verify that one there.

- [ ] **Step 10: Document the component**

Update the `<app-confirm-dialog>` entry in `docs/design-language.md` (or add one if the catalog only documents `<app-overlay-panel>`): the new `requireText` input, and the rule for when to use it — a deletion that takes content with it and cannot be undone.

- [ ] **Step 11: Frontend gate and commit**

Run: `npm run check`

```bash
git add frontend/src/app/shared/confirm-dialog frontend/public/i18n docs/design-language.md
git commit -m "feat(#246): typed confirmation for the shared confirm dialog"
```

---

### Task 8: Admin UI — delete an account

**Files:**
- Modify: `frontend/src/app/admin/admin-api.ts`
- Modify: `frontend/src/app/admin/admin-user-detail.component.{ts,html}`
- Modify: `frontend/src/app/admin/admin-user-detail.component.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `ConfirmData.requireText` from Task 7; `DELETE /api/admin/users/{id}` from Task 5.
- Produces: `AdminApi.deleteUser(id: number): Observable<void>`.

The delete lives on the **detail page only**, not on the user-list rows. The list's row actions are approve/reject/suspend — reversible things. A deletion that takes a person's content with it should cost a navigation.

- [ ] **Step 1: Write the failing test**

Add to `frontend/src/app/admin/admin-user-detail.component.spec.ts`, following its existing `HttpTestingController` setup:

```ts
it('deletes the account and returns to the user list once confirmed', () => {
  // dialogStub is the Dialog provider override this spec already installs for
  // confirmThenAct; make it resolve to true.
  dialogStub.open.mockReturnValue({ closed: of(true) });

  component.confirmThenDelete();

  const request = httpMock.expectOne(`${base}/api/admin/users/7`);
  expect(request.request.method).toBe('DELETE');
  request.flush(null, { status: 204, statusText: 'No Content' });

  expect(router.navigate).toHaveBeenCalledWith(['/admin/users']);
});

it('passes the account email as the required confirmation text', () => {
  dialogStub.open.mockReturnValue({ closed: of(false) });

  component.confirmThenDelete();

  expect(dialogStub.open.mock.calls[0][1].data.requireText).toBe('victim@example.com');
});
```

Match the spec's existing names for the component fixture, the router spy and the base URL.

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx jest src/app/admin/admin-user-detail`
Expected: FAIL — `confirmThenDelete` is not a function.

- [ ] **Step 3: Add the API method**

`frontend/src/app/admin/admin-api.ts`, next to `clearTrial`:

```ts
deleteUser(id: number): Observable<void> {
  return this.http.delete<void>(`${this.base}/api/admin/users/${id}`);
}
```

Do **not** widen the `AdminAction` union — that type is for the POST status actions.

- [ ] **Step 4: Add the component method**

`frontend/src/app/admin/admin-user-detail.component.ts`, next to `confirmThenAct`:

```ts
/** Deletion is irreversible and takes the account's content with it, so it
 *  gets the strongest treatment the app has: a danger-outline initiator, and
 *  a confirm the admin must type the target's email address to enable. */
confirmThenDelete(): void {
  const email = this.detail()?.user.email ?? '';
  const data: ConfirmData = {
    title: this.i18n.translate('admin.confirm.deleteTitle'),
    message: this.i18n.translate('admin.confirm.deleteMessage', { email }),
    confirmLabel: this.i18n.translate('admin.delete'),
    danger: true,
    requireText: email,
  };
  const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
    data,
    role: 'alertdialog',
    panelClass: 'app-dialog',
  });
  ref.closed.subscribe((confirmed) => {
    if (confirmed) this.deleteAccount();
  });
}

private deleteAccount(): void {
  this.actionError.set(null);
  this.api.deleteUser(this.id).subscribe({
    next: () => void this.router.navigate(['/admin/users']),
    error: (failure: HttpErrorResponse) => this.actionError.set(parseProblem(failure)),
  });
}
```

`Router` is likely already injected for other navigation; if not, add `private readonly router = inject(Router);`. The error path reuses `actionError` and `parseProblem`, so the `409` last-admin problem document renders in the existing banner with no new UI.

- [ ] **Step 5: Add the button**

`admin-user-detail.component.html`, in the same action row as reject/suspend:

```html
<app-button variant="danger-outline" (click)="confirmThenDelete()">
  {{ 'admin.delete' | transloco }}
</app-button>
```

`danger-outline` initiates; the filled `danger` inside the dialog confirms. That two-step scale is the documented rule in `docs/design-language.md`.

- [ ] **Step 6: Add the translation keys**

`frontend/public/i18n/en.json`:

```json
"delete": "Delete account",
"confirm": {
  "deleteTitle": "Delete this account?",
  "deleteMessage": "This permanently deletes {{email}} with all subscriptions, tags and read state. Feeds nobody else reads are deleted too. This cannot be undone."
}
```

`frontend/public/i18n/de.json`:

```json
"delete": "Konto löschen",
"confirm": {
  "deleteTitle": "Dieses Konto löschen?",
  "deleteMessage": "Dies löscht {{email}} dauerhaft, mit allen Abonnements, Tags und Lesezuständen. Feeds, die niemand sonst liest, werden ebenfalls gelöscht. Das kann nicht rückgängig gemacht werden."
}
```

Merge into the existing `admin` and `admin.confirm` objects — do not create duplicate keys.

- [ ] **Step 7: Run the test to verify it passes**

Run: `npx jest src/app/admin/admin-user-detail`
Expected: PASS.

- [ ] **Step 8: Check it against the running app**

With the Docker stack up and `npm start` running, sign in as an admin, open a throwaway account's detail page, and delete it. Confirm: the button is disabled until the email is typed exactly, the list is reachable afterwards, and the account is gone from it. Then try to delete the last admin and confirm the `409` message renders in the error banner rather than as a blank failure.

- [ ] **Step 9: Frontend gate and commit**

Run: `npm run check`

```bash
git add frontend/src/app/admin frontend/public/i18n
git commit -m "feat(#246): admin can delete an account from the detail page"
```

---

### Task 9: Settings UI — delete your own account

**Files:**
- Modify: `frontend/src/app/core/auth.service.ts` (add `deleteAccount()`)
- Modify: `frontend/src/app/settings/account-section.component.{ts,html,scss}`
- Modify: `frontend/src/app/settings/account-section.component.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `ConfirmData.requireText` from Task 7; `DELETE /api/me` from Task 6.
- Produces: `AuthService.deleteAccount(): Observable<void>`.

- [ ] **Step 1: Write the failing test**

Add to `frontend/src/app/settings/account-section.component.spec.ts`:

```ts
it('deletes the account and logs out once confirmed', () => {
  dialogStub.open.mockReturnValue({ closed: of(true) });

  component.confirmThenDelete();

  const request = httpMock.expectOne(`${base}/api/me`);
  expect(request.request.method).toBe('DELETE');
  request.flush(null, { status: 204, statusText: 'No Content' });

  expect(logoutSpy).toHaveBeenCalled();
});

it('does nothing when the dialog is dismissed', () => {
  dialogStub.open.mockReturnValue({ closed: of(false) });

  component.confirmThenDelete();

  httpMock.expectNone(`${base}/api/me`);
  expect(logoutSpy).not.toHaveBeenCalled();
});
```

The spec currently has no HTTP or dialog setup — add `provideHttpClientTesting()`, a `Dialog` stub and a spy on `AuthService.logout`, following how `admin-user-detail.component.spec.ts` does it.

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx jest src/app/settings/account-section`
Expected: FAIL — `confirmThenDelete` is not a function.

- [ ] **Step 3: Add `deleteAccount()` to `AuthService`**

`frontend/src/app/core/auth.service.ts`:

```ts
deleteAccount(): Observable<void> {
  return this.http.delete<void>(`${this.base}/api/me`);
}
```

It deliberately does **not** call `logout()` itself: the caller decides what to do with a failure, and `logout()` navigates.

- [ ] **Step 4: Add the component method**

`frontend/src/app/settings/account-section.component.ts`:

```ts
readonly deleteError = signal<string | null>(null);

/** The account and everything in it, gone. Same treatment as the admin's
 *  delete: type your own address to enable the confirm. */
confirmThenDelete(): void {
  const email = this.auth.user()?.email ?? '';
  const data: ConfirmData = {
    title: this.i18n.translate('settings.account.deleteTitle'),
    message: this.i18n.translate('settings.account.deleteMessage'),
    confirmLabel: this.i18n.translate('settings.account.delete'),
    danger: true,
    requireText: email,
  };
  const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
    data,
    role: 'alertdialog',
    panelClass: 'app-dialog',
  });
  ref.closed.subscribe((confirmed) => {
    if (confirmed) this.deleteAccount();
  });
}

private deleteAccount(): void {
  this.deleteError.set(null);
  this.auth.deleteAccount().subscribe({
    // logout() clears the token, resets per-account state and routes to /login.
    // The token is stateless and the user row is gone, so it authenticates
    // nobody either way — clearing it is what stops the app from rendering a
    // signed-in shell for an account that no longer exists.
    next: () => this.auth.logout(),
    error: (failure: HttpErrorResponse) => this.deleteError.set(parseProblem(failure)),
  });
}
```

Inject `Dialog`, `TranslocoService` and whatever `parseProblem` lives in (`grep -rn "export function parseProblem" src` to find it).

- [ ] **Step 5: Add the UI**

`account-section.component.html`, at the end of the card:

```html
<div class="danger-zone">
  <p class="danger-zone__note">{{ 'settings.account.deleteNote' | transloco }}</p>
  <app-button variant="danger-outline" (click)="confirmThenDelete()">
    {{ 'settings.account.delete' | transloco }}
  </app-button>
  @if (deleteError(); as error) {
    <app-error-banner [message]="error" />
  }
</div>
```

Use the existing `<app-error-banner>` from `src/app/shared/error-banner/` — check its actual input name before wiring it.

`account-section.component.scss`:

```scss
.danger-zone {
  margin-block-start: var(--sp-5);
  padding-block-start: var(--sp-4);
  border-block-start: var(--border-hairline) solid var(--border-subtle);
}

.danger-zone__note {
  margin-block-end: var(--sp-3);
  font-size: var(--fs-sm);
  color: var(--text-muted);
}
```

Verify every token against `src/app/theme/` — invent none.

- [ ] **Step 6: Add the translation keys**

`frontend/public/i18n/en.json`, under `settings.account`:

```json
"delete": "Delete my account",
"deleteTitle": "Delete your account?",
"deleteNote": "Deleting your account removes your subscriptions, tags and read state permanently. Export your feeds first if you want to keep them.",
"deleteMessage": "This permanently deletes your account with all subscriptions, tags and read state. This cannot be undone."
```

`frontend/public/i18n/de.json`, under `settings.account`:

```json
"delete": "Mein Konto löschen",
"deleteTitle": "Ihr Konto löschen?",
"deleteNote": "Das Löschen Ihres Kontos entfernt Ihre Abonnements, Tags und Lesezustände dauerhaft. Exportieren Sie Ihre Feeds vorher, wenn Sie sie behalten möchten.",
"deleteMessage": "Dies löscht Ihr Konto dauerhaft, mit allen Abonnements, Tags und Lesezuständen. Das kann nicht rückgängig gemacht werden."
```

`deleteNote` points at the OPML export that already exists two cards away — that is the reason this plan has no export-before-delete step.

- [ ] **Step 7: Run the test to verify it passes**

Run: `npx jest src/app/settings/account-section`
Expected: PASS.

- [ ] **Step 8: Check it against the running app**

Register a throwaway account, subscribe it to one feed nothing else reads, then delete it from settings. Confirm: the confirm button needs the exact email, the app lands on `/login`, signing in again fails, and the feed is gone from the database:

```bash
docker compose exec php bin/console dbal:run-sql "SELECT COUNT(*) FROM feed"
```

- [ ] **Step 9: Frontend gate and commit**

Run: `npm run check`

```bash
git add frontend/src/app/core/auth.service.ts frontend/src/app/settings frontend/public/i18n
git commit -m "feat(#246): users can delete their own account from settings"
```

---

### Task 10: Full verification and the PR

**Files:**
- Modify: `docs/architecture.md` (only if it enumerates the API surface)

**Interfaces:**
- Consumes: everything above.
- Produces: the pull request.

- [ ] **Step 1: Both backend legs**

```bash
cd backend && php bin/phpunit
```
Then from the repo root:
```bash
docker compose exec php vendor/bin/phpunit
```
Expected: PASS on both. The MySQL leg has a known order-dependent rate-limiter flake that is not a regression from this work — if a limiter test fails, re-run that file alone to confirm it passes in isolation, and say so in the PR rather than chasing it.

- [ ] **Step 2: Static gates**

```bash
cd backend && bin/console cache:warmup && composer check && composer md
```
```bash
cd frontend && npm run check
```
Expected: no findings from either.

- [ ] **Step 3: PhpStorm inspections on the changed PHP**

Run `mcp__phpstorm__lint_files` over every `.php` file this branch touched. Block on ERROR and WARNING; weak warnings are advisory.

- [ ] **Step 4: Scan the backend log**

Read `backend/var/log/dev.log` for anything this work produced — deprecations, swallowed exceptions, Doctrine warnings about the bulk DELETE.

- [ ] **Step 5: Migration sanity, even though there is none**

This branch adds no migration. Prove it:

Run: `git diff develop --stat -- backend/migrations`
Expected: empty. If a migration appeared, an entity changed by accident — find out why before opening the PR.

Then confirm the schema still matches the metadata:

Run: `docker compose exec php bin/console doctrine:schema:validate`
Expected: both mapping and database in sync.

- [ ] **Step 6: Check the API surface docs**

Open `docs/architecture.md`. If it lists the endpoints, add `DELETE /api/admin/users/{id}` and `DELETE /api/me` and run the §6 native-client checklist against both — bearer auth, stateless, JSON in, `application/problem+json` out, no browser-only input. If it does not enumerate endpoints, change nothing.

- [ ] **Step 7: Open the PR**

```bash
git push -u origin feature/246-delete-user-and-orphaned-feeds
```

```bash
gh pr create --base develop --title "feat(#246): delete a user account with all of its content, and reclaim orphaned feeds" --body "Closes #246"
```

Write the body properly: what the two endpoints do, the two guards, the immediate-plus-sweep orphan design and why the DELETE re-checks its own condition, that the sweep only runs on the maintenance refresh, that no migration was needed, and the results of both test legs.

- [ ] **Step 8: Verify CI**

`gh run watch --exit-status` returns 0 even on a failed run, and `gh pr checks` exits 8 while merely pending. Read the conclusion back by run id before believing the branch is green:

```bash
gh run list --branch feature/246-delete-user-and-orphaned-feeds --limit 1 --json databaseId,status,conclusion
```

---

## Self-Review

**Spec coverage.** Every requirement in #246 maps to a task: admin endpoint → 5; self endpoint → 6; hard delete through the ORM with FK cascade → 4; self-delete guard → 4; last-admin guard → 4; typed confirmation → 7, 8, 9; immediate orphan reclaim → 1, 2, 4; sweep → 3; unsubscribe extracted out of the controller → 2; both-dialect verification → 1 Step 5, 10 Step 1; functional tests over real routes → 5, 6; catalog unaffected → 1 (no FK, verified before planning). The acceptance criterion "a pre-existing orphan is removed by the next refresh" is Task 3 Step 1, with the caveat recorded under Facts: only a pruning (`allDue`) refresh sweeps.

**Naming consistency.** `OrphanedFeedReclaimer::reclaim`/`reclaimAll` are used with those exact names in Tasks 2, 3 and 4. `AccountDeleter::deleteAsAdmin`/`deleteSelf` match between 4, 5 and 6. `ConfirmData.requireText` matches between 7, 8 and 9. `AdminApi.deleteUser` and `AuthService.deleteAccount` each have one definition and one consumer.

**Known soft spots**, called out rather than papered over:
- Entity constructor signatures in the Task 1 and Task 4 fixtures are written from the field names, not from a read of each constructor. The steps say to check them first.
- The Task 5 last-admin test asserts `404` on a request made with a token belonging to a just-deleted admin; `401` is equally correct depending on the firewall's ordering. The step says so and adds the assertion that actually matters.
- Existing frontend spec setups (dialog stub names, HTTP base URL, router spy) are referenced generically because they differ per file. Every such step says to read the file first.
