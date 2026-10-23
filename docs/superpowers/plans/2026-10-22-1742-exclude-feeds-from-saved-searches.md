# Exclude feeds and social networks from saved searches (#1742) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user keep single feeds, and whole social networks, out of every saved search — lists, badges, counts, mark-read, pills and the digest mail — with social networks left out by default.

**Architecture:** Two switches, both applied at *read* time in the one membership predicate every `SavedSearchEntryRepository` read already shares. A per-subscription column `include_in_saved_searches` (default true) and a per-user table `saved_search_network_inclusion` (a row means "this network's feeds count"; no row means excluded, the default). The membership sweep stays user-agnostic and unchanged, so a toggle takes effect at once in both directions with no rematch. An entry counts only if its subscription's flag is on **and** its feed has no network or the network has an inclusion row; each feed keeps its own flag, so switching a network back on restores exactly what was there.

**Tech Stack:** Symfony 7.4 / Doctrine ORM (PHP 8.4), MySQL + SQLite; Angular 20 standalone + signals, Transloco, Jest.

**Spec:** the design agreed in chat and recorded in issue #1742 (no separate spec file).

## Global Constraints

- Branch `feature/1742-exclude-feeds-from-saved-searches`; commits `type(#1742): lower-case summary`; no Claude attribution.
- CLAUDE.md Clean Code rules: `final readonly` services, no boolean flag parameters, no comments unless a reader would get the code wrong, every touched `src` file PHPMD-clean.
- HTTP statuses are `Response::HTTP_*`, never literals.
- Datetimes untouched; no new ones.
- Existing accounts change behaviour on deploy: social posts leave saved searches and digests until the user switches the network on. This is intended ("excluded by default").
- Hex colours / px literals forbidden in `.scss` outside the theme; no new styles are expected.
- Run frontend tests inside the Docker frontend container: `docker compose exec -T frontend npm test`.

## File Structure

Backend — create:
- `backend/src/Entity/SavedSearchNetworkInclusion.php` — one included network of one user.
- `backend/src/Repository/SavedSearchNetworkInclusionRepository.php` — reads of that table.
- `backend/src/Service/Search/SavedSearchNetworks.php` — include / exclude a network (persists).
- `backend/migrations/Version20261022120000.php` — the column and the table, MySQL + SQLite.
- Tests: `backend/tests/Repository/SavedSearchExclusionReadsTest.php`, `backend/tests/Service/Search/SavedSearchNetworksTest.php`, `backend/tests/Controller/Api/SavedSearchNetworksControllerTest.php`.

Backend — modify:
- `Entity/Subscription.php` (flag, accessor, `takeSettingsFrom`)
- `Dto/Subscription/UpdateSubscriptionRequest.php`, `Dto/Subscription/BulkUpdateSubscriptionsRequest.php`
- `Service/Subscription/Model/SubscriptionChangeModel.php`, `Service/Subscription/Model/BulkSubscriptionChangeModel.php`
- `Service/Subscription/SubscriptionEditor.php`, `Service/Subscription/BulkSubscriptionUpdater.php`
- `Http/SubscriptionJson.php`, `Http/SavedSearchJson.php`, `Controller/Api/SavedSearchController.php`
- `Repository/SavedSearchEntryRepository.php` (the predicate)
- Backup: `Service/Backup/BackupLines.php`, `Service/Backup/AccountBackupExporter.php`, `Service/Backup/Dto/AccountLine.php`, `Service/Backup/Dto/SubscriptionLine.php`, `Service/Backup/Factory/RestoredFoundationFactory.php`, `Service/Backup/Pass/RestoreLoadPass.php`, `Service/Backup/Support/LineFieldWithDefault.php`
- Test support: `tests/Support/BackupFieldDeclarations.php`, `tests/Support/FullyPopulatedAccount.php`, `tests/Service/Backup/Support/BackupSchemaCoverageTest.php`, `tests/Fixtures/backup/current/000-foundation.ndjson`, `docs/backup.md`

Frontend — create:
- `frontend/src/app/reader/feed-exclusions.ts` (+ `.spec.ts`) — which surfaces a feed is hidden from, and the marker tooltip.

Frontend — modify:
- `reader/models.ts`, `reader/subscription-bulk.ts`, `reader/reader-api.ts`
- `reader/state/subscriptions.store.ts` (`patchLocal` type), `reader/state/saved-searches.store.ts` (`includedNetworks`)
- `reader/feeds/manage/manage-actions.service.ts`, `reader/feeds/manage/edit-subscription-dialog.component.{ts,html}`
- `reader/shell/sidebar/sidebar-feed-row.component.{ts,html}`, `reader/shell/sidebar/sidebar-row-actions.service.ts`, `reader/shell/sidebar/sidebar-social-section.component.{ts,html}`
- `settings/organise/organise-feed-row.component.{ts,html}`, `settings/organise/organise-section.component.{ts,html}`, `settings/organise/organise-tag-group.component.html`
- `public/i18n/en.json`, `public/i18n/de.json`
- `reader/testing/subscription.factory.ts`, `src/testing/subscription-testing.ts`

---

### Task 1: Per-feed flag — entity, migration, API, backup

**Files:**
- Modify: `backend/src/Entity/Subscription.php:57-63, 133-141, 181-191`
- Create: `backend/migrations/Version20261022120000.php` (column part; Task 2 adds the table to the same file)
- Modify: the two request DTOs, the two change models, `SubscriptionEditor.php:48-56`, `BulkSubscriptionUpdater.php:106-114`, `SubscriptionJson.php:68, 106-107`
- Modify: `SubscriptionLine.php`, `BackupLines.php:117-118`, `RestoredFoundationFactory.php:50-51`
- Test: `tests/Service/Subscription/SubscriptionEditorTest.php`, `tests/Service/Subscription/BulkSubscriptionUpdaterTest.php`, `tests/Http/SubscriptionJsonTest.php`, `tests/Entity/SubscriptionSettingsTest.php`, `tests/Dto/Subscription/*RequestTest.php`, backup support files

**Interfaces:**
- Produces: `Subscription::isIncludeInSavedSearches(): bool`, `Subscription::setIncludeInSavedSearches(bool): void`; DQL field `includeInSavedSearches`; JSON key `includeInSavedSearches` on every subscription; request key `includeInSavedSearches` (nullable, null = unchanged) on `PATCH /api/subscriptions/{id}` and the bulk endpoint; backup key `includeInSavedSearches` on subscription lines (missing → true).

- [ ] **Step 1: Write the failing tests**

