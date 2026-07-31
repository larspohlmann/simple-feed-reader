# Per-User Admin Statistics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give admins per-user statistics — last login, feed and tag counts, last refresh, staleness — on the users list, plus a detail page holding each user's complete tag and feed lists.

**Architecture:** The backend gains one new `User` column (`lastLoginAt`), stamped by a single Lexik `JWTCreatedEvent` listener that covers both the password and the OAuth login path. Counts reach the list endpoint through batched `GROUP BY` repository reads (never per row), and a dedicated `UserStatistics` service computes the detail figures. The frontend adds a lazy `/settings/admin/users/:id` detail route under the existing settings shell.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Doctrine ORM + migrations, Lexik JWT bundle, PHPUnit; Angular 20 standalone + signals, Transloco, Jest, Playwright.

**Branch:** `feature/180-admin-user-statistics` (already created off `develop`; spec committed as `9fe5df8`).

**Spec:** `docs/superpowers/specs/2026-07-31-admin-user-statistics-design.md`

## Global Constraints

- **Frontend commands run from `frontend/`; backend commands from `backend/`.** Always use absolute paths in shell calls — the Bash working directory persists between calls.
- **PHP Clean Code is mandatory** (see `CLAUDE.md`): intention-revealing names, functions doing one thing, guard clauses over nesting, no boolean flag parameters, `final readonly class` with constructor promotion as the house style, typed exceptions, comments explaining *why*.
- **Backend gates:** `composer cs` (PSR-12), `composer stan` (PHPStan level max — warm the cache with `bin/console cache:warmup` first), `composer md` (PHPMD codesize). **Every `src` file you touch must be PHPMD-clean before commit**, not merely free of new findings.
- **`declare(strict_types=1)` in every PHP file.**
- **Datetimes are stored as naive UTC.** Normalise anything incoming to UTC before persisting. The kernel pins UTC, so `ClockInterface::now()` is already UTC.
- **Migrations need platform-aware, additive-only DDL.** Tests build the schema from ORM metadata and never execute a migration, so a dialect error is caught only by CI's migrate-from-empty leg. Follow `migrations/Version20260727120000.php` exactly: `AbstractMySQLPlatform` vs `SQLitePlatform` verb selection, per-column idempotence guard, no DROP or narrowing in `up()`.
- **No N+1.** Any per-user figure on a list endpoint is one batched query indexed by user id, following the existing `AdminUserController::providersByUserId()` precedent, and pinned by a `QueryRecorder` count test.
- **Frontend gate:** `npm run check` (ESLint + Prettier 100-col + Stylelint + Jest). Run `npx prettier --write <changed files>` before committing.
- **SCSS: no hex colours, no raw `px` for spacing/font-size/radius** outside `src/app/theme/` — tokens only. Breakpoints via `@use '../theme/breakpoints' as bp;`. Component styles live in a sibling `.scss` referenced by `styleUrl`, never inline `styles:`.
- **i18n keys go in BOTH `frontend/public/i18n/en.json` and `de.json`**; both files must stay valid JSON.
- **Native iOS viability:** every new endpoint is JSON in, `application/problem+json` out, bearer-authenticated, stateless. No cookie, no CSRF token, no HTML fallback.
- **Thresholds (exact values):** dormant = no login for **90** days (or never logged in and created more than 90 days ago); stale feed = not fetched for **7** or more days.
- **Privacy:** the detail page exposes one user's full reading interests to an admin. Deliberate and admin-only; never widen it beyond `ROLE_ADMIN`.

---

## File map

**Create (backend):**

| File | Responsibility |
|---|---|
| `migrations/Version20260731120000.php` | Adds the `last_login_at` column |
| `src/EventListener/StampLastLoginOnTokenIssue.php` | Stamps `lastLoginAt` when a JWT is issued (both login paths) |
| `src/Service/Admin/UserStatistics.php` | Computes one user's activity + footprint figures |
| `src/Dto/Admin/UserFootprint.php` | Value object returned by `UserStatistics` |
| `tests/Service/Admin/UserStatisticsTest.php` | Unit tests for the service |

**Create (frontend):**

| File | Responsibility |
|---|---|
| `src/app/admin/admin-user-detail.component.{ts,html,scss}` (+spec) | The per-user detail page |

**Modify (backend):**

| File | Change |
|---|---|
| `src/Entity/User.php` | `lastLoginAt` column + accessors |
| `src/Repository/SubscriptionRepository.php` | `countsByUserIds()` batched read |
| `src/Repository/TagRepository.php` | `countsByUserIds()` batched read |
| `src/Controller/Admin/AdminUserController.php` | List gains 3 fields; new `detail()` action |
| `tests/Controller/Admin/AdminUserControllerTest.php` | List-field, query-count and detail-endpoint tests |
| `tests/Support/UserFactory.php` | Optional `lastLoginAt` for fixtures |

**Modify (frontend):**

| File | Change |
|---|---|
| `src/app/admin/admin.models.ts` | `AdminUserDto` gains 3 fields; new detail DTOs |
| `src/app/admin/admin-api.ts` | `userDetail(id)` |
| `src/app/admin/admin-users.component.{ts,html,scss}` (+spec) | Counts, last login, row link |
| `src/app/settings/settings.routes.ts` | `admin/users/:id` lazy child with `adminGuard` |
| `public/i18n/en.json`, `de.json` | New keys |
| `e2e/settings-admin-smoke.spec.ts` | Opens a user detail page |

---

### Task 1: `lastLoginAt` on User, with migration

**Files:**
- Modify: `backend/src/Entity/User.php`
- Create: `backend/migrations/Version20260731120000.php`
- Modify: `backend/tests/Support/UserFactory.php`
- Test: `backend/tests/Entity/UserTest.php` (create if absent)

**Interfaces:**
- Produces: `User::getLastLoginAt(): ?\DateTimeImmutable`, `User::setLastLoginAt(\DateTimeImmutable $at): void`, and `UserFactory::create(..., ?\DateTimeImmutable $lastLoginAt = null)`. Tasks 2–5 rely on these exact names.

- [ ] **Step 1: Write the failing test**

Create or extend `backend/tests/Entity/UserTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testANewAccountHasNeverLoggedIn(): void
    {
        $user = new User('nobody@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));

        // null is the "never" the admin UI renders — not epoch, not createdAt.
        self::assertNull($user->getLastLoginAt());
    }

    public function testTheLastLoginStampIsRecorded(): void
    {
        $user = new User('nobody@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));
        $stamp = new \DateTimeImmutable('2026-07-30 08:15:00');

        $user->setLastLoginAt($stamp);

        self::assertEquals($stamp, $user->getLastLoginAt());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit tests/Entity/UserTest.php`
Expected: FAIL — `Call to undefined method App\Entity\User::getLastLoginAt()`

- [ ] **Step 3: Add the column and accessors**

In `backend/src/Entity/User.php`, beside the other nullable datetime columns (near `approvedAt`), add:

```php
    /**
     * When this account last had a token issued to it. Null means "never
     * signed in", which the admin list renders as such and the dormancy rule
     * treats as an account that was created and then abandoned.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;
```

And with the other accessors:

```php
    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(\DateTimeImmutable $lastLoginAt): void
    {
        $this->lastLoginAt = $lastLoginAt;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit tests/Entity/UserTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Write the migration**

Create `backend/migrations/Version20260731120000.php`. Read `migrations/Version20260727120000.php` first and mirror its structure exactly:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds last_login_at to user, so the admin screens can show when an account
 * was last used and flag accounts that were created and then abandoned.
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration, so a dialect error here is caught only by CI's
 * dedicated migrate-from-empty leg.
 *
 * ADDITIVE ONLY. One nullable column, no DROP, no narrowing, no constraint on
 * existing data — every account that existed before this ships simply reads as
 * "never signed in" until its owner next signs in.
 */
final class Version20260731120000 extends AbstractMigration
{
    private const string TABLE = 'user';
    private const string COLUMN = 'last_login_at';

    public function getDescription(): string
    {
        return 'Add user.last_login_at for the admin activity screens.';
    }

    public function up(Schema $schema): void
    {
        $mysql = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $verb = $mysql ? 'ADD' : 'ADD COLUMN';

        // Per-column idempotence for a database baselined from
        // doctrine:schema:create, where ORM metadata already produced the column.
        if ($schema->hasTable(self::TABLE) && $schema->getTable(self::TABLE)->hasColumn(self::COLUMN)) {
            return;
        }

        $this->addSql(\sprintf(
            'ALTER TABLE %s %s %s DATETIME DEFAULT NULL',
            $this->quoteTable(),
            $verb,
            self::COLUMN,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql(\sprintf('ALTER TABLE %s DROP COLUMN %s', $this->quoteTable(), self::COLUMN));
    }

    /**
     * `user` is a reserved word on MySQL and needs quoting there; SQLite is
     * happy either way, so one quoted form serves both.
     */
    private function quoteTable(): string
    {
        return '"' . self::TABLE . '"';
    }
}
```

