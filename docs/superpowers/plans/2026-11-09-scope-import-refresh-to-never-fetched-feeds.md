# Scope the import refresh to never-fetched feeds — Implementation Plan (#2106)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adding a social search, importing social feeds or importing OPML refreshes only the feeds that have never been fetched, not every due feed the user has.

**Architecture:** A new refresh scope, `?neverFetched=1` on `POST /api/refresh`, narrows the forced per-user sweep to the caller's feeds whose `fetchSchedule.lastFetchedAt IS NULL`. A just-subscribed feed that no one has fetched is exactly that set; one another user already fetched has entries and needs no fetch (the Add-feed path already skips those on `lastFetchedAt`). A failed fetch stamps `lastFetchedAt` (`FetchSchedule::recordFailure`), so the set drains; a throttled feed stamps nothing, and the run's `excludedFeedIds` already keeps a sweep finite (#302). The import endpoints stay unchanged: no feed ids travel back.

**Tech Stack:** Symfony 7.4 / Doctrine QueryBuilder, PHPUnit 12; Angular 20, Jest.

**Spec:** GitHub issue #2106 and this plan's Architecture paragraph.

## Global Constraints

- CLAUDE.md Clean Code rules; `composer check`, `composer md` clean on every touched `src` file.
- Commit format `type(#2106): lower-case summary`; no Claude attribution.
- No comments unless a future reader would get the code wrong without one.
- Frontend tests run inside the Docker frontend container: `docker compose exec -T frontend npm test -- <path>`.
- Out of scope: the backup restore refresh (`backup-section.component.ts:240`), which restores a whole account and is a sweep by intent; the onboarding sweep, whose feeds are all never-fetched anyway.

---

### Task 1: Due-feed query narrows to never-fetched feeds

**Files:**
- Modify: `backend/src/Repository/DueFeedCriteria.php`
- Modify: `backend/src/Repository/FeedRepository.php` (`dueQueryBuilder`, new `restrictToNeverFetched`)
- Create: `backend/tests/Repository/FeedRepositoryNeverFetchedScopeTest.php`

**Interfaces:**
- Produces: `DueFeedCriteria::__construct(..., ?FeedGroup $feedGroup = null, bool $neverFetched = false)`; `excluding()` carries `neverFetched` through.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\User;
use App\Repository\DueFeedCriteria;
use App\Repository\FeedRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;
use App\Tests\Support\SeedsUsers;

final class FeedRepositoryNeverFetchedScopeTest extends DbTestCase
{
    use SeedsUsers;

    private const string NOW = '2026-06-01T00:00:00Z';

    public function testTheScopeSelectsOnlyTheUsersFeedsNoFetchHasStamped(): void
    {
        $owner = $this->user('never-fetched-owner@example.com');
        $fixtures = new NetworkFeedSubscriptions($this->entityManager);
        $fresh = $fixtures->subscribe($owner, 'https://example.com/fresh.xml')->getFeed();
        $fetched = $fixtures->subscribe($owner, NetworkFeedSubscriptions::PLAIN)->getFeed();
        $fetched->recordSuccessfulFetch(new \DateTimeImmutable('2026-05-01T00:00:00Z'), 60);
        $this->entityManager->flush();

        $criteria = $this->criteria($owner);

        self::assertSame([$fresh->requireId()], $this->idsOf($this->feeds()->findDue($criteria, 50)));
        self::assertSame(1, $this->feeds()->countDue($criteria));
    }

    public function testTheScopeSurvivesExcludingFeedsAlreadyHandled(): void
    {
        $owner = $this->user('never-fetched-excluding@example.com');
        $fixtures = new NetworkFeedSubscriptions($this->entityManager);
        $handled = $fixtures->subscribe($owner, 'https://example.com/handled.xml')->getFeed();
        $fetched = $fixtures->subscribe($owner, NetworkFeedSubscriptions::PLAIN)->getFeed();
        $fetched->recordSuccessfulFetch(new \DateTimeImmutable('2026-05-01T00:00:00Z'), 60);
        $this->entityManager->flush();

        $criteria = $this->criteria($owner)->excluding([$handled->requireId()]);

        self::assertSame([], $this->feeds()->findDue($criteria, 50));
    }

    public function testTheScopeNeverReachesAnotherUsersUnfetchedFeed(): void
    {
        $owner = $this->user('never-fetched-self@example.com');
        $stranger = $this->user('never-fetched-stranger@example.com');
        (new NetworkFeedSubscriptions($this->entityManager))->subscribe($stranger, NetworkFeedSubscriptions::PLAIN);

        self::assertSame([], $this->feeds()->findDue($this->criteria($owner), 50));
    }

    private function criteria(User $owner): DueFeedCriteria
    {
        return new DueFeedCriteria(
            new \DateTimeImmutable(self::NOW),
            $owner->requireId(),
            force: true,
            neverFetched: true,
        );
    }

    /**
     * @param list<Feed> $feeds
     *
     * @return list<int>
     */
    private function idsOf(array $feeds): array
    {
        return array_map(static fn (Feed $feed): int => $feed->requireId(), $feeds);
    }

    private function feeds(): FeedRepository
    {
        $repository = $this->entityManager->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $repository);

        return $repository;
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `php bin/phpunit tests/Repository/FeedRepositoryNeverFetchedScopeTest.php`
Expected: error, unknown named parameter `$neverFetched`.

- [ ] **Step 3: Implement**

`DueFeedCriteria`: add `public bool $neverFetched = false,` as the last promoted constructor parameter, and pass `$this->neverFetched` as the last argument in `excluding()`.

`FeedRepository::dueQueryBuilder`: add `$this->restrictToNeverFetched($qb, $criteria);` after `restrictToFeedGroup`, and:

```php
    private function restrictToNeverFetched(QueryBuilder $qb, DueFeedCriteria $criteria): void
    {
        if (!$criteria->neverFetched) {
            return;
        }

        $qb->andWhere('f.fetchSchedule.lastFetchedAt IS NULL');
    }
```

- [ ] **Step 4: Run it to see it pass**, plus `tests/Repository/FeedRepositoryFeedGroupScopeTest.php` still green.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repository/DueFeedCriteria.php backend/src/Repository/FeedRepository.php backend/tests/Repository/FeedRepositoryNeverFetchedScopeTest.php
git commit -m "feat(#2106): let the due-feed query narrow to never-fetched feeds"
```

### Task 2: Fold the refresh scopes into one `RefreshTarget`

Task 1 took `DueFeedCriteria::__construct` to 10 parameters (PHPMD `ExcessiveParameterList`), and Task 3 would do the same to `RefreshRequestModel`. The design the metric points at: both classes carry `feedId`, `tagId`, `networkScope`, `feedGroup` (and now `neverFetched`) as separate nullable fields, yet every factory sets at most one and nothing combines them. They are one value — what a refresh covers. This task is a pure refactor: no behaviour changes, every existing test keeps asserting the same thing through the new shape.

**Files:**
- Create: `backend/src/Repository/RefreshTarget.php` (beside `NetworkScope`; persistence must not import a service)
- Create: `backend/tests/Repository/RefreshTargetTest.php`
- Modify: `backend/src/Repository/DueFeedCriteria.php`, `backend/src/Repository/FeedRepository.php`
- Modify: `backend/src/Service/Refresh/Model/RefreshRequestModel.php`, `backend/src/Service/Refresh/RefreshRunner/RefreshRunner.php`, `backend/src/Service/Refresh/RefreshRunStore.php`, `backend/src/Service/Queue/QueueDepths.php`
- Modify (tests): every `new DueFeedCriteria(` in `backend/tests/Repository/*` (`DueSocialFeedsTest`, `FeedRepositoryNeverFetchedScopeTest`, `FeedRepositoryTagScopeTest`, `FeedRepositoryUserFeedScopeTest`, `FeedRepositoryTest`, `FeedRepositoryNetworkScopeTest`, `FeedRepositoryFeedGroupScopeTest`), and every `$request->feedId|tagId|networkScope|feedGroup` read in `tests/Command/RefreshFeedsCommandRequestTest.php`, `tests/Service/Refresh/UserRefreshScopeTest.php`, `tests/Service/Refresh/Model/RefreshRequestModelTest.php` (find them with `grep -rn "new DueFeedCriteria\|request->\(feedId\|tagId\|networkScope\|feedGroup\)" tests`).

**Interfaces:**
- Consumes: Task 1's `restrictToNeverFetched` and its test.
- Produces:
  - `App\Repository\RefreshTarget` — `final readonly`, private constructor, public readonly `?int $feedId`, `?int $tagId`, `?NetworkScope $networkScope`, `?FeedGroup $feedGroup`, `bool $neverFetched`; factories `everyFeed()`, `feed(int $feedId)`, `tag(int $tagId)`, `network(NetworkScope $networkScope)`, `feedGroup(FeedGroup $feedGroup)`, `neverFetchedFeeds()`; `runKey(): string`.
  - `DueFeedCriteria::__construct(\DateTimeImmutable $now, RefreshTarget $target, ?int $userId = null, bool $force = false, ?\DateTimeImmutable $cooldownCutoff = null, array $excludedFeedIds = [])` — the five scope fields are gone.
  - `RefreshRequestModel` fields: `?int $userId`, `RefreshTarget $target`, `bool $force`, `int $budgetSeconds`, `bool $prune`, `int $batchLimit` — the four scope fields are gone; every existing public factory keeps its signature.

- [ ] **Step 1: Write the failing test** — `backend/tests/Repository/RefreshTargetTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Enum\FeedGroup;
use App\Enum\SocialNetwork;
use App\Repository\NetworkScope;
use App\Repository\RefreshTarget;
use PHPUnit\Framework\TestCase;

final class RefreshTargetTest extends TestCase
{
    public function testEveryFeedNarrowsNothing(): void
    {
        $target = RefreshTarget::everyFeed();

        self::assertNull($target->feedId);
        self::assertNull($target->tagId);
        self::assertNull($target->networkScope);
        self::assertNull($target->feedGroup);
        self::assertFalse($target->neverFetched);
        self::assertSame('all', $target->runKey());
    }

    public function testEachTargetNamesItsOwnRun(): void
    {
        self::assertSame('feed-7', RefreshTarget::feed(7)->runKey());
        self::assertSame('tag-7', RefreshTarget::tag(7)->runKey());
        self::assertSame('group-tags', RefreshTarget::feedGroup(FeedGroup::Tags)->runKey());
        self::assertSame('never-fetched', RefreshTarget::neverFetchedFeeds()->runKey());
        $networkScope = NetworkScope::whole(SocialNetwork::Mastodon);
        self::assertSame(
            'set-network-' . $networkScope->toRunKey(),
            RefreshTarget::network($networkScope)->runKey(),
        );
    }

    public function testAFactorySetsOnlyItsOwnField(): void
    {
        $target = RefreshTarget::tag(3);

        self::assertSame(3, $target->tagId);
        self::assertNull($target->feedId);
        self::assertTrue(RefreshTarget::neverFetchedFeeds()->neverFetched);
        self::assertNull(RefreshTarget::neverFetchedFeeds()->tagId);
    }
}
```

The run keys are the exact strings `RefreshRunStore::scopeOf` produces today (read it before writing `runKey()`); cached runs keyed by them must keep resolving.

- [ ] **Step 2: Run it to see it fail** — `php bin/phpunit tests/Repository/RefreshTargetTest.php`; expected: class `RefreshTarget` not found.

- [ ] **Step 3: Create `RefreshTarget`**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\FeedGroup;

final readonly class RefreshTarget
{
    private function __construct(
        public ?int $feedId = null,
        public ?int $tagId = null,
        public ?NetworkScope $networkScope = null,
        public ?FeedGroup $feedGroup = null,
        public bool $neverFetched = false,
    ) {
    }

    public static function everyFeed(): self
    {
        return new self();
    }

    public static function feed(int $feedId): self
    {
        return new self(feedId: $feedId);
    }

    public static function tag(int $tagId): self
    {
        return new self(tagId: $tagId);
    }

    public static function network(NetworkScope $networkScope): self
    {
        return new self(networkScope: $networkScope);
    }

    public static function feedGroup(FeedGroup $feedGroup): self
    {
        return new self(feedGroup: $feedGroup);
    }

    public static function neverFetchedFeeds(): self
    {
        return new self(neverFetched: true);
    }

    public function runKey(): string
    {
        return match (true) {
            null !== $this->feedId => 'feed-' . $this->feedId,
            null !== $this->tagId => 'tag-' . $this->tagId,
            null !== $this->networkScope => 'set-network-' . $this->networkScope->toRunKey(),
            null !== $this->feedGroup => 'group-' . $this->feedGroup->value,
            $this->neverFetched => 'never-fetched',
            default => 'all',
        };
    }
}
```

Run the test: PASS.

- [ ] **Step 4: Switch `DueFeedCriteria` and `FeedRepository` to the target**

`DueFeedCriteria` becomes `(\DateTimeImmutable $now, RefreshTarget $target, ?int $userId = null, bool $force = false, ?\DateTimeImmutable $cooldownCutoff = null, array $excludedFeedIds = [])`; `excluding()` passes `$this->target` through. Update its class docblock: "`$feedId` picks one feed" becomes "a feed target picks one feed".

In `FeedRepository`, every `$criteria->feedId|tagId|networkScope|feedGroup|neverFetched` becomes `$criteria->target->…`. Where a narrowed value is passed on (e.g. `NetworkScopeDql::restrict(..., $criteria->networkScope)`), read it into a local first if PHPStan cannot see the null-guard through the property chain.

`QueueDepths`: `new DueFeedCriteria($this->clock->now(), RefreshTarget::everyFeed())`.

Update every test construction: `new DueFeedCriteria($now, $userId, force: true, feedGroup: $group)` becomes `new DueFeedCriteria($now, RefreshTarget::feedGroup($group), $userId, force: true)`; a construction with no scope field gets `RefreshTarget::everyFeed()`.

- [ ] **Step 5: Switch `RefreshRequestModel` to the target**

Private constructor `(?int $userId, RefreshTarget $target, bool $force, int $budgetSeconds, bool $prune, int $batchLimit = self::DEFAULT_BATCH_LIMIT)`. Factories keep their public signatures and build the target: `allDue` and `forUser` → `RefreshTarget::everyFeed()`, `forUserTag` → `tag($tagId)`, `forUserNetwork` → `network(...)`, `forUserFeedGroup` → `feedGroup(...)`, `forFeed` and `forUserFeed` → `feed($feedId)`. `withoutPruning()`, `ignoringSchedule()`, `limitedTo()` pass `$this->target` through.

`RefreshRunner::dueCriteria`: `new DueFeedCriteria($now, $request->target, $request->userId, $request->force, $cooldownCutoff)`.

`RefreshRunStore`: delete `scopeOf()`; `keyFor()` uses `$request->target->runKey()`.

Test reads `$request->feedId` etc. become `$request->target->feedId` etc.

- [ ] **Step 6: Verify** — `php bin/phpunit tests/Repository tests/Service/Refresh tests/Service/Queue tests/Command tests/Controller/Api/RefreshControllerTest.php`, then `composer cs`, PHPStan on the touched files, and PHPMD on every touched `src` file: `DueFeedCriteria` and `RefreshRequestModel` must now be clean. `grep -rn "scopeOf" src tests` returns nothing.

- [ ] **Step 7: Commit**

```bash
git add backend/src backend/tests
git commit -m "refactor(#2106): fold the refresh scopes into one RefreshTarget"
```

### Task 3: `POST /api/refresh?neverFetched=1`

**Files:**
- Modify: `backend/src/Service/Refresh/Model/RefreshRequestModel.php`
- Modify: `backend/src/Service/Refresh/UserRefreshScope.php`
- Modify: `backend/src/Controller/Api/RefreshController.php`
- Test: `backend/tests/Service/Refresh/UserRefreshScopeTest.php`, `backend/tests/Service/Refresh/RefreshRunStoreTest.php`, `backend/tests/Controller/Api/RefreshControllerTest.php`

**Interfaces:**
- Consumes: `RefreshTarget::neverFetchedFeeds()` and the `RefreshRequestModel` shape from Task 2 (`$request->target`).
- Produces: `RefreshRequestModel::forUserNeverFetched(int $userId, int $budgetSeconds): self`; `UserRefreshScope::requestForNeverFetched(int $userId): RefreshRequestModel`; query parameter `neverFetched` (boolean, `1`).

- [ ] **Step 1: Write the failing tests**

`UserRefreshScopeTest`, next to the feed-group test:

```php
    public function testANeverFetchedRefreshIsAForcedRunOverTheUsersUnfetchedFeeds(): void
    {
        $request = $this->scope()->requestForNeverFetched($this->user->requireId());

        self::assertSame($this->user->requireId(), $request->userId);
        self::assertEquals(RefreshTarget::neverFetchedFeeds(), $request->target);
        self::assertTrue($request->force);
        self::assertSame(25, $request->budgetSeconds);
    }
```

`RefreshRunStoreTest`, next to the feed-group runs test:

```php
    public function testANeverFetchedRunIsKeptApartFromTheSweep(): void
    {
        $neverFetched = RefreshRequestModel::forUserNeverFetched(1, self::BUDGET);
        $this->store->save($neverFetched, RefreshRunProgressModel::start()->advancedBy(1, 2));

        self::assertSame(0, $this->store->open(RefreshRequestModel::forUser(1, self::BUDGET))->done);
        self::assertSame(1, $this->store->open($neverFetched)->done);
    }
```

`RefreshControllerTest`, after `testFeedGroupRefreshSweepsOnlyThatGroupsFeeds`:

```php
    public function testNeverFetchedRefreshSweepsOnlyFeedsNoFetchHasStamped(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->factory()->create('never-fetched-refresh@example.com');
        $fixtures = new NetworkFeedSubscriptions($entityManager);
        $fixtures->subscribe($user, 'https://example.com/fresh.xml');
        $fixtures->subscribe($user, NetworkFeedSubscriptions::PLAIN)->getFeed()
            ->recordSuccessfulFetch(new \DateTimeImmutable('-1 day'), 60);
        $entityManager->flush();

        $passes = new StubPassFetcher();
        foreach ([NetworkFeedSubscriptions::PLAIN, 'https://example.com/fresh.xml'] as $feedUrl) {
            $passes->willReturn($feedUrl, FetchResponseModel::notModified($feedUrl, false, null, null));
        }
        RefreshFetchers::install(self::getContainer(), $passes, 'https://example.com');

        $client->request('POST', '/api/refresh?neverFetched=1', server: $this->bearerFor($user));

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertReportsARunOfOneFeed($body);
        self::assertIsArray($body);
        self::assertSame(0, $body['remaining'], 'the already-fetched feed is never due');
    }
```

The PLAIN feed was fetched a day ago, past the 5-minute force cooldown (`RefreshRunner::COOLDOWN_MINUTES`), so only the scope keeps it out.

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit --filter 'NeverFetched'`
Expected: undefined method `requestForNeverFetched` / `forUserNeverFetched`; the controller test sees both feeds (`remaining` 1 or progress total 2).

- [ ] **Step 3: Implement**

`RefreshRequestModel`: add, next to `forUserFeedGroup`,

```php
    public static function forUserNeverFetched(int $userId, int $budgetSeconds): self
    {
        return new self($userId, RefreshTarget::neverFetchedFeeds(), true, $budgetSeconds, false);
    }
```

`RefreshRunner` and `RefreshRunStore` need no change: Task 2 already threads `$request->target` to the criteria and keys runs by `runKey()` (`never-fetched`).

`UserRefreshScope`:

```php
    public function requestForNeverFetched(int $userId): RefreshRequestModel
    {
        return RefreshRequestModel::forUserNeverFetched($userId, self::BUDGET_SECONDS);
    }
```

`RefreshController::__invoke`: add `#[MapQueryParameter] bool $neverFetched = false,` as the last parameter, the docblock's list of narrowing parameters gains `?neverFetched=1`, and the `match` gains an arm before `default`:

```php
            $neverFetched => $this->scope->requestForNeverFetched($user->requireId()),
```

- [ ] **Step 4: Run them to see them pass**, then the whole refresh area: `php bin/phpunit tests/Service/Refresh tests/Controller/Api/RefreshControllerTest.php tests/Repository`.

- [ ] **Step 5: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#2106): add a never-fetched scope to the refresh endpoint"
```

### Task 4: Imports refresh only never-fetched feeds

**Files:**
- Modify: `frontend/src/app/reader/query/query.ts` (`RefreshScope`)
- Modify: `frontend/src/app/reader/reader-api.ts` (`refresh`)
- Modify: `frontend/src/app/reader/feeds/social-picker/social-import-dialog.component.ts` (`finish`)
- Modify: `frontend/src/app/settings/import/opml-section.component.ts` (import `next`)
- Test: `frontend/src/app/reader/reader-api.spec.ts`, `frontend/src/app/reader/feeds/social-picker/social-import-dialog.component.spec.ts`, `frontend/src/app/settings/import/opml-section.component.spec.ts`

**Interfaces:**
- Consumes: query parameter `neverFetched=1` from Task 3.
- Produces: `RefreshScope.neverFetched?: true`.

- [ ] **Step 1: Write the failing tests**

`reader-api.spec.ts`, beside the group test:

```ts
  it('scopes refresh to never-fetched feeds when asked', () => {
    api.refresh({ neverFetched: true }).subscribe();
    const testRequest = ctrl.expectOne((request) => request.url === 'https://api.test/api/refresh');
    expect(testRequest.request.params.get('neverFetched')).toBe('1');
    testRequest.flush(refreshReport());
  });
```

`social-import-dialog.component.spec.ts`: in the three places that assert `expect(refreshStub.run).toHaveBeenCalled()` (lines ~278, ~497, ~577), assert the scope instead:

```ts
    expect(refreshStub.run).toHaveBeenCalledWith(expect.any(Function), { neverFetched: true });
```

`opml-section.component.spec.ts` drives the real `RefreshService` through `HttpTestingController`. In `'imports pasted OPML, shows the result, reloads, and refreshes the new feeds'` (line ~72), `expectOne('https://api.test/api/refresh')` matches the URL with its parameters, so replace it and drop the comment above it:

```ts
    const refresh = ctrl.expectOne((request) => request.url === 'https://api.test/api/refresh');
    expect(refresh.request.method).toBe('POST');
    expect(refresh.request.params.get('neverFetched')).toBe('1');
```

In `'does not refresh when nothing new was imported'` (line ~84), make `expectNone` take the same predicate, or it would pass against a parameterised request.

- [ ] **Step 2: Run them to see them fail**

Run: `docker compose exec -T frontend npm test -- reader-api social-import-dialog opml-section`

- [ ] **Step 3: Implement**

`RefreshScope`: add `neverFetched?: true;`.

`ReaderApi.refresh`: one more branch at the end of the chain:

```ts
    } else if (scope?.group != null) params = params.set('group', scope.group);
    else if (scope?.neverFetched) params = params.set('neverFetched', '1');
```

and the docblock gains "or to the caller's never-fetched feeds (a just-imported batch)".

`SocialImportDialogComponent.finish`: `this.refresh.run(() => this.subscriptions.load(), { neverFetched: true });`

`OpmlSectionComponent`: `this.refresh.run(() => this.subs.load(), { neverFetched: true });` — and drop the three-line comment above it, which narrates what the call already says.

- [ ] **Step 4: Run them to see them pass**, then `docker compose exec -T frontend npm run check`.

- [ ] **Step 5: Commit**

```bash
git add frontend/src
git commit -m "fix(#2106): refresh only never-fetched feeds after a social or OPML import"
```

### Task 5: Gates

- [ ] `composer check`, `composer md` (touched files clean), `php bin/phpunit` and `docker compose exec php composer test:parallel` in parallel.
- [ ] `composer infection:diff`.
- [ ] Scan today's dev log.
- [ ] Live check on :4200: add a social search; the network tab shows `POST /api/refresh?neverFetched=1` and the progress covers one feed.