In `tests/Service/Subscription/SubscriptionEditorTest.php`, next to the existing For-you flag test, add (reuse that test's fixture helpers; read the file first and match its subscription/helper names):

```php
public function testUpdateExcludesTheFeedFromSavedSearchesAndANullFlagLeavesItAlone(): void
{
    $subscription = $this->subscription();

    $this->editor()->update($subscription, new SubscriptionChangeModel(includeInSavedSearches: false));
    self::assertFalse($subscription->isIncludeInSavedSearches());

    $this->editor()->update($subscription, new SubscriptionChangeModel());
    self::assertFalse($subscription->isIncludeInSavedSearches());
}
```

In `tests/Service/Subscription/BulkSubscriptionUpdaterTest.php`, next to its For-you case:

```php
public function testBulkUpdateSetsTheSavedSearchFlagOnEveryListedFeed(): void
{
    [$first, $second] = [$this->subscription('a'), $this->subscription('b')];

    $this->updater()->update($this->user, new BulkSubscriptionChangeModel(
        subscriptionIds: [$first->requireId(), $second->requireId()],
        includeInSavedSearches: false,
    ));

    self::assertFalse($first->isIncludeInSavedSearches());
    self::assertFalse($second->isIncludeInSavedSearches());
}
```

In `tests/Entity/SubscriptionSettingsTest.php` extend the `takeSettingsFrom` test: set `$previous->setIncludeInSavedSearches(false)` and assert `self::assertFalse($next->isIncludeInSavedSearches())`.

In `tests/Http/SubscriptionJsonTest.php` add `'includeInSavedSearches' => true` to the expected shape (and a `false` case mirroring the For-you one).

In the two DTO tests, mirror the `includeInForYou` mapping assertions for `includeInSavedSearches`.

Backup: in `tests/Support/FullyPopulatedAccount.php:116-117` add `$subscription->setIncludeInSavedSearches(false);`; in `tests/Support/BackupFieldDeclarations.php:57-58` add `'includeInSavedSearches' => 'includeInSavedSearches',`. Add to `AccountRestorerTest` (next to the existing default-for-missing-field case for `includeInForYou`; grep `includeInForYou` there) a case that a subscription line without `includeInSavedSearches` restores as `true`.

- [ ] **Step 2: Run them and see them fail**

Run: `cd backend && php bin/phpunit --filter 'SubscriptionEditorTest|BulkSubscriptionUpdaterTest|SubscriptionSettingsTest|SubscriptionJsonTest|UpdateSubscriptionRequestTest|BulkUpdateSubscriptionsRequestTest|AccountRestorerTest|BackupSchemaCoverageTest'`
Expected: FAIL — unknown named parameter `includeInSavedSearches` / undefined method `isIncludeInSavedSearches`.

- [ ] **Step 3: Implement**

`Subscription.php`, after the For-you column:

```php
#[ORM\Column(name: 'include_in_saved_searches', options: ['default' => true])]
private bool $includeInSavedSearches = true;
```

Accessors after `setIncludeInForYou`:

```php
public function isIncludeInSavedSearches(): bool
{
    return $this->includeInSavedSearches;
}

public function setIncludeInSavedSearches(bool $includeInSavedSearches): void
{
    $this->includeInSavedSearches = $includeInSavedSearches;
}
```

`takeSettingsFrom()`: add `$this->includeInSavedSearches = $previous->includeInSavedSearches;` after the For-you line.

Both change models and both request DTOs: add `public ?bool $includeInSavedSearches = null,` after `includeInForYou`, and pass `includeInSavedSearches: $this->includeInSavedSearches,` in each `toChange()`. Update the bulk DTO docblock "either inclusion flag" → "any inclusion flag".

`SubscriptionEditor::applyFlags()` and `BulkSubscriptionUpdater::applyFlags()`: append

```php
if (null !== $change->includeInSavedSearches) {
    $subscription->setIncludeInSavedSearches($change->includeInSavedSearches);
}
```

Run `composer md` on both files afterwards; if PHPMD flags the method, split each flag into its own one-line private method rather than raising a threshold.

`SubscriptionJson::one()`: add `'includeInSavedSearches' => $subscription->isIncludeInSavedSearches(),` after the For-you key, and `includeInSavedSearches: bool,` to the `@return` shape.

Backup: `SubscriptionLine` constructor gains `public bool $includeInSavedSearches,` and `fromLine` gains `includeInSavedSearches: LineFieldWithDefault::bool($line, 'includeInSavedSearches', true),`. `BackupLines::subscriptionLine` writes `'includeInSavedSearches' => $subscription->isIncludeInSavedSearches(),`. `RestoredFoundationFactory` adds `$subscription->setIncludeInSavedSearches($line->includeInSavedSearches);`. Add `"includeInSavedSearches":true` after `"includeInForYou":true` in `tests/Fixtures/backup/current/000-foundation.ndjson` (leave `oldest-supported` untouched — it proves the default). Fix any other test that constructs `SubscriptionLine` positionally (grep `new SubscriptionLine(`).

Migration `backend/migrations/Version20261022120000.php` (Task 2 extends `upOnMySql`/`upOnSqlite`/`down`):

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022120000 extends AbstractMigration
{
    private const string COLUMN = 'include_in_saved_searches';

    public function getDescription(): string
    {
        return 'Keep feeds and social networks out of saved searches:'
            . ' subscription.include_in_saved_searches and saved_search_network_inclusion (#1742).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('subscription')->hasColumn(self::COLUMN), 'Already applied.');

        $this->abortIfUnsupportedPlatform();

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->upOnMySql();

            return;
        }

        $this->upOnSqlite();
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable('subscription')->hasColumn(self::COLUMN), 'Not applied.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('ALTER TABLE subscription DROP COLUMN ' . self::COLUMN);
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function upOnMySql(): void
    {
        $this->addSql('ALTER TABLE subscription ADD include_in_saved_searches TINYINT(1) DEFAULT 1 NOT NULL');
    }

    private function upOnSqlite(): void
    {
        $this->addSql('ALTER TABLE subscription ADD COLUMN include_in_saved_searches BOOLEAN DEFAULT 1 NOT NULL');
    }

    private function abortIfUnsupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
```

- [ ] **Step 4: Run the tests and see them pass**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend
git commit -m "feat(#1742): a per-feed switch for saved searches on the subscription"
```

---

### Task 2: Network inclusion — entity, repository, service, endpoints, backup

**Files:**
- Create: `backend/src/Entity/SavedSearchNetworkInclusion.php`, `backend/src/Repository/SavedSearchNetworkInclusionRepository.php`, `backend/src/Service/Search/SavedSearchNetworks.php`
- Modify: `backend/migrations/Version20261022120000.php`, `Controller/Api/SavedSearchController.php`, `Http/SavedSearchJson.php`
- Modify (backup): `AccountLine.php`, `LineFieldWithDefault.php`, `BackupLines.php:57-65`, `AccountBackupExporter.php:68`, `RestoreLoadPass.php:89-94`, `BackupFieldDeclarations.php`, `BackupSchemaCoverageTest.php`, `FullyPopulatedAccount.php`, `docs/backup.md`
- Test: `tests/Service/Search/SavedSearchNetworksTest.php`, `tests/Controller/Api/SavedSearchNetworksControllerTest.php`, `AccountRestorerTest.php`

**Interfaces:**
- Produces: entity `App\Entity\SavedSearchNetworkInclusion(User $user, SocialNetwork $network)` with DQL fields `user`, `network`; `SavedSearchNetworkInclusionRepository::includedNetworksFor(int $userId): list<SocialNetwork>` (enum declaration order) and `findOneFor(int $userId, SocialNetwork $network): ?SavedSearchNetworkInclusion`; `SavedSearchNetworks::include(User, SocialNetwork): void` and `::exclude(User, SocialNetwork): void`, both idempotent; `PUT` / `DELETE /api/saved-searches/included-networks/{network}` → 204, unknown network → 404; `GET /api/saved-searches` gains `includedNetworks: list<string>`; account backup line key `savedSearchIncludedNetworks: list<string>` (missing → `[]`, unknown values dropped).

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Search/SavedSearchNetworksTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SavedSearchNetworkInclusionRepository;
use App\Service\Search\SavedSearchNetworks;
use App\Tests\DbTestCase;

final class SavedSearchNetworksTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('networks@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    public function testEveryNetworkStartsExcluded(): void
    {
        self::assertSame([], $this->inclusions()->includedNetworksFor($this->user->requireId()));
    }

    public function testIncludingTwiceStoresOneRowAndExcludingRemovesIt(): void
    {
        $this->networks()->include($this->user, SocialNetwork::Bluesky);
        $this->networks()->include($this->user, SocialNetwork::Bluesky);
        self::assertSame(
            [SocialNetwork::Bluesky],
            $this->inclusions()->includedNetworksFor($this->user->requireId()),
        );

        $this->networks()->exclude($this->user, SocialNetwork::Bluesky);
        $this->networks()->exclude($this->user, SocialNetwork::Bluesky);
        self::assertSame([], $this->inclusions()->includedNetworksFor($this->user->requireId()));
    }

    private function networks(): SavedSearchNetworks
    {
        $service = self::getContainer()->get(SavedSearchNetworks::class);
        self::assertInstanceOf(SavedSearchNetworks::class, $service);

        return $service;
    }

    private function inclusions(): SavedSearchNetworkInclusionRepository
    {
        $repository = self::getContainer()->get(SavedSearchNetworkInclusionRepository::class);
        self::assertInstanceOf(SavedSearchNetworkInclusionRepository::class, $repository);

        return $repository;
    }
}
```

`backend/tests/Controller/Api/SavedSearchNetworksControllerTest.php` — follow the authenticated-client helper an existing saved-search controller test uses (grep `api/saved-searches` under `tests/Controller/Api` and copy its login/request helpers verbatim):

```php
public function testANetworkIsExcludedUntilIncludedAndTheListReportsIt(): void
{
    self::assertSame([], $this->getJson('/api/saved-searches')['includedNetworks']);

    $this->request('PUT', '/api/saved-searches/included-networks/bluesky');
    self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    self::assertSame(['bluesky'], $this->getJson('/api/saved-searches')['includedNetworks']);

    $this->request('DELETE', '/api/saved-searches/included-networks/bluesky');
    self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    self::assertSame([], $this->getJson('/api/saved-searches')['includedNetworks']);
}

public function testAnUnknownNetworkIsNotFound(): void
{
    $this->request('PUT', '/api/saved-searches/included-networks/myspace');

    self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
}
```

Backup, in `AccountRestorerTest`: a round trip of `FullyPopulatedAccount` keeps `[SocialNetwork::Mastodon]` included, and an account line without `savedSearchIncludedNetworks` restores with `[]`. In `FullyPopulatedAccount` add, after the preferences are set (read the file for where the user is built): `$entityManager->persist(new SavedSearchNetworkInclusion($user, SocialNetwork::Mastodon));`.

- [ ] **Step 2: Run them and see them fail**

Run: `cd backend && php bin/phpunit --filter 'SavedSearchNetworksTest|SavedSearchNetworksControllerTest|AccountRestorerTest|BackupSchemaCoverageTest'`
Expected: FAIL — class `SavedSearchNetworks` not found.

- [ ] **Step 3: Implement**

`backend/src/Entity/SavedSearchNetworkInclusion.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SocialNetwork;
use App\Repository\SavedSearchNetworkInclusionRepository;
use Doctrine\ORM\Mapping as ORM;

/** A social network whose feeds count in the user's saved searches; a network without a row is left out (#1742). */
#[ORM\Entity(repositoryClass: SavedSearchNetworkInclusionRepository::class)]
#[ORM\Table(name: 'saved_search_network_inclusion')]
#[ORM\Index(name: 'idx_saved_search_network_inclusion_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_saved_search_network_inclusion', columns: ['user_id', 'network'])]
final class SavedSearchNetworkInclusion
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

`backend/src/Repository/SavedSearchNetworkInclusionRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SavedSearchNetworkInclusion;
use App\Enum\SocialNetwork;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SavedSearchNetworkInclusion> */
final class SavedSearchNetworkInclusionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedSearchNetworkInclusion::class);
    }

    /** @return list<SocialNetwork> */
    public function includedNetworksFor(int $userId): array
    {
        $included = array_map(
            static fn (SavedSearchNetworkInclusion $inclusion): SocialNetwork => $inclusion->getNetwork(),
            $this->findBy(['user' => $userId]),
        );

        return array_values(array_filter(
            SocialNetwork::cases(),
            static fn (SocialNetwork $network): bool => \in_array($network, $included, true),
        ));
    }

    public function findOneFor(int $userId, SocialNetwork $network): ?SavedSearchNetworkInclusion
    {
        return $this->findOneBy(['user' => $userId, 'network' => $network]);
    }
}
```

`backend/src/Service/Search/SavedSearchNetworks.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\SavedSearchNetworkInclusion;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SavedSearchNetworkInclusionRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SavedSearchNetworks
{
    public function __construct(
        private SavedSearchNetworkInclusionRepository $inclusions,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function include(User $user, SocialNetwork $network): void
    {
        if (null !== $this->inclusions->findOneFor($user->requireId(), $network)) {
            return;
        }
        $this->entityManager->persist(new SavedSearchNetworkInclusion($user, $network));
        $this->entityManager->flush();
    }

    public function exclude(User $user, SocialNetwork $network): void
    {
        $inclusion = $this->inclusions->findOneFor($user->requireId(), $network);
        if (null === $inclusion) {
            return;
        }
        $this->entityManager->remove($inclusion);
        $this->entityManager->flush();
    }
}
```

If `ServiceRoleRule` objects to `new SavedSearchNetworkInclusion` in a root service (it may require a `Factory/`), keep it: this constructor has no construction logic, and `SavedSearchEditor::create()` does the same with `new SavedSearch`.

`SavedSearchJson` — add:

```php
/**
 * @param list<SocialNetwork> $networks
 *
 * @return list<string>
 */
public static function networks(array $networks): array
{
    return array_map(static fn (SocialNetwork $network): string => $network->value, $networks);
}
```

`SavedSearchController` — inject `SavedSearchNetworkInclusionRepository $includedNetworks` and `SavedSearchNetworks $networks`; in `list()` add `'includedNetworks' => SavedSearchJson::networks($this->includedNetworks->includedNetworksFor($userId)),` after `savedSearches`; add:

```php
#[Route('/included-networks/{network}', name: 'api_saved_searches_include_network', methods: ['PUT'])]
public function includeNetwork(SocialNetwork $network, #[CurrentUser] User $user): JsonResponse
{
    $this->networks->include($user, $network);

    return new JsonResponse(null, Response::HTTP_NO_CONTENT);
}

#[Route('/included-networks/{network}', name: 'api_saved_searches_exclude_network', methods: ['DELETE'])]
public function excludeNetwork(SocialNetwork $network, #[CurrentUser] User $user): JsonResponse
{
    $this->networks->exclude($user, $network);

    return new JsonResponse(null, Response::HTTP_NO_CONTENT);
}
```

Symfony's `BackedEnumValueResolver` turns `{network}` into the enum and answers an unknown value with 404 (problem+json via the existing listener).

Migration — extend `Version20261022120000`: in `upOnMySql()` append

```php
$this->addSql('CREATE TABLE saved_search_network_inclusion (id INT AUTO_INCREMENT NOT NULL,'
    . ' network VARCHAR(16) NOT NULL, user_id INT NOT NULL,'
    . ' INDEX idx_saved_search_network_inclusion_user (user_id),'
    . ' UNIQUE INDEX uniq_saved_search_network_inclusion (user_id, network), PRIMARY KEY (id))'
    . ' DEFAULT CHARACTER SET utf8mb4');
$this->addSql('ALTER TABLE saved_search_network_inclusion ADD CONSTRAINT fk_saved_search_network_inclusion_user'
    . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
```

in `upOnSqlite()` append

```php
$this->addSql('CREATE TABLE saved_search_network_inclusion (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
    . ' network VARCHAR(16) NOT NULL, user_id INTEGER NOT NULL,'
    . ' CONSTRAINT fk_saved_search_network_inclusion_user FOREIGN KEY (user_id)'
    . ' REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
$this->addSql('CREATE INDEX idx_saved_search_network_inclusion_user ON saved_search_network_inclusion (user_id)');
$this->addSql('CREATE UNIQUE INDEX uniq_saved_search_network_inclusion'
    . ' ON saved_search_network_inclusion (user_id, network)');
```

and in `down()` add `$this->addSql('DROP TABLE saved_search_network_inclusion');` before the column drop.

Backup:
- `LineFieldWithDefault` — add:

```php
/**
 * @param array<string, mixed> $line
 *
 * @return list<string>
 */
public static function stringList(array $line, string $key): array
{
    $value = $line[$key] ?? [];
    if (!\is_array($value)) {
        return [];
    }

    return array_values(array_filter($value, \is_string(...)));
}
```

- `AccountLine` — add `/** @var list<SocialNetwork> */ public array $savedSearchIncludedNetworks,` (constructor-promoted, with a `@param list<SocialNetwork> $savedSearchIncludedNetworks` on the constructor), and in `fromLine`:

```php
savedSearchIncludedNetworks: array_values(array_filter(array_map(
    SocialNetwork::tryFrom(...),
    LineFieldWithDefault::stringList($line, 'savedSearchIncludedNetworks'),
))),
```

- `BackupLines::accountLine(User $user, array $savedSearchIncludedNetworks)` with `@param list<SocialNetwork> $savedSearchIncludedNetworks`, writing `'savedSearchIncludedNetworks' => array_map(static fn (SocialNetwork $network): string => $network->value, $savedSearchIncludedNetworks),`.
- `AccountBackupExporter` — inject `SavedSearchNetworkInclusionRepository $includedNetworks`; line 68 becomes `$this->lines->accountLine($this->users->getById($userId), $this->includedNetworks->includedNetworksFor($userId)),`.
- `RestoreLoadPass::loadAccount()` — append:

```php
foreach ($line->savedSearchIncludedNetworks as $network) {
    $this->entityManager->persist(new SavedSearchNetworkInclusion($this->user, $network));
}
```

- `BackupFieldDeclarations::BACKED_UP` — add `SavedSearchNetworkInclusion::class => ['network' => 'savedSearchIncludedNetworks'],` and, in `BackupSchemaCoverageTest`, map it to the account kind in `KIND_OF` (read `KIND_OF` and the field-decision test first; if `user` needs a decision, declare it the way `SavedSearch`'s `user` is handled there). Add a line for the new field to the matching section of `docs/backup.md` if the coverage test asks for one.

- [ ] **Step 4: Run the tests and see them pass**

Run the Step 2 command, then `php bin/phpunit --filter Backup`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend docs/backup.md
git commit -m "feat(#1742): social networks count in saved searches only once included"
```

---

### Task 3: Apply both switches to every saved-search read

**Files:**
- Modify: `backend/src/Repository/SavedSearchEntryRepository.php`
- Create: `backend/tests/Repository/SavedSearchExclusionReadsTest.php`

**Interfaces:**
- Consumes: `Subscription.includeInSavedSearches` (Task 1), `SavedSearchNetworkInclusion` (Task 2).
- Produces: every public read of `SavedSearchEntryRepository` keeps only entries whose subscription is included and whose feed has no network or an included one. Signatures unchanged.

- [ ] **Step 1: Write the failing test**

`backend/tests/Repository/SavedSearchExclusionReadsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\SavedSearchNetworkInclusion;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\EntryListRow;
use App\Repository\SavedSearchEntryRepository;
use App\Repository\SavedSearchListQuery;
use App\Tests\DbTestCase;

/** A feed switched off, or a social network not switched on, drops out of every saved-search read (#1742). */
final class SavedSearchExclusionReadsTest extends DbTestCase
{
    private User $user;
    private SavedSearch $search;
    private Entry $kept;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->search = new SavedSearch($this->user, 'climate', false);
        $this->entityManager->persist($this->search);
        $this->entityManager->flush();

        $this->kept = $this->memberOf($this->subscribedFeed('https://kept.example.com/feed.xml'), 'kept');
    }

    public function testAFeedSwitchedOffIsInNoRead(): void
    {
        $feed = $this->subscribedFeed('https://off.example.com/feed.xml');
        $this->subscriptionOf($feed)->setIncludeInSavedSearches(false);
        $this->memberOf($feed, 'off');

        $this->assertOnlyTheKeptEntryIsSeen();
    }

    public function testASocialFeedIsInNoReadWhileItsNetworkIsNotIncluded(): void
    {
        $this->memberOf($this->subscribedFeed('https://bsky.example.com/profile.xml', SocialNetwork::Bluesky), 'post');

        $this->assertOnlyTheKeptEntryIsSeen();
    }

    public function testIncludingTheNetworkBringsItsFeedsBack(): void
    {
        $post = $this->memberOf(
            $this->subscribedFeed('https://bsky.example.com/profile.xml', SocialNetwork::Bluesky),
            'post',
        );
        $this->include(SocialNetwork::Bluesky);

        self::assertEqualsCanonicalizing(
            [$this->kept->getId(), $post->getId()],
            $this->listedIds(),
        );
    }

    public function testTheFeedSwitchStillAppliesInsideAnIncludedNetwork(): void
    {
        $this->include(SocialNetwork::Bluesky);
        $feed = $this->subscribedFeed('https://bsky.example.com/list.xml', SocialNetwork::Bluesky);
        $this->subscriptionOf($feed)->setIncludeInSavedSearches(false);
        $this->memberOf($feed, 'post');

        $this->assertOnlyTheKeptEntryIsSeen();
    }

    public function testIncludingOneNetworkLeavesTheOtherOut(): void
    {
        $this->include(SocialNetwork::Bluesky);
        $this->memberOf($this->subscribedFeed('https://mastodon.example.com/@a.rss', SocialNetwork::Mastodon), 'toot');

        $this->assertOnlyTheKeptEntryIsSeen();
    }

    public function testAnExcludedLowestIdCopyDoesNotHideTheIncludedOne(): void
    {
        $offFeed = $this->subscribedFeed('https://off.example.com/feed.xml');
        $this->subscriptionOf($offFeed)->setIncludeInSavedSearches(false);
        $this->memberOf($offFeed, 'original', urlHash: 'same');
        $copy = $this->memberOf($this->subscribedFeed('https://on.example.com/feed.xml'), 'copy', urlHash: 'same');

        self::assertEqualsCanonicalizing([$this->kept->getId(), $copy->getId()], $this->listedIds());
    }

    public function testAnUnincludedNetworksLowestIdCopyDoesNotHideTheIncludedOne(): void
    {
        $this->memberOf(
            $this->subscribedFeed('https://bsky.example.com/profile.xml', SocialNetwork::Bluesky),
            'original',
            urlHash: 'same',
        );
        $copy = $this->memberOf($this->subscribedFeed('https://on.example.com/feed.xml'), 'copy', urlHash: 'same');

        self::assertEqualsCanonicalizing([$this->kept->getId(), $copy->getId()], $this->listedIds());
    }

    private function assertOnlyTheKeptEntryIsSeen(): void
    {
        $userId = $this->user->requireId();
        $searchId = $this->search->requireId();
        $keptId = $this->kept->requireId();

        self::assertSame([$keptId], $this->listedIds(), 'list');
        self::assertSame([$searchId => [$keptId]], $this->repository()->unreadMemberIdsBySavedSearch($userId, [$searchId]), 'badge');
        self::assertSame([$searchId => 1], $this->repository()->memberCountsBySavedSearch($userId, [$searchId]), 'count');
        self::assertSame(
            [$keptId],
            $this->repository()->unreadMemberIdsUpTo($userId, [$searchId], new \DateTimeImmutable('2026-12-31T00:00:00Z')),
            'mark-read',
        );
        self::assertSame(
            [$keptId],
            $this->repository()->unreadMemberIdsForUserSince($userId, $searchId, new \DateTimeImmutable('2026-01-01T00:00:00Z')),
            'digest',
        );
        $pillEntryIds = array_keys($this->repository()->savedSearchesByEntry($userId, $this->allEntryIds()));
        self::assertSame([$keptId], $pillEntryIds, 'pills');
    }

    private function subscribedFeed(string $url, ?SocialNetwork $network = null): Feed
    {
        $feed = new Feed($url);
        $feed->setNetwork($network);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->entityManager->flush();

        return $feed;
    }

    private function subscriptionOf(Feed $feed): Subscription
    {
        $subscription = $this->entityManager->getRepository(Subscription::class)->findOneBy(['feed' => $feed]);
        self::assertInstanceOf(Subscription::class, $subscription);

        return $subscription;
    }

    private function include(SocialNetwork $network): void
    {
        $this->entityManager->persist(new SavedSearchNetworkInclusion($this->user, $network));
        $this->entityManager->flush();
    }

    private function memberOf(Feed $feed, string $guid, ?string $urlHash = null): Entry
    {
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
            $urlHash,
        );
        $this->entityManager->persist($entry);
        $this->entityManager->persist(new SavedSearchEntry($this->search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00')));
        $this->entityManager->flush();

        return $entry;
    }

    /** @return list<int|null> */
    private function listedIds(): array
    {
        $rows = $this->repository()->listMembers(new SavedSearchListQuery(
            userId: $this->user->requireId(),
            savedSearchIds: [$this->search->requireId()],
            onlyUnread: false,
            cursor: null,
            limit: 50,
        ));

        return array_map(static fn (EntryListRow $row): ?int => $row->entry->getId(), $rows);
    }

    /** @return list<int> */
    private function allEntryIds(): array
    {
        return array_map(
            static fn (Entry $entry): int => $entry->requireId(),
            $this->entityManager->getRepository(Entry::class)->findAll(),
        );
    }

    private function repository(): SavedSearchEntryRepository
    {
        $repository = self::getContainer()->get(SavedSearchEntryRepository::class);
        self::assertInstanceOf(SavedSearchEntryRepository::class, $repository);

        return $repository;
    }
}
```

Check `Feed::setNetwork` exists (grep `function setNetwork` in `src/Entity/Feed.php`); if the network is only settable through another method, use that one. Wrap the over-long assertion lines to 120 columns for phpcs.

- [ ] **Step 2: Run it and see it fail**

Run: `cd backend && php bin/phpunit tests/Repository/SavedSearchExclusionReadsTest.php`
Expected: FAIL on every test except `testIncludingTheNetworkBringsItsFeedsBack`.

- [ ] **Step 3: Implement**

In `SavedSearchEntryRepository`:

1. `applyMembership()` adds the scope to whichever alias set it gets, so the primary query and the collapse subquery agree:

```php
private static function applyMembership(QueryBuilder $qb, EntryAliases $aliases): void
{
    $qb->andWhere(self::memberOfAnySearch($aliases))
        ->andWhere(self::countsInSavedSearches($aliases));
}

/** The subscription's own switch, and the feed's network when it has one; both bind only the shared :user. */
private static function countsInSavedSearches(EntryAliases $aliases): string
{
    $feed = 'searchFeed' . ucfirst($aliases->entry);
    $inclusion = 'networkInclusion' . ucfirst($aliases->entry);

    return \sprintf(
        '%1$s.includeInSavedSearches = true AND EXISTS (SELECT 1 FROM %2$s %3$s WHERE %3$s = %4$s.feed AND '
        . '(%3$s.network IS NULL OR EXISTS (SELECT 1 FROM %5$s %6$s WHERE %6$s.user = :user '
        . 'AND %6$s.network = %3$s.network)))',
        $aliases->subscription,
        Feed::class,
        $feed,
        $aliases->entry,
        SavedSearchNetworkInclusion::class,
        $inclusion,
    );
}
```

Import `App\Entity\Feed` and `App\Entity\SavedSearchNetworkInclusion`. `unreadMemberIdsBySavedSearch()` joins membership itself instead of calling `restrictToMembers()`; add `->andWhere(self::countsInSavedSearches(EntryAliases::primary()))` to its builder (its collapse already goes through `applyMembership`).

2. `memberCountsBySavedSearch()` joins `Subscription s` on its own; add `->andWhere(self::countsInSavedSearches(EntryAliases::primary()))` after the subscription join. Its `:user` is already bound.

3. `savedSearchesByEntry()` has no subscription join; add
```php
->join('sse.entry', 'e')
->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = ss.user')
->andWhere(self::countsInSavedSearches(EntryAliases::primary()))
```
before `->andWhere('sse.entry IN (:entryIds)')`.

If Doctrine rejects `%3$s = %4$s.feed` inside the subquery, write it `%3$s.id = IDENTITY(%4$s.feed)`.

- [ ] **Step 4: Run the tests and see them pass**

Run: `cd backend && php bin/phpunit tests/Repository/SavedSearchExclusionReadsTest.php tests/Repository/SavedSearchMembershipReadsTest.php`
Expected: PASS (the existing reads test uses plain feeds, so nothing there changes).

- [ ] **Step 5: Commit**

```bash
git add backend
git commit -m "feat(#1742): saved-search reads skip switched-off feeds and unincluded networks"
```

---

### Task 4: Digest mail honours both switches

**Files:**
- Test: `backend/tests/Service/Mail/Digest/DigestComposerTest.php`

**Interfaces:**
- Consumes: Task 3's filter through `unreadMemberIdsForUserSince`. No production change is expected; these tests pin the user-visible promise.

- [ ] **Step 1: Write the tests**

Add to `DigestComposerTest` (its `member()` helper creates one feed + subscription per entry; add a `subscriptionOf(Entry $entry): Subscription` helper looking it up with `findOneBy(['feed' => $entry->getFeed(), 'user' => $this->user])`):

```php
public function testAnExcludedFeedLeavesTheDigestAndTheCount(): void
{
    $rust = $this->search('rust');
    $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$rust]);
    $kept = $this->member($rust, 'Kept', 'Feed A', new \DateTimeImmutable('2026-08-15T00:00:00Z'));
    $dropped = $this->member($rust, 'Dropped', 'Feed B', new \DateTimeImmutable('2026-08-15T01:00:00Z'));
    $this->subscriptionOf($dropped)->setIncludeInSavedSearches(false);
    $this->entityManager->flush();

    $model = $this->composer()->compose($this->user, $this->since);

    self::assertNotNull($model);
    self::assertSame(1, $model->total);
    self::assertSame(['Kept'], array_map(static fn ($entry) => $entry->title, $model->groups[0]->entries));
}

public function testADigestWhoseOnlyMatchesAreExcludedIsNotComposed(): void
{
    $rust = $this->search('rust');
    $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$rust]);
    $dropped = $this->member($rust, 'Dropped', 'Feed B', new \DateTimeImmutable('2026-08-15T01:00:00Z'));
    $this->subscriptionOf($dropped)->setIncludeInSavedSearches(false);
    $this->entityManager->flush();

    self::assertNull($this->composer()->compose($this->user, $this->since));
}