**Verify before continuing:** check how existing migrations refer to the `user` table (`grep -rn "user" backend/migrations/ | grep -i "alter table"`). If the codebase already has a working quoting convention for it, use that one verbatim instead of `quoteTable()` above, and delete the helper.

- [ ] **Step 6: Run the migration on both engines**

SQLite (native):
```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/console doctrine:migrations:migrate --no-interaction
```
MySQL (Docker), from the repo root:
```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec php bin/console doctrine:schema:validate
```
Expected: both migrate cleanly; `schema:validate` reports the mapping and database in sync.

- [ ] **Step 7: Let fixtures set the stamp**

In `backend/tests/Support/UserFactory.php`, add a trailing optional parameter to `create()` and apply it:

```php
    public function create(
        string $email,
        string $password = 'correct-horse-battery',
        UserStatus $status = UserStatus::Active,
        array $roles = [],
        string $locale = 'en',
        ?\DateTimeImmutable $lastLoginAt = null,
    ): User {
```

and, immediately before `$this->em->persist($user);`:

```php
        if (null !== $lastLoginAt) {
            $user->setLastLoginAt($lastLoginAt);
        }
```

- [ ] **Step 8: Run the gates and commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit && composer cs && bin/console cache:warmup && composer stan && composer md
```
Expected: suite green, all three gates clean.

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader && git add backend/src/Entity/User.php backend/migrations/Version20260731120000.php backend/tests/Support/UserFactory.php backend/tests/Entity/UserTest.php && git commit -m "feat(admin): record when an account last signed in (#180)"
```

---

### Task 2: Stamp `lastLoginAt` on every real login

**Files:**
- Create: `backend/src/EventListener/StampLastLoginOnTokenIssue.php`
- Test: `backend/tests/Controller/Api/LastLoginStampTest.php`

**Interfaces:**
- Consumes: `User::setLastLoginAt()` and `User::getLastLoginAt()` from Task 1.
- Produces: nothing other tasks call directly — the stamp is observed through `User::getLastLoginAt()`.

**Why this hook:** `AuthController::login()` is never executed — the `json_login` listener intercepts the request and Lexik's `authentication_success` handler writes the response (`config/packages/security.yaml`). The OAuth path issues its own token in `OAuthSignIn::redeemLoginCode()` via `$this->jwtManager->create($user)`. `JWTCreatedEvent` is the one hook both paths pass through, and the app has **no refresh-token endpoint**, so it fires exactly once per genuine sign-in.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The stamp is asserted through the REAL sign-in paths, never by invoking the
 * listener: a listener called directly proves only that the method body runs,
 * not that the dispatcher ever reaches it.
 */
final class LastLoginStampTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function factory(): UserFactory
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($em, $hasher);
    }

    private function reload(int $id): User
    {
        /** @var UserRepository $users */
        $users = self::getContainer()->get(UserRepository::class);
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $user = $users->find($id);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    public function testAPasswordLoginStampsTheAccount(): void
    {
        $user = $this->factory()->create('signs-in@example.com', 'correct-horse-battery');
        self::assertNull($user->getLastLoginAt());
        $id = (int) $user->getId();

        $this->client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'email' => 'signs-in@example.com',
                'password' => 'correct-horse-battery',
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->reload($id)->getLastLoginAt());
    }

    public function testAFailedPasswordLoginLeavesTheAccountUnstamped(): void
    {
        $user = $this->factory()->create('wrong-pass@example.com', 'correct-horse-battery');
        $id = (int) $user->getId();

        $this->client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'email' => 'wrong-pass@example.com',
                'password' => 'not-the-password',
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
        self::assertNull($this->reload($id)->getLastLoginAt());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit tests/Controller/Api/LastLoginStampTest.php`
Expected: FAIL — `testAPasswordLoginStampsTheAccount` fails asserting not-null (the second test already passes; that is fine and it guards the negative case).

- [ ] **Step 3: Write the listener**

```php
<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Records when an account last signed in.
 *
 * Token issuance is the one point BOTH sign-in paths share: the password path
 * never reaches AuthController::login() — the json_login listener intercepts it
 * and Lexik's success handler writes the response — while the OAuth path mints
 * its token directly in OAuthSignIn::redeemLoginCode(). Hooking JWTCreatedEvent
 * covers both without either knowing about this listener.
 *
 * It fires once per genuine sign-in because the app issues no refresh tokens.
 * Should one ever be added, refreshes would start counting as logins and this
 * listener must learn to tell them apart.
 *
 * The flush is not defended with a try/catch: reaching this point already
 * required reading the account from the same connection, so a write that fails
 * here means the database is gone and the login is rightly failing anyway.
 */
#[AsEventListener(event: JWTCreatedEvent::class, method: '__invoke')]
final readonly class StampLastLoginOnTokenIssue
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        // The kernel pins UTC, so the clock already reads in the naive-UTC
        // wall clock Doctrine persists.
        $user->setLastLoginAt($this->clock->now());
        $this->entityManager->flush();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit tests/Controller/Api/LastLoginStampTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Cover the OAuth path through its real service**

Find the existing OAuth sign-in test (`grep -rln "redeemLoginCode" /Users/lars/Documents/work/eigenes/simple-feed-reader/backend/tests`). Add a case there — or, if none exists, add it to `LastLoginStampTest` — that boots the kernel, fetches the real `App\Service\OAuth\OAuthSignIn` from the container, issues a login code for a user through `issueLoginCode()`, redeems it with `redeemLoginCode()`, and then asserts the reloaded user has a non-null `getLastLoginAt()`. Mirror the existing test's fixture helpers for building an `OAuthIdentity` and a browser token.

This exercises the production service and the real dispatcher — it is not a direct invocation of the listener.

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit tests/`
Expected: PASS — whole suite green.

- [ ] **Step 6: Run the gates and commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && composer cs && bin/console cache:warmup && composer stan && composer md
```

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader && git add backend/src/EventListener/StampLastLoginOnTokenIssue.php backend/tests/ && git commit -m "feat(admin): stamp last login when a token is issued (#180)"
```

---

### Task 3: Batched counts on the users list endpoint

**Files:**
- Modify: `backend/src/Repository/SubscriptionRepository.php`
- Modify: `backend/src/Repository/TagRepository.php`
- Modify: `backend/src/Controller/Admin/AdminUserController.php`
- Test: `backend/tests/Controller/Admin/AdminUserControllerTest.php`

**Interfaces:**
- Consumes: `User::getLastLoginAt()` (Task 1).
- Produces: `SubscriptionRepository::countsByUserIds(array $userIds): array<int, int>` and `TagRepository::countsByUserIds(array $userIds): array<int, int>`, both keyed by user id with **absent users meaning zero**. Task 5 reuses neither — it counts one user at a time — but Task 6's frontend DTO mirrors the three new response fields: `feedsCount`, `tagsCount`, `lastLoginAt`.

- [ ] **Step 1: Write the failing tests**

Add to `backend/tests/Controller/Admin/AdminUserControllerTest.php`. Read the file's existing helpers first (`factory()`, `tokenFor()`, `admin()`, `call()`, `payload()`, `rowFor()`) and use them rather than inventing new ones:

```php
    public function testTheListCarriesFootprintCountsAndTheLastLoginStamp(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);

        $this->factory()->create(
            'busy@example.com',
            lastLoginAt: new \DateTimeImmutable('2026-07-29 09:00:00'),
        );

        $this->call('GET', self::LIST, $token);

        self::assertResponseIsSuccessful();
        $row = $this->rowFor('busy@example.com');
        self::assertSame(0, $row['feedsCount']);
        self::assertSame(0, $row['tagsCount']);
        self::assertStringStartsWith('2026-07-29T09:00:00', (string) $row['lastLoginAt']);
    }

    public function testAnAccountThatNeverSignedInReportsANullStamp(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);
        $this->factory()->create('fresh@example.com');

        $this->call('GET', self::LIST, $token);

        self::assertResponseIsSuccessful();
        self::assertNull($this->rowFor('fresh@example.com')['lastLoginAt']);
    }

    public function testTheFootprintCountsCostOneQueryEachHoweverManyUsersAreListed(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);

        for ($i = 0; $i < 8; ++$i) {
            $this->factory()->create(\sprintf('counted%d@example.com', $i));
        }

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $this->call('GET', self::LIST, $token);

        self::assertResponseIsSuccessful();

        $feedReads = $recorder->queriesMatching('from subscription');
        self::assertCount(
            1,
            $feedReads,
            "the feed count must be one batched read, got:\n" . implode("\n", $feedReads),
        );

        $tagReads = $recorder->queriesMatching('from tag');
        self::assertCount(
            1,
            $tagReads,
            "the tag count must be one batched read, got:\n" . implode("\n", $tagReads),
        );
    }
