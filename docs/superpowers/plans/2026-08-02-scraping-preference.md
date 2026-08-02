# Scraping as an Opt-In Experimental Preference — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make website scraping a per-user preference that is off by default and labelled experimental, instead of an unconditional discovery fallback.

**Architecture:** A new `Preferences` entity holds one row per user, created by the `User` constructor so every account-creation path gets it for free. Two independent code paths reach the scraper — feed discovery and the direct `format: 'scraped'` subscribe/preview — and both are gated. `FeedDiscovery` never learns about `User`; it receives a `ScrapeFallback` enum, which one policy service produces from a user.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine ORM, Angular 20 with signals and Transloco, PHPUnit, Jest.

**Spec:** [docs/superpowers/specs/2026-08-02-scraping-preference-design.md](../specs/2026-08-02-scraping-preference-design.md)
**Issue:** [#237](https://github.com/larspohlmann/simple-feed-reader/issues/237)
**Branch:** `feature/237-scraping-preference` (already created, spec already committed)

## Global Constraints

- Every PHP file starts with `declare(strict_types=1);`.
- PHP classes are `final`, and `final readonly` with constructor promotion where the class is a service or a value object. Entities are not readonly.
- No boolean flag parameters. Use an enum.
- Errors are typed exceptions in `Service/*/Exception/`, never `null` or a magic value.
- Controllers have no private methods that carry responsibility (`ThinControllerRule` in PHPStan).
- Comments explain **why**, never **what**.
- PHPStan runs at level max over `src` and `tests`. Array shapes need generics. Warm the cache with `bin/console cache:warmup` before `composer stan`.
- Every `src` file touched must be PHPMD-clean before commit, not merely free of new findings.
- Frontend: no hex colours and no raw `px` spacing or media-query literals in `.scss` outside `src/app/theme/`. Stylelint fails the build on both.
- Frontend: component styles live in a sibling `.scss` file via `styleUrl`, never inline in the `.ts`.
- Frontend: Prettier wraps at 100 columns.
- Every new user-facing string gets a key in **both** `frontend/public/i18n/en.json` and `frontend/public/i18n/de.json`.
- Backend commands run from `backend/`, frontend commands from `frontend/`.

---

### Task 1: The `Preferences` entity and its `User` composition

**Files:**
- Create: `backend/src/Entity/Preferences.php`
- Create: `backend/src/Repository/PreferencesRepository.php`
- Modify: `backend/src/Entity/User.php` (add the relation, constructor line and getter)
- Test: `backend/tests/Entity/PreferencesTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Entity\Preferences` with `getId(): ?int`, `getUser(): User`, `isScrapeFallbackEnabled(): bool`, `setScrapeFallbackEnabled(bool $enabled): void`, and constructor `__construct(User $user)`. `App\Entity\User::getPreferences(): Preferences`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Entity/PreferencesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Preferences;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class PreferencesTest extends TestCase
{
    public function testANewUserHasPreferencesWithScrapingDisabled(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-08-02 10:00:00'));

        self::assertFalse($user->getPreferences()->isScrapeFallbackEnabled());
    }

    public function testPreferencesPointBackAtTheirUser(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-08-02 10:00:00'));

        self::assertSame($user, $user->getPreferences()->getUser());
    }

    public function testScrapeFallbackCanBeEnabled(): void
    {
        $preferences = new Preferences(
            new User('reader@example.com', new \DateTimeImmutable('2026-08-02 10:00:00')),
        );

        $preferences->setScrapeFallbackEnabled(true);

        self::assertTrue($preferences->isScrapeFallbackEnabled());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Entity/PreferencesTest.php`
Expected: FAIL with `Class "App\Entity\Preferences" not found`.

- [ ] **Step 3: Create the entity**

Create `backend/src/Entity/Preferences.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PreferencesRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row of per-account settings. Created by the User constructor rather than
 * by each caller, so no account-creation path can forget it.
 */
#[ORM\Entity(repositoryClass: PreferencesRepository::class)]
#[ORM\Table(name: 'user_preferences')]
#[ORM\UniqueConstraint(name: 'uniq_preferences_user', columns: ['user_id'])]
class Preferences
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'preferences')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    /**
     * Whether feed discovery may fall back to scraping a plain HTML page.
     * Off by default: extraction quality depends entirely on the target page
     * and can break whenever that page changes, so the feature is opt-in and
     * presented as experimental.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $scrapeFallbackEnabled = false;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function isScrapeFallbackEnabled(): bool
    {
        return $this->scrapeFallbackEnabled;
    }

    public function setScrapeFallbackEnabled(bool $scrapeFallbackEnabled): void
    {
        $this->scrapeFallbackEnabled = $scrapeFallbackEnabled;
    }
}
```

- [ ] **Step 4: Create the repository**

Create `backend/src/Repository/PreferencesRepository.php`. Copy the shape of an existing simple repository; if `backend/src/Repository/UserRepository.php` extends `ServiceEntityRepository`, match it exactly.

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Preferences;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Preferences>
 */
final class PreferencesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Preferences::class);
    }
}
```

- [ ] **Step 5: Wire the relation into `User`**

In `backend/src/Entity/User.php`, add the property after `$maxSubscriptions` (around line 99):

```php
    /**
     * Per-account settings. The constructor creates the row, so every creation
     * path gets one without knowing about preferences.
     *
     * Nullable only because Doctrine hydration bypasses the constructor: a
     * hydrated row without preferences is a corrupt row, not a supported
     * state, and getPreferences() says so.
     */
    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist'], orphanRemoval: true)]
    private ?Preferences $preferences = null;
```

At the end of the constructor body (after `$this->createdAt = $createdAt;`, around line 110), add:

```php
        $this->preferences = new Preferences($this);
```

Add the getter next to `getMaxSubscriptions()` (around line 251):

```php
    /**
     * Mirrors the getUserIdentifier() guard: the invariant is set in the
     * constructor, and Doctrine hydration bypasses it, so it is re-checked
     * here where callers actually depend on it.
     */
    public function getPreferences(): Preferences
    {
        if (null === $this->preferences) {
            throw new \LogicException('User has no preferences row; the stored row is corrupt.');
        }

        return $this->preferences;
    }
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php bin/phpunit tests/Entity/PreferencesTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 7: Run the full backend suite**

Run: `php bin/phpunit`
Expected: PASS. The ORM builds the test schema from metadata, so the new table appears automatically and existing tests keep working.

- [ ] **Step 8: Lint**

Run: `composer cs:fix && bin/console cache:warmup && composer check && composer md`
Expected: all clean.

- [ ] **Step 9: Commit**

```bash
git add backend/src/Entity/Preferences.php backend/src/Repository/PreferencesRepository.php backend/src/Entity/User.php backend/tests/Entity/PreferencesTest.php
git commit -m "feat(#237): add a Preferences entity owned by User"
```

---

### Task 2: The migration

**Files:**
- Create: `backend/migrations/Version20260802120000.php`