public function testASocialPostStaysOutOfTheDigestUntilItsNetworkIsIncluded(): void
{
    $rust = $this->search('rust');
    $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$rust]);
    $post = $this->member($rust, 'Post', 'Bluesky', new \DateTimeImmutable('2026-08-15T01:00:00Z'));
    $post->getFeed()->setNetwork(SocialNetwork::Bluesky);
    $this->entityManager->flush();

    self::assertNull($this->composer()->compose($this->user, $this->since));

    $this->entityManager->persist(new SavedSearchNetworkInclusion($this->user, SocialNetwork::Bluesky));
    $this->entityManager->flush();

    self::assertNotNull($this->composer()->compose($this->user, $this->since));
}
```

Read `DigestModel` / `DigestGroupModel` / `DigestEntryModel` for the exact property names (`total`, `groups`, `entries`, `title`) and adjust to match.

- [ ] **Step 2: Run them**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Digest/DigestComposerTest.php`
Expected: PASS. To prove they guard something, temporarily drop the `countsInSavedSearches` line from `applyMembership()`: all three must FAIL. Restore it by re-applying the edit (no `git checkout --`).

- [ ] **Step 3: Commit**

```bash
git add backend/tests/Service/Mail/Digest/DigestComposerTest.php
git commit -m "test(#1742): the digest leaves out switched-off feeds and unincluded networks"
```

