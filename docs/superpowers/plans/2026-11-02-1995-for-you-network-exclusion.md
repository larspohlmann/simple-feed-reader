# Exclude social networks from For you (#1995) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user switch a whole social network (Bluesky, Mastodon) out of For you, the way #1742 lets them switch one into saved searches.

**Architecture:** A `for_you_network_exclusion` row (user, network) excludes a network; no row means included, so nothing changes on deploy. One DQL predicate, `EntryScopePredicates::inForYou()`, replaces the bare `includeInForYou = true` check in the scoring candidates and in the For you list/count/mark-read query, so the switch keeps an excluded network out of model spend and out of the list at once. A small `ForYouController` serves `GET/PUT/DELETE /api/for-you/excluded-networks`; a root `ForYouNetworksStore` on the frontend holds the set and drives the network ⋯ item and the heading's "Hidden from …" marker.

**Tech Stack:** Symfony 7.4 / Doctrine ORM (PHP 8.4), PHPUnit 12; Angular 20 (signals), Jest in the Docker `frontend` container.

**Spec:** issue #1995 (body is the agreed design). Reference implementation to mirror: commit `f157e9c95` (#1742, the saved-search network switch).

## Global Constraints

- Branch `feature/1995-for-you-network-exclusion` (exists). Commit format `feat(#1995): …`, `test(#1995): …`, `refactor(#1995): …`, `docs(#1995): …`. No Claude attribution anywhere.
- Default: **every network is included in For you**. A row excludes. Never the other way round.
- The network switch wins over the per-feed `include_in_for_you`; each feed keeps its own flag.
- Clean Code rules in `CLAUDE.md` apply: `final readonly`, no comments unless a future reader would get the code wrong without one (one line), names spelled out, queries only in `src/Repository`, controllers thin (no private methods, no entity construction), `Response::HTTP_*` constants never magic numbers, `declare(strict_types=1)`.
- Test fixtures for social feeds use real network URLs (`https://bsky.app/profile/<handle>/rss`, `https://mastodon.example.com/@a.rss`): `FeedNetworkListener` reclassifies a feed's network from its URL on flush, so `classifyAs()` alone is undone.
- Backend tests: `php bin/phpunit <path>` from `backend/` (SQLite). Frontend tests: from repo root `docker compose exec -T frontend npx jest <paths>`; never two Jest runs at once.
- `npm run check` misses template errors: after a template change also run `docker compose exec -T frontend npx ng build`.
- Every `src` file touched must be PHPMD-clean (`composer md`).

## File map

| File | Change |
|---|---|
| `backend/src/Entity/ForYouNetworkExclusion.php` | new entity |
| `backend/src/Repository/ForYouNetworkExclusionRepository.php` | new: `excludedNetworksFor()`, `findOneFor()` |
| `backend/migrations/Version20261102130000.php` | new table, MySQL + SQLite |
| `backend/src/Service/Recommendation/Feed/ForYouNetworks.php` | new: `exclude()`, `include()` |
| `backend/src/Http/SocialNetworksJson.php` | new: `values()`; replaces `SavedSearchJson::networks()` |
| `backend/src/Http/SavedSearchJson.php`, `Controller/Api/SavedSearchController.php` | use `SocialNetworksJson::values()` |
| `backend/src/Controller/Api/ForYouController.php` | new: GET/PUT/DELETE `/api/for-you/excluded-networks` |
| `backend/src/Repository/EntryScopePredicates.php` | new `inForYou()` |
| `backend/src/Repository/RecommendationCandidateRepository.php` | `inForYou()` in `poolScope()` and `candidateQueryBuilder()` |
| `backend/src/Repository/RecommendationItemRepository.php` | `inForYou()` in `applyForYouCriteria()` |
| `backend/src/Repository/AccountWipeRepository.php` | wipes the new rows |
| `backend/src/Service/Backup/{AccountBackupExporter,BackupLines}.php`, `Dto/AccountLine.php`, `Pass/RestoreLoadPass.php` | `forYouExcludedNetworks` on the account line |
| `backend/tests/Support/{BackupFieldDeclarations,FullyPopulatedAccount}.php`, `tests/Service/Backup/Support/BackupSchemaCoverageTest.php`, `tests/Fixtures/backup/current/000-foundation.ndjson` | backup coverage |
| `docs/backup.md` | account line row + owner paragraph |
| `frontend/src/app/reader/reader-api.ts` | three calls |
| `frontend/src/app/reader/state/for-you-networks.store.ts` (+ spec) | new store |
| `frontend/src/app/reader/shell/sidebar/sidebar-row-actions.service.ts` (+ spec) | For you item in sheet + menu |
| `frontend/src/app/reader/shell/sidebar/sidebar-social-section.component.{ts,html}` (+ spec) | menu item; marker lists both surfaces |
| `frontend/src/app/reader/state/same-members.ts` | `sameMembers` moved out of `saved-searches.store.ts` |
| `frontend/src/app/reader/shell/reader-shell.component.ts` (+ spec) | loads the store at boot |

---

### Task 1: Exclusion table, service and endpoints

**Files:**
- Create: `backend/src/Entity/ForYouNetworkExclusion.php`, `backend/src/Repository/ForYouNetworkExclusionRepository.php`, `backend/migrations/Version20261102130000.php`, `backend/src/Service/Recommendation/Feed/ForYouNetworks.php`, `backend/src/Http/SocialNetworksJson.php`, `backend/src/Controller/Api/ForYouController.php`
- Modify: `backend/src/Http/SavedSearchJson.php` (delete `networks()`), `backend/src/Controller/Api/SavedSearchController.php:51`
- Test: `backend/tests/Service/Recommendation/Feed/ForYouNetworksTest.php`, `backend/tests/Controller/Api/ForYouNetworksControllerTest.php`, `backend/tests/Http/SocialNetworksJsonTest.php`