**Interfaces:**
- Consumes: the `user_preferences` table shape from Task 1.
- Produces: nothing consumed by later tasks.

The suite never executes migrations — `tests/bootstrap.php` builds the schema from ORM metadata — so this task is verified by running the migration by hand on both platforms.

- [ ] **Step 1: Write the migration**

Create `backend/migrations/Version20260802120000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds user_preferences, one row of per-account settings per user, starting
 * with scrape_fallback_enabled.
 *
 * PLATFORM-AWARE DDL for the same reason Version20260724120000 is: a
 * `doctrine:migrations:diff` on a SQLite dev box emits SQLite-only DDL MySQL
 * cannot parse, and the suite cannot catch it because tests build their schema
 * from ORM metadata rather than by executing this chain.
 *
 * The backfill is not optional. User::getPreferences() throws when the row is
 * missing, and hydration bypasses the constructor that would create it, so
 * every account that predates this migration needs its row written here.
 */
final class Version20260802120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_preferences (scrape_fallback_enabled) and backfill one row per user';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $mysql = $platform instanceof AbstractMySQLPlatform;
        $sqlite = $platform instanceof SQLitePlatform;

        // Better a refusal than DDL invented for a platform nobody tested.
        $this->abortIf(!$mysql && !$sqlite, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));

        // Idempotence for a database baselined from doctrine:schema:create,
        // where ORM metadata already produced the table.
        if ($schema->hasTable('user_preferences')) {
            return;
        }

        if ($mysql) {
            $this->addSql(
                'CREATE TABLE user_preferences ('
                . 'id INT AUTO_INCREMENT NOT NULL, '
                . 'user_id INT NOT NULL, '
                . 'scrape_fallback_enabled TINYINT(1) DEFAULT 0 NOT NULL, '
                . 'UNIQUE INDEX uniq_preferences_user (user_id), '
                . 'PRIMARY KEY(id)'
                . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
            );
            $this->addSql(
                'ALTER TABLE user_preferences ADD CONSTRAINT fk_preferences_user '
                . 'FOREIGN KEY (user_id) REFERENCES app_user (id)',
            );
        } else {
            $this->addSql(
                'CREATE TABLE user_preferences ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
                . 'user_id INTEGER NOT NULL, '
                . 'scrape_fallback_enabled BOOLEAN DEFAULT 0 NOT NULL, '
                . 'CONSTRAINT fk_preferences_user FOREIGN KEY (user_id) REFERENCES app_user (id) '
                . 'NOT DEFERRABLE INITIALLY IMMEDIATE'
                . ')',
            );
            $this->addSql(
                'CREATE UNIQUE INDEX uniq_preferences_user ON user_preferences (user_id)',
            );
        }

        // Every existing account needs its row; see the class docblock.
        $this->addSql(
            'INSERT INTO user_preferences (user_id, scrape_fallback_enabled) '
            . 'SELECT id, 0 FROM app_user',
        );
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('user_preferences')) {
            $this->addSql('DROP TABLE user_preferences');
        }
    }
}
```

- [ ] **Step 2: Verify the migration on SQLite from empty**

```bash
cd backend && rm -f var/migrate-check.db && DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" bin/console doctrine:migrations:migrate --no-interaction
```

Expected: every migration runs, ending with `Version20260802120000`, no errors.

- [ ] **Step 3: Validate the SQLite schema against the ORM mapping**

```bash
cd backend && DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" bin/console doctrine:schema:validate
```

Expected: `[OK] The database schema is in sync with the mapping files.`

If it reports a difference on `user_preferences`, fix the DDL above to match what the ORM expects — do not change the entity to match the DDL.

- [ ] **Step 4: Verify on MySQL from empty**

```bash
docker compose up -d && docker compose exec php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec php bin/console doctrine:schema:validate
```

Expected: migrations apply and the schema validates. If the MySQL database already has data from earlier work, that is fine — the backfill is what you want to see exercised. Confirm it wrote rows:

```bash
docker compose exec php bin/console dbal:run-sql "SELECT COUNT(*) AS preferences, (SELECT COUNT(*) FROM app_user) AS users FROM user_preferences"
```

Expected: the two counts are equal.

- [ ] **Step 5: Clean up the throwaway SQLite file**

```bash
cd backend && rm -f var/migrate-check.db
```

- [ ] **Step 6: Commit**

```bash
git add backend/migrations/Version20260802120000.php
git commit -m "feat(#237): migrate user_preferences with a per-user backfill"
```

---

### Task 3: Expose preferences on `GET /api/me`

**Files:**
- Modify: `backend/src/Http/MeJson.php`
- Test: `backend/tests/Controller/Api/MeControllerTest.php` (add one test)

**Interfaces:**
- Consumes: `User::getPreferences()` from Task 1.
- Produces: the `preferences` key in the `/api/me` payload, shape `array{scrapeFallbackEnabled: bool}`. Task 7 (frontend model) depends on this exact key spelling.

- [ ] **Step 1: Write the failing test**

Add to `backend/tests/Controller/Api/MeControllerTest.php`. Read the existing tests in that file first and match their client-and-auth setup exactly; the assertion below is the part that matters:

```php
    public function testTheProfileCarriesPreferencesWithScrapingOffByDefault(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('prefs-default@example.com');

        $client->request('GET', '/api/me', server: $this->authHeader($user->getEmail()));

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(['scrapeFallbackEnabled' => false], $payload['preferences']);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Controller/Api/MeControllerTest.php`
Expected: FAIL — `preferences` is an undefined array key.

- [ ] **Step 3: Add the key to `MeJson`**

In `backend/src/Http/MeJson.php`, add to the returned array after `trialEndsAt`:

```php
            'preferences' => [
                'scrapeFallbackEnabled' => $user->getPreferences()->isScrapeFallbackEnabled(),
            ],
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php bin/phpunit tests/Controller/Api/MeControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Http/MeJson.php backend/tests/Controller/Api/MeControllerTest.php
git commit -m "feat(#237): expose preferences on GET /api/me"
```

---

### Task 4: `PATCH /api/me/preferences`

**Files:**
- Create: `backend/src/Dto/Me/UpdatePreferencesRequest.php`
- Modify: `backend/src/Controller/Api/MeController.php`
- Test: `backend/tests/Controller/Api/MeControllerTest.php` (add tests)

**Interfaces:**
- Consumes: `MeJson::profile()` from Task 3, `User::getPreferences()` from Task 1.
- Produces: route name `api_me_update_preferences` at `PATCH /api/me/preferences`, accepting `{"scrapeFallbackEnabled": bool}` and returning the full `MeJson::profile()` payload. Task 7 depends on this URL and body shape.

- [ ] **Step 1: Write the failing tests**

Add to `backend/tests/Controller/Api/MeControllerTest.php`, matching the file's existing setup helpers:

```php
    public function testScrapeFallbackCanBeEnabled(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('prefs-enable@example.com');

        $client->request(
            'PATCH',
            '/api/me/preferences',
            server: $this->authHeader($user->getEmail()),
            content: json_encode(['scrapeFallbackEnabled' => true], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(['scrapeFallbackEnabled' => true], $payload['preferences']);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->clear();
        $reloaded = $entityManager->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertTrue($reloaded->getPreferences()->isScrapeFallbackEnabled());
    }

    public function testANonBooleanPreferenceIsRejected(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('prefs-invalid@example.com');

        $client->request(
            'PATCH',
            '/api/me/preferences',
            server: $this->authHeader($user->getEmail()),
            content: json_encode(['scrapeFallbackEnabled' => 'yes'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
    }
```

Add `use App\Entity\User;` to the test file's imports if it is not already there.

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Controller/Api/MeControllerTest.php`
Expected: FAIL with a 404 — the route does not exist.

- [ ] **Step 3: Create the request DTO**

Create `backend/src/Dto/Me/UpdatePreferencesRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Me;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The payload is required rather than defaulted, for the same reason
 * UpdateLocaleRequest refuses an unsupported locale: a preference that
 * degrades quietly to a default is indistinguishable from one the user set.
 */
final readonly class UpdatePreferencesRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Type('bool')]
        public bool $scrapeFallbackEnabled = false,
    ) {
    }
}
```

- [ ] **Step 4: Add the action**

In `backend/src/Controller/Api/MeController.php`, add the import `use App\Dto\Me\UpdatePreferencesRequest;` and this action after `updateLocale()`:

```php
    /**
     * Per-account settings. Separate from the locale PATCH because
     * UpdateLocaleRequest requires a non-blank locale: folding preferences into
     * it would force every preference write to resend the language, or cost the
     * locale its 422-on-unsupported-value guarantee (#180).
     */
    #[Route('/api/me/preferences', name: 'api_me_update_preferences', methods: ['PATCH'])]
    public function updatePreferences(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdatePreferencesRequest $request,
    ): JsonResponse {
        $user->getPreferences()->setScrapeFallbackEnabled($request->scrapeFallbackEnabled);
        $this->entityManager->flush();

        return new JsonResponse(MeJson::profile($user));
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Controller/Api/MeControllerTest.php`
Expected: PASS.

If the 422 test fails with a 400 instead, check how other DTOs in this codebase surface validation failures and match that behaviour rather than changing the assertion blindly.

- [ ] **Step 6: Lint**

Run: `composer cs:fix && bin/console cache:warmup && composer check && composer md`
Expected: clean. `ThinControllerRule` must stay silent — the action only reads the payload, delegates and returns.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Dto/Me/UpdatePreferencesRequest.php backend/src/Controller/Api/MeController.php backend/tests/Controller/Api/MeControllerTest.php
git commit -m "feat(#237): add PATCH /api/me/preferences"
```

---

### Task 5: Gate the discovery path

**Files:**
- Create: `backend/src/Enum/ScrapeFallback.php`
- Create: `backend/src/Service/Discovery/ScrapeFallbackPolicy.php`
- Modify: `backend/src/Service/Discovery/FeedDiscoveryInterface.php`
- Modify: `backend/src/Service/Discovery/FeedDiscovery.php`
- Modify: `backend/src/Service/Subscription/SubscriptionService.php`
- Modify: `backend/tests/Service/Discovery/FeedDiscoveryTest.php`
- Modify: `backend/tests/Service/Subscription/SubscriptionServiceTest.php` (the anonymous double at line 36 must adopt the new signature)
- Modify: `backend/tests/Controller/Api/OnboardingControllerTest.php` (mock at line 105 — verify it still compiles)

**Interfaces:**
- Consumes: `User::getPreferences()` from Task 1.
- Produces:
  - `App\Enum\ScrapeFallback` with cases `Enabled` and `Disabled`.
  - `App\Service\Discovery\ScrapeFallbackPolicy::forUser(User $user): ScrapeFallback`.
  - `FeedDiscoveryInterface::discover(string $url, ScrapeFallback $fallback): FeedDiscoveryResult`.
  Task 6 consumes `ScrapeFallbackPolicy` and `ScrapeFallback`.

- [ ] **Step 1: Write the failing tests**

Add to `backend/tests/Service/Discovery/FeedDiscoveryTest.php`. It already has the helpers `discovery()`, `fetcherReturning()` and the `ScrapedFixtures` trait, whose accessor is `scrapedFixture(string $name)`. The existing scrape-fallback test at line 89 uses `heise-2026-07-23.html`, so these use the same fixture — a page with no advertised feed that the extractor does handle.

```php
    public function testAScrapablePageOffersNoCandidateWhenTheFallbackIsDisabled(): void
    {
        $fetcher = $this->fetcherReturning(
            'https://example.com/blog',
            'https://example.com/blog',
            $this->scrapedFixture('heise-2026-07-23.html'),
        );

        $result = $this->discovery($fetcher)->discover('https://example.com/blog', ScrapeFallback::Disabled);

        self::assertFalse($result->isDirectFeed);
        self::assertSame([], $result->candidates);
        // A null reason is what makes the dialog render its plain "no feed
        // found" state instead of a scrape-flavoured warning.
        self::assertNull($result->scrapeFailureReason);
    }

    public function testAnEmptyBodyReportsNoReasonWhenTheFallbackIsDisabled(): void
    {
        $fetcher = $this->fetcherReturning('https://example.com/blank', 'https://example.com/blank', '   ');

        $result = $this->discovery($fetcher)->discover('https://example.com/blank', ScrapeFallback::Disabled);

        self::assertSame([], $result->candidates);
        self::assertNull($result->scrapeFailureReason);
    }

    public function testAnUnreachableSiteStillReportsItsReasonWhenTheFallbackIsDisabled(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow('https://example.com/gone', new FeedUnreachableException('gone', 404));

        $result = $this->discovery($fetcher)->discover('https://example.com/gone', ScrapeFallback::Disabled);

        self::assertSame('unreachable', $result->scrapeFailureReason);
    }
```

`StubFeedFetcher::willThrow(string $url, FetchException $exception)` is the real signature; confirm `FeedUnreachableException`'s constructor argument order against `backend/src/Service/Fetch/Exception/FeedUnreachableException.php` and match the existing unreachable test in this file.

Add `use App\Enum\ScrapeFallback;` to the imports.

Then update every existing `->discover($url)` call in this file to `->discover($url, ScrapeFallback::Enabled)`, which preserves today's behaviour.

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Service/Discovery/FeedDiscoveryTest.php`
Expected: FAIL — `App\Enum\ScrapeFallback` not found.

- [ ] **Step 3: Create the enum**

Create `backend/src/Enum/ScrapeFallback.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Whether discovery may offer a plain HTML page as a scraped source. An enum
 * rather than a bool so the decision reads at the call site, and so discovery
 * never has to know which user it is deciding for.
 */
enum ScrapeFallback
{
    case Enabled;
    case Disabled;
}
```

- [ ] **Step 4: Create the policy**

Create `backend/src/Service/Discovery/ScrapeFallbackPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Entity\User;
use App\Enum\ScrapeFallback;

/**
 * The only place an account's preference becomes a discovery mode. Keeping the
 * mapping here is what lets FeedDiscovery stay free of any User dependency.
 */
final readonly class ScrapeFallbackPolicy
{
    public function forUser(User $user): ScrapeFallback
    {
        return $user->getPreferences()->isScrapeFallbackEnabled()
            ? ScrapeFallback::Enabled
            : ScrapeFallback::Disabled;
    }
}
```

- [ ] **Step 5: Change the interface**

In `backend/src/Service/Discovery/FeedDiscoveryInterface.php`, add `use App\Enum\ScrapeFallback;` and change the signature, extending the existing docblock:

```php
    /**
     * Never throws for an unreachable or feedless address: those are expected
     * outcomes the subscribe UI must render, so they surface as
     * FeedDiscoveryResult::$scrapeFailureReason
     * ('blocked'|'unreachable'|'not_scrapable') instead of an exception.
     * Callers can rely on always getting a result back to translate.
     *
     * With $fallback disabled, a page that advertises no feed yields an empty
     * candidate list and NO reason: 'not_scrapable' would tell the user about
     * a feature they have not turned on.
     */
    public function discover(string $url, ScrapeFallback $fallback): FeedDiscoveryResult;
```

- [ ] **Step 6: Gate `FeedDiscovery`**

In `backend/src/Service/Discovery/FeedDiscovery.php`, add `use App\Enum\ScrapeFallback;`, change the signature to match the interface, and change the two branches.

Replace the empty-body branch (currently lines 52-55):

```php
        $body = $response->body ?? '';
        if ('' === trim($body)) {
            return ScrapeFallback::Enabled === $fallback
                ? FeedDiscoveryResult::scrapeFailed('not_scrapable')
                : FeedDiscoveryResult::candidates([]);
        }
```

Replace the no-candidates branch (currently lines 65-68):

```php
        $candidates = $this->scanHtml($body, $response->finalUrl);
        if ([] === $candidates) {
            return ScrapeFallback::Enabled === $fallback
                ? $this->scrapeFallback($body, $response->finalUrl)
                : FeedDiscoveryResult::candidates([]);
        }
```

Leave the two `catch` blocks that produce `'blocked'` and `'unreachable'` untouched: those are fetch failures, not scraping outcomes.

- [ ] **Step 7: Thread the policy through `SubscriptionService`**

In `backend/src/Service/Subscription/SubscriptionService.php`, add `ScrapeFallbackPolicy $scrapeFallbackPolicy` to the promoted constructor parameters, and change the discovery call in `subscribe()`:

```php
        $result = $this->discovery->discover($url, $this->scrapeFallbackPolicy->forUser($user));
```

- [ ] **Step 8: Fix the test doubles**

In `backend/tests/Service/Subscription/SubscriptionServiceTest.php`, the anonymous class at line 36 implements the interface. Update its method and add the import:

```php
            public function discover(string $url, ScrapeFallback $fallback): FeedDiscoveryResult
            {
                return $this->result;
            }
```

Its `service()` helper at line 48 constructs `SubscriptionService` directly, so it also needs the new `ScrapeFallbackPolicy` argument — pass `new ScrapeFallbackPolicy()`.

In `backend/tests/Controller/Api/OnboardingControllerTest.php`, the `createMock(FeedDiscoveryInterface::class)` at line 105 adapts to the new signature automatically. Run the test to confirm.

- [ ] **Step 9: Run the tests**

Run: `php bin/phpunit`
Expected: PASS, including the three new discovery tests.

- [ ] **Step 10: Lint**

Run: `composer cs:fix && bin/console cache:warmup && composer check && composer md`
Expected: clean.

- [ ] **Step 11: Commit**

```bash
git add backend/src/Enum/ScrapeFallback.php backend/src/Service/Discovery backend/src/Service/Subscription/SubscriptionService.php backend/tests
git commit -m "feat(#237): gate the discovery scrape fallback on the user preference"
```

---

### Task 6: Close the direct scraped subscribe and preview bypass

This is the task that makes the gate real. `SubscriptionService::subscribe()` short-circuits on `SourceFormat::SCRAPED` **without running discovery**, and `FeedPreviewService::preview()` runs the extractor on the same terms. Task 5 alone leaves both reachable by a hand-made request.

**Files:**
- Create: `backend/src/Service/Subscription/Exception/ScrapingDisabledException.php`
- Modify: `backend/src/Service/Subscription/SubscriptionService.php`
- Modify: `backend/src/Service/Preview/FeedPreviewService.php`
- Modify: `backend/src/Controller/Api/FeedPreviewController.php`
- Modify: `backend/tests/Service/Subscription/SubscriptionServiceTest.php`
- Modify: `backend/tests/Service/Preview/FeedPreviewServiceTest.php` (exists; add a test and update its existing `preview()` calls for the new first parameter)

**Interfaces:**
- Consumes: `ScrapeFallbackPolicy`, `ScrapeFallback` from Task 5.
- Produces: `App\Service\Subscription\Exception\ScrapingDisabledException`; `FeedPreviewService::preview(User $user, string $url, ?string $format = null): FeedPreview`.

- [ ] **Step 1: Write the failing test**

Add to `backend/tests/Service/Subscription/SubscriptionServiceTest.php`, matching its existing helpers:

```php
    public function testAScrapedSubscribeIsRefusedWhenTheUserHasScrapingDisabled(): void
    {
        $user = $this->factory()->create('scrape-off@example.com');
        $service = $this->service($this->discoveryReturning(FeedDiscoveryResult::candidates([])));

        $this->expectException(ScrapingDisabledException::class);

        $service->subscribe($user, 'https://example.com/blog', SourceFormat::SCRAPED);
    }

    public function testAScrapedSubscribeSucceedsWhenTheUserHasScrapingEnabled(): void
    {
        $user = $this->factory()->create('scrape-on@example.com');
        $user->getPreferences()->setScrapeFallbackEnabled(true);
        $service = $this->service($this->discoveryReturning(FeedDiscoveryResult::candidates([])));

        $outcome = $service->subscribe($user, 'https://example.com/blog', SourceFormat::SCRAPED);

        self::assertNotNull($outcome->subscription);
    }
```

Add the imports for `ScrapingDisabledException` and `SourceFormat`.

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Service/Subscription/SubscriptionServiceTest.php`
Expected: FAIL — the exception class does not exist.

- [ ] **Step 3: Create the exception**

Create `backend/src/Service/Subscription/Exception/ScrapingDisabledException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription\Exception;

/**
 * A scraped source was requested by an account that has the experimental
 * scrape fallback turned off. Discovery never offers such a candidate to that
 * account, so this only arises from a hand-made request — which is exactly why
 * the check cannot live in discovery alone.
 */
final class ScrapingDisabledException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Website scraping is turned off for this account.');
    }
}
```

- [ ] **Step 4: Guard `SubscriptionService::subscribe()`**

In the `SourceFormat::SCRAPED` branch of `subscribe()`, add a guard clause before the existing return:

```php
        if (SourceFormat::SCRAPED === $format) {
            if (ScrapeFallback::Enabled !== $this->scrapeFallbackPolicy->forUser($user)) {
                throw new ScrapingDisabledException();
            }

            return SubscribeOutcome::subscribed(
                $this->createSubscription($user, $url, SourceFormat::SCRAPED, $tags),
            );
        }
```

Add the imports for `ScrapeFallback` and `ScrapingDisabledException`.

- [ ] **Step 5: Run the subscription tests**

Run: `php bin/phpunit tests/Service/Subscription/SubscriptionServiceTest.php`
Expected: PASS.

- [ ] **Step 6: Guard `FeedPreviewService::preview()`**

Change the signature to take the user first and inject the policy:

```php
    public function preview(User $user, string $url, ?string $format = null): FeedPreview
    {
        if (SourceFormat::SCRAPED === $format
            && ScrapeFallback::Enabled !== $this->scrapeFallbackPolicy->forUser($user)) {
            throw new ScrapingDisabledException();
        }

        // …existing body unchanged…
```

Add `ScrapeFallbackPolicy $scrapeFallbackPolicy` to the promoted constructor parameters, and the imports for `User`, `ScrapeFallback` and `ScrapingDisabledException`.

- [ ] **Step 7: Update the preview controller**

In `backend/src/Controller/Api/FeedPreviewController.php`, the action already has `#[CurrentUser] User $user`. Pass it through:

```php
            $preview = $this->previews->preview($user, $request->url, $request->format);
```

`ScrapingDisabledException` is not a `FeedPreviewException`, so the existing `catch` will not swallow it. Add a second catch that converts it, keeping the controller a pure delegation:

```php
        } catch (ScrapingDisabledException $e) {
            throw new FeedPreviewApiException($e->getMessage(), $e);
        }
```

Order the catches so `ScrapingDisabledException` is caught explicitly. Add the import.

- [ ] **Step 8: Add the preview test**

The file already exists. Every existing `preview(...)` call in it now needs a `User` as its first argument — add one built inline, since these tests do not touch the database. Then add:

```php
    public function testAScrapedPreviewIsRefusedWhenTheUserHasScrapingDisabled(): void
    {
        $user = new User('preview-off@example.com', new \DateTimeImmutable('2026-08-02 10:00:00'));

        $this->expectException(ScrapingDisabledException::class);

        $this->service()->preview($user, 'https://example.com/blog', SourceFormat::SCRAPED);
    }
```

- [ ] **Step 9: Run the full suite**

Run: `php bin/phpunit`
Expected: PASS.

- [ ] **Step 10: Lint and scan the log**

Run: `composer cs:fix && bin/console cache:warmup && composer check && composer md`
Then check `backend/var/log/dev.log` for new deprecations or swallowed errors.
Expected: clean.

- [ ] **Step 11: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#237): refuse scraped subscribe and preview when the preference is off"
```

---

### Task 7: Frontend model, writer and service

**Files:**
- Modify: `frontend/src/app/core/auth.service.ts` (extend `CurrentUser`)
- Create: `frontend/src/app/core/preferences-writer.ts`
- Create: `frontend/src/app/core/http-preferences-writer.ts`
- Create: `frontend/src/app/core/preferences.service.ts`
- Modify: `frontend/src/app/app.config.ts` (provide the real writer)
- Test: `frontend/src/app/core/preferences.service.spec.ts`

**Interfaces:**
- Consumes: `PATCH /api/me/preferences` from Task 4 and the `preferences` key from Task 3.
- Produces: `PreferencesService` with `scrapeFallbackEnabled: Signal<boolean>`, `saveFailed: Signal<boolean>`, `setScrapeFallbackEnabled(enabled: boolean): void` and `adopt(user: CurrentUser): void`. Task 9 consumes `PreferencesService`.

- [ ] **Step 1: Extend the user model**

In `frontend/src/app/core/auth.service.ts`, extend the interface:

```ts
export interface UserPreferences {
  scrapeFallbackEnabled: boolean;
}

export interface CurrentUser {
  id: number;
  email: string;
  roles: string[];
  status: string;
  createdAt: string;
  locale: string;
  trialEndsAt: string | null;
  preferences: UserPreferences;
}
```

- [ ] **Step 2: Write the failing test**

Create `frontend/src/app/core/preferences.service.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { Observable, of } from 'rxjs';
import { PREFERENCES_WRITER, PreferencesWriter } from './preferences-writer';
import { PreferencesService } from './preferences.service';

class FakeWriter implements PreferencesWriter {
  written: boolean[] = [];
  result = true;

  write(enabled: boolean): Observable<boolean> {
    this.written.push(enabled);
    return of(this.result);
  }
}

describe('PreferencesService', () => {
  let writer: FakeWriter;

  const service = (): PreferencesService => TestBed.inject(PreferencesService);

  beforeEach(() => {
    writer = new FakeWriter();
    TestBed.configureTestingModule({
      providers: [{ provide: PREFERENCES_WRITER, useValue: writer }],
    });
  });

  it('defaults to scraping disabled', () => {
    expect(service().scrapeFallbackEnabled()).toBe(false);
  });

  it('applies the value locally and writes it through', () => {
    const s = service();

    s.setScrapeFallbackEnabled(true);

    expect(s.scrapeFallbackEnabled()).toBe(true);
    expect(writer.written).toEqual([true]);
    expect(s.saveFailed()).toBe(false);
  });

  it('flags a failed write without reverting the local value', () => {
    writer.result = false;
    const s = service();

    s.setScrapeFallbackEnabled(true);

    expect(s.scrapeFallbackEnabled()).toBe(true);
    expect(s.saveFailed()).toBe(true);
  });

  it('adopts the account value without writing it back', () => {
    const s = service();

    s.adopt({
      id: 1,
      email: 'a@example.com',
      roles: [],
      status: 'active',
      createdAt: '2026-08-02T10:00:00+00:00',
      locale: 'en',
      trialEndsAt: null,
      preferences: { scrapeFallbackEnabled: true },
    });

    expect(s.scrapeFallbackEnabled()).toBe(true);
    expect(writer.written).toEqual([]);
  });
});
```

- [ ] **Step 3: Run to verify it fails**

Run: `npm test -- preferences.service`
Expected: FAIL — the modules do not exist.

- [ ] **Step 4: Create the writer token**

Create `frontend/src/app/core/preferences-writer.ts`:

```ts
// src/app/core/preferences-writer.ts
import { InjectionToken } from '@angular/core';
import { Observable, of } from 'rxjs';

/**
 * Writes a preference to the account. Isolated behind a token for the same
 * reason `LOCALE_WRITER` is: the service is read by components that must not
 * pull in the HTTP layer, and their tests must not need an HTTP provider.
 */
export interface PreferencesWriter {
  /** Resolves `true` on success, `false` on failure. Never errors. */
  write(scrapeFallbackEnabled: boolean): Observable<boolean>;
}

export const PREFERENCES_WRITER = new InjectionToken<PreferencesWriter>('PREFERENCES_WRITER', {
  providedIn: 'root',
  factory: (): PreferencesWriter => ({ write: () => of(true) }),
});
```

- [ ] **Step 5: Create the HTTP writer**

Create `frontend/src/app/core/http-preferences-writer.ts`:

```ts
// src/app/core/http-preferences-writer.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, of } from 'rxjs';
import { API_BASE_URL } from './api';
import { PreferencesWriter } from './preferences-writer';

/**
 * The real `PreferencesWriter`. Goes straight at `HttpClient`/`API_BASE_URL`
 * rather than through `AuthService`, which would close a dependency cycle —
 * the same reason `HttpLocaleWriter` does.
 */
@Injectable({ providedIn: 'root' })
export class HttpPreferencesWriter implements PreferencesWriter {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  write(scrapeFallbackEnabled: boolean): Observable<boolean> {
    return this.http.patch(`${this.base}/api/me/preferences`, { scrapeFallbackEnabled }).pipe(
      map(() => true),
      catchError(() => of(false)),
    );
  }
}
```

- [ ] **Step 6: Create the service**

Create `frontend/src/app/core/preferences.service.ts`:

```ts
// src/app/core/preferences.service.ts
import { Injectable, inject, signal } from '@angular/core';
import { CurrentUser } from './auth.service';
import { PREFERENCES_WRITER } from './preferences-writer';

/**
 * Per-account settings, mirroring `LanguageService`: the account is the source
 * of truth, the signal is a cache the UI reads, and a write applies locally
 * first so the toggle does not wait on the network.
 */
@Injectable({ providedIn: 'root' })
export class PreferencesService {
  private readonly writer = inject(PREFERENCES_WRITER);

  readonly scrapeFallbackEnabled = signal(false);

  /** True when the value applied locally but the account write failed. */
  readonly saveFailed = signal(false);

  setScrapeFallbackEnabled(enabled: boolean): void {
    this.scrapeFallbackEnabled.set(enabled);
    this.saveFailed.set(false);

    this.writer.write(enabled).subscribe((ok) => {
      if (!ok) this.saveFailed.set(true);
    });
  }

  /**
   * Take the account's values, typically right after `AuthService.loadMe()`.
   * Caches only — a value that just arrived from the server is never PATCHed
   * straight back to it.
   */
  adopt(user: CurrentUser): void {
    this.scrapeFallbackEnabled.set(user.preferences.scrapeFallbackEnabled);
  }
}
```

- [ ] **Step 7: Adopt the value on login**

In `frontend/src/app/core/auth.service.ts`, inject `PreferencesService` and adopt inside the existing `loadMe()` tap, beside `this.language.adopt(u.locale)`:

```ts
        this.preferences.adopt(u);
```

- [ ] **Step 8: Provide the real writer**

In `frontend/src/app/app.config.ts`, find where `LOCALE_WRITER` is provided and add the parallel provider:

```ts
    { provide: PREFERENCES_WRITER, useExisting: HttpPreferencesWriter },
```

Match the exact provider style already used for `LOCALE_WRITER` in that file.

- [ ] **Step 9: Run the tests**

Run: `npm test -- preferences.service`
Expected: PASS, 4 tests.

Then run the whole suite: `npm test`. Existing specs that build a `CurrentUser` literal now fail to type-check because `preferences` is required. Add `preferences: { scrapeFallbackEnabled: false }` to each. Search with `grep -rln "trialEndsAt" src/app` to find them.

- [ ] **Step 10: Lint**

Run: `npm run check`
Expected: clean.

- [ ] **Step 11: Commit**

```bash
git add frontend/src/app/core frontend/src/app/app.config.ts
git commit -m "feat(#237): add the preferences service and its account writer"
```

---

### Task 8: A shared toggle component

**Files:**
- Create: `frontend/src/app/shared/toggle/toggle.component.ts`
- Create: `frontend/src/app/shared/toggle/toggle.component.html`
- Create: `frontend/src/app/shared/toggle/toggle.component.scss`
- Test: `frontend/src/app/shared/toggle/toggle.component.spec.ts`

**Interfaces:**
- Consumes: nothing.
- Produces: `<app-toggle [checked]="bool" [label]="string" (toggled)="fn($event)" />`, selector `app-toggle`, from `ToggleComponent`. Task 9 consumes it.

Read `docs/design-language.md` before writing the styles, and copy the token names and density conventions from an existing shared component such as `frontend/src/app/shared/button/`.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/app/shared/toggle/toggle.component.spec.ts`:

```ts
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ToggleComponent } from './toggle.component';

describe('ToggleComponent', () => {
  let fixture: ComponentFixture<ToggleComponent>;

  const input = (): HTMLInputElement =>
    fixture.nativeElement.querySelector('input[type="checkbox"]');

  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [ToggleComponent] }).compileComponents();
    fixture = TestBed.createComponent(ToggleComponent);
    fixture.componentRef.setInput('label', 'Enable scraping');
    fixture.detectChanges();
  });

  it('reflects the checked input', () => {
    fixture.componentRef.setInput('checked', true);
    fixture.detectChanges();

    expect(input().checked).toBe(true);
  });

  it('emits the new value when clicked', () => {
    const seen: boolean[] = [];
    fixture.componentInstance.toggled.subscribe((v) => seen.push(v));

    input().click();
    fixture.detectChanges();

    expect(seen).toEqual([true]);
  });

  it('labels the control for assistive technology', () => {
    expect(input().getAttribute('aria-label')).toBe('Enable scraping');
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `npm test -- toggle.component`
Expected: FAIL — module not found.

- [ ] **Step 3: Write the component**

Create `frontend/src/app/shared/toggle/toggle.component.ts`:

```ts
// src/app/shared/toggle/toggle.component.ts
import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';

/**
 * A labelled on/off switch built on a native checkbox, so keyboard focus,
 * space-to-toggle and assistive-technology semantics come for free.
 */
@Component({
  selector: 'app-toggle',
  templateUrl: './toggle.component.html',
  styleUrl: './toggle.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ToggleComponent {
  readonly checked = input(false);
  readonly label = input.required<string>();
  readonly toggled = output<boolean>();

  onChange(event: Event): void {
    this.toggled.emit((event.target as HTMLInputElement).checked);
  }
}
```

Create `frontend/src/app/shared/toggle/toggle.component.html`:

```html
<label class="toggle">
  <input
    type="checkbox"
    [checked]="checked()"
    [attr.aria-label]="label()"
    (change)="onChange($event)"
  />
  <span class="track" aria-hidden="true"><span class="thumb"></span></span>
</label>
```

Create `frontend/src/app/shared/toggle/toggle.component.scss` using only design tokens — no hex, no raw `px`:

```scss
.toggle {
  display: inline-flex;
  align-items: center;
  cursor: pointer;
}

.toggle input {
  position: absolute;
  opacity: 0;
  width: var(--space-1);
  height: var(--space-1);
}

.track {
  display: inline-flex;
  align-items: center;
  width: var(--space-8);
  height: var(--space-5);
  padding: var(--space-1);
  border-radius: var(--radius-pill);
  background: var(--surface-3);
  transition: background var(--motion-fast);
}

.thumb {
  width: var(--space-3);
  height: var(--space-3);
  border-radius: var(--radius-pill);
  background: var(--surface-1);
  transition: transform var(--motion-fast);
}

.toggle input:checked + .track {
  background: var(--accent);
}

.toggle input:checked + .track .thumb {
  transform: translateX(var(--space-3));
}

.toggle input:focus-visible + .track {
  outline: var(--focus-ring);
  outline-offset: var(--space-1);
}
```

The token names above are a starting point. Open `frontend/src/app/theme/` and replace any that do not exist with the real ones — Stylelint and the build will tell you, but reading the theme first is faster.

- [ ] **Step 4: Run the tests**

Run: `npm test -- toggle.component`
Expected: PASS, 3 tests.

- [ ] **Step 5: Lint**

Run: `npm run check`
Expected: clean, including Stylelint on the new `.scss`.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/shared/toggle
git commit -m "feat(#237): add a shared toggle component"
```

---

### Task 9: The preferences page toggle

**Files:**
- Modify: `frontend/src/app/settings/preferences-section.component.ts`
- Modify: `frontend/src/app/settings/preferences-section.component.html`
- Modify: `frontend/src/app/settings/preferences-section.component.scss`
- Modify: `frontend/public/i18n/en.json`
- Modify: `frontend/public/i18n/de.json`
- Modify: `frontend/src/app/settings/preferences-section.component.spec.ts` (exists)

**Interfaces:**
- Consumes: `PreferencesService` from Task 7, `ToggleComponent` from Task 8.
- Produces: nothing consumed later.

- [ ] **Step 1: Add the translation keys**

In `frontend/public/i18n/en.json`, inside the `settings` object beside `languageSaveFailed`:

```json
    "experimental": "Experimental",
    "scraping": "Scrape websites without a feed",
    "scrapingHint": "When a page offers no feed, offer the page itself as a source. Quality depends on the site and can break without notice.",
    "scrapingSaveFailed": "The setting changed on this device, but could not be saved to your account.",
```

In `frontend/public/i18n/de.json`, at the matching position:

```json
    "experimental": "Experimentell",
    "scraping": "Webseiten ohne Feed auslesen",
    "scrapingHint": "Wenn eine Seite keinen Feed anbietet, die Seite selbst als Quelle anbieten. Die Qualität hängt von der Seite ab und kann jederzeit nicht mehr funktionieren.",
    "scrapingSaveFailed": "Die Einstellung wurde auf diesem Gerät geändert, konnte aber nicht in deinem Konto gespeichert werden.",
```

Match the surrounding indentation and the informal/formal address already used in `de.json` — read the neighbouring strings and follow them.

- [ ] **Step 2: Write the failing test**

`frontend/src/app/settings/preferences-section.component.spec.ts` already exists. Keep its Transloco setup and add a `PreferencesService` handle (`const preferences = TestBed.inject(PreferencesService)`) plus these two tests:

```ts
  it('renders the scraping toggle marked experimental', () => {
    const text = fixture.nativeElement.textContent as string;

    expect(text).toContain('Experimental');
    expect(fixture.nativeElement.querySelector('app-toggle')).not.toBeNull();
  });

  it('writes the preference when toggled', () => {
    const input = fixture.nativeElement.querySelector(
      'app-toggle input[type="checkbox"]',
    ) as HTMLInputElement;

    input.click();
    fixture.detectChanges();

    expect(preferences.scrapeFallbackEnabled()).toBe(true);
  });
```

- [ ] **Step 3: Run to verify it fails**

Run: `npm test -- preferences-section`
Expected: FAIL — no `app-toggle` in the template.

- [ ] **Step 4: Update the component**

`frontend/src/app/settings/preferences-section.component.ts`:

```ts
// src/app/settings/preferences-section.component.ts
import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../core/language.service';
import { PreferencesService } from '../core/preferences.service';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { LanguageSwitcherComponent } from '../shared/language-switcher/language-switcher.component';
import { SettingsCardComponent } from '../shared/settings-card/settings-card.component';
import { ToggleComponent } from '../shared/toggle/toggle.component';

@Component({
  selector: 'app-preferences-section',
  imports: [
    LanguageSwitcherComponent,
    SettingsCardComponent,
    ToggleComponent,
    TranslocoPipe,
    ErrorBannerComponent,
  ],
  templateUrl: './preferences-section.component.html',
  styleUrl: './preferences-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PreferencesSectionComponent {
  readonly language = inject(LanguageService);
  readonly preferences = inject(PreferencesService);
}
```

- [ ] **Step 5: Update the template**

`frontend/src/app/settings/preferences-section.component.html`:

```html
<app-settings-card [heading]="'settings.preferences' | transloco">
  <div class="row">
    <span>{{ 'lang.label' | transloco }}</span>
    <app-language-switcher />
  </div>
  @if (language.saveFailed()) {
    <app-error-banner [message]="'settings.languageSaveFailed' | transloco" />
  }

  <div class="row">
    <span class="setting">
      <span class="setting-label">
        {{ 'settings.scraping' | transloco }}
        <span class="badge">{{ 'settings.experimental' | transloco }}</span>
      </span>
      <span class="setting-hint">{{ 'settings.scrapingHint' | transloco }}</span>
    </span>
    <app-toggle
      [checked]="preferences.scrapeFallbackEnabled()"
      [label]="'settings.scraping' | transloco"
      (toggled)="preferences.setScrapeFallbackEnabled($event)"
    />
  </div>
  @if (preferences.saveFailed()) {
    <app-error-banner [message]="'settings.scrapingSaveFailed' | transloco" />
  }
</app-settings-card>
```

- [ ] **Step 6: Add the styles**

Append to `frontend/src/app/settings/preferences-section.component.scss`, tokens only:

```scss
.setting {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.setting-label {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  color: var(--text);
}

.setting-hint {
  color: var(--text-muted);
  font-size: var(--font-size-sm);
}

.badge {
  padding: var(--space-1) var(--space-2);
  border-radius: var(--radius-sm);
  background: var(--surface-3);
  color: var(--text-muted);
  font-size: var(--font-size-xs);
  text-transform: uppercase;
}
```

Replace any token that does not exist in `frontend/src/app/theme/` with the real one.

Note the existing `.row span { color: var(--text-muted); }` rule now also matches the new spans. Scope it to the language row or override it in `.setting-label`, whichever reads better once you see it rendered.

- [ ] **Step 7: Run the tests**

Run: `npm test -- preferences-section`
Expected: PASS.

- [ ] **Step 8: Lint**

Run: `npm run check`
Expected: clean.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/app/settings frontend/public/i18n
git commit -m "feat(#237): add the experimental scraping toggle to preferences"
```

---

### Task 10: Mark the scraped candidate experimental in the add-feed overlay

The off state needs no frontend change: the backend returns `candidates: []` with no `scrapeFailureReason`, which the dialog already renders as its plain "no feeds found" hint. Verify that, then add the marker for the on state.

**Files:**
- Modify: `frontend/src/app/reader/add-feed/add-feed-dialog.component.html`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/reader/add-feed/add-feed-dialog.component.spec.ts`

**Interfaces:**
- Consumes: the existing `FeedCandidate.format === 'scraped'` branch.
- Produces: nothing consumed later.

- [ ] **Step 1: Add the translation key**

In both `en.json` and `de.json`, inside `dialog.addFeed`, beside `scrapedHint`:

```json
      "experimental": "Experimental",
```

German: `"experimental": "Experimentell",`

- [ ] **Step 2: Write the failing test**

Add to `frontend/src/app/reader/add-feed/add-feed-dialog.component.spec.ts`, matching the file's existing harness for feeding candidates into the component:

```ts
  it('marks a scraped candidate as experimental', () => {
    component.candidates.set([
      { url: 'https://example.com/blog', title: 'Blog', format: 'scraped' },
    ]);
    component.searched.set(true);
    fixture.detectChanges();

    const badges = fixture.nativeElement.querySelectorAll('.badge');
    const text = Array.from(badges, (b) => (b as HTMLElement).textContent?.trim());
    expect(text).toContain('Experimental');
  });

  it('shows no scraping wording when nothing was found', () => {
    component.candidates.set([]);
    component.searched.set(true);
    fixture.detectChanges();

    const text = (fixture.nativeElement.textContent as string).toLowerCase();
    expect(text).toContain('no feeds found');
    expect(text).not.toContain('scrap');
  });
```

If `candidates` and `searched` are private in the component, drive the component through its public `submit()` with a stubbed API instead — read the spec file and follow whatever pattern it already uses.

- [ ] **Step 3: Run to verify it fails**

Run: `npm test -- add-feed-dialog`
Expected: the first test FAILS, the second PASSES (proving the off state already says nothing about scraping).

- [ ] **Step 4: Add the badge**

In `frontend/src/app/reader/add-feed/add-feed-dialog.component.html`, inside the `badges` div, after the format badge:

```html
                @if (c.format === 'scraped') {
                  <span class="badge experimental">{{
                    'dialog.addFeed.experimental' | transloco
                  }}</span>
                }
```

- [ ] **Step 5: Style the badge**

In `frontend/src/app/reader/add-feed/add-feed-dialog.component.scss`, beside the existing `.badge` rule:

```scss
.badge.experimental {
  background: var(--surface-3);
  color: var(--text-muted);
  text-transform: uppercase;
}
```

- [ ] **Step 6: Run the tests**

Run: `npm test -- add-feed-dialog`
Expected: both PASS.

- [ ] **Step 7: Lint**

Run: `npm run check`
Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader/add-feed frontend/public/i18n
git commit -m "feat(#237): mark scraped candidates experimental in the add-feed overlay"
```

---

### Task 11: Full verification and the pull request

**Files:** none created; this task proves the whole change.

- [ ] **Step 1: Backend suite on SQLite**

```bash
cd backend && php bin/phpunit
```

Expected: PASS.

- [ ] **Step 2: Backend suite on MySQL**

```bash
docker compose up -d && docker compose exec php vendor/bin/phpunit
```

Expected: PASS. Note: the full MySQL run has known order-dependent rate-limiter failures that pass in isolation. If a limiter test fails, re-run that test alone to confirm it is the known flake and not a regression from this branch.

- [ ] **Step 3: Backend quality gates**

```bash
cd backend && bin/console cache:warmup && composer check && composer md
```

Expected: clean. Also run the PhpStorm inspections on the changed PHP via `mcp__phpstorm__lint_files`; block on ERROR and WARNING.

- [ ] **Step 4: Scan the dev log**

Read `backend/var/log/dev.log` and confirm no new deprecations or swallowed errors from this work.

- [ ] **Step 5: Frontend gate**

```bash
cd frontend && npm run check
```

Expected: clean.

- [ ] **Step 6: Verify the feature end to end in the running stack**

With the Docker stack up, sign in and check all four states by hand:

1. Settings → Preferences shows the toggle, off, marked experimental.
2. With it off, adding a feedless page URL shows "No feeds found at that address." and says nothing about scraping.
3. Turn it on, reload, confirm it stayed on — this proves the write reached the account.
4. With it on, the same URL now offers a scraped candidate carrying the Experimental badge, and subscribing works.

- [ ] **Step 7: Push and open the PR**

```bash
git push -u origin feature/237-scraping-preference
```

Then open a PR into `develop` whose body includes `Closes #237`. `develop` is the default branch, so this auto-closes the issue on merge — verify afterwards that it closed rather than closing it by hand.

---

## Notes for the implementer

- **The two-path gate is the point.** Task 5 alone looks complete and is not. `SubscriptionService::subscribe()` accepts `format: 'scraped'` and creates the subscription without ever calling discovery. If you are tempted to skip Task 6, re-read it.
- **The migration backfill is load-bearing.** `User::getPreferences()` throws when the row is missing, and Doctrine hydration bypasses the constructor that creates it. Without the backfill, every pre-existing account breaks on its first `/api/me`.
- **Migrations are not covered by the suite.** `tests/bootstrap.php` builds the schema from ORM metadata, so a broken or dialect-wrong migration passes green. Task 2's manual verification on both platforms is the only thing that catches it.
- **Already verified, do not redo:** the spec asked whether the surviving failure strings leak the feature. They do not. `dialog.addFeed.failBlocked` ("This site blocks automated access…") and `failUnreachable` ("The site couldn't be reached…") mention nothing about scraping, so both stay reachable with the preference off. Only `failNotScrapable` names it, and Task 5 makes that reason unreachable in the off state.
- **The off state needs no frontend work, and this was checked against the code:** `add-feed-dialog.component.ts` `subscribe()` takes its `else` branch when `scrapeFailureReason` is absent, setting `candidates` to `[]` and `searched` to `true` — which is exactly the condition guarding the existing `dialog.addFeed.noneFound` hint. Task 10's second test pins that behaviour so a later refactor cannot quietly reintroduce a scraping mention.
- **Token names in the `.scss` blocks are proposals.** Read `frontend/src/app/theme/` and `docs/design-language.md` and substitute the real ones. Stylelint rejects hex colours and raw `px` outside the theme directory.
- **Do not add the preference to the `PATCH /api/me` locale endpoint.** That was considered and rejected in the spec; see the reasoning there.