---

### Task 5: Frontend — the per-feed switch

**Files:**
- Create: `frontend/src/app/reader/feed-exclusions.ts`, `frontend/src/app/reader/feed-exclusions.spec.ts`
- Modify: `reader/models.ts:127, 411`, `reader/subscription-bulk.ts`, `reader/state/subscriptions.store.ts:348-357`, `reader/feeds/manage/manage-actions.service.ts:48-56, 106-133, 333-341`, `reader/feeds/manage/edit-subscription-dialog.component.{ts,html}`, `reader/shell/sidebar/sidebar-feed-row.component.{ts,html}`, `reader/shell/sidebar/sidebar-row-actions.service.ts:55-91, 136-148`, `settings/organise/organise-feed-row.component.{ts,html}`, `settings/organise/organise-section.component.{ts,html}`, `settings/organise/organise-tag-group.component.html:122`, `public/i18n/{en,de}.json`, test factories
- Test: the specs next to each modified file

**Interfaces:**
- Consumes: JSON `includeInSavedSearches` (Task 1).
- Produces: `SubscriptionDto.includeInSavedSearches: boolean`; `ManageActions.setIncludeInSavedSearches(sub, value)`; `excludedSurfaces(sub, includedNetworks): ExclusionSurface[]` and `exclusionTitle(...)` in `feed-exclusions.ts` (Task 6 passes networks in).