```

**Note:** `queriesMatching()` is a case-insensitive substring match. If `from tag` also matches `subscription_tag` rows in your schema, tighten the needle to the exact table name the generated SQL uses — run the test once, read the failure message (it prints every matched query), and adjust the needle accordingly. Do not weaken the `assertCount(1, ...)`.

- [ ] **Step 2: Run to verify they fail**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit tests/Controller/Admin/AdminUserControllerTest.php`
Expected: FAIL — undefined array key `feedsCount`.

- [ ] **Step 3: Add the batched reads**

In `backend/src/Repository/SubscriptionRepository.php`:

```php
    /**
     * How many feeds each of the given users is subscribed to, in ONE query.
     *
     * A user with no subscriptions is absent from the result rather than
     * present with a zero — GROUP BY has no row to return for them — so
     * callers must default a miss to 0. The obvious per-user countForUser()
     * loop would be an N+1 that no assertion on the response body could catch,
     * which is why AdminUserControllerTest counts the queries.
     *
     * @param list<int> $userIds
     *
     * @return array<int, int>
     */
    public function countsByUserIds(array $userIds): array
    {
        // An empty IN () is a syntax error on both engines, and there is
        // nothing to ask about anyway.
        if ([] === $userIds) {
            return [];
        }

        /** @var list<array{userId: int|string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.user) AS userId', 'COUNT(s.id) AS total')
            ->andWhere('s.user IN (:userIds)')->setParameter('userIds', $userIds)
            ->groupBy('s.user')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['userId']] = (int) $row['total'];
        }

        return $counts;
    }
```

In `backend/src/Repository/TagRepository.php`, add the identical shape over `Tag` (alias `t`, `IDENTITY(t.user)`, `COUNT(t.id)`), with the same docblock rationale:

```php
    /**
     * How many tags each of the given users owns, in ONE query. A user with no
     * tags is absent from the result, not zero-valued — callers default a miss
     * to 0. See SubscriptionRepository::countsByUserIds() for why this is
     * batched and query-count tested.
     *
     * @param list<int> $userIds
     *
     * @return array<int, int>
     */
    public function countsByUserIds(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        /** @var list<array{userId: int|string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.user) AS userId', 'COUNT(t.id) AS total')
            ->andWhere('t.user IN (:userIds)')->setParameter('userIds', $userIds)
            ->groupBy('t.user')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['userId']] = (int) $row['total'];
        }

        return $counts;
    }
```

- [ ] **Step 4: Wire them into the list action**

In `backend/src/Controller/Admin/AdminUserController.php`, add the two repositories to the constructor:

```php
        private SubscriptionRepository $subscriptions,
        private TagRepository $tags,
```

In `list()`, after `$providersByUserId = $this->providersByUserId($users);`, add:

```php
        $userIds = array_values(array_filter(array_map(
            static fn (User $user): ?int => $user->getId(),
            $users,
        )));
        $feedCounts = $this->subscriptions->countsByUserIds($userIds);
        $tagCounts = $this->tags->countsByUserIds($userIds);
```

and extend the hand-built row (keeping the existing comment and fields) with:

```php
                    // Footprint at a glance. A user with none of either is
                    // absent from the batched counts, hence the ?? 0.
                    'feedsCount' => $feedCounts[$user->getId()] ?? 0,
                    'tagsCount' => $tagCounts[$user->getId()] ?? 0,
                    'lastLoginAt' => $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
```

The closure is `static fn (User $user): array => [...]`, so it cannot read `$this`. Bind the three locals into it by changing the arrow function's captured scope — arrow functions capture automatically, so `$feedCounts` and `$tagCounts` are available as written. Keep it `static`.

Add the two `use` imports for `SubscriptionRepository` and `TagRepository`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit tests/Controller/Admin/AdminUserControllerTest.php`
Expected: PASS — including the two query-count assertions.

- [ ] **Step 6: Run the gates and commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit && composer cs && bin/console cache:warmup && composer stan && composer md
```

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader && git add backend/src backend/tests && git commit -m "feat(admin): users list carries feed and tag counts and the last login (#180)"
```

---

### Task 4: The `UserStatistics` service

**Files:**
- Create: `backend/src/Dto/Admin/UserFootprint.php`
- Create: `backend/src/Service/Admin/UserStatistics.php`
- Test: `backend/tests/Service/Admin/UserStatisticsTest.php`

**Interfaces:**
- Consumes: `User::getLastLoginAt()`, `User::getCreatedAt()` (Task 1).
- Produces: `UserStatistics::forUser(User $user): UserFootprint`, and the readonly `UserFootprint` with public properties `feedsCount`, `tagsCount`, `feedsLimit`, `staleFeedsCount`, `lastRefreshAt` (`?\DateTimeImmutable`) and `dormant` (`bool`). Task 5 serialises exactly these.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\Admin\UserStatistics;
use App\Service\Subscription\SubscriptionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class UserStatisticsTest extends TestCase
{
    private const string NOW = '2026-07-31 12:00:00';

    private function user(?string $lastLoginAt, string $createdAt = '2026-01-01 09:00:00'): User
    {
        $user = new User('someone@example.com', new \DateTimeImmutable($createdAt));
        if (null !== $lastLoginAt) {
            $user->setLastLoginAt(new \DateTimeImmutable($lastLoginAt));
        }

        return $user;
    }

    /**
     * @param list<?string> $fetchedAt one entry per subscribed feed
     */
    private function statisticsFor(User $user, array $fetchedAt, int $tagCount): UserStatistics
    {
        $subscriptions = [];
        foreach ($fetchedAt as $stamp) {
            $feed = new Feed('https://example.test/feed');
            $feed->setLastFetchedAt(null === $stamp ? null : new \DateTimeImmutable($stamp));
            $subscriptions[] = new Subscription($user, $feed, new \DateTimeImmutable(self::NOW));
        }

        $subscriptionRepository = $this->createMock(SubscriptionRepository::class);
        $subscriptionRepository->method('findForUserWithTags')->willReturn($subscriptions);

        $tagRepository = $this->createMock(TagRepository::class);
        $tagRepository->method('findForUser')->willReturn(
            array_map(
                static fn (int $i): Tag => new Tag($user, 'tag' . $i),
                range(1, max(0, $tagCount)),
            ),
        );

        return new UserStatistics(
            $subscriptionRepository,
            $tagRepository,
            new MockClock(new \DateTimeImmutable(self::NOW)),
        );
    }

    public function testItCountsTheFootprintAgainstTheGlobalCap(): void
    {
        $user = $this->user('2026-07-30 08:00:00');
        $footprint = $this->statisticsFor($user, ['2026-07-31 06:00:00', '2026-07-30 06:00:00'], 3)
            ->forUser($user);

        self::assertSame(2, $footprint->feedsCount);
        self::assertSame(3, $footprint->tagsCount);
        self::assertSame(SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER, $footprint->feedsLimit);
    }

    public function testTheLastRefreshIsTheNewestFetchAcrossTheUsersFeeds(): void
    {
        $user = $this->user('2026-07-30 08:00:00');
        $footprint = $this->statisticsFor(
            $user,
            ['2026-07-20 06:00:00', '2026-07-29 18:30:00', '2026-07-25 06:00:00'],
            0,
        )->forUser($user);

        self::assertEquals(new \DateTimeImmutable('2026-07-29 18:30:00'), $footprint->lastRefreshAt);
    }

    public function testAnAccountWithoutFeedsHasNeverRefreshed(): void
    {
        $user = $this->user('2026-07-30 08:00:00');
        $footprint = $this->statisticsFor($user, [], 0)->forUser($user);

        self::assertNull($footprint->lastRefreshAt);
        self::assertSame(0, $footprint->feedsCount);
        self::assertSame(0, $footprint->staleFeedsCount);
    }

    public function testAFeedCountsAsStaleAfterSevenDaysAndANeverFetchedFeedAlways(): void
    {
        $user = $this->user('2026-07-30 08:00:00');
        $footprint = $this->statisticsFor($user, [
            '2026-07-31 06:00:00', // fresh
            '2026-07-25 12:00:00', // exactly 6 days — fresh
            '2026-07-24 12:00:00', // exactly 7 days — stale
            null,                  // never fetched — stale
        ], 0)->forUser($user);

        self::assertSame(2, $footprint->staleFeedsCount);
    }

    public function testAnAccountIsDormantAfterNinetyDaysWithoutALogin(): void
    {
        $recent = $this->user('2026-07-01 08:00:00');
        $stale = $this->user('2026-04-01 08:00:00');

        self::assertFalse($this->statisticsFor($recent, [], 0)->forUser($recent)->dormant);
        self::assertTrue($this->statisticsFor($stale, [], 0)->forUser($stale)->dormant);
    }

    public function testAnAccountThatNeverSignedInIsDormantOnlyOnceItIsOldEnough(): void
    {
        $young = $this->user(null, '2026-07-20 09:00:00');
        $abandoned = $this->user(null, '2026-01-01 09:00:00');

        self::assertFalse($this->statisticsFor($young, [], 0)->forUser($young)->dormant);
        self::assertTrue($this->statisticsFor($abandoned, [], 0)->forUser($abandoned)->dormant);
    }
}
```

