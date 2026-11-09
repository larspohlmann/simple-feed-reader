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

### Task 2: `POST /api/refresh?neverFetched=1`

**Files:**
- Modify: `backend/src/Service/Refresh/Model/RefreshRequestModel.php`
- Modify: `backend/src/Service/Refresh/RefreshRunner/RefreshRunner.php` (`dueCriteria`)
- Modify: `backend/src/Service/Refresh/RefreshRunStore.php` (`scopeOf`)
- Modify: `backend/src/Service/Refresh/UserRefreshScope.php`
- Modify: `backend/src/Controller/Api/RefreshController.php`
- Test: `backend/tests/Service/Refresh/UserRefreshScopeTest.php`, `backend/tests/Service/Refresh/RefreshRunStoreTest.php`, `backend/tests/Controller/Api/RefreshControllerTest.php`

**Interfaces:**
- Consumes: `DueFeedCriteria(..., neverFetched: bool)` from Task 1.
- Produces: `RefreshRequestModel::forUserNeverFetched(int $userId, int $budgetSeconds): self` and `public bool $neverFetched` on the model; `UserRefreshScope::requestForNeverFetched(int $userId): RefreshRequestModel`; run key suffix `never-fetched`; query parameter `neverFetched` (boolean, `1`).

- [ ] **Step 1: Write the failing tests**

`UserRefreshScopeTest`, next to the feed-group test:

```php
    public function testANeverFetchedRefreshIsAForcedRunOverTheUsersUnfetchedFeeds(): void
    {
        $request = $this->scope()->requestForNeverFetched($this->user->requireId());

        self::assertSame($this->user->requireId(), $request->userId);
        self::assertTrue($request->neverFetched);
        self::assertNull($request->feedGroup);
        self::assertNull($request->networkScope);
        self::assertNull($request->feedId);
        self::assertNull($request->tagId);
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

`RefreshRequestModel`: add `public bool $neverFetched = false,` as the last private-constructor parameter; pass `$this->neverFetched` through `withoutPruning()`, `ignoringSchedule()` and `limitedTo()`; add

```php
    public static function forUserNeverFetched(int $userId, int $budgetSeconds): self
    {
        return new self($userId, null, null, true, $budgetSeconds, false, neverFetched: true);
    }
```

`RefreshRunner::dueCriteria`: add `neverFetched: $request->neverFetched,` to the `DueFeedCriteria` call.

`RefreshRunStore::scopeOf`: before the final `return 'all';`

```php
        if ($request->neverFetched) {
            return 'never-fetched';
        }
```

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

### Task 3: Imports refresh only never-fetched feeds

**Files:**
- Modify: `frontend/src/app/reader/query/query.ts` (`RefreshScope`)
- Modify: `frontend/src/app/reader/reader-api.ts` (`refresh`)
- Modify: `frontend/src/app/reader/feeds/social-picker/social-import-dialog.component.ts` (`finish`)
- Modify: `frontend/src/app/settings/import/opml-section.component.ts` (import `next`)
- Test: `frontend/src/app/reader/reader-api.spec.ts`, `frontend/src/app/reader/feeds/social-picker/social-import-dialog.component.spec.ts`, `frontend/src/app/settings/import/opml-section.component.spec.ts`

**Interfaces:**
- Consumes: query parameter `neverFetched=1` from Task 2.
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

### Task 4: Gates

- [ ] `composer check`, `composer md` (touched files clean), `php bin/phpunit` and `docker compose exec php composer test:parallel` in parallel.
- [ ] `composer infection:diff`.
- [ ] Scan today's dev log.
- [ ] Live check on :4200: add a social search; the network tab shows `POST /api/refresh?neverFetched=1` and the progress covers one feed.