- [ ] **Step 1: Write the failing tests**

`frontend/src/app/reader/feed-exclusions.spec.ts`:

```ts
import { excludedSurfaces } from './feed-exclusions';
import { subscription } from './testing/subscription.factory';

describe('excludedSurfaces', () => {
  it('is empty for a plain feed with every switch on', () => {
    expect(excludedSurfaces(subscription(), new Set())).toEqual([]);
  });

  it('lists every surface whose switch is off, in sidebar order', () => {
    const feed = subscription({
      includeInAllItems: false,
      includeInForYou: false,
      includeInSavedSearches: false,
    });

    expect(excludedSurfaces(feed, new Set())).toEqual(['allItems', 'forYou', 'savedSearches']);
  });
});
```

(Read `reader/testing/subscription.factory.ts` for its exported factory name and overrides shape; use that.)

Extend the existing specs, each next to its For-you case:
- `manage-actions.service.spec.ts`: `setIncludeInSavedSearches(sub, false)` patches the store locally, PATCHes `{customTitle, tagIds, includeInSavedSearches: false}`, then reloads subscriptions **and** saved searches.
- `edit-subscription-dialog.component.spec.ts`: the third switch starts from `data.includeInSavedSearches` and its value is in the PATCH body.
- `sidebar-row-actions.service.spec.ts` (or `sidebar.component.spec.ts`, wherever the feed sheet is tested): the sheet offers `reader.excludeFromSavedSearches`, and choosing it calls `setIncludeInSavedSearches(sub, false)`.
- `organise-feed-row.component.spec.ts`: a `toggle-saved-searches` menu item emits `toggleSavedSearches`.
- `organise-section.component.spec.ts`: the visibility menu has six commands, and the two new ones send `{ includeInSavedSearches: true|false }`.