**Before running:** confirm the real constructor signatures of `Feed`, `Subscription` and `Tag` (`grep -n "__construct" backend/src/Entity/{Feed,Subscription,Tag}.php`) and adjust the three fixture builders above to match. Confirm `SubscriptionRepository::findForUserWithTags()` returns the subscriptions with their feed reachable via `getFeed()`; if it does not fetch-join `feed`, add `->addSelect('f')->leftJoin('s.feed', 'f')` to that existing method so the service does not trigger a lazy load per row.

- [ ] **Step 2: Run it to verify it fails**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit tests/Service/Admin/UserStatisticsTest.php`
Expected: FAIL — `Class "App\Service\Admin\UserStatistics" does not exist`

- [ ] **Step 3: Write the value object**

```php
<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/**
 * What one account has built up, as the admin detail screen shows it.
 */
final readonly class UserFootprint
{
    public function __construct(
        public int $feedsCount,
        public int $tagsCount,
        /** The cap the subscribe path enforces today; per-user caps arrive with #66. */
        public int $feedsLimit,
        public int $staleFeedsCount,
        /** Newest fetch across the account's feeds; null when it has no feeds. */
        public ?\DateTimeImmutable $lastRefreshAt,
        public bool $dormant,
    ) {
    }
}
```

- [ ] **Step 4: Write the service**

```php
<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\Admin\UserFootprint;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\Subscription\SubscriptionService;
use Psr\Clock\ClockInterface;

/**
 * The figures behind the admin's per-user detail screen.
 *
 * Deliberately one account at a time: it backs a detail page, not a list, so
 * the batched reads the list endpoint needs (SubscriptionRepository::
 * countsByUserIds) would buy nothing here.
 */
final readonly class UserStatistics
{
    /** No sign-in for this long marks an account dormant. */
    private const int DORMANT_AFTER_DAYS = 90;

    /** Refresh is manual, so a week without a fetch is the useful staleness mark. */
    private const int STALE_AFTER_DAYS = 7;

    public function __construct(
        private SubscriptionRepository $subscriptions,
        private TagRepository $tags,
        private ClockInterface $clock,
    ) {
    }

    public function forUser(User $user): UserFootprint
    {
        $subscriptions = $this->subscriptions->findForUserWithTags((int) $user->getId());
        $now = $this->clock->now();

        return new UserFootprint(
            feedsCount: \count($subscriptions),
            tagsCount: \count($this->tags->findForUser((int) $user->getId())),
            feedsLimit: SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER,
            staleFeedsCount: $this->countStale($subscriptions, $now),
            lastRefreshAt: $this->newestFetch($subscriptions),
            dormant: $this->isDormant($user, $now),
        );
    }

    /**
     * @param list<Subscription> $subscriptions
     */
    private function countStale(array $subscriptions, \DateTimeImmutable $now): int
    {
        $cutoff = $now->modify(\sprintf('-%d days', self::STALE_AFTER_DAYS));
        $stale = 0;

        foreach ($subscriptions as $subscription) {
            $fetchedAt = $subscription->getFeed()->getLastFetchedAt();

            // A feed that was never fetched is stale by definition, not fresh.
            if (null === $fetchedAt || $fetchedAt <= $cutoff) {
                ++$stale;
            }
        }

        return $stale;
    }

    /**
     * @param list<Subscription> $subscriptions
     */
    private function newestFetch(array $subscriptions): ?\DateTimeImmutable
    {
        $newest = null;

        foreach ($subscriptions as $subscription) {
            $fetchedAt = $subscription->getFeed()->getLastFetchedAt();

            if (null !== $fetchedAt && (null === $newest || $fetchedAt > $newest)) {
                $newest = $fetchedAt;
            }
        }

        return $newest;
    }

    /**
     * An account that never signed in is judged on its age instead, so a
     * registration from this morning does not read as abandoned.
     */
    private function isDormant(User $user, \DateTimeImmutable $now): bool
    {
        $cutoff = $now->modify(\sprintf('-%d days', self::DORMANT_AFTER_DAYS));

        return ($user->getLastLoginAt() ?? $user->getCreatedAt()) < $cutoff;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit tests/Service/Admin/UserStatisticsTest.php`
Expected: PASS (6 tests)

- [ ] **Step 6: Run the gates and commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit && composer cs && bin/console cache:warmup && composer stan && composer md
```

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader && git add backend/src backend/tests && git commit -m "feat(admin): per-user footprint statistics service (#180)"
```

---

### Task 5: The user detail endpoint

**Files:**
- Modify: `backend/src/Controller/Admin/AdminUserController.php`
- Test: `backend/tests/Controller/Admin/AdminUserControllerTest.php`

**Interfaces:**
- Consumes: `UserStatistics::forUser()` and `UserFootprint` (Task 4); `SubscriptionRepository::findForUserWithTags()`, `TagRepository::findForUser()`.
- Produces: `GET /api/admin/users/{id}` returning the JSON shape Task 6 types — `{ user: {...}, footprint: {...}, tags: [...], subscriptions: [...] }`.

- [ ] **Step 1: Write the failing tests**

Add to `backend/tests/Controller/Admin/AdminUserControllerTest.php`:

```php
    public function testTheDetailEndpointReturnsTheAccountItsFootprintAndItsLists(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);
        $user = $this->factory()->create(
            'detailed@example.com',
            lastLoginAt: new \DateTimeImmutable('2026-07-29 09:00:00'),
        );

        $this->call('GET', '/api/admin/users/' . $user->getId(), $token);

        self::assertResponseIsSuccessful();
        $body = $this->payload();

        self::assertSame('detailed@example.com', $body['user']['email']);
        self::assertStringStartsWith('2026-07-29T09:00:00', (string) $body['user']['lastLoginAt']);
        self::assertSame(0, $body['footprint']['feedsCount']);
        self::assertSame(0, $body['footprint']['tagsCount']);
        self::assertFalse($body['footprint']['dormant']);
        self::assertSame([], $body['tags']);
        self::assertSame([], $body['subscriptions']);
    }

    public function testTheDetailEndpointNeverLeaksThePasswordHash(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);
        $user = $this->factory()->create('secretive@example.com');

        $this->call('GET', '/api/admin/users/' . $user->getId(), $token);

        self::assertResponseIsSuccessful();
        $raw = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('passwordHash', $raw);
        self::assertStringNotContainsString('$2y$', $raw);
    }

    public function testAnUnknownUserIsANotFoundProblem(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);

        $this->call('GET', '/api/admin/users/999999', $token);

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }
```

Also extend the existing `testNonAdminIsForbiddenOnEveryRoute` and `testAnonymousIsUnauthorizedOnEveryRoute` data providers with the new route (`['GET', '/api/admin/users/%d']` or whatever placeholder shape that provider already uses — read it first and match).

- [ ] **Step 2: Run to verify they fail**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit tests/Controller/Admin/AdminUserControllerTest.php`
Expected: FAIL — 404 for the detail route because it does not exist yet.

- [ ] **Step 3: Add the action**