**Interfaces:**
- Produces: `App\Entity\ForYouNetworkExclusion(User $user, SocialNetwork $network)` with `getId()`, `getUser()`, `getNetwork()`; `ForYouNetworkExclusionRepository::excludedNetworksFor(int $userId): list<SocialNetwork>` (ordered by network ASC), `::findOneFor(int $userId, SocialNetwork $network): ?ForYouNetworkExclusion`; `ForYouNetworks::exclude(User, SocialNetwork): void`, `::include(User, SocialNetwork): void` (both idempotent); `SocialNetworksJson::values(list<SocialNetwork>): list<string>`; HTTP `GET /api/for-you/excluded-networks` → `{"excludedNetworks": ["bluesky"]}`, `PUT|DELETE /api/for-you/excluded-networks/{network}` → 204, unknown network → 404.

- [ ] **Step 1: Write the failing service test**

`backend/tests/Service/Recommendation/Feed/ForYouNetworksTest.php` — a copy of `tests/Service/Search/SavedSearchNetworksTest.php` with the polarity flipped:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Feed;

use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\ForYouNetworkExclusionRepository;
use App\Service\Recommendation\Feed\ForYouNetworks;
use App\Tests\DbTestCase;

final class ForYouNetworksTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('for-you-networks@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    public function testEveryNetworkStartsIncluded(): void
    {
        self::assertSame([], $this->exclusions()->excludedNetworksFor($this->user->requireId()));
    }

    public function testExcludingTwiceStoresOneRowAndIncludingRemovesIt(): void
    {
        $this->networks()->exclude($this->user, SocialNetwork::Bluesky);
        $this->networks()->exclude($this->user, SocialNetwork::Bluesky);
        self::assertSame(
            [SocialNetwork::Bluesky],
            $this->exclusions()->excludedNetworksFor($this->user->requireId()),
        );

        $this->networks()->include($this->user, SocialNetwork::Bluesky);
        $this->networks()->include($this->user, SocialNetwork::Bluesky);
        self::assertSame([], $this->exclusions()->excludedNetworksFor($this->user->requireId()));
    }

    public function testExcludedNetworksAreListedInNetworkOrder(): void
    {
        $this->networks()->exclude($this->user, SocialNetwork::Mastodon);
        $this->networks()->exclude($this->user, SocialNetwork::Bluesky);

        self::assertSame(
            [SocialNetwork::Bluesky, SocialNetwork::Mastodon],
            $this->exclusions()->excludedNetworksFor($this->user->requireId()),
        );
    }

    public function testAnotherUsersExclusionIsNeitherListedNorFound(): void
    {
        $other = new User('other-for-you-networks@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $this->networks()->exclude($other, SocialNetwork::Bluesky);
        $this->networks()->exclude($this->user, SocialNetwork::Mastodon);

        $userId = $this->user->requireId();

        self::assertSame([SocialNetwork::Mastodon], $this->exclusions()->excludedNetworksFor($userId));
        self::assertNull($this->exclusions()->findOneFor($userId, SocialNetwork::Bluesky));
        self::assertNotNull($this->exclusions()->findOneFor($userId, SocialNetwork::Mastodon));
    }

    private function networks(): ForYouNetworks
    {
        $service = self::getContainer()->get(ForYouNetworks::class);
        self::assertInstanceOf(ForYouNetworks::class, $service);

        return $service;
    }

    private function exclusions(): ForYouNetworkExclusionRepository
    {
        $repository = self::getContainer()->get(ForYouNetworkExclusionRepository::class);
        self::assertInstanceOf(ForYouNetworkExclusionRepository::class, $repository);

        return $repository;
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (`Class "App\Repository\ForYouNetworkExclusionRepository" not found`)

Run: `cd backend && php bin/phpunit tests/Service/Recommendation/Feed/ForYouNetworksTest.php`

- [ ] **Step 3: Entity, repository, service**

`backend/src/Entity/ForYouNetworkExclusion.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SocialNetwork;
use App\Repository\ForYouNetworkExclusionRepository;
use Doctrine\ORM\Mapping as ORM;

/** A social network whose feeds the user keeps out of For you; a network without a row counts (#1995). */
#[ORM\Entity(repositoryClass: ForYouNetworkExclusionRepository::class)]
#[ORM\Table(name: 'for_you_network_exclusion')]
#[ORM\Index(name: 'idx_for_you_network_exclusion_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_for_you_network_exclusion', columns: ['user_id', 'network'])]
final class ForYouNetworkExclusion
{
    use PersistedId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: SocialNetwork::class)]
    private SocialNetwork $network;

    public function __construct(User $user, SocialNetwork $network)
    {
        $this->user = $user;
        $this->network = $network;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getNetwork(): SocialNetwork
    {
        return $this->network;
    }
}
```

`backend/src/Repository/ForYouNetworkExclusionRepository.php`: same body as `SavedSearchNetworkInclusionRepository` with `ForYouNetworkExclusion`, alias `exclusion`, method `excludedNetworksFor(int $userId): array` (`@return list<SocialNetwork>`), and `findOneFor(int $userId, SocialNetwork $network): ?ForYouNetworkExclusion`.

`backend/src/Service/Recommendation/Feed/ForYouNetworks.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed;

use App\Entity\ForYouNetworkExclusion;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\ForYouNetworkExclusionRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ForYouNetworks
{
    public function __construct(
        private ForYouNetworkExclusionRepository $exclusions,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function exclude(User $user, SocialNetwork $network): void
    {
        if (null !== $this->exclusions->findOneFor($user->requireId(), $network)) {
            return;
        }
        $this->entityManager->persist(new ForYouNetworkExclusion($user, $network));
        $this->entityManager->flush();
    }

    public function include(User $user, SocialNetwork $network): void
    {
        $exclusion = $this->exclusions->findOneFor($user->requireId(), $network);
        if (null === $exclusion) {
            return;
        }
        $this->entityManager->remove($exclusion);
        $this->entityManager->flush();
    }
}
```

- [ ] **Step 4: Migration**

`backend/migrations/Version20261102130000.php` — shape of `Version20261022120000.php` (non-transactional, `abortIfUnsupportedPlatform()`, platform split), but only the table:

- `getDescription()`: `'Keep social networks out of For you: for_you_network_exclusion (#1995).'`
- `up()`: `$this->skipIf($schema->hasTable('for_you_network_exclusion'), 'Already applied.');`
- MySQL:
  ```sql
  CREATE TABLE for_you_network_exclusion (id INT AUTO_INCREMENT NOT NULL, network VARCHAR(16) NOT NULL, user_id INT NOT NULL, INDEX idx_for_you_network_exclusion_user (user_id), UNIQUE INDEX uniq_for_you_network_exclusion (user_id, network), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4
  ALTER TABLE for_you_network_exclusion ADD CONSTRAINT fk_for_you_network_exclusion_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE
  ```
- SQLite:
  ```sql
  CREATE TABLE for_you_network_exclusion (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, network VARCHAR(16) NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT fk_for_you_network_exclusion_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)
  CREATE INDEX idx_for_you_network_exclusion_user ON for_you_network_exclusion (user_id)
  CREATE UNIQUE INDEX uniq_for_you_network_exclusion ON for_you_network_exclusion (user_id, network)
  ```
- `down()`: `skipIf(!$schema->hasTable(...))`, `DROP TABLE for_you_network_exclusion`.

- [ ] **Step 5: Run the service test — expect PASS**

- [ ] **Step 6: Failing controller + JSON tests**

`backend/tests/Http/SocialNetworksJsonTest.php`:

```php
public function testEachNetworkBecomesItsWireValueInOrder(): void
{
    self::assertSame(
        ['bluesky', 'mastodon'],
        SocialNetworksJson::values([SocialNetwork::Bluesky, SocialNetwork::Mastodon]),
    );
}
```

`backend/tests/Controller/Api/ForYouNetworksControllerTest.php` — shape of `SavedSearchNetworksControllerTest`:

```php
public function testANetworkIsIncludedUntilExcludedAndTheListReportsIt(): void
{
    self::assertSame([], $this->excludedNetworks());

    $this->client->request('PUT', '/api/for-you/excluded-networks/bluesky', server: $this->headers);
    self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    self::assertSame(['bluesky'], $this->excludedNetworks());

    $this->client->request('DELETE', '/api/for-you/excluded-networks/bluesky', server: $this->headers);
    self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    self::assertSame([], $this->excludedNetworks());
}

public function testAnUnknownNetworkIsNotFound(): void
{
    $this->client->request('PUT', '/api/for-you/excluded-networks/myspace', server: $this->headers);

    self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
}

public function testTheListNeedsALogin(): void
{
    $this->client->request('GET', '/api/for-you/excluded-networks');

    self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
}

/** @return list<string> */
private function excludedNetworks(): array
{
    $this->client->request('GET', '/api/for-you/excluded-networks', server: $this->headers);
    self::assertResponseIsSuccessful();

    /** @var list<string> $networks */
    $networks = $this->payload($this->client)['excludedNetworks'];

    return $networks;
}
```

Run both — expect FAIL (class / route missing).

- [ ] **Step 7: JSON mapper, controller, saved-search call site**

`backend/src/Http/SocialNetworksJson.php`:

```php
final class SocialNetworksJson
{
    /**
     * @param list<SocialNetwork> $networks
     *
     * @return list<string>
     */
    public static function values(array $networks): array
    {
        return array_map(static fn (SocialNetwork $network): string => $network->value, $networks);
    }
}
```

Delete `SavedSearchJson::networks()` and its `SocialNetwork` import; `SavedSearchController::list()` calls `SocialNetworksJson::values(...)` instead (this is the DRY home both controllers share).

`backend/src/Controller/Api/ForYouController.php`:

```php
#[Route('/api/for-you/excluded-networks')]
final readonly class ForYouController
{
    public function __construct(
        private ForYouNetworkExclusionRepository $excludedNetworks,
        private ForYouNetworks $networks,
    ) {
    }

    #[Route('', name: 'api_for_you_excluded_networks', methods: ['GET'])]
    public function excludedNetworks(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse([
            'excludedNetworks' => SocialNetworksJson::values(
                $this->excludedNetworks->excludedNetworksFor($user->requireId()),
            ),
        ]);
    }

    #[Route('/{network}', name: 'api_for_you_exclude_network', methods: ['PUT'])]
    public function excludeNetwork(SocialNetwork $network, #[CurrentUser] User $user): JsonResponse
    {
        $this->networks->exclude($user, $network);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{network}', name: 'api_for_you_include_network', methods: ['DELETE'])]
    public function includeNetwork(SocialNetwork $network, #[CurrentUser] User $user): JsonResponse
    {
        $this->networks->include($user, $network);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
```

Note the polarity: `PUT` on the *excluded* collection excludes; `DELETE` includes. Check `config/packages/security.yaml` covers `^/api` (it does for every other `/api/*` route); the 401 test proves it.

- [ ] **Step 8: Run all three tests + `SavedSearchNetworksControllerTest` — expect PASS**

- [ ] **Step 9: Apply the migration to the Docker dev DB** — `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`, then `docker compose exec php bin/console doctrine:schema:validate`. Expect "in sync". (The from-empty SQLite check runs in Task 5.)

- [ ] **Step 10: Gates on touched files, commit**

```bash
cd backend && composer cs && composer stan && composer md
git add backend/src/Entity/ForYouNetworkExclusion.php backend/src/Repository/ForYouNetworkExclusionRepository.php backend/migrations/Version20261102130000.php backend/src/Service/Recommendation/Feed/ForYouNetworks.php backend/src/Http/SocialNetworksJson.php backend/src/Http/SavedSearchJson.php backend/src/Controller/Api/ForYouController.php backend/src/Controller/Api/SavedSearchController.php backend/tests/Service/Recommendation/Feed/ForYouNetworksTest.php backend/tests/Controller/Api/ForYouNetworksControllerTest.php backend/tests/Http/SocialNetworksJsonTest.php
git commit -m "feat(#1995): a social network can be switched out of For you"
```

---

### Task 2: For you reads honour the network switch

**Files:**
- Modify: `backend/src/Repository/EntryScopePredicates.php`, `backend/src/Repository/RecommendationCandidateRepository.php:79-101`, `backend/src/Repository/RecommendationItemRepository.php:121-133` (+ its constructor)
- Test: `backend/tests/Repository/ForYouExclusionReadsTest.php` (new)

**Interfaces:**
- Consumes: `ForYouNetworkExclusion` (Task 1).
- Produces: `EntryScopePredicates::inForYou(QueryBuilder $qb, EntryAliases $aliases): void` — adds `<subscription>.includeInForYou = true AND NOT EXISTS (… excluded network of <entry>.feed for :user …)`. Binds nothing; relies on the shared `:user` (both callers bind it).

- [ ] **Step 1: Write the failing read test**

`backend/tests/Repository/ForYouExclusionReadsTest.php` — modelled on `SavedSearchExclusionReadsTest` (copy its `subscribedFeed(url, ?SocialNetwork)` / `subscriptionOf()` helpers, and an entry helper taking an optional `urlHash` like its `memberOf()`, minus the saved-search membership). Fixture: one user, one plain feed with entry `kept`, all entries dated `2026-08-01T00:00:00Z`; one completed `RecommendationRun` (build it as `RecommendationFeedTest::seedRun()` does) with a `RecommendationItem` for every entry the test creates.

The assertion helper checks every read the switch must reach:

```php
private function assertOnlyTheKeptEntryIsSeen(): void
{
    $userId = $this->user->requireId();
    $keptId = $this->kept->requireId();

    self::assertSame([$keptId], $this->listedIds(), 'list');
    self::assertSame(1, $this->items()->countForYou($userId), 'count');
    self::assertSame(
        [$keptId],
        $this->items()->unreadEntryIdsForYou($userId, new \DateTimeImmutable('2026-12-31T00:00:00Z')),
        'mark-read',
    );
    self::assertSame(
        [$keptId],
        $this->entryIds($this->candidates()->newestPool($userId, new \DateTimeImmutable('2026-01-01T00:00:00Z'), 50)),
        'pool',
    );
    self::assertSame(
        [$keptId],
        $this->entryIds($this->candidates()->forIds($userId, $this->allEntryIds())),
        'snapshot',
    );
}
```

(`listedIds()` maps `listForYou(new ForYouFeedQuery($this->user, null, 50), null)` rows to `$row->row->entry->getId()`; `entryIds()` maps `TitledEntry` to its entry id — read `src/Repository/TitledEntry.php` for the accessor; `candidates()` fetches `RecommendationCandidateRepository` from the container, `items()` the `RecommendationItemRepository`.)

Tests:

```php
public function testASocialFeedIsInEveryReadWhileItsNetworkIsIncluded(): void
{
    $post = $this->recommended(
        $this->subscribedFeed('https://bsky.app/profile/alice.bsky.social/rss', SocialNetwork::Bluesky),
        'post',
    );

    self::assertEqualsCanonicalizing([$this->kept->getId(), $post->getId()], $this->listedIds());
}

public function testExcludingTheNetworkDropsItsFeedsFromEveryRead(): void
{
    $this->recommended(
        $this->subscribedFeed('https://bsky.app/profile/alice.bsky.social/rss', SocialNetwork::Bluesky),
        'post',
    );
    $this->exclude(SocialNetwork::Bluesky);

    $this->assertOnlyTheKeptEntryIsSeen();
}

public function testExcludingOneNetworkLeavesTheOtherIn(): void
{
    $this->exclude(SocialNetwork::Bluesky);
    $toot = $this->recommended(
        $this->subscribedFeed('https://mastodon.example.com/@a.rss', SocialNetwork::Mastodon),
        'toot',
    );

    self::assertEqualsCanonicalizing([$this->kept->getId(), $toot->getId()], $this->listedIds());
}

public function testTheFeedSwitchStillAppliesWhileItsNetworkIsIncluded(): void
{
    $feed = $this->subscribedFeed('https://bsky.app/profile/bob.bsky.social/rss', SocialNetwork::Bluesky);
    $this->subscriptionOf($feed)->setIncludeInForYou(false);
    $this->recommended($feed, 'post');

    $this->assertOnlyTheKeptEntryIsSeen();
}

public function testAnotherUsersExclusionLeavesThisUsersNetworkIn(): void
{
    $other = new User('other@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
    $this->entityManager->persist($other);
    $this->entityManager->persist(new ForYouNetworkExclusion($other, SocialNetwork::Bluesky));
    $post = $this->recommended(
        $this->subscribedFeed('https://bsky.app/profile/alice.bsky.social/rss', SocialNetwork::Bluesky),
        'post',
    );

    self::assertEqualsCanonicalizing([$this->kept->getId(), $post->getId()], $this->listedIds());
}

public function testAnExcludedNetworksLowestIdCopyDoesNotHideTheIncludedOneFromThePool(): void
{
    $this->exclude(SocialNetwork::Bluesky);
    $this->recommended(
        $this->subscribedFeed('https://bsky.app/profile/alice.bsky.social/rss', SocialNetwork::Bluesky),
        'original',
        urlHash: 'same',
    );
    $copy = $this->recommended($this->subscribedFeed('https://on.example.com/feed.xml'), 'copy', urlHash: 'same');

    self::assertEqualsCanonicalizing(
        [$this->kept->getId(), $copy->getId()],
        $this->entryIds($this->candidates()->newestPool(
            $this->user->requireId(),
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            50,
        )),
    );
}
```

`exclude()` persists a `ForYouNetworkExclusion` and flushes.

- [ ] **Step 2: Run — expect FAIL** on every exclusion test (the excluded post is still listed).

Run: `cd backend && php bin/phpunit tests/Repository/ForYouExclusionReadsTest.php`

- [ ] **Step 3: The predicate**

In `EntryScopePredicates`, beside `inSavedSearches()`:

```php
/** Binds only the shared :user, which the duplicate-collapse subquery relies on. */
public function inForYou(QueryBuilder $qb, EntryAliases $aliases): void
{
    $feed = 'forYouFeed' . ucfirst($aliases->entry);
    $exclusion = 'networkExclusion' . ucfirst($aliases->entry);

    $qb->andWhere(\sprintf(
        '%1$s.includeInForYou = true AND NOT EXISTS (SELECT 1 FROM %2$s %3$s WHERE %3$s = %4$s.feed AND '
        . 'EXISTS (SELECT 1 FROM %5$s %6$s WHERE %6$s.user = :user AND %6$s.network = %3$s.network))',
        $aliases->subscription,
        Feed::class,
        $feed,
        $aliases->entry,
        ForYouNetworkExclusion::class,
        $exclusion,
    ));
}
```

A feed with a null network never matches `= %3$s.network`, so plain feeds pass.

- [ ] **Step 4: Wire it in**

`RecommendationCandidateRepository`: inject `private EntryScopePredicates $scope` (constructor, after `$collapse`). In `poolScope()` replace `->andWhere(\sprintf('%s.includeInForYou = true', $aliases->subscription))` with `$this->scope->inForYou($inner, $aliases);` followed by the rest of the chain on `$inner`. In `candidateQueryBuilder()` build the query builder into a variable, replace `->andWhere('s.includeInForYou = true')` with `$this->scope->inForYou($qb, EntryAliases::primary());`, return `$qb`.

`RecommendationItemRepository`: add `private readonly EntryScopePredicates $scope` to its constructor (it is a `ServiceEntityRepository`; keep the `ManagerRegistry` parent call). In `applyForYouCriteria()` drop `->andWhere('s.includeInForYou = true')` and call `$this->scope->inForYou($qb, EntryAliases::primary());` after the joins (aliases `e`/`s` match `EntryAliases::primary()`); keep the method's return.

- [ ] **Step 5: Run the new test, `RecommendationFeedTest`, `tests/Service/Recommendation` — expect PASS**

Run: `cd backend && php bin/phpunit tests/Repository/ForYouExclusionReadsTest.php tests/Repository/RecommendationFeedTest.php tests/Service/Recommendation`

- [ ] **Step 6: Gates, commit**

```bash
cd backend && composer cs && composer stan && composer md && composer tramp
git add backend/src/Repository/EntryScopePredicates.php backend/src/Repository/RecommendationCandidateRepository.php backend/src/Repository/RecommendationItemRepository.php backend/tests/Repository/ForYouExclusionReadsTest.php
git commit -m "feat(#1995): an excluded network leaves the For you pool, list and count"
```

---

### Task 3: Backup and account wipe carry the exclusions

**Files:**
- Modify: `backend/src/Repository/AccountWipeRepository.php`, `backend/src/Service/Backup/AccountBackupExporter.php`, `backend/src/Service/Backup/BackupLines.php`, `backend/src/Service/Backup/Dto/AccountLine.php`, `backend/src/Service/Backup/Pass/RestoreLoadPass.php`, `backend/tests/Support/BackupFieldDeclarations.php`, `backend/tests/Support/FullyPopulatedAccount.php`, `backend/tests/Service/Backup/Support/BackupSchemaCoverageTest.php`, `backend/tests/Fixtures/backup/current/000-foundation.ndjson`, `docs/backup.md`
- Test: `backend/tests/Service/Backup/AccountRestorerTest.php`, `backend/tests/Service/Backup/Dto/AccountLineTest.php`, the wipe test that covers `SavedSearchNetworkInclusion` (find it: `grep -rln AccountWipe backend/tests`)

**Interfaces:**
- Consumes: Task 1's entity and `excludedNetworksFor()`.
- Produces: account line field `forYouExcludedNetworks: list<string>`; `AccountLine::$forYouExcludedNetworks: list<SocialNetwork>`; `BackupLines::accountLine(User $user, AccountNetworkSwitchesModel $networks)` — see Step 3.

- [ ] **Step 1: Failing tests**

`AccountRestorerTest` — three tests mirroring the saved-search ones (`testTheIncludedNetworksSurvive…`, `testAnAccountLineWithoutIncludedNetworks…`, `testAnUnknownIncludedNetwork…`), for exclusions:

```php
public function testTheExcludedForYouNetworksSurviveARestoreOverAnAccountThatAlreadyHasThem(): void
{
    $source = $this->fullyPopulatedAccount()->create('for-you-networks-source@example.com');
    $foundation = $this->backupOf($source);
    $this->entityManager->persist(new ForYouNetworkExclusion($this->reload($source), SocialNetwork::Mastodon));
    $this->entityManager->flush();

    $this->restorer()->start($this->reload($source), $foundation, 'REPLACE');

    self::assertSame([SocialNetwork::Bluesky], $this->forYouExcludedNetworksOf($source));
}

public function testAnAccountLineWithoutForYouExclusionsRestoresWithEveryNetworkIncluded(): void
{
    $user = $this->userFactory->create('for-you-networks-missing@example.com');
    $this->entityManager->persist(new ForYouNetworkExclusion($user, SocialNetwork::Bluesky));
    $this->entityManager->flush();

    $this->restorer()->start($this->reload($user), $this->foundationGzip(), 'REPLACE');

    self::assertSame([], $this->forYouExcludedNetworksOf($user));
}
```

plus the unknown-value test with `'forYouExcludedNetworks' => ['myspace', 'mastodon', 7]` → `[SocialNetwork::Mastodon]`. `AccountLineTest`: a duplicated value is read once (copy its `savedSearchIncludedNetworks` test). Wipe test: a `ForYouNetworkExclusion` row is gone after the wipe (follow the existing saved-search inclusion assertion there).

`FullyPopulatedAccount` persists `new ForYouNetworkExclusion($user, SocialNetwork::Bluesky)` (note: Bluesky, so it differs from the saved-search Mastodon row and a crossed wire fails).

- [ ] **Step 2: Run — expect FAIL** (`BackupSchemaCoverageTest` also fails on the unmapped entity — good, that is its job).

- [ ] **Step 3: Exporter, line, DTO, restore, wipe**

`accountLine()` would reach three parameters; instead give the networks a model. Create `backend/src/Service/Backup/Model/AccountNetworkSwitchesModel.php`:

```php
final readonly class AccountNetworkSwitchesModel
{
    /**
     * @param list<SocialNetwork> $savedSearchIncluded
     * @param list<SocialNetwork> $forYouExcluded
     */
    public function __construct(
        public array $savedSearchIncluded,
        public array $forYouExcluded,
    ) {
    }
}
```

`BackupLines::accountLine(User $user, AccountNetworkSwitchesModel $networks)` writes `'savedSearchIncludedNetworks' => …$networks->savedSearchIncluded` and `'forYouExcludedNetworks' => …$networks->forYouExcluded` (one private static `networkValues(list<SocialNetwork>): list<string>` for both). `AccountBackupExporter` injects `ForYouNetworkExclusionRepository $forYouExcludedNetworks` and builds the model. Run `ServiceRoleRule` via `composer stan` to confirm `Model/` is the right folder for it.

`AccountLine` gains `public array $forYouExcludedNetworks` (`@param list<SocialNetwork>`), read with the same `array_values(array_filter(array_map(SocialNetwork::tryFrom(...), array_unique(LineFieldWithDefault::stringList($line, 'forYouExcludedNetworks')))))` — extract that expression into a private static `networks(array $line, string $key): array` now that it has two uses. `RestoreLoadPass::loadAccount()` persists a `ForYouNetworkExclusion` per entry. `AccountWipeRepository` adds `$this->deleteByUser(ForYouNetworkExclusion::class, $user);`.

Coverage: `BackupFieldDeclarations` adds `ForYouNetworkExclusion::class => ['network' => 'forYouExcludedNetworks']` and `ForYouNetworkExclusion::class => BackupSchema::KIND_ACCOUNT`; `BackupSchemaCoverageTest` adds `ForYouNetworkExclusion::class => ['user' => self::OWNER_IS_THE_RESTORING_ACCOUNT]`. Fixture `000-foundation.ndjson` line 2 gains `"forYouExcludedNetworks":[]` (only if `GoldenBackupRestoreTest` compares the export with the fixture — run it first and change the fixture only if it fails).

`docs/backup.md`: account row adds “and the social networks you keep out of For you (`forYouExcludedNetworks`; a network not listed is included)”; the owner paragraph adds “each social network kept out of For you”.

- [ ] **Step 4: Run all backup tests — expect PASS**

Run: `cd backend && php bin/phpunit tests/Service/Backup tests/Repository/AccountWipeRepositoryTest.php` (adjust the wipe test path to what Step 1 found).

- [ ] **Step 5: Gates, commit**

```bash
cd backend && composer cs && composer stan && composer md && composer tramp
git add -A backend/src/Service/Backup backend/src/Repository/AccountWipeRepository.php backend/tests docs/backup.md
git commit -m "feat(#1995): backups and the account wipe carry the For you network switch"
```

---

### Task 4: Frontend — store, menu item, marker

**Files:**
- Create: `frontend/src/app/reader/state/for-you-networks.store.ts`, `frontend/src/app/reader/state/for-you-networks.store.spec.ts`
- Modify: `frontend/src/app/reader/reader-api.ts`, `frontend/src/app/reader/state/saved-searches.store.ts`, `frontend/src/app/reader/shell/reader-shell.component.ts` (+ spec), `frontend/src/app/reader/shell/sidebar/sidebar-row-actions.service.ts` (+ spec), `frontend/src/app/reader/shell/sidebar/sidebar-social-section.component.{ts,html}` (+ spec)

**Interfaces:**
- Consumes: Task 1's HTTP API.
- Produces: `ReaderApi.forYouExcludedNetworks(): Observable<{ excludedNetworks: SocialNetworkName[] }>`, `excludeNetworkFromForYou(network): Observable<void>` (PUT), `includeNetworkInForYou(network): Observable<void>` (DELETE); `ForYouNetworksStore` (`providedIn: 'root'`) with `excludedNetworks: Signal<ReadonlySet<SocialNetworkName>>`, `load(): void`, `toggleNetwork(network): void`; `SidebarRowActions.networkForYouAction(section): ActionSheetAction` (id `'forYou'`), `toggleNetworkInForYou(section): void`.

- [ ] **Step 1: Failing store spec**

```ts
describe('ForYouNetworksStore', () => {
  function setup(api: Partial<ReaderApi>): ForYouNetworksStore {
    TestBed.configureTestingModule({
      providers: [ForYouNetworksStore, { provide: ReaderApi, useValue: api }],
    });
    return TestBed.inject(ForYouNetworksStore);
  }

  it('starts with every network included and learns the exclusions on load', () => {
    const store = setup({ forYouExcludedNetworks: () => of({ excludedNetworks: ['bluesky'] }) });
    expect(store.excludedNetworks().size).toBe(0);

    store.load();

    expect([...store.excludedNetworks()]).toEqual(['bluesky']);
  });

  it('excludes an included network at once, then reloads', () => {
    const forYouExcludedNetworks = jest.fn(() => of({ excludedNetworks: ['mastodon' as const] }));
    const excludeNetworkFromForYou = jest.fn(() => of(undefined));
    const store = setup({ forYouExcludedNetworks, excludeNetworkFromForYou });

    store.toggleNetwork('mastodon');

    expect(excludeNetworkFromForYou).toHaveBeenCalledWith('mastodon');
    expect(forYouExcludedNetworks).toHaveBeenCalledTimes(1);
    expect(store.excludedNetworks().has('mastodon')).toBe(true);
  });

  it('includes an excluded network again', () => {
    const includeNetworkInForYou = jest.fn(() => of(undefined));
    const store = setup({
      forYouExcludedNetworks: () => of({ excludedNetworks: ['bluesky' as const] }),
      includeNetworkInForYou,
    });
    store.load();

    store.toggleNetwork('bluesky');

    expect(includeNetworkInForYou).toHaveBeenCalledWith('bluesky');
  });

  it('a failed write reloads, which snaps the switch back', () => {
    const store = setup({
      forYouExcludedNetworks: () => of({ excludedNetworks: [] }),
      excludeNetworkFromForYou: () => throwError(() => new Error('offline')),
    });

    store.toggleNetwork('bluesky');

    expect(store.excludedNetworks().has('bluesky')).toBe(false);
  });
});
```

Also: the toggle reloads the sidebar counts (`SubscriptionsStore.reloadCountsQuietly`) after the write — provide a `SubscriptionsStore` stub `{ reloadCountsQuietly: jest.fn() }` in `setup` and assert it was called in the exclude test.

Run: `docker compose exec -T frontend npx jest src/app/reader/state/for-you-networks.store.spec.ts` — expect FAIL (module missing).

- [ ] **Step 2: API + store**

`reader-api.ts`, next to the saved-search network calls:

```ts
forYouExcludedNetworks(): Observable<{ excludedNetworks: SocialNetworkName[] }> {
  return this.http.get<{ excludedNetworks: SocialNetworkName[] }>(
    `${this.base}/api/for-you/excluded-networks`,
  );
}

excludeNetworkFromForYou(network: SocialNetworkName): Observable<void> {
  return this.http.put<void>(`${this.base}/api/for-you/excluded-networks/${network}`, null);
}

includeNetworkInForYou(network: SocialNetworkName): Observable<void> {
  return this.http.delete<void>(`${this.base}/api/for-you/excluded-networks/${network}`);
}
```

`for-you-networks.store.ts`:

```ts
/** The social networks the user keeps out of For you; every other network's feeds count. */
@Injectable({ providedIn: 'root' })
export class ForYouNetworksStore {
  private readonly api = inject(ReaderApi);
  private readonly subscriptions = inject(SubscriptionsStore);

  private readonly stored = signal<ReadonlySet<SocialNetworkName>>(new Set(), { equal: sameMembers });
  readonly excludedNetworks = this.stored.asReadonly();

  constructor() {
    onIdentityChange(() => this.stored.set(new Set()));
  }

  load(): void {
    this.api
      .forYouExcludedNetworks()
      .pipe(catchError(() => EMPTY))
      .subscribe((response) => this.stored.set(new Set(response.excludedNetworks)));
  }

  toggleNetwork(network: SocialNetworkName): void {
    const excluded = this.excludedNetworks().has(network);
    this.stored.update((current) => {
      const next = new Set(current);
      if (excluded) next.delete(network);
      else next.add(network);
      return next;
    });
    const write = excluded
      ? this.api.includeNetworkInForYou(network)
      : this.api.excludeNetworkFromForYou(network);
    write.pipe(catchError(() => of(undefined))).subscribe(() => {
      this.load();
      this.subscriptions.reloadCountsQuietly();
    });
  }
}
```

`sameMembers` already exists privately in `saved-searches.store.ts`: move it to `frontend/src/app/reader/state/same-members.ts` (exported) and import it in both stores — second use, and it keeps the two stores identical in behaviour.

Run the store spec + `saved-searches.store.spec.ts` — expect PASS.

- [ ] **Step 3: Load at boot** — `ReaderShellComponent` boots the sidebar stores (`reader-shell.component.ts:256`, `this.savedSearchesStore.load();`). Inject `ForYouNetworksStore` there and call `this.forYouNetworks.load();` on the next line. Nothing else changes the exclusions, so no refresh-time reload is needed. Add the expectation to the boot test in `reader-shell.component.spec.ts` that already asserts the saved-search load (stub `ForYouNetworksStore` with `{ load: jest.fn(), excludedNetworks: signal(new Set()) }` wherever that spec stubs `SavedSearchesStore`).

- [ ] **Step 4: Failing row-actions spec**

In `sidebar-row-actions.service.spec.ts`, beside the saved-searches network tests, provide `{ provide: ForYouNetworksStore, useValue: { excludedNetworks, toggleNetwork: forYouToggle } }` with `excludedNetworks = signal<ReadonlySet<SocialNetworkName>>(new Set())`:

```ts
it('labels the network For you item by whether the network is excluded', () => {
  const rows = TestBed.inject(SidebarRowActions);
  expect(rows.networkForYouAction(sectionOf([]))).toEqual({
    id: 'forYou',
    label: 'Exclude from For You',
  });

  excludedNetworks.set(new Set(['bluesky']));

  expect(rows.networkForYouAction(sectionOf([])).label).toBe('Show in For You');
});

it('toggles the network in For you and closes the menu', () => {
  const rows = TestBed.inject(SidebarRowActions);
  rows.menuFor.set('social-bluesky');

  rows.toggleNetworkInForYou(sectionOf([]));

  expect(forYouToggle).toHaveBeenCalledWith('bluesky');
  expect(rows.menuFor()).toBeNull();
});

it('the network sheet offers For you after saved searches and acts on it', () => {
  sheetOpen.mockReturnValueOnce(of('forYou'));

  TestBed.inject(SidebarRowActions).openSocialSheet(sectionOf([]));

  expect(sheetOpen).toHaveBeenCalledWith(
    expect.objectContaining({
      actions: expect.arrayContaining([expect.objectContaining({ id: 'forYou' })]),
    }),
  );
  expect(forYouToggle).toHaveBeenCalledWith('bluesky');
});
```

Run — expect FAIL.

- [ ] **Step 5: Row actions, menu, marker, i18n**

`sidebar-row-actions.service.ts`: `const FOR_YOU_CHOICE = 'forYou';`, inject `ForYouNetworksStore`. `openSocialSheet` actions become `[...this.socialAccountActions(section), this.networkSavedSearchesAction(section), this.networkForYouAction(section)]`; the subscribe handles `FOR_YOU_CHOICE` like `SAVED_SEARCHES_CHOICE`. New:

```ts
/** The network head's For you item, for its sheet and its menu alike. */
networkForYouAction(section: SocialSection): ActionSheetAction {
  const excluded = this.forYouNetworks.excludedNetworks().has(section.network);
  return {
    id: FOR_YOU_CHOICE,
    label: this.transloco.translate(excluded ? 'reader.includeInForYou' : 'reader.excludeFromForYou'),
  };
}

toggleNetworkInForYou(section: SocialSection): void {
  this.forYouNetworks.toggleNetwork(section.network);
  this.closeMenu();
}
```

The per-feed keys `reader.excludeFromForYou` / `reader.includeInForYou` ("Exclude from For You" / "Show in For You") already read right for a network; no new i18n keys needed. If the service passes 300 lines (`max-lines`), extract the two network surface items into `sidebar-network-actions.ts` rather than squeezing.

`sidebar-social-section.component.html`, after the saved-searches menu button:

```html
<button role="menuitem" (click)="rows.toggleNetworkInForYou(current)">
  {{ rows.networkForYouAction(current).label }}
</button>
```

`sidebar-social-section.component.ts`: inject `ForYouNetworksStore`; replace `networkExcluded` / `networkExclusionTitle` with

```ts
private readonly networkExcludedSurfaces = computed<ExclusionSurface[]>(() => {
  const network = this.section().network;
  const surfaces: ExclusionSurface[] = [];
  if (this.forYouNetworks.excludedNetworks().has(network)) surfaces.push('forYou');
  if (!this.savedSearches.includedNetworks().has(network)) surfaces.push('savedSearches');
  return surfaces;
});
protected readonly networkExcluded = computed(() => this.networkExcludedSurfaces().length > 0);
protected readonly networkExclusionTitle = computed(() => {
  this.dictionary.loaded();
  return exclusionTitle(this.i18n, this.networkExcludedSurfaces());
});
```

Order matches `ownExcludedSurfaces()` (allItems, forYou, savedSearches).

Component spec: a network excluded from For you and included in saved searches shows the marker titled "Hidden from For You"; excluded from both → "Hidden from For You, Saved searches" (use the exact `reader.forYou`/`reader.savedSearches` strings from `en.json`); the fine-pointer menu lists the For you item.

- [ ] **Step 6: Run the sidebar specs + store specs — expect PASS**

Run: `docker compose exec -T frontend npx jest src/app/reader/shell/sidebar src/app/reader/state`

- [ ] **Step 7: Check, build, commit**

```bash
cd frontend && npm run check
docker compose exec -T frontend npx ng build
git add frontend/src
git commit -m "feat(#1995): the network menu switches a network out of For you"
```

---

### Task 5: Whole-branch verification

- [ ] Backend, both legs in parallel: `cd backend && composer test:parallel` and `docker compose exec php composer test:parallel`. Expect green.
- [ ] `cd backend && composer check && composer md && composer infection:diff` — expect MSI ≥ `minMsi`.
- [ ] Migrations from empty on both platforms (the CI leg): `docker compose exec php bin/console doctrine:schema:validate` on the dev DB after migrating; for SQLite, `cd backend && DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" php bin/console doctrine:migrations:migrate --no-interaction && DATABASE_URL=… php bin/console doctrine:schema:validate`, then delete `var/migrate-check.db`.
- [ ] Live check on http://localhost:4200 (built-in browser): open a network's ⋯, choose "Exclude from For You", confirm the For you count drops and the network heading shows the hidden marker naming For You; include it again and confirm both revert. Check the coarse sheet at mobile width in Organise mode.
- [ ] Scan the dev log: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`.
- [ ] `/simplify` on the branch, re-run the gates, then open the PR into `develop` with `Closes #1995`.