- [ ] **Step 2: Run them and see them fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/feed-exclusions.spec.ts src/app/reader/feeds/manage src/app/reader/shell/sidebar src/app/settings/organise`
Expected: FAIL — module `./feed-exclusions` not found; `setIncludeInSavedSearches` is not a function.

- [ ] **Step 3: Implement**

`models.ts`: add `includeInSavedSearches: boolean;` after `includeInForYou` in `SubscriptionDto`, and `includeInSavedSearches?: boolean;` in `SubscriptionUpdate`. `subscription-bulk.ts`: add `includeInSavedSearches?: boolean;` to `SubscriptionFlags` and change its doc to "The per-feed inclusion switches". Test factories: default `includeInSavedSearches: true`.

`subscriptions.store.ts` `patchLocal` flags type → `flags: SubscriptionFlags` (import it from `../subscription-bulk`), comment "(All items / For you / saved searches)".

`frontend/src/app/reader/feed-exclusions.ts`:

```ts
import { TranslocoService } from '@jsverse/transloco';
import { SubscriptionDto } from './models';
import { SocialNetworkName } from '../core/social-network';

export type ExclusionSurface = 'allItems' | 'forYou' | 'savedSearches';

const SURFACE_LABEL_KEYS: Readonly<Record<ExclusionSurface, string>> = {
  allItems: 'reader.allItems',
  forYou: 'reader.forYou',
  savedSearches: 'reader.savedSearches',
};

/** A feed of a network the user has not included counts as switched off for saved searches. */
export function excludedSurfaces(
  subscription: SubscriptionDto,
  includedNetworks: ReadonlySet<SocialNetworkName>,
): ExclusionSurface[] {
  const network = subscription.social?.network ?? null;
  const networkLeftOut = network !== null && !includedNetworks.has(network);
  const surfaces: ExclusionSurface[] = [];
  if (!subscription.includeInAllItems) surfaces.push('allItems');
  if (!subscription.includeInForYou) surfaces.push('forYou');
  if (!subscription.includeInSavedSearches || networkLeftOut) surfaces.push('savedSearches');
  return surfaces;
}