Add `UserStatistics` to the constructor, then add this action to `AdminUserController`. Place it **after** the `list()` action but keep the `/{id}` route requirement `\d+` so it cannot shadow a literal path:

```php
    /**
     * Everything the admin detail screen shows about one account.
     *
     * Hand-built like list(), and for the same reason: a column added to User
     * later must not reach an admin's browser merely because it exists. Note
     * what is absent — the password hash and every token column.
     */
    #[Route('/{id}', name: 'api_admin_users_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        $user = $this->users->find($id);

        if (!$user instanceof User) {
            throw new NotFoundHttpException('No such user.');
        }

        $footprint = $this->statistics->forUser($user);
        $userId = (int) $user->getId();

        return new JsonResponse([
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'status' => $user->getStatus()->value,
                'roles' => $user->getRoles(),
                'locale' => $user->getLocale(),
                'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'approvedAt' => $user->getApprovedAt()?->format(\DateTimeInterface::ATOM),
                'lastLoginAt' => $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
                'identities' => $this->providersByUserId([$user])[$userId] ?? [],
            ],
            'footprint' => [
                'feedsCount' => $footprint->feedsCount,
                'tagsCount' => $footprint->tagsCount,
                'feedsLimit' => $footprint->feedsLimit,
                'staleFeedsCount' => $footprint->staleFeedsCount,
                'lastRefreshAt' => $footprint->lastRefreshAt?->format(\DateTimeInterface::ATOM),
                'dormant' => $footprint->dormant,
            ],
            'tags' => $this->tagRows($userId),
            'subscriptions' => $this->subscriptionRows($userId),
        ]);
    }

    /**
     * The account's tags in the order its owner arranged them, each with how
     * many of that account's feeds carry it.
     *
     * @return list<array<string, mixed>>
     */
    private function tagRows(int $userId): array
    {
        $feedsPerTag = [];
        foreach ($this->subscriptions->findForUserWithTags($userId) as $subscription) {
            foreach ($subscription->getTags() as $tag) {
                $tagId = (int) $tag->getId();
                $feedsPerTag[$tagId] = ($feedsPerTag[$tagId] ?? 0) + 1;
            }
        }

        return array_map(
            static fn (Tag $tag): array => [
                'id' => $tag->getId(),
                'name' => $tag->getName(),
                'color' => $tag->getColor(),
                'icon' => $tag->getIcon(),
                'position' => $tag->getPosition(),
                'feedsCount' => $feedsPerTag[(int) $tag->getId()] ?? 0,
            ],
            $this->tags->findForUser($userId),
        );
    }

    /**
     * The account's subscriptions in its owner's order, each with the tags it
     * carries and the freshness of the underlying feed.
     *
     * @return list<array<string, mixed>>
     */
    private function subscriptionRows(int $userId): array
    {
        return array_map(
            static fn (Subscription $subscription): array => [
                'id' => $subscription->getId(),
                'title' => $subscription->getFeed()->getTitle(),
                'customTitle' => $subscription->getCustomTitle(),
                'url' => $subscription->getFeed()->getUrl(),
                'position' => $subscription->getPosition(),
                'createdAt' => $subscription->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'lastFetchedAt' => $subscription->getFeed()->getLastFetchedAt()
                    ?->format(\DateTimeInterface::ATOM),
                'tags' => array_map(
                    static fn (Tag $tag): array => [
                        'id' => $tag->getId(),
                        'name' => $tag->getName(),
                        'color' => $tag->getColor(),
                    ],
                    $subscription->getTags(),
                ),
            ],
            $this->subscriptions->findForUserWithTags($userId),
        );
    }
```

**Verify while implementing:** `Subscription::getTags()` may return a `Collection` rather than a `list`. Read the entity (it has an `orderedSubscriptionTags()` helper) and use whichever accessor yields the ordered `Tag` objects; wrap with `->toArray()` if needed so `array_map` accepts it. Confirm `Feed::getTitle()` and `Feed::getUrl()` exist under those names. Add the `use` imports for `NotFoundHttpException`, `Subscription` and `Tag`.

**PHPMD watch:** `detail()` builds a large array literal. If PHPMD flags the method's length, extract the `user` and `footprint` array literals into two small private methods (`accountRow(User $user): array`, `footprintRow(UserFootprint $footprint): array`) rather than raising the threshold.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit tests/Controller/Admin/AdminUserControllerTest.php`
Expected: PASS — including the 404, the no-leak assertion, and the extended role/anonymous providers.

- [ ] **Step 5: Run the whole backend and both legs**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit && composer cs && bin/console cache:warmup && composer stan && composer md
```
Then the MySQL leg, from the repo root: `docker compose exec php vendor/bin/phpunit`
Expected: green on both.

- [ ] **Step 6: Commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader && git add backend/src backend/tests && git commit -m "feat(admin): per-user detail endpoint with tag and feed lists (#180)"
```

---

### Task 6: Frontend models and API client

**Files:**
- Modify: `frontend/src/app/admin/admin.models.ts`
- Modify: `frontend/src/app/admin/admin-api.ts`
- Test: `frontend/src/app/admin/admin-api.spec.ts` (create if absent)

**Interfaces:**
- Consumes: the JSON shapes from Tasks 3 and 5.
- Produces: `AdminUserDto` extended with `feedsCount: number`, `tagsCount: number`, `lastLoginAt: string | null`; new interfaces `AdminUserFootprintDto`, `AdminUserTagDto`, `AdminUserSubscriptionDto`, `AdminUserDetailDto`; and `AdminApi.userDetail(id: number): Observable<AdminUserDetailDto>`. Tasks 7 and 8 import exactly these names.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/app/admin/admin-api.spec.ts` (if it exists, add this case to it and reuse its existing setup):

```ts
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../core/api';
import { AdminApi } from './admin-api';