export function exclusionTitle(i18n: TranslocoService, surfaces: readonly ExclusionSurface[]): string {
  const names = surfaces.map((surface) => i18n.translate(SURFACE_LABEL_KEYS[surface])).join(', ');
  return i18n.translate('reader.excludedFrom', { surfaces: names });
}
```

(Task 6 supplies real `includedNetworks`; until then callers pass `new Set()`. Note: with an empty set every social feed shows as excluded from saved searches — which is the true default.)

`manage-actions.service.ts`:
- inject `SavedSearchesStore` (`../../state/saved-searches.store`).
- replace the two setters and `patchFlags` with:

```ts
setIncludeInAllItems(sub: SubscriptionDto, value: boolean): void {
  this.patchFlags(sub, { includeInAllItems: value });
}

setIncludeInForYou(sub: SubscriptionDto, value: boolean): void {
  this.patchFlags(sub, { includeInForYou: value });
}

setIncludeInSavedSearches(sub: SubscriptionDto, value: boolean): void {
  this.patchFlags(sub, { includeInSavedSearches: value });
}

/** The backend PATCH clears customTitle/tagIds when they are omitted, so every flag toggle resends them; an
 *  omitted flag stays as it is. */
private patchFlags(sub: SubscriptionDto, flags: SubscriptionFlags): void {
  this.subs.patchLocal(sub.id, flags);
  this.reloadAfter(
    this.api.updateSubscription(sub.id, {
      customTitle: sub.customTitle,
      tagIds: sub.tags.map((tag) => tag.id),
      ...flags,
    }),
    () => this.reloadFeedsAndSearches(),
  );
}

/** A feed's flags move saved-search badges and counts, which only a reload re-learns. */
private reloadFeedsAndSearches(): void {
  this.subs.load();
  this.savedSearches.load();
}
```

- `editSubscription()` closes with `if (updated) this.reloadFeedsAndSearches();` and `bulkPatch()` taps `this.reloadFeedsAndSearches();` instead of `this.subs.load();`.
- Update the specs that asserted the full two-flag body (`manage-actions.service.spec.ts`) to the single-flag body.

`edit-subscription-dialog.component.ts`: `readonly includeInSavedSearches = signal<boolean>(this.data.includeInSavedSearches);` and `includeInSavedSearches: this.includeInSavedSearches(),` in `submit()`'s body. `.html`, after the For-you row:

```html
<div class="switch-row">
  <label class="switch-label" for="edit-feed-saved-searches">
    {{ 'dialog.editFeed.showInSavedSearches' | transloco }}
  </label>
  <app-toggle
    inputId="edit-feed-saved-searches"
    [checked]="includeInSavedSearches()"
    [label]="'dialog.editFeed.showInSavedSearches' | transloco"
    (toggled)="includeInSavedSearches.set($event)"
  />
</div>
```

Sidebar row (`sidebar-feed-row.component.ts`): add

```ts
private readonly i18n = inject(TranslocoService);
/** A private feed is out of All items and For you by design, so only its saved-search state is news. */
protected readonly markerSurfaces = computed(() => {
  const surfaces = excludedSurfaces(this.subscription(), new Set());
  return this.isPrivate() ? surfaces.filter((surface) => surface === 'savedSearches') : surfaces;
});
protected readonly markerTitle = computed(() => exclusionTitle(this.i18n, this.markerSurfaces()));
```

`.html`: the marker condition becomes `@if (markerSurfaces().length > 0)` with `[title]="markerTitle()"`; after the For-you menu button add

```html
<button
  role="menuitem"
  (click)="manage.setIncludeInSavedSearches(feed, !feed.includeInSavedSearches); rows.closeMenu()"
>
  {{
    (feed.includeInSavedSearches ? 'reader.excludeFromSavedSearches' : 'reader.includeInSavedSearches')
      | transloco
  }}
</button>
```

`sidebar-row-actions.service.ts`: in `openFeedSheet` add after `toggleForYou`

```ts
{
  id: 'toggleSavedSearches',
  label: this.toggleLabel(
    subscription.includeInSavedSearches,
    'reader.excludeFromSavedSearches',
    'reader.includeInSavedSearches',
  ),
},
```

and `if (choice === 'toggleSavedSearches') this.manage.setIncludeInSavedSearches(subscription, !subscription.includeInSavedSearches);`. Delete `exclusionTitle()` there (the row computes its own now) and its spec cases; move their intent into `feed-exclusions.spec.ts`.

Organise row (`organise-feed-row.component.ts`): `readonly toggleSavedSearches = output<void>();`, a sheet action `{ id: 'savedSearches', label: this.i18n.translate(sub.includeInSavedSearches ? 'reader.excludeFromSavedSearches' : 'reader.includeInSavedSearches') }` with `if (choice === 'savedSearches') this.toggleSavedSearches.emit();`, and `protected readonly excluded = computed(() => excludedSurfaces(this.subscription(), new Set()).length > 0);`. `.html`: the marker condition becomes `@if (excluded())`; add after the For-you menu button

```html
<button
  role="menuitem"
  data-test="toggle-saved-searches"
  (click)="toggleSavedSearches.emit(); closeMenu()"
>
  {{
    (subscription().includeInSavedSearches
      ? 'reader.excludeFromSavedSearches'
      : 'reader.includeInSavedSearches'
    ) | transloco
  }}
</button>
```

In `organise-section.component.html:311` and `organise-tag-group.component.html:122` add `(toggleSavedSearches)="manage.setIncludeInSavedSearches(s, !s.includeInSavedSearches)"`. In `organise-section.component.ts` append to `visibilityMenuItems`:

```ts
{ flag: 'includeInSavedSearches', value: true, labelKey: 'settings.organise.showInSavedSearches' },
{ flag: 'includeInSavedSearches', value: false, labelKey: 'settings.organise.hideFromSavedSearches' },
```

and change its doc "Four commands" → "Commands".

i18n — `en.json`:
- `dialog.editFeed.showInSavedSearches`: "Show in saved searches"
- `settings.organise.showInSavedSearches`: "Show in saved searches", `hideFromSavedSearches`: "Hide from saved searches"
- `reader.excludeFromSavedSearches`: "Exclude from saved searches", `reader.includeInSavedSearches`: "Show in saved searches"
- `reader.excludedFrom`: "Hidden from {{surfaces}}"
- delete `reader.excludedFromAllItems`, `reader.excludedFromForYou`, `reader.excludedFromBoth`

`de.json`: "In gespeicherten Suchen anzeigen", "Aus gespeicherten Suchen ausblenden", "Aus gespeicherten Suchen ausschließen", "Ausgeblendet aus {{surfaces}}"; delete the same three keys.

- [ ] **Step 4: Run the tests and see them pass**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "feat(#1742): switch a feed out of saved searches from the dialog, sidebar and organise page"
```

---

### Task 6: Frontend — the network switch

**Files:**
- Modify: `reader/models.ts` (`SavedSearchesResponse`), `reader/reader-api.ts:293-316`, `reader/state/saved-searches.store.ts`, `reader/shell/sidebar/sidebar-social-section.component.{ts,html}`, `reader/shell/sidebar/sidebar-row-actions.service.ts:96-115`, `reader/shell/sidebar/sidebar-feed-row.component.ts`, `settings/organise/organise-feed-row.component.ts`, `public/i18n/{en,de}.json`
- Test: `saved-searches.store.spec.ts`, `sidebar-social-section.component.spec.ts`, `sidebar-feed-row.component.spec.ts`, `feed-exclusions.spec.ts`

**Interfaces:**
- Consumes: `GET /api/saved-searches` → `includedNetworks`, `PUT|DELETE /api/saved-searches/included-networks/{network}` (Task 2); `excludedSurfaces` (Task 5).
- Produces: `SavedSearchesStore.includedNetworks: Signal<ReadonlySet<SocialNetworkName>>`, `SavedSearchesStore.setNetworkIncluded(network, included)`.

- [ ] **Step 1: Write the failing tests**

`feed-exclusions.spec.ts`, add:

```ts
it('counts a social feed of a network the user has not included as out of saved searches', () => {
  const post = subscription({ social: { network: 'bluesky', accountId: null, kind: 'profile' } });

  expect(excludedSurfaces(post, new Set())).toEqual(['savedSearches']);
  expect(excludedSurfaces(post, new Set(['bluesky']))).toEqual([]);
});
```

`saved-searches.store.spec.ts`: after `load()` answers `{ savedSearches: [], includedNetworks: ['mastodon'] }`, `includedNetworks()` holds `mastodon`; `setNetworkIncluded('bluesky', true)` adds it at once, sends `PUT /api/saved-searches/included-networks/bluesky`, then reloads; a failed write reloads too (so the switch snaps back).

`sidebar-social-section.component.spec.ts`: the head menu offers `reader.includeNetworkInSavedSearches` while the network is not included and `reader.excludeNetworkFromSavedSearches` once it is; clicking calls `setNetworkIncluded`; the menu is there even for a network with no connected account (profiles only).

`sidebar-feed-row.component.spec.ts`: a Bluesky profile row shows the hidden marker until the store includes `bluesky`.

- [ ] **Step 2: Run them and see them fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/feed-exclusions.spec.ts src/app/reader/state/saved-searches.store.spec.ts src/app/reader/shell/sidebar`
Expected: FAIL — `includedNetworks` is not a function.

- [ ] **Step 3: Implement**

`reader-api.ts`: the list call's type becomes `Observable<SavedSearchesResponse>` with, in `models.ts`,

```ts
export interface SavedSearchesResponse {
  savedSearches: SavedSearchWire[];
  includedNetworks: SocialNetworkName[];
}
```

and add

```ts
includeNetworkInSavedSearches(network: SocialNetworkName): Observable<void> {
  return this.http.put<void>(`${this.base}/api/saved-searches/included-networks/${network}`, null);
}

excludeNetworkFromSavedSearches(network: SocialNetworkName): Observable<void> {
  return this.http.delete<void>(`${this.base}/api/saved-searches/included-networks/${network}`);
}
```

`saved-searches.store.ts`:

```ts
private readonly storedIncludedNetworks = signal<ReadonlySet<SocialNetworkName>>(new Set());
/** The social networks whose feeds count in saved searches; every other network's are left out. */
readonly includedNetworks = this.storedIncludedNetworks.asReadonly();
```

In `load()`'s `next`, add `this.storedIncludedNetworks.set(new Set(response.includedNetworks));`. Add:

```ts
/** Optimistic, then a reload re-learns the badges the switch moved, or snaps it back if the write failed. */
setNetworkIncluded(network: SocialNetworkName, included: boolean): void {
  this.storedIncludedNetworks.update((current) => {
    const next = new Set(current);
    if (included) next.add(network);
    else next.delete(network);
    return next;
  });
  const write = included
    ? this.api.includeNetworkInSavedSearches(network)
    : this.api.excludeNetworkFromSavedSearches(network);
  write.subscribe({ next: () => this.load(), error: () => this.load() });
}
```

Split `setNetworkIncluded` into `includeNetwork(network)` / `excludeNetwork(network)` if the boolean parameter trips the frontend lint or review (CLAUDE.md forbids boolean flag parameters in PHP; apply the same taste here): callers then pick the method by the current state.

`sidebar-feed-row.component.ts` and `organise-feed-row.component.ts`: inject `SavedSearchesStore` and pass `this.savedSearches.includedNetworks()` to `excludedSurfaces` instead of `new Set()`.

`sidebar-social-section.component.ts`: inject `SavedSearchesStore` as `protected readonly savedSearches`; add

```ts
protected readonly networkIncluded = computed(() =>
  this.savedSearches.includedNetworks().has(this.section().network),
);
```

`.html`: drop the `@if (current.networkAccounts.length > 0)` guard around the menu (the saved-search item is always there; the account items still come from `socialAccountActions`, which is empty without accounts). In the desktop menu template, after the account loop:

```html
<button
  role="menuitem"
  (click)="savedSearches.setNetworkIncluded(current.network, !networkIncluded()); rows.closeMenu()"
>
  {{
    (networkIncluded()
      ? 'reader.excludeNetworkFromSavedSearches'
      : 'reader.includeNetworkInSavedSearches'
    ) | transloco
  }}
</button>
```

`sidebar-row-actions.service.ts` `openSocialSheet(section)`: append an action `{ id: 'savedSearches', label: ... }` computed from `this.savedSearches.includedNetworks().has(section.network)` (inject the store), and on that choice call `this.savedSearches.setNetworkIncluded(section.network, !included)`.

i18n — `en.json`: `reader.includeNetworkInSavedSearches`: "Include in saved searches", `reader.excludeNetworkFromSavedSearches`: "Exclude from saved searches". `de.json`: "In gespeicherte Suchen aufnehmen", "Aus gespeicherten Suchen ausschließen".

- [ ] **Step 4: Run the tests and see them pass**

Run the Step 2 command, then the whole suite: `docker compose exec -T frontend npm test`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "feat(#1742): include a social network in saved searches from its sidebar heading"
```

---

### Task 7: Gates, migration check, live check

- [ ] **Step 1: Backend gates**

```bash
cd backend && bin/console cache:warmup && composer check && composer md && composer test:parallel
docker compose exec php composer test
composer infection:diff
```

Expected: all green. Fix the design a PHPMD finding points at; never raise a threshold or `minMsi`.

- [ ] **Step 2: Migration on both dialects**

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```

Also migrate a fresh SQLite database the way the CI migration leg does (read `.github/workflows` for its exact commands) and run `doctrine:schema:validate` there. Expected: "in sync" on both.

- [ ] **Step 3: Frontend gate**

```bash
cd frontend && npm run check
```

Expected: PASS.

- [ ] **Step 4: Live check on :4200**

Confirm the containers run this branch's code (memory: check the container is current; restart the worker and php-fpm if needed), then in the browser:
1. A Bluesky post matching a saved search is absent from the search, its badge and count.
2. Bluesky heading menu → "Include in saved searches": the post appears and the badge rises; exclude again: it leaves.
3. Edit a plain feed → switch "Show in saved searches" off: its matches leave the search; the row shows the eye marker, tooltip "Hidden from Saved searches".
4. Organise → select two feeds → Visibility → "Hide from saved searches": both change.

Scan today's dev log for errors: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name=="ERROR" or .level_name=="CRITICAL")'`.

- [ ] **Step 5: Commit any fixes**

```bash
git add -A backend frontend
git commit -m "fix(#1742): <what the gates or the live check found>"
```