describe('AdminApi.userDetail', () => {
  let api: AdminApi;
  let ctrl: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    api = TestBed.inject(AdminApi);
    ctrl = TestBed.inject(HttpTestingController);
  });

  afterEach(() => ctrl.verify());

  it('reads one user from the detail endpoint', () => {
    let received: unknown = null;
    api.userDetail(7).subscribe((detail) => (received = detail));

    const req = ctrl.expectOne('https://api.test/api/admin/users/7');
    expect(req.request.method).toBe('GET');
    req.flush({ user: { id: 7 }, footprint: {}, tags: [], subscriptions: [] });

    expect(received).toEqual({ user: { id: 7 }, footprint: {}, tags: [], subscriptions: [] });
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest src/app/admin/admin-api.spec.ts`
Expected: FAIL — `api.userDetail is not a function`

- [ ] **Step 3: Extend the models**

In `frontend/src/app/admin/admin.models.ts`, extend `AdminUserDto` with the three new fields and add the detail shapes:

```ts
export interface AdminUserDto {
  id: number;
  email: string;
  status: AdminUserStatus;
  roles: string[];
  createdAt: string;
  approvedAt: string | null;
  identities: string[];
  /** How many feeds this account subscribes to. */
  feedsCount: number;
  tagsCount: number;
  /** null means the account has never signed in. */
  lastLoginAt: string | null;
}

export interface AdminUserFootprintDto {
  feedsCount: number;
  tagsCount: number;
  /** The cap the subscribe path enforces today; per-user caps arrive with #66. */
  feedsLimit: number;
  staleFeedsCount: number;
  /** Newest fetch across the account's feeds; null when it has no feeds. */
  lastRefreshAt: string | null;
  dormant: boolean;
}

export interface AdminUserTagDto {
  id: number;
  name: string;
  color: string | null;
  icon: string | null;
  position: number;
  /** How many of this account's feeds carry the tag. */
  feedsCount: number;
}

export interface AdminUserSubscriptionDto {
  id: number;
  title: string;
  customTitle: string | null;
  url: string;
  position: number;
  createdAt: string;
  lastFetchedAt: string | null;
  tags: { id: number; name: string; color: string | null }[];
}

export interface AdminUserDetailDto {
  user: AdminUserDto & { locale: string };
  footprint: AdminUserFootprintDto;
  tags: AdminUserTagDto[];
  subscriptions: AdminUserSubscriptionDto[];
}
```

- [ ] **Step 4: Add the API method**

In `frontend/src/app/admin/admin-api.ts`, after `act()`:

```ts
  userDetail(id: number): Observable<AdminUserDetailDto> {
    return this.http.get<AdminUserDetailDto>(`${this.base}/api/admin/users/${id}`);
  }
```

Add `AdminUserDetailDto` to the existing import from `./admin.models`.

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest src/app/admin/admin-api.spec.ts`
Expected: PASS

Then run the full suite: `npx jest`. Existing specs that build an `AdminUserDto` fixture now fail to type-check because of the three required fields. Fix each by adding `feedsCount: 0, tagsCount: 0, lastLoginAt: null` to the fixture — do not weaken the interface with optional fields.

- [ ] **Step 6: Commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx prettier --write src/app/admin/admin.models.ts src/app/admin/admin-api.ts src/app/admin/admin-api.spec.ts
cd /Users/lars/Documents/work/eigenes/simple-feed-reader && git add frontend/src/app/admin && git commit -m "feat(admin): types and client for per-user statistics (#180)"
```

---

### Task 7: Counts and last login on the users list

**Files:**
- Modify: `frontend/src/app/admin/admin-users.component.ts`
- Modify: `frontend/src/app/admin/admin-users.component.html`
- Modify: `frontend/src/app/admin/admin-users.component.scss`
- Modify: `frontend/src/app/admin/admin-users.component.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `AdminUserDto.feedsCount/tagsCount/lastLoginAt` (Task 6).
- Produces: rows linking to `/settings/admin/users/{id}` — Task 8 supplies that route.

- [ ] **Step 1: Add the i18n keys**

In `frontend/public/i18n/en.json`, inside `"admin"`:

```json
"feedsLabel": "feeds",
"tagsLabel": "tags",
"lastLogin": "Last login",
"neverLoggedIn": "never",
"openDetail": "View details"
```

In `frontend/public/i18n/de.json`, inside `"admin"`:

```json
"feedsLabel": "Feeds",
"tagsLabel": "Tags",
"lastLogin": "Letzte Anmeldung",
"neverLoggedIn": "nie",
"openDetail": "Details ansehen"
```

- [ ] **Step 2: Write the failing test**

Add to `frontend/src/app/admin/admin-users.component.spec.ts` (read its existing `mount()` and fixture helpers first and reuse them; the user fixture now needs the three new fields):

```ts
  it('shows the footprint counts and links each row to the detail page', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({
      users: [
        user(1, {
          status: 'active',
          feedsCount: 12,
          tagsCount: 3,
          lastLoginAt: '2026-07-29T09:00:00+00:00',
        }),
      ],
    });
    f.detectChanges();

    const text = f.nativeElement.textContent as string;
    expect(text).toContain('12');
    expect(text).toContain('3');

    const link = f.nativeElement.querySelector('a[href="/settings/admin/users/1"]');
    expect(link).not.toBeNull();
  });

  it('renders an account that never signed in as never', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({
      users: [user(1, { status: 'active', feedsCount: 0, tagsCount: 0, lastLoginAt: null })],
    });
    f.detectChanges();

    expect(f.nativeElement.textContent).toContain('never');
  });
```

The spec must provide the router — add `provideRouter([])` to the TestBed providers if it is not already there, and import `RouterLink` handling via the component's own imports.

- [ ] **Step 3: Run it to verify it fails**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest src/app/admin/admin-users.component.spec.ts`
Expected: FAIL — no such link, counts absent.

- [ ] **Step 4: Render the new columns**

In `admin-users.component.ts`, add `RouterLink` to the component's `imports` array and import it from `@angular/router`.

In `admin-users.component.html`, inside the `.who` block and after the existing `.meta` span, add the footprint line, and wrap the email in a link. Replace the `<div class="who">…</div>` block with:

```html
          <div class="who">
            <a class="email" [routerLink]="['/settings/admin/users', u.id]">{{ u.email }}</a>
            <span class="meta">
              <span class="badge" [attr.data-s]="u.status">{{
                'admin.status.' + u.status | transloco
              }}</span>
              @if (u.identities.length) {
                <span class="prov">{{ u.identities.join(', ') }}</span>
              }
            </span>
            <span class="footprint">
              <span>{{ u.feedsCount }} {{ 'admin.feedsLabel' | transloco }}</span>
              <span>{{ u.tagsCount }} {{ 'admin.tagsLabel' | transloco }}</span>
              <span>
                {{ 'admin.lastLogin' | transloco }}:
                {{ u.lastLoginAt ? (u.lastLoginAt | date: 'mediumDate') : ('admin.neverLoggedIn' | transloco) }}
              </span>
            </span>
          </div>
```

Add `DatePipe` to the component's `imports` array (`import { DatePipe } from '@angular/common';`).

- [ ] **Step 5: Style the new line**

In `admin-users.component.scss`, add beside the existing `.meta` rule:

```scss
.footprint {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-3);
  font-size: var(--fs-sm);
  color: var(--text-muted);
}

a.email {
  color: var(--text-primary);
  text-decoration: none;
}

a.email:hover {
  text-decoration: underline;
}
```

Keep the existing `.email` overflow rules; if they are declared on `.email` they already apply to `a.email`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest src/app/admin/admin-users.component.spec.ts`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx prettier --write src/app/admin/admin-users.component.ts src/app/admin/admin-users.component.html src/app/admin/admin-users.component.scss src/app/admin/admin-users.component.spec.ts public/i18n/en.json public/i18n/de.json
cd /Users/lars/Documents/work/eigenes/simple-feed-reader && git add frontend && git commit -m "feat(admin): users list shows footprint counts and last login (#180)"
```

---

### Task 8: The user detail page

**Files:**
- Create: `frontend/src/app/admin/admin-user-detail.component.ts`
- Create: `frontend/src/app/admin/admin-user-detail.component.html`
- Create: `frontend/src/app/admin/admin-user-detail.component.scss`
- Create: `frontend/src/app/admin/admin-user-detail.component.spec.ts`
- Modify: `frontend/src/app/settings/settings.routes.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `AdminApi.userDetail()` and the detail DTOs (Task 6).
- Produces: the route `/settings/admin/users/:id`, which Task 7's rows link to and Task 9's e2e opens.

- [ ] **Step 1: Add the i18n keys**

In `frontend/public/i18n/en.json`, inside `"admin"`:

```json
"detail": {
  "account": "Account",
  "activity": "Activity",
  "footprint": "Footprint",
  "tagsTitle": "Tags",
  "feedsTitle": "Feeds",
  "locale": "Language",
  "created": "Registered",
  "approved": "Approved",
  "lastRefresh": "Last refresh",
  "neverRefreshed": "never",
  "dormant": "Dormant",
  "staleFeeds": "{{count}} not refreshed recently",
  "feedsOfLimit": "{{used}} of {{limit}} feeds",
  "limits": "Limits",
  "limitsUnset": "No per-user limits set",
  "noTags": "This account has no tags.",
  "noFeeds": "This account has no feeds.",
  "backToUsers": "Users"
}
```

In `frontend/public/i18n/de.json`, inside `"admin"`:

```json
"detail": {
  "account": "Konto",
  "activity": "Aktivität",
  "footprint": "Nutzung",
  "tagsTitle": "Tags",
  "feedsTitle": "Feeds",
  "locale": "Sprache",
  "created": "Registriert",
  "approved": "Freigegeben",
  "lastRefresh": "Letzte Aktualisierung",
  "neverRefreshed": "nie",
  "dormant": "Inaktiv",
  "staleFeeds": "{{count}} länger nicht aktualisiert",
  "feedsOfLimit": "{{used}} von {{limit}} Feeds",
  "limits": "Limits",
  "limitsUnset": "Keine benutzerbezogenen Limits gesetzt",
  "noTags": "Dieses Konto hat keine Tags.",
  "noFeeds": "Dieses Konto hat keine Feeds.",
  "backToUsers": "Benutzer"
}
```

- [ ] **Step 2: Write the failing test**

```ts
// src/app/admin/admin-user-detail.component.spec.ts
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { API_BASE_URL } from '../core/api';
import { AdminUserDetailComponent } from './admin-user-detail.component';

const detail = {
  user: {
    id: 7,
    email: 'detailed@example.com',
    status: 'active',
    roles: ['ROLE_USER'],
    locale: 'en',
    createdAt: '2026-01-01T09:00:00+00:00',
    approvedAt: '2026-01-02T09:00:00+00:00',
    lastLoginAt: '2026-07-29T09:00:00+00:00',
    identities: ['google'],
    feedsCount: 2,
    tagsCount: 1,
  },
  footprint: {
    feedsCount: 2,
    tagsCount: 1,
    feedsLimit: 500,
    staleFeedsCount: 1,
    lastRefreshAt: '2026-07-30T06:00:00+00:00',
    dormant: false,
  },
  tags: [
    { id: 3, name: 'Tech', color: '#112233', icon: 'memory', position: 0, feedsCount: 2 },
  ],
  subscriptions: [
    {
      id: 5,
      title: 'Ars Technica',
      customTitle: null,
      url: 'https://example.test/feed',
      position: 0,
      createdAt: '2026-02-01T09:00:00+00:00',
      lastFetchedAt: '2026-07-30T06:00:00+00:00',
      tags: [{ id: 3, name: 'Tech', color: '#112233' }],
    },
  ],
};

describe('AdminUserDetailComponent', () => {
  let ctrl: HttpTestingController;

  function mount() {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: ActivatedRoute, useValue: { paramMap: of({ get: () => '7' }) } },
      ],
    });
    const f = TestBed.createComponent(AdminUserDetailComponent);
    ctrl = TestBed.inject(HttpTestingController);
    f.detectChanges();
    return f;
  }

  afterEach(() => ctrl.verify());

  it('renders the account, its footprint and both lists', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
    f.detectChanges();

    const text = f.nativeElement.textContent as string;
    expect(text).toContain('detailed@example.com');
    expect(text).toContain('Ars Technica');
    expect(text).toContain('Tech');
    expect(text).toContain('500');
  });

  it('renders empty states when the account has no tags and no feeds', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush({
      ...detail,
      footprint: { ...detail.footprint, feedsCount: 0, tagsCount: 0, lastRefreshAt: null },
      tags: [],
      subscriptions: [],
    });
    f.detectChanges();

    const text = f.nativeElement.textContent as string;
    expect(text).toContain('This account has no tags.');
    expect(text).toContain('This account has no feeds.');
  });
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest src/app/admin/admin-user-detail.component.spec.ts`
Expected: FAIL — `Cannot find module './admin-user-detail.component'`

- [ ] **Step 4: Implement the component**

```ts
// src/app/admin/admin-user-detail.component.ts
import { DatePipe } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { HttpErrorResponse } from '@angular/common/http';
import { Problem, parseProblem } from '../core/problem';
import { IconComponent } from '../shared/icon/icon.component';
import { SpinnerComponent } from '../shared/spinner/spinner.component';
import { AdminApi } from './admin-api';
import { AdminUserDetailDto } from './admin.models';

/** Everything the admin knows about one account: who they are, how active they
 *  are, and exactly which tags and feeds they own. Read-only apart from the
 *  account actions the list page also offers. */
@Component({
  selector: 'app-admin-user-detail',
  imports: [DatePipe, IconComponent, RouterLink, SpinnerComponent, TranslocoPipe],
  templateUrl: './admin-user-detail.component.html',
  styleUrl: './admin-user-detail.component.scss',
})
export class AdminUserDetailComponent {
  private readonly api = inject(AdminApi);
  private readonly route = inject(ActivatedRoute);

  readonly detail = signal<AdminUserDetailDto | null>(null);
  readonly loading = signal(true);
  readonly error = signal<Problem | null>(null);

  constructor() {
    this.route.paramMap.subscribe((params) => this.load(Number(params.get('id'))));
  }

  load(id: number): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.userDetail(id).subscribe({
      next: (detail) => {
        this.detail.set(detail);
        this.loading.set(false);
      },
      error: (failure: HttpErrorResponse) => {
        this.error.set(parseProblem(failure));
        this.loading.set(false);
      },
    });
  }
}
```

**Verify while implementing:** confirm `parseProblem` and the `Problem` type are exported from `../core/problem` under those names (the catalog dialogs import them the same way), and that `SpinnerComponent` lives at `../shared/spinner/spinner.component` — `admin-users.component.ts` already imports both, so copy its import lines verbatim.

```html
<!-- src/app/admin/admin-user-detail.component.html -->
<section>
  <a class="back" routerLink="/settings/admin/users">
    <app-icon name="arrow_back" size="sm" /> {{ 'admin.detail.backToUsers' | transloco }}
  </a>

  @if (loading()) {
    <div class="pad"><app-spinner /></div>
  } @else if (error()) {
    <div class="banner" role="alert">{{ error()!.detail || error()!.title }}</div>
  } @else if (detail(); as d) {
    <h2>{{ d.user.email }}</h2>

    <div class="cards">
      <div class="card">
        <h3>{{ 'admin.detail.account' | transloco }}</h3>
        <dl>
          <dt>{{ 'admin.status.' + d.user.status | transloco }}</dt>
          <dd>{{ d.user.roles.join(', ') }}</dd>
          <dt>{{ 'admin.detail.locale' | transloco }}</dt>
          <dd>{{ d.user.locale }}</dd>
          <dt>{{ 'admin.detail.created' | transloco }}</dt>
          <dd>{{ d.user.createdAt | date: 'medium' }}</dd>
          <dt>{{ 'admin.detail.approved' | transloco }}</dt>
          <dd>{{ d.user.approvedAt ? (d.user.approvedAt | date: 'medium') : '—' }}</dd>
          @if (d.user.identities.length) {
            <dt>{{ 'admin.detail.account' | transloco }}</dt>
            <dd>{{ d.user.identities.join(', ') }}</dd>
          }
        </dl>
      </div>

      <div class="card">
        <h3>{{ 'admin.detail.activity' | transloco }}</h3>
        <dl>
          <dt>{{ 'admin.lastLogin' | transloco }}</dt>
          <dd>
            {{
              d.user.lastLoginAt
                ? (d.user.lastLoginAt | date: 'medium')
                : ('admin.neverLoggedIn' | transloco)
            }}
          </dd>
          <dt>{{ 'admin.detail.lastRefresh' | transloco }}</dt>
          <dd>
            {{
              d.footprint.lastRefreshAt
                ? (d.footprint.lastRefreshAt | date: 'medium')
                : ('admin.detail.neverRefreshed' | transloco)
            }}
          </dd>
        </dl>
        @if (d.footprint.dormant) {
          <p class="flag">{{ 'admin.detail.dormant' | transloco }}</p>
        }
      </div>

      <div class="card">
        <h3>{{ 'admin.detail.footprint' | transloco }}</h3>
        <p>
          {{
            'admin.detail.feedsOfLimit'
              | transloco: { used: d.footprint.feedsCount, limit: d.footprint.feedsLimit }
          }}
        </p>
        <p>{{ d.footprint.tagsCount }} {{ 'admin.tagsLabel' | transloco }}</p>
        @if (d.footprint.staleFeedsCount > 0) {
          <p class="muted">
            {{ 'admin.detail.staleFeeds' | transloco: { count: d.footprint.staleFeedsCount } }}
          </p>
        }
        <p class="muted">{{ 'admin.detail.limitsUnset' | transloco }}</p>
      </div>
    </div>

    <h3>{{ 'admin.detail.tagsTitle' | transloco }}</h3>
    @if (d.tags.length === 0) {
      <p class="muted">{{ 'admin.detail.noTags' | transloco }}</p>
    } @else {
      <ul class="rows">
        @for (tag of d.tags; track tag.id) {
          <li>
            <span class="swatch" [style.background]="tag.color"></span>
            @if (tag.icon) {
              <app-icon [name]="tag.icon" size="sm" />
            }
            <span class="name">{{ tag.name }}</span>
            <span class="count">{{ tag.feedsCount }} {{ 'admin.feedsLabel' | transloco }}</span>
          </li>
        }
      </ul>
    }

    <h3>{{ 'admin.detail.feedsTitle' | transloco }}</h3>
    @if (d.subscriptions.length === 0) {
      <p class="muted">{{ 'admin.detail.noFeeds' | transloco }}</p>
    } @else {
      <ul class="rows">
        @for (sub of d.subscriptions; track sub.id) {
          <li class="feed">
            <span class="ident">
              <span class="name">{{ sub.customTitle || sub.title }}</span>
              <span class="url">{{ sub.url }}</span>
            </span>
            <span class="tags">
              @for (tag of sub.tags; track tag.id) {
                <span class="chip" [style.border-color]="tag.color">{{ tag.name }}</span>
              }
            </span>
            <span class="count">
              {{ sub.lastFetchedAt ? (sub.lastFetchedAt | date: 'shortDate') : '—' }}
            </span>
          </li>
        }
      </ul>
    }
  }
</section>
```

```scss
// src/app/admin/admin-user-detail.component.scss
section {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.back {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  color: var(--text-secondary);
  text-decoration: none;
}

h2 {
  font-size: var(--fs-lg);
  margin: 0;
}

h3 {
  font-size: var(--fs-md);
  margin: 0;
}

.cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
  gap: var(--space-4);
}

.card {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  padding: var(--row-pad-comfy-y) var(--row-pad-comfy-x);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.card p {
  margin: 0;
}

dl {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: var(--space-1) var(--space-3);
  margin: 0;
  font-size: var(--fs-sm);
}

dt {
  color: var(--text-muted);
}

dd {
  margin: 0;
}

.flag {
  color: var(--danger);
  font-size: var(--fs-sm);
}

.muted {
  color: var(--text-muted);
  font-size: var(--fs-sm);
}

.rows {
  list-style: none;
  margin: 0;
  padding: 0;
}

.rows li {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--row-pad-y) var(--row-pad-comfy-x);
  border-bottom: 1px solid var(--border);
}

.swatch {
  width: var(--space-3);
  height: var(--space-3);
  border-radius: var(--radius-sm);
  flex-shrink: 0;
}

.ident {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.url {
  font-size: var(--fs-sm);
  color: var(--text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tags {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1);
}

.chip {
  font-size: var(--fs-xs);
  border: 1px solid var(--border);
  border-radius: var(--radius-pill);
  padding: 0 var(--space-2);
}

.count {
  font-size: var(--fs-sm);
  color: var(--text-muted);
  white-space: nowrap;
  margin-left: auto;
}
```

**Note:** `minmax(15rem, 1fr)` uses `rem`, not `px`, so it does not trip the Stylelint unit rule. If Stylelint still objects, use the documented escape hatch with a reason rather than changing the layout.

- [ ] **Step 5: Register the route**

In `frontend/src/app/settings/settings.routes.ts`, add **after** the existing `admin/users` entry (order matters only for readability; Angular matches the full path):

```ts
      {
        path: 'admin/users/:id',
        canActivate: [adminGuard],
        loadComponent: () =>
          import('../admin/admin-user-detail.component').then((m) => m.AdminUserDetailComponent),
      },
```

The settings shell matches sections by `url().startsWith('/settings/' + path)`, so `/settings/admin/users/7` still resolves to the `admin/users` section for the back-target and wide-column logic. That is the intended behavior — the detail page belongs to the users section. Do **not** add an entry to `settings-sections.ts`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest src/app/admin/admin-user-detail.component.spec.ts`
Expected: PASS (2 tests)

Then the whole gate: `npm run check`
Expected: ESLint, Prettier, Stylelint and Jest all clean.

- [ ] **Step 7: Commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx prettier --write src/app/admin/admin-user-detail.component.ts src/app/admin/admin-user-detail.component.html src/app/admin/admin-user-detail.component.scss src/app/admin/admin-user-detail.component.spec.ts src/app/settings/settings.routes.ts public/i18n/en.json public/i18n/de.json
cd /Users/lars/Documents/work/eigenes/simple-feed-reader && git add frontend && git commit -m "feat(admin): per-user detail page with tag and feed lists (#180)"
```

---

### Task 9: E2e, full verification and PR

**Files:**
- Modify: `frontend/e2e/settings-admin-smoke.spec.ts`

- [ ] **Step 1: Extend the smoke**

Precondition: the Docker stack is up (`docker compose up -d` from the repo root). Keep the file's header comment block and the `signInAsAdmin` helper unchanged. Append to the existing test body, before its closing brace:

```ts
  // The admin can open one account's detail page from the list.
  await page.goto('/settings/admin/users');
  const firstUser = page.locator('a[href^="/settings/admin/users/"]').first();
  await expect(firstUser).toBeVisible();
  await firstUser.click();
  await expect(page).toHaveURL(/\/settings\/admin\/users\/\d+$/);
  await expect(page.getByRole('heading', { name: 'Tags' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Feeds' })).toBeVisible();
```

- [ ] **Step 2: Run the smoke**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npm run e2e -- settings-admin-smoke.spec.ts`
Expected: PASS. A clean skip means the seeded admin is missing — run `docker compose exec php bin/console app:e2e:seed-admin` from the repo root and re-run to an actual PASS.

Then the whole Playwright suite: `npm run e2e`
Expected: PASS.

- [ ] **Step 3: Full gates, both backend legs**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npm run check
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit && composer cs && bin/console cache:warmup && composer stan && composer md
```
Then, from the repo root: `docker compose exec php vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 4: Migration verification and log scan**

From the repo root:
```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
docker compose exec php sh -c "tail -n 100 var/log/dev.log"
```
Expected: migration applies, schema validates, and the log shows no new errors or deprecations from the session's traffic.

- [ ] **Step 5: Manual verification in the browser**

With the stack up and `npm start` running:

1. Sign in as the seeded admin, open `/settings/admin/users`: each row shows feed and tag counts and a last-login date (or "never").
2. Click a user: the detail page shows account, activity and footprint cards, then the full tag and feed lists.
3. Sign in as that user in another browser profile, then reload the admin detail page: the last-login stamp has moved to now.
4. An account with no feeds shows both empty states and "never" for last refresh.
5. A non-admin deep-linking `/settings/admin/users/1` is bounced to the reader.

- [ ] **Step 6: Push and open the PR**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader && git push -u origin feature/180-admin-user-statistics
gh pr create --base develop --title "admin: per-user statistics and detail page (#180)" --body "$(cat <<'EOF'
Part of #180 (Phase 5 — better user management). The issue stays open.

## What
- `User` gains `lastLoginAt`, stamped by a single Lexik `JWTCreatedEvent` listener that covers both the password and the OAuth sign-in path (the password path never reaches `AuthController::login()`, so the token-issue hook is the only shared point). Additive, platform-aware migration.
- `GET /api/admin/users` gains `feedsCount`, `tagsCount` and `lastLoginAt`, read in one batched `GROUP BY` per figure and pinned by `QueryRecorder` query-count tests.
- New `GET /api/admin/users/{id}` returns the account, a `UserFootprint` (feeds vs the global cap, stale feeds, last refresh, dormancy) and the account's complete tag and feed lists, in the user's own order.
- New `/settings/admin/users/:id` detail page behind `adminGuard`; the users list shows the counts and links each row to it.

## Notes
- Thresholds: dormant after 90 days without a sign-in; a feed is stale after 7 days without a fetch (refresh is manual by design).
- The detail page deliberately exposes one account's reading interests to an admin, and is admin-only — `adminGuard` in the SPA, `ROLE_ADMIN` on `^/api/admin/` server-side.
- A read-only "no per-user limits set" line reserves the layout for #66; this PR does not implement limit editing.

## Tests
- Backend: entity, `UserStatistics` unit tests (counts, newest fetch, stale and dormant boundaries, empty account), functional tests for both sign-in paths, the list fields, the query counts, and the detail endpoint (404, no hash leak, non-admin forbidden). Both SQLite and MySQL legs green; migrate-from-empty verified.
- Frontend: API client, list columns, detail page including empty states. `npm run check` clean.
- E2e: the admin smoke opens a user detail page from the list.
EOF
)"
```

---

## Self-review notes (already applied)

- **Spec coverage:** routing/surfaces → Tasks 7/8; list fields → Task 3; detail contents → Tasks 4/5/8; `lastLoginAt` + stamping → Tasks 1/2; batched reads/no N+1 → Task 3; privacy (admin-only) → Task 5's forbidden/unauthorized provider extension; testing matrix → every task plus Task 9; native-iOS → Task 5's JSON/problem+json shape.
- **Spec correction carried into the plan:** the spec named `AuthController::login()` as a stamping point. It is never executed — `json_login` intercepts it. Task 2 uses Lexik's `JWTCreatedEvent` instead, which is the only hook both sign-in paths share, and records why in the listener's docblock.
- **Type consistency:** `countsByUserIds(array $userIds): array<int, int>` (absent = zero) is used identically in both repositories and in Task 3's controller wiring; `UserFootprint`'s six properties are produced in Task 4 and serialised unchanged in Task 5, then typed as `AdminUserFootprintDto` in Task 6 and read in Task 8; `AdminApi.userDetail(id)` is defined in Task 6 and consumed in Task 8.
- **Deliberate verify-at-implementation points** (each names exactly what to check): the `user`-table quoting convention in the migration; `Feed`/`Subscription`/`Tag` constructor signatures in the Task 4 fixtures; whether `findForUserWithTags()` fetch-joins `feed`; whether `Subscription::getTags()` returns a Collection; the `queriesMatching()` needle if `from tag` over-matches; the existing OAuth test's fixture helpers.
- **PHPMD:** Task 5 flags the large array literal in `detail()` and prescribes extracting two private row builders rather than tuning the threshold.
