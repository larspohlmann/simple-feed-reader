# Per-user trial period and configurable max feeds Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give each account an optional trial period (expiry → suspended, lazily, on the user's next request) and an optional per-user subscription cap, both admin-configurable from the frontend, with a sidebar countdown for the user.

**Architecture:** Two nullable `User` columns (`trialEndsAt`, `maxSubscriptions`). A `TrialExpiryGuard` runs inside the two security checkers and flips an expired trial to `Suspended` on the next request. A `SubscriptionLimitResolver` centralises the effective cap. A `UserLimits` admin service plus three thin `AdminUserController` actions expose the controls; the admin UI and the reader sidebar consume new JSON fields.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Doctrine ORM + migrations, PHPUnit (SQLite native / MySQL Docker); Angular 20 standalone + signals, Jest, Transloco.

## Global Constraints

- **No scheduler.** The trial → suspended transition is lazy, on the user's own next request. Never add a cron/background job.
- **`declare(strict_types=1);`** in every PHP file. PSR-12. `final readonly` with constructor promotion is the house style.
- **Thin controllers.** Actions read the request, delegate to a service, return a response. No private methods that carry responsibility (`ThinControllerRule`).
- **No boolean flag parameters.** Split the method instead.
- **Errors are typed exceptions**, never `null`/magic values.
- **Naive UTC datetimes.** Compute dates from the injected `ClockInterface`; store UTC.
- **Native iOS readiness.** JSON only, bearer-token, stateless, `application/problem+json` on error, no browser-only inputs.
- **Migrations are platform-aware** (MySQL + SQLite) and additive; they get their own verification (the suite never runs them).
- **Frontend:** standalone components + signals; component styles in a sibling `.scss`; no hex colours / raw `px` outside `theme/`; dates via `formatLongDate`/`formatDateOr` (never `DatePipe`).
- **Quality gates on touched files:** backend `composer check` + `composer md` + PhpStorm inspections clean; frontend `npm run check` clean. Scan `backend/var/log/dev.log` after backend work.
- **Branch:** `feature/66-per-user-trial-and-feed-cap` off `develop`; PR into `develop`; close #66 manually on merge.

---

### Task 1: `User` entity fields + migration + test factory

**Files:**
- Modify: `backend/src/Entity/User.php`
- Create: `backend/migrations/Version20260731130000.php`
- Modify: `backend/tests/Support/UserFactory.php`
- Test: `backend/tests/Entity/UserTest.php` (create if absent)

**Interfaces:**
- Produces:
  - `User::getTrialEndsAt(): ?\DateTimeImmutable`, `User::setTrialEndsAt(?\DateTimeImmutable $trialEndsAt): void`
  - `User::getMaxSubscriptions(): ?int`, `User::setMaxSubscriptions(?int $maxSubscriptions): void`
  - `UserFactory::create(..., ?\DateTimeImmutable $trialEndsAt = null, ?int $maxSubscriptions = null): User`

- [ ] **Step 1: Write the failing test** — `backend/tests/Entity/UserTest.php`

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    private function user(): User
    {
        return new User('reader@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));
    }

    public function testTrialEndsAtDefaultsToNull(): void
    {
        self::assertNull($this->user()->getTrialEndsAt());
    }

    public function testTrialEndsAtRoundTrips(): void
    {
        $user = $this->user();
        $ends = new \DateTimeImmutable('2026-08-01 10:00:00');
        $user->setTrialEndsAt($ends);
        self::assertSame($ends, $user->getTrialEndsAt());
        $user->setTrialEndsAt(null);
        self::assertNull($user->getTrialEndsAt());
    }

    public function testMaxSubscriptionsDefaultsToNull(): void
    {
        self::assertNull($this->user()->getMaxSubscriptions());
    }

    public function testMaxSubscriptionsRoundTrips(): void
    {
        $user = $this->user();
        $user->setMaxSubscriptions(25);
        self::assertSame(25, $user->getMaxSubscriptions());
        $user->setMaxSubscriptions(null);
        self::assertNull($user->getMaxSubscriptions());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Entity/UserTest.php`
Expected: FAIL (`getTrialEndsAt` / `getMaxSubscriptions` not defined).

- [ ] **Step 3: Add the fields and accessors** — in `backend/src/Entity/User.php`, after the `$locale` property block, add:

```php
    /**
     * When this account's trial period ends. Null means the account has no
     * trial and no expiry — the state of every account created before this
     * column. App\Security\TrialExpiryGuard blocks the account (and flips its
     * status to Suspended on the next request) once this is in the past; the
     * date is retained after expiry so the admin can see the suspension came
     * from the trial rather than from a manual action.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt = null;

    /**
     * A per-user override of the global subscription cap. Null means "fall back
     * to SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER" — resolved in exactly
     * one place, App\Service\Subscription\SubscriptionLimitResolver.
     */
    #[ORM\Column(nullable: true)]
    private ?int $maxSubscriptions = null;
```

Then add accessors near `getLocale()`/`setLocale()`:

```php
    public function getTrialEndsAt(): ?\DateTimeImmutable
    {
        return $this->trialEndsAt;
    }

    public function setTrialEndsAt(?\DateTimeImmutable $trialEndsAt): void
    {
        $this->trialEndsAt = $trialEndsAt;
    }

    public function getMaxSubscriptions(): ?int
    {
        return $this->maxSubscriptions;
    }

    public function setMaxSubscriptions(?int $maxSubscriptions): void
    {
        $this->maxSubscriptions = $maxSubscriptions;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Entity/UserTest.php`
Expected: PASS.

- [ ] **Step 5: Write the migration** — `backend/migrations/Version20260731130000.php` (model on `Version20260731120000.php`, two columns):

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds app_user.trial_ends_at and app_user.max_subscriptions for #66: a
 * per-account trial period and a per-account subscription cap.
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration, so a dialect error here is caught only by CI's
 * migrate-from-empty leg.
 *
 * ADDITIVE ONLY. Two nullable columns, no DROP, no constraint on existing
 * data — every account that existed before this reads as "no trial, no
 * per-user cap".
 */
final class Version20260731130000 extends AbstractMigration
{
    private const TABLE = 'app_user';

    public function getDescription(): string
    {
        return 'Add app_user.trial_ends_at and app_user.max_subscriptions (#66).';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->hasTable(self::TABLE)
                && $schema->getTable(self::TABLE)->hasColumn('trial_ends_at')
                && $schema->getTable(self::TABLE)->hasColumn('max_subscriptions'),
            'app_user trial columns already exist; nothing to do.',
        );

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE app_user ADD trial_ends_at DATETIME DEFAULT NULL, ADD max_subscriptions INT DEFAULT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE app_user ADD COLUMN trial_ends_at DATETIME DEFAULT NULL');
            $this->addSql('ALTER TABLE app_user ADD COLUMN max_subscriptions INT DEFAULT NULL');

            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP COLUMN trial_ends_at');
        $this->addSql('ALTER TABLE app_user DROP COLUMN max_subscriptions');
    }
}
```

- [ ] **Step 6: Extend `UserFactory`** — in `backend/tests/Support/UserFactory.php`, add two optional parameters to `create()` after `?\DateTimeImmutable $lastLoginAt = null,`:

```php
        ?\DateTimeImmutable $trialEndsAt = null,
        ?int $maxSubscriptions = null,
```

and before `$this->em->persist($user);` add:

```php
        $user->setTrialEndsAt($trialEndsAt);
        $user->setMaxSubscriptions($maxSubscriptions);
```

- [ ] **Step 7: Verify the migration on both engines**

Run (SQLite, native — builds schema from metadata, then validate): `cd backend && bin/console doctrine:schema:validate --skip-sync -v 2>&1 | tail -5`
Run (MySQL, Docker — actually migrate from empty): `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec php bin/console doctrine:schema:validate`
Expected: mapping in sync; migration runs clean.

- [ ] **Step 8: Lint + commit**

Run: `cd backend && composer cs:fix && composer stan && composer md -- src/Entity/User.php`
Then:

```bash
git add backend/src/Entity/User.php backend/migrations/Version20260731130000.php backend/tests/Support/UserFactory.php backend/tests/Entity/UserTest.php
git commit -m "feat(#66): add trialEndsAt and maxSubscriptions to User"
```

---

### Task 2: `SubscriptionLimitResolver` + wire the three cap sites

**Files:**
- Create: `backend/src/Service/Subscription/SubscriptionLimitResolver.php`
- Modify: `backend/src/Service/Subscription/SubscriptionService.php`
- Modify: `backend/src/Service/Subscription/BulkSubscriber.php`
- Modify: `backend/src/Service/Admin/UserStatistics.php`
- Test: `backend/tests/Service/Subscription/SubscriptionLimitResolverTest.php`
- Test (extend): `backend/tests/Service/Subscription/SubscriptionServiceTest.php`

**Interfaces:**
- Consumes: `User::getMaxSubscriptions()` (Task 1), `SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER` (existing public const).
- Produces: `SubscriptionLimitResolver::resolve(User $user): int`.

- [ ] **Step 1: Write the failing test** — `backend/tests/Service/Subscription/SubscriptionLimitResolverTest.php`

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\User;
use App\Service\Subscription\SubscriptionLimitResolver;
use App\Service\Subscription\SubscriptionService;
use PHPUnit\Framework\TestCase;

final class SubscriptionLimitResolverTest extends TestCase
{
    private function user(?int $maxSubscriptions): User
    {
        $user = new User('cap@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));
        $user->setMaxSubscriptions($maxSubscriptions);

        return $user;
    }

    public function testFallsBackToGlobalDefaultWhenUnset(): void
    {
        self::assertSame(
            SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER,
            (new SubscriptionLimitResolver())->resolve($this->user(null)),
        );
    }

    public function testUsesThePerUserOverrideWhenSet(): void
    {
        self::assertSame(25, (new SubscriptionLimitResolver())->resolve($this->user(25)));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Subscription/SubscriptionLimitResolverTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement the resolver** — `backend/src/Service/Subscription/SubscriptionLimitResolver.php`

```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\User;

/**
 * The single place the effective per-user subscription cap is decided: a
 * per-user override when set, else the global default. Every enforcement and
 * display site routes through here so the fallback rule cannot drift.
 */
final readonly class SubscriptionLimitResolver
{
    public function resolve(User $user): int
    {
        return $user->getMaxSubscriptions() ?? SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Subscription/SubscriptionLimitResolverTest.php`
Expected: PASS.

- [ ] **Step 5: Wire `SubscriptionService`** — inject the resolver and use it in `createSubscription`.

In the constructor of `backend/src/Service/Subscription/SubscriptionService.php` add a property:

```php
        private SubscriptionLimitResolver $subscriptionLimits,
```

Replace the cap check in `createSubscription()`:

```php
        $userId = (int) $user->getId();
        $limit = $this->subscriptionLimits->resolve($user);
        if ($this->subscriptions->countForUser($userId) >= $limit) {
            throw new SubscriptionLimitReachedException($limit);
        }
```

- [ ] **Step 6: Wire `BulkSubscriber`** — inject the resolver and replace the constant at line ~96.

Add to the `BulkSubscriber` constructor: `private SubscriptionLimitResolver $subscriptionLimits,`. Replace:

```php
        if ($state->existing >= $this->subscriptionLimits->resolve($user)) {
            return $result->with(skippedOverLimit: 1);
        }
```

(`$user` is already a parameter of the enclosing method.)

- [ ] **Step 7: Wire `UserStatistics`** — inject the resolver, use it for `feedsLimit`.

Add to the `UserStatistics` constructor: `private SubscriptionLimitResolver $subscriptionLimits,`. In `forUser()` replace `feedsLimit: SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER,` with:

```php
            feedsLimit: $this->subscriptionLimits->resolve($user),
```

- [ ] **Step 8: Add a DB test proving the override changes the boundary** — append to `backend/tests/Service/Subscription/SubscriptionServiceTest.php`. Update `service()` to pass the resolver, and add a test. First, update the `service()` helper's `new SubscriptionService(...)` call to add `new SubscriptionLimitResolver(),` as the final constructor argument (import the class). Then add:

```php
    public function testPerUserCapOverridesTheGlobalDefault(): void
    {
        $user = $this->factory()->create('capped@example.com', maxSubscriptions: 1);
        $service = $this->service($this->discoveryReturning(
            FeedDiscoveryResult::directFeed('https://example.com/a.xml'),
        ));

        $service->subscribe($user, 'https://example.com/a.xml');

        $this->expectException(\App\Exception\SubscriptionLimitReachedException::class);
        $service->subscribe($user, 'https://example.com/b.xml');
    }
```

Note: confirm the `FeedDiscoveryResult::directFeed(...)` factory name against the existing test's usage; reuse whatever the file already calls to build a direct-feed result (see `testDirectFeedCreatesFeedAndSubscription`). If the two subscribes need distinct discovery results, build a fresh `service()` per URL as that test does.

- [ ] **Step 9: Run tests**

Run: `cd backend && php bin/phpunit tests/Service/Subscription tests/Service/Admin/UserStatisticsTest.php`
Expected: PASS (existing `feedsLimit === MAX_SUBSCRIPTIONS_PER_USER` assertions still hold — those users have `maxSubscriptions` null).

- [ ] **Step 10: Lint + commit**

Run: `cd backend && composer cs:fix && composer stan && composer md -- src/Service/Subscription/SubscriptionLimitResolver.php src/Service/Subscription/SubscriptionService.php src/Service/Subscription/BulkSubscriber.php src/Service/Admin/UserStatistics.php`

```bash
git add backend/src/Service backend/tests/Service/Subscription
git commit -m "feat(#66): resolve effective per-user subscription cap in one place"
```

---

### Task 3: `TrialExpiryGuard` + wire both security checkers

**Files:**
- Create: `backend/src/Security/TrialExpiryGuard.php`
- Modify: `backend/src/Security/UserChecker.php`
- Modify: `backend/src/Security/LoginUserChecker.php`
- Test: `backend/tests/Security/TrialExpiryGuardTest.php`
- Modify: `backend/tests/Security/UserCheckerTest.php`
- Test (functional): `backend/tests/Controller/Api/JwtAccessTest.php`, `backend/tests/Controller/Api/LoginTest.php`

**Interfaces:**
- Consumes: `User::getTrialEndsAt()` (Task 1), `AccountStatusException` (existing).
- Produces: `TrialExpiryGuard::enforce(User $user): void` — throws `AccountStatusException('suspended')` for an expired trial; flips a still-`Active` user to `Suspended` and flushes first.

- [ ] **Step 1: Write the failing unit test** — `backend/tests/Security/TrialExpiryGuardTest.php`

```php
<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Security\AccountStatusException;
use App\Security\TrialExpiryGuard;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class TrialExpiryGuardTest extends TestCase
{
    private function user(?\DateTimeImmutable $trialEndsAt, UserStatus $status = UserStatus::Active): User
    {
        $user = new User('trial@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));
        $user->setStatus($status);
        $user->setTrialEndsAt($trialEndsAt);

        return $user;
    }

    private function guard(EntityManagerInterface $em): TrialExpiryGuard
    {
        return new TrialExpiryGuard($em, new MockClock('2026-07-15T00:00:00Z'));
    }

    public function testNoTrialIsANoOp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $this->guard($em)->enforce($this->user(null));
        $this->expectNotToPerformAssertions();
    }

    public function testActiveTrialInTheFutureIsANoOp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $this->guard($em)->enforce($this->user(new \DateTimeImmutable('2026-07-20T00:00:00Z')));
        $this->expectNotToPerformAssertions();
    }

    public function testExpiredTrialFlipsActiveUserToSuspendedThenThrows(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $user = $this->user(new \DateTimeImmutable('2026-07-10T00:00:00Z'));

        try {
            $this->guard($em)->enforce($user);
            self::fail('Expected AccountStatusException');
        } catch (AccountStatusException $exception) {
            self::assertSame('suspended', $exception->accountStatus);
        }

        self::assertSame(UserStatus::Suspended, $user->getStatus());
    }

    public function testExpiredTrialOnAlreadySuspendedUserThrowsWithoutFlushing(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $user = $this->user(new \DateTimeImmutable('2026-07-10T00:00:00Z'), UserStatus::Suspended);

        $this->expectException(AccountStatusException::class);
        $this->guard($em)->enforce($user);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Security/TrialExpiryGuardTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement the guard** — `backend/src/Security/TrialExpiryGuard.php`

```php
<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Enforces the trial period. There is no scheduler in this app by design, so
 * the trial → suspended transition is lazy: the first request an account makes
 * after its trial ends flips its stored status to Suspended and is refused.
 *
 * The flip is a deliberate, named side effect kept out of the security
 * checkers themselves, which only delegate here. It happens at most once per
 * account (afterwards the status is already Suspended), so a live trial costs
 * only a null check and a date comparison.
 *
 * The date is left in place after expiry: a Suspended account whose
 * trialEndsAt is in the past is how the admin screens tell a trial expiry apart
 * from a manual suspend.
 */
final readonly class TrialExpiryGuard
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function enforce(User $user): void
    {
        $trialEndsAt = $user->getTrialEndsAt();

        if (null === $trialEndsAt || $trialEndsAt > $this->clock->now()) {
            return;
        }

        if (UserStatus::Active === $user->getStatus()) {
            $user->setStatus(UserStatus::Suspended);
            $this->entityManager->flush();
        }

        throw new AccountStatusException(UserStatus::Suspended->value);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Security/TrialExpiryGuardTest.php`
Expected: PASS.

- [ ] **Step 5: Wire `UserChecker`** — give it the guard and call it in `checkPreAuth` before the status throw.

Rewrite `backend/src/Security/UserChecker.php`:

```php
<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Runs on every authenticated request (the Doctrine provider reloads the user
 * from the DB anyway), which is what makes suspension effective immediately
 * instead of when the 7-day token expires. It is also where an expired trial
 * takes effect: TrialExpiryGuard flips the account to Suspended here, on the
 * account's own next request.
 */
final readonly class UserChecker implements UserCheckerInterface
{
    public function __construct(private TrialExpiryGuard $trialExpiryGuard)
    {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        $this->trialExpiryGuard->enforce($user);

        if (UserStatus::Active !== $user->getStatus()) {
            throw new AccountStatusException($user->getStatus()->value);
        }
    }

    // Empty, but the signature carries $token because UserCheckerInterface is
    // adding `?TokenInterface $token` to checkPostAuth in its next major, and
    // Symfony's DebugClassLoader deprecates implementations that omit it.
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
```

- [ ] **Step 6: Wire `LoginUserChecker`** — call the guard in `checkPostAuth` before the status throw.

In `backend/src/Security/LoginUserChecker.php` add a constructor and the call. The class becomes `final readonly`:

```php
final readonly class LoginUserChecker implements UserCheckerInterface
{
    public function __construct(private TrialExpiryGuard $trialExpiryGuard)
    {
    }

    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }

        $this->trialExpiryGuard->enforce($user);

        if (UserStatus::Active !== $user->getStatus()) {
            throw new AccountStatusException($user->getStatus()->value);
        }
    }
}
```

- [ ] **Step 7: Fix the existing `UserCheckerTest`** — it constructs `new UserChecker()`. Give it a guard.

In `backend/tests/Security/UserCheckerTest.php`, add imports and a helper, and update every `new UserChecker()` to `new UserChecker($this->guard())`:

```php
use App\Security\TrialExpiryGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\MockClock;
```

```php
    private function guard(): TrialExpiryGuard
    {
        return new TrialExpiryGuard(
            $this->createMock(EntityManagerInterface::class),
            new MockClock('2026-07-21T09:00:00Z'),
        );
    }
```

(The existing `user()` helper builds users with `trialEndsAt` null, so the guard is a no-op for those cases and the four existing assertions are unchanged.)

Add one new case proving the delegation:

```php
    public function testExpiredTrialIsRejectedEvenWhenStoredStatusIsStillActive(): void
    {
        $user = $this->user(UserStatus::Active);
        $user->setTrialEndsAt(new \DateTimeImmutable('2026-07-20T09:00:00Z'));

        $this->expectException(AccountStatusException::class);
        (new UserChecker($this->guard()))->checkPreAuth($user);
    }
```

- [ ] **Step 8: Run the unit + checker tests**

Run: `cd backend && php bin/phpunit tests/Security/UserCheckerTest.php tests/Security/TrialExpiryGuardTest.php`
Expected: PASS.

- [ ] **Step 9: Add the functional API test** (through the firewall — direct-invocation is not enough). Append to `backend/tests/Controller/Api/JwtAccessTest.php`:

```php
    /**
     * An expired trial blocks the account on its next request and — the lazy
     * transition — flips the stored status to Suspended. Asserted through the
     * firewall, not by invoking the checker: only the real wiring proves the
     * guard actually runs on a JWT request.
     */
    public function testExpiredTrialBlocksTheRequestAndFlipsStatusToSuspended(): void
    {
        $client = self::createClient();
        $this->factory()->create(
            'expired-trial@example.com',
            trialEndsAt: new \DateTimeImmutable('-1 day'),
        );
        $token = $this->tokenFor($client, 'expired-trial@example.com');

        $client->request('GET', self::PROTECTED, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $this->assertUnauthorizedProblem($client);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'expired-trial@example.com']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserStatus::Suspended, $user->getStatus());
    }

    public function testActiveTrialInTheFutureIsAllowed(): void
    {
        $client = self::createClient();
        $this->factory()->create(
            'active-trial@example.com',
            trialEndsAt: new \DateTimeImmutable('+7 days'),
        );
        $token = $this->tokenFor($client, 'active-trial@example.com');

        $client->request('GET', self::PROTECTED, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
    }
```

Confirm `use App\Entity\User;`, `use App\Enum\UserStatus;`, and `use Doctrine\ORM\EntityManagerInterface;` are present in the file (they are used by the existing suspend test).

- [ ] **Step 10: Add the functional login test.** Append to `backend/tests/Controller/Api/LoginTest.php`, mirroring `testSuspendedIs403()`:

```php
    public function testExpiredTrialLoginIs403AndNamesSuspended(): void
    {
        $this->factory()->create(
            'trial-login@example.com',
            trialEndsAt: new \DateTimeImmutable('-1 day'),
        );

        // ... issue the POST /api/auth/login the same way testSuspendedIs403 does,
        // with a correct password, then:
        self::assertResponseStatusCodeSame(403);
        self::assertSame('suspended', $this->payload()['accountStatus']);
    }
```

Match the exact request/helper shape used by `testSuspendedIs403()` in that file (it already builds the login POST and reads `accountStatus`).

- [ ] **Step 11: Run the functional tests**

Run: `cd backend && php bin/phpunit tests/Controller/Api/JwtAccessTest.php tests/Controller/Api/LoginTest.php`
Expected: PASS.

- [ ] **Step 12: Lint + commit**

Run: `cd backend && composer cs:fix && composer stan && composer md -- src/Security/TrialExpiryGuard.php src/Security/UserChecker.php src/Security/LoginUserChecker.php`
Check: `cat var/log/dev.log | tail -20` (no new deprecations/errors).

```bash
git add backend/src/Security backend/tests/Security backend/tests/Controller/Api/JwtAccessTest.php backend/tests/Controller/Api/LoginTest.php
git commit -m "feat(#66): block expired trials and flip them to suspended lazily"
```

---

### Task 4: expose `trialEndsAt` on GET /api/me

**Files:**
- Modify: `backend/src/Http/MeJson.php`
- Modify: `backend/tests/Controller/Api/JwtAccessTest.php` (the exact-key-set pin)
- Test: `backend/tests/Controller/Api/MeControllerTest.php`

**Interfaces:**
- Produces: GET /api/me payload gains `trialEndsAt: string|null` (ISO 8601 / ATOM).

- [ ] **Step 1: Update the failing pin first** — in `backend/tests/Controller/Api/JwtAccessTest.php::testMeExposesExactlyTheIntendedFields`, change the expected sorted key list to include `trialEndsAt`:

```php
        self::assertSame(
            ['createdAt', 'email', 'id', 'locale', 'roles', 'status', 'trialEndsAt'],
            $this->sortedKeys($payload),
            'GET /api/me must expose exactly these fields — adding one is a deliberate act, not a side effect.',
        );
```

- [ ] **Step 2: Add a value test** — append to `backend/tests/Controller/Api/MeControllerTest.php`:

```php
    public function testTrialEndsAtIsExposedWhenSet(): void
    {
        $client = self::createClient();
        $this->factory()->create(
            'has-trial@example.com',
            trialEndsAt: new \DateTimeImmutable('2026-09-01T00:00:00Z'),
        );
        $token = $this->tokenFor($client, 'has-trial@example.com');

        $client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseIsSuccessful();
        self::assertSame('2026-09-01T00:00:00+00:00', $this->payload($client)['trialEndsAt']);
    }

    public function testTrialEndsAtIsNullWhenUnset(): void
    {
        $client = self::createClient();
        $this->factory()->create('no-trial@example.com');
        $token = $this->tokenFor($client, 'no-trial@example.com');

        $client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->payload($client)['trialEndsAt']);
    }
```

Match the file's existing `tokenFor` / `payload` helper signatures (see the top of `MeControllerTest.php`); adapt the calls if they differ.

- [ ] **Step 3: Run tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Controller/Api/MeControllerTest.php tests/Controller/Api/JwtAccessTest.php`
Expected: FAIL (`trialEndsAt` absent).

- [ ] **Step 4: Add the field** — in `backend/src/Http/MeJson.php::profile`, add after `'status' => ...`:

```php
            'trialEndsAt' => $user->getTrialEndsAt()?->format(\DateTimeInterface::ATOM),
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Controller/Api/MeControllerTest.php tests/Controller/Api/JwtAccessTest.php`
Expected: PASS.

- [ ] **Step 6: Lint + commit**

Run: `cd backend && composer cs:fix && composer stan && composer md -- src/Http/MeJson.php`

```bash
git add backend/src/Http/MeJson.php backend/tests/Controller/Api/MeControllerTest.php backend/tests/Controller/Api/JwtAccessTest.php
git commit -m "feat(#66): expose trialEndsAt on GET /api/me"
```

---

### Task 5: `UserLimits` admin service + request DTOs

**Files:**
- Create: `backend/src/Service/Admin/UserLimits.php`
- Create: `backend/src/Dto/Admin/StartTrialRequest.php`
- Create: `backend/src/Dto/Admin/SetSubscriptionLimitRequest.php`
- Test: `backend/tests/Service/Admin/UserLimitsTest.php`

**Interfaces:**
- Consumes: `User` accessors (Task 1), `ClockInterface`, `EntityManagerInterface`, `UserStatus`.
- Produces:
  - `UserLimits::startTrial(User $user, int $days): void`
  - `UserLimits::clearTrial(User $user): void`
  - `UserLimits::setSubscriptionLimit(User $user, ?int $maxSubscriptions): void`
  - `StartTrialRequest { public int $days }`
  - `SetSubscriptionLimitRequest { public ?int $maxSubscriptions }`

- [ ] **Step 1: Write the failing test** — `backend/tests/Service/Admin/UserLimitsTest.php`

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Service\Admin\UserLimits;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserLimitsTest extends DbTestCase
{
    private function factory(): UserFactory
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($this->em, $hasher);
    }

    private function service(): UserLimits
    {
        return new UserLimits($this->em, new MockClock('2026-07-15T00:00:00Z'));
    }

    public function testStartTrialSetsEndDateFromToday(): void
    {
        $user = $this->factory()->create('t1@example.com');
        $this->service()->startTrial($user, 14);

        self::assertEquals(new \DateTimeImmutable('2026-07-29T00:00:00Z'), $user->getTrialEndsAt());
        self::assertSame(UserStatus::Active, $user->getStatus());
    }

    public function testStartTrialReactivatesASuspendedAccount(): void
    {
        $user = $this->factory()->create(
            't2@example.com',
            status: UserStatus::Suspended,
            trialEndsAt: new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );

        $this->service()->startTrial($user, 30);

        self::assertSame(UserStatus::Active, $user->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-08-14T00:00:00Z'), $user->getTrialEndsAt());
        self::assertNotNull($user->getApprovedAt());
    }

    public function testClearTrialMakesTheAccountPermanent(): void
    {
        $user = $this->factory()->create(
            't3@example.com',
            trialEndsAt: new \DateTimeImmutable('2026-08-01T00:00:00Z'),
        );

        $this->service()->clearTrial($user);

        self::assertNull($user->getTrialEndsAt());
        self::assertSame(UserStatus::Active, $user->getStatus());
    }

    public function testClearTrialReactivatesAnExpiredTrialSuspension(): void
    {
        $user = $this->factory()->create(
            't4@example.com',
            status: UserStatus::Suspended,
            trialEndsAt: new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );

        $this->service()->clearTrial($user);

        self::assertNull($user->getTrialEndsAt());
        self::assertSame(UserStatus::Active, $user->getStatus());
    }

    public function testSetSubscriptionLimitSetsAndClears(): void
    {
        $user = $this->factory()->create('t5@example.com');

        $this->service()->setSubscriptionLimit($user, 50);
        self::assertSame(50, $user->getMaxSubscriptions());

        $this->service()->setSubscriptionLimit($user, null);
        self::assertNull($user->getMaxSubscriptions());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Admin/UserLimitsTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement the service** — `backend/src/Service/Admin/UserLimits.php`

```php
<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The admin's per-account limit controls: start or clear a trial, and set or
 * clear the per-user subscription cap. Starting a trial for, or clearing the
 * trial of, a trial-suspended account also restores its access — a silent
 * reinstatement, mirroring the suspended-restoration rule in
 * AdminUserController::approve().
 */
final readonly class UserLimits
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function startTrial(User $user, int $days): void
    {
        $user->setTrialEndsAt($this->clock->now()->modify(\sprintf('+%d days', $days)));
        $this->reactivateIfNotActive($user);
        $this->entityManager->flush();
    }

    public function clearTrial(User $user): void
    {
        if ($this->isTrialExpired($user)) {
            $this->reactivateIfNotActive($user);
        }

        $user->setTrialEndsAt(null);
        $this->entityManager->flush();
    }

    public function setSubscriptionLimit(User $user, ?int $maxSubscriptions): void
    {
        $user->setMaxSubscriptions($maxSubscriptions);
        $this->entityManager->flush();
    }

    private function isTrialExpired(User $user): bool
    {
        $trialEndsAt = $user->getTrialEndsAt();

        return null !== $trialEndsAt && $trialEndsAt <= $this->clock->now();
    }

    private function reactivateIfNotActive(User $user): void
    {
        if (UserStatus::Active === $user->getStatus()) {
            return;
        }

        $user->setStatus(UserStatus::Active);
        $user->setApprovedAt($this->clock->now());
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Admin/UserLimitsTest.php`
Expected: PASS.

- [ ] **Step 5: Add the request DTOs.** `backend/src/Dto/Admin/StartTrialRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A trial length in whole days, counted from now. Bounded so a typo cannot set
 * a decade-long trial; the lower bound rejects zero and negatives, which would
 * otherwise create an already-expired trial.
 */
final readonly class StartTrialRequest
{
    public function __construct(
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(3650)]
        public int $days = 0,
    ) {
    }
}
```

`backend/src/Dto/Admin/SetSubscriptionLimitRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A per-user subscription cap, or null to clear the override and fall back to
 * the global default. Assert\Positive skips null, so null is accepted and any
 * present value must be a positive integer.
 */
final readonly class SetSubscriptionLimitRequest
{
    public function __construct(
        #[Assert\Positive]
        public ?int $maxSubscriptions = null,
    ) {
    }
}
```

- [ ] **Step 6: Lint + commit**

Run: `cd backend && composer cs:fix && composer stan && composer md -- src/Service/Admin/UserLimits.php src/Dto/Admin/StartTrialRequest.php src/Dto/Admin/SetSubscriptionLimitRequest.php`

```bash
git add backend/src/Service/Admin/UserLimits.php backend/src/Dto/Admin backend/tests/Service/Admin/UserLimitsTest.php
git commit -m "feat(#66): add UserLimits admin service and request DTOs"
```

---

### Task 6: admin API — controller actions + JSON fields

**Files:**
- Modify: `backend/src/Controller/Admin/AdminUserController.php`
- Modify: `backend/src/Dto/Admin/AdminUserAccount.php`
- Modify: `backend/src/Http/AdminUserJson.php`
- Modify: `backend/tests/Controller/Admin/AdminUserControllerTest.php` (list key-set pin + new cases)

**Interfaces:**
- Consumes: `UserLimits` (Task 5), `StartTrialRequest`, `SetSubscriptionLimitRequest`, `User::getTrialEndsAt()/getMaxSubscriptions()`.
- Produces:
  - `POST /api/admin/users/{id}/trial` `{days}` → `{status, trialEndsAt}`
  - `DELETE /api/admin/users/{id}/trial` → `{status, trialEndsAt: null}`
  - `PUT /api/admin/users/{id}/subscription-limit` `{maxSubscriptions}` → `{maxSubscriptions}`
  - list rows + detail `account` gain `trialEndsAt`, `maxSubscriptions`.

- [ ] **Step 1: Update the list key-set pin** — in `backend/tests/Controller/Admin/AdminUserControllerTest.php` (~line 233), append the two keys in mapper order:

```php
                [
                    'id', 'email', 'status', 'roles', 'createdAt', 'approvedAt', 'identities',
                    'feedsCount', 'tagsCount', 'lastLoginAt', 'trialEndsAt', 'maxSubscriptions',
                ],
```

- [ ] **Step 2: Write the failing action tests** — append to `AdminUserControllerTest.php`. Use the file's existing helpers (`admin()`, `tokenFor()`, `factory()`, `call()`, `payload()`); for a body-bearing request add a small helper if the file lacks one:

```php
    public function testStartTrialSetsTrialAndReturnsIt(): void
    {
        $admin = $this->admin();
        $user = $this->factory()->create('trial-target@example.com');

        $this->client->request(
            'POST',
            self::LIST . '/' . $user->getId() . '/trial',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin), 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['days' => 14]),
        );

        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->payload()['status']);
        self::assertNotNull($this->payload()['trialEndsAt']);
    }

    public function testStartTrialRejectsNonPositiveDays(): void
    {
        $admin = $this->admin();
        $user = $this->factory()->create('bad-days@example.com');

        $this->client->request(
            'POST',
            self::LIST . '/' . $user->getId() . '/trial',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin), 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['days' => 0]),
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testClearTrialMakesPermanent(): void
    {
        $admin = $this->admin();
        $user = $this->factory()->create(
            'perm@example.com',
            trialEndsAt: new \DateTimeImmutable('+10 days'),
        );

        $this->client->request(
            'DELETE',
            self::LIST . '/' . $user->getId() . '/trial',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );

        self::assertResponseIsSuccessful();
        self::assertNull($this->payload()['trialEndsAt']);
    }

    public function testSetSubscriptionLimitStoresTheOverride(): void
    {
        $admin = $this->admin();
        $user = $this->factory()->create('cap-target@example.com');

        $this->client->request(
            'PUT',
            self::LIST . '/' . $user->getId() . '/subscription-limit',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin), 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['maxSubscriptions' => 42]),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(42, $this->payload()['maxSubscriptions']);
    }

    public function testSetSubscriptionLimitClearsWithNull(): void
    {
        $admin = $this->admin();
        $user = $this->factory()->create('cap-clear@example.com', maxSubscriptions: 5);

        $this->client->request(
            'PUT',
            self::LIST . '/' . $user->getId() . '/subscription-limit',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin), 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['maxSubscriptions' => null]),
        );

        self::assertResponseIsSuccessful();
        self::assertNull($this->payload()['maxSubscriptions']);
    }
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Controller/Admin/AdminUserControllerTest.php`
Expected: FAIL (routes 404 / key-set mismatch).

- [ ] **Step 4: Add the fields to the JSON.** In `backend/src/Dto/Admin/AdminUserAccount.php` add two promoted params after `public ?string $lastLoginAt,`:

```php
        public ?string $trialEndsAt,
        public ?int $maxSubscriptions,
```

In `backend/src/Http/AdminUserJson.php::listRows`, add after `'lastLoginAt' => ...`:

```php
                'trialEndsAt' => $user->getTrialEndsAt()?->format(\DateTimeInterface::ATOM),
                'maxSubscriptions' => $user->getMaxSubscriptions(),
```

In `AdminUserJson::account`, add after `lastLoginAt: ...`:

```php
            trialEndsAt: $user->getTrialEndsAt()?->format(\DateTimeInterface::ATOM),
            maxSubscriptions: $user->getMaxSubscriptions(),
```

- [ ] **Step 5: Add the controller actions.** In `backend/src/Controller/Admin/AdminUserController.php`:

Add imports:

```php
use App\Dto\Admin\SetSubscriptionLimitRequest;
use App\Dto\Admin\StartTrialRequest;
use App\Service\Admin\UserLimits;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
```

Add `private UserLimits $userLimits,` to the constructor. Add the three actions:

```php
    #[Route('/{id}/trial', name: 'api_admin_users_start_trial', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function startTrial(int $id, #[MapRequestPayload] StartTrialRequest $request): JsonResponse
    {
        $user = $this->users->getById($id);
        $this->userLimits->startTrial($user, $request->days);

        return new JsonResponse([
            'status' => $user->getStatus()->value,
            'trialEndsAt' => $user->getTrialEndsAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{id}/trial', name: 'api_admin_users_clear_trial', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function clearTrial(int $id): JsonResponse
    {
        $user = $this->users->getById($id);
        $this->userLimits->clearTrial($user);

        return new JsonResponse([
            'status' => $user->getStatus()->value,
            'trialEndsAt' => $user->getTrialEndsAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{id}/subscription-limit', name: 'api_admin_users_set_subscription_limit', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function setSubscriptionLimit(int $id, #[MapRequestPayload] SetSubscriptionLimitRequest $request): JsonResponse
    {
        $user = $this->users->getById($id);
        $this->userLimits->setSubscriptionLimit($user, $request->maxSubscriptions);

        return new JsonResponse(['maxSubscriptions' => $user->getMaxSubscriptions()]);
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Controller/Admin/AdminUserControllerTest.php`
Expected: PASS.

- [ ] **Step 7: Confirm the controller stays thin and PHPMD-clean** (a 10th constructor param is at, not over, the ExcessiveParameterList threshold; verify):

Run: `cd backend && composer stan && composer md -- src/Controller/Admin/AdminUserController.php src/Http/AdminUserJson.php src/Dto/Admin/AdminUserAccount.php`
Expected: clean. If PHPMD flags the constructor, that is a real signal — do not raise the threshold; consolidate (e.g. move `getById` lookups behind `UserLimits`), then re-run.

- [ ] **Step 8: Run the whole backend suite (SQLite) + scan the log**

Run: `cd backend && php bin/phpunit && tail -20 var/log/dev.log`
Expected: green; no new deprecations/errors.

- [ ] **Step 9: Commit**

```bash
git add backend/src/Controller/Admin/AdminUserController.php backend/src/Http/AdminUserJson.php backend/src/Dto/Admin/AdminUserAccount.php backend/tests/Controller/Admin/AdminUserControllerTest.php
git commit -m "feat(#66): admin endpoints for trial and per-user subscription cap"
```

---

### Task 7: reader sidebar trial countdown + `CurrentUser.trialEndsAt`

**Files:**
- Modify: `frontend/src/app/core/auth.service.ts`
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.ts`
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.html`
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.scss`
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: GET /api/me `trialEndsAt` (Task 4).
- Produces: `CurrentUser.trialEndsAt: string | null`; `SidebarComponent.trialDaysLeft(): number | null`.

- [ ] **Step 1: Extend `CurrentUser`** — in `frontend/src/app/core/auth.service.ts`, add to the interface:

```ts
  trialEndsAt: string | null;
```

- [ ] **Step 2: Write the failing sidebar test** — add to `frontend/src/app/reader/sidebar/sidebar.component.spec.ts`. The sidebar reads the account from `AuthService`, so provide a stub whose `user` signal returns a trial account. Mirror the spec's existing `TestBed`/`ComponentFixture` setup; the key assertions:

```ts
it('shows the trial countdown when a trial is active', () => {
  // Arrange: AuthService.user() returns { ..., trialEndsAt: <+5 days ISO> }
  // Act: detectChanges()
  // Assert: the trial indicator element is present and names 5 days.
  const el = fixture.nativeElement.querySelector('.trial');
  expect(el?.textContent).toContain('5');
});

it('hides the trial countdown when there is no trial', () => {
  // AuthService.user() returns { ..., trialEndsAt: null }
  expect(fixture.nativeElement.querySelector('.trial')).toBeNull();
});

it('hides the trial countdown when the trial is already past', () => {
  // AuthService.user() returns { ..., trialEndsAt: <-1 day ISO> }
  expect(fixture.nativeElement.querySelector('.trial')).toBeNull();
});
```

Build the `+5 days` / `-1 day` ISO strings from `new Date(Date.now() ± n*86400000).toISOString()`. If the existing spec does not yet provide `AuthService`, add a minimal stub to the `providers` array: `{ provide: AuthService, useValue: { user: signal<CurrentUser | null>(<account>) } }`.

- [ ] **Step 3: Run test to verify it fails**

Run: `cd frontend && npx jest sidebar`
Expected: FAIL (no `.trial` element).

- [ ] **Step 4: Implement the countdown** — in `frontend/src/app/reader/sidebar/sidebar.component.ts` add imports/`computed` and inject `AuthService`:

```ts
import { Component, computed, inject, input, output, signal } from '@angular/core';
import { AuthService } from '../../core/auth.service';
```

Inside the class:

```ts
  private readonly auth = inject(AuthService);

  /** Whole days left in the current trial, or null when the account has no
   *  active trial. Expired trials read as null here — the account is suspended
   *  by then and never reaches this view. */
  readonly trialDaysLeft = computed<number | null>(() => {
    const endsAt = this.auth.user()?.trialEndsAt;
    if (!endsAt) return null;
    const remainingMs = new Date(endsAt).getTime() - Date.now();
    if (remainingMs <= 0) return null;
    return Math.ceil(remainingMs / 86_400_000);
  });

  /** The last stretch of a trial is emphasised. */
  readonly trialEndingSoon = computed(() => {
    const daysLeft = this.trialDaysLeft();
    return daysLeft !== null && daysLeft <= 3;
  });
```

- [ ] **Step 5: Render it** — in `frontend/src/app/reader/sidebar/sidebar.component.html`, add above the `version` anchor (near the end, after `<app-view-controls .../>`):

```html
  @if (trialDaysLeft(); as days) {
    <p class="trial" [class.soon]="trialEndingSoon()">
      <app-icon name="schedule" size="sm" />
      <span>{{
        (days === 1 ? 'reader.trialDayLeft' : 'reader.trialDaysLeft') | transloco: { days }
      }}</span>
    </p>
  }
```

Confirm an icon named `schedule` (or a suitable existing glyph) is available in the icon set; if not, reuse one the sidebar already references.

- [ ] **Step 6: Style it** — in `frontend/src/app/reader/sidebar/sidebar.component.scss`, add a `.trial` rule using existing spacing/colour tokens (no hex, no raw px). Follow the `.version`/`.label` rules already in that file for token usage; `.trial.soon` uses a warning/accent token for emphasis.

- [ ] **Step 7: Add i18n keys** — in `frontend/public/i18n/en.json` under `reader`:

```json
"trialDaysLeft": "Trial · {{days}} days left",
"trialDayLeft": "Trial · 1 day left",
```

In `frontend/public/i18n/de.json` under `reader`:

```json
"trialDaysLeft": "Testphase · noch {{days}} Tage",
"trialDayLeft": "Testphase · noch 1 Tag",
```

- [ ] **Step 8: Run test to verify it passes**

Run: `cd frontend && npx jest sidebar`
Expected: PASS.

- [ ] **Step 9: Lint + commit**

Run: `cd frontend && npm run check`

```bash
git add frontend/src/app/core/auth.service.ts frontend/src/app/reader/sidebar frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#66): show the trial countdown in the reader sidebar"
```

---

### Task 8: admin frontend model + API methods

**Files:**
- Modify: `frontend/src/app/admin/admin.models.ts`
- Modify: `frontend/src/app/admin/admin-api.ts`
- Modify: `frontend/src/app/admin/admin-api.spec.ts`

**Interfaces:**
- Consumes: the endpoints from Task 6.
- Produces:
  - `AdminUserAccountDto.trialEndsAt: string | null`, `.maxSubscriptions: number | null`
  - `AdminUserDto.trialEndsAt: string | null`, `.maxSubscriptions: number | null`
  - `AdminApi.startTrial(id, days)`, `.clearTrial(id)`, `.setSubscriptionLimit(id, max | null)`

- [ ] **Step 1: Extend the models** — in `frontend/src/app/admin/admin.models.ts`, add to `AdminUserDto` and `AdminUserAccountDto`:

```ts
  /** ISO 8601, or null when the account has no trial. */
  trialEndsAt: string | null;
  /** Per-user subscription cap, or null to use the global default. */
  maxSubscriptions: number | null;
```

Update the `AdminUserFootprintDto.feedsLimit` comment: it now reports the effective per-user cap.

- [ ] **Step 2: Write the failing API test** — add to `frontend/src/app/admin/admin-api.spec.ts`, matching the file's `HttpTestingController` pattern:

```ts
it('POSTs to start a trial', () => {
  api.startTrial(7, 14).subscribe();
  const req = httpMock.expectOne(`${base}/api/admin/users/7/trial`);
  expect(req.request.method).toBe('POST');
  expect(req.request.body).toEqual({ days: 14 });
  req.flush({ status: 'active', trialEndsAt: '2026-08-01T00:00:00+00:00' });
});

it('DELETEs to clear a trial', () => {
  api.clearTrial(7).subscribe();
  const req = httpMock.expectOne(`${base}/api/admin/users/7/trial`);
  expect(req.request.method).toBe('DELETE');
  req.flush({ status: 'active', trialEndsAt: null });
});

it('PUTs the subscription limit', () => {
  api.setSubscriptionLimit(7, 42).subscribe();
  const req = httpMock.expectOne(`${base}/api/admin/users/7/subscription-limit`);
  expect(req.request.method).toBe('PUT');
  expect(req.request.body).toEqual({ maxSubscriptions: 42 });
  req.flush({ maxSubscriptions: 42 });
});
```

Match the spec's existing names for the API instance (`api`), the mock (`httpMock`), and the base URL (`base`).

- [ ] **Step 3: Run test to verify it fails**

Run: `cd frontend && npx jest admin-api`
Expected: FAIL (methods undefined).

- [ ] **Step 4: Add the API methods** — in `frontend/src/app/admin/admin-api.ts`:

```ts
  startTrial(id: number, days: number): Observable<{ status: AdminUserStatus; trialEndsAt: string | null }> {
    return this.http.post<{ status: AdminUserStatus; trialEndsAt: string | null }>(
      `${this.base}/api/admin/users/${id}/trial`,
      { days },
    );
  }

  clearTrial(id: number): Observable<{ status: AdminUserStatus; trialEndsAt: string | null }> {
    return this.http.delete<{ status: AdminUserStatus; trialEndsAt: string | null }>(
      `${this.base}/api/admin/users/${id}/trial`,
    );
  }

  setSubscriptionLimit(id: number, maxSubscriptions: number | null): Observable<{ maxSubscriptions: number | null }> {
    return this.http.put<{ maxSubscriptions: number | null }>(
      `${this.base}/api/admin/users/${id}/subscription-limit`,
      { maxSubscriptions },
    );
  }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd frontend && npx jest admin-api`
Expected: PASS.

- [ ] **Step 6: Lint + commit**

Run: `cd frontend && npm run check`

```bash
git add frontend/src/app/admin/admin.models.ts frontend/src/app/admin/admin-api.ts frontend/src/app/admin/admin-api.spec.ts
git commit -m "feat(#66): admin API client for trial and subscription-cap controls"
```

---

### Task 9: admin detail — the Limits card

**Files:**
- Modify: `frontend/src/app/admin/admin-user-detail.component.ts`
- Modify: `frontend/src/app/admin/admin-user-detail.component.html`
- Modify: `frontend/src/app/admin/admin-user-detail.component.scss`
- Modify: `frontend/src/app/admin/admin-user-detail.component.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `AdminApi.startTrial/clearTrial/setSubscriptionLimit` (Task 8), `AdminUserAccountDto.trialEndsAt/maxSubscriptions`, `footprint.feedsLimit`.
- Produces: a `trialState(): 'none' | 'active' | 'expired'` computed and the three action handlers, reloading the detail on success (mirrors the existing `act()`).

- [ ] **Step 1: Write the failing component tests** — add to `frontend/src/app/admin/admin-user-detail.component.spec.ts`, following the file's existing harness (it stubs `AdminApi` and drives the `detail` signal). Assertions:

```ts
it('shows an active trial with the days remaining', () => {
  // detail.user.trialEndsAt = <+5 days ISO>, status 'active'
  // expect the limits card to render the active-trial line and "5"
});

it('shows that a suspended account was ended by its trial', () => {
  // detail.user.status = 'suspended', trialEndsAt = <-1 day ISO>
  // expect the "suspended — trial ended" line
});

it('starts a trial through the API', () => {
  // set the days input to 30, click "Start trial"
  // expect api.startTrial(id, 30) called, then a reload
});

it('saves a max-feeds override through the API', () => {
  // set the max-feeds input to 42, click save
  // expect api.setSubscriptionLimit(id, 42) called
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx jest admin-user-detail`
Expected: FAIL.

- [ ] **Step 3: Add component logic** — in `frontend/src/app/admin/admin-user-detail.component.ts`:

```ts
  /** The trial's high-level state, derived from the end date and status. */
  readonly trialState = computed<'none' | 'active' | 'expired'>(() => {
    const endsAt = this.detail()?.user.trialEndsAt;
    if (!endsAt) return 'none';
    return new Date(endsAt).getTime() > Date.now() ? 'active' : 'expired';
  });

  /** Whole days left in an active trial (0 when not active). */
  readonly trialDaysLeft = computed(() => {
    const endsAt = this.detail()?.user.trialEndsAt;
    if (!endsAt) return 0;
    const remainingMs = new Date(endsAt).getTime() - Date.now();
    return remainingMs > 0 ? Math.ceil(remainingMs / 86_400_000) : 0;
  });

  /** True when the account is suspended and its trial end date is in the past —
   *  i.e. the suspension came from the trial, not from a manual admin action. */
  readonly suspendedByTrial = computed(
    () => this.detail()?.user.status === 'suspended' && this.trialState() === 'expired',
  );

  readonly trialDays = signal(14);
  readonly maxFeeds = signal<number | null>(null);

  startTrial(): void {
    this.actionError.set(null);
    this.api.startTrial(this.id, this.trialDays()).subscribe({
      next: () => this.load(),
      error: (failure: HttpErrorResponse) => this.actionError.set(parseProblem(failure)),
    });
  }

  clearTrial(): void {
    this.actionError.set(null);
    this.api.clearTrial(this.id).subscribe({
      next: () => this.load(),
      error: (failure: HttpErrorResponse) => this.actionError.set(parseProblem(failure)),
    });
  }

  saveMaxFeeds(): void {
    this.actionError.set(null);
    this.api.setSubscriptionLimit(this.id, this.maxFeeds()).subscribe({
      next: () => this.load(),
      error: (failure: HttpErrorResponse) => this.actionError.set(parseProblem(failure)),
    });
  }

  formatDate(iso: string): string {
    return formatLongDate(iso, this.language.lang());
  }
```

(`formatDate` already exists — reuse it for the trial dates. Add `computed`/`signal` to the `@angular/core` import if not already there; `signal` is present, add `computed`.) When the detail loads, seed `maxFeeds` from `detail.user.maxSubscriptions` — do this in the `load()` success callback: `this.maxFeeds.set(detail.user.maxSubscriptions);`.

- [ ] **Step 4: Replace the placeholder Limits block** — in `frontend/src/app/admin/admin-user-detail.component.html`, replace the two placeholder lines in the footprint card:

```html
        <p class="limits-label">{{ 'admin.detail.limits' | transloco }}</p>
        <p class="muted">{{ 'admin.detail.limitsUnset' | transloco }}</p>
```

with the Limits controls:

```html
        <p class="limits-label">{{ 'admin.detail.limits' | transloco }}</p>

        @switch (trialState()) {
          @case ('none') {
            <p class="muted">{{ 'admin.detail.trialNone' | transloco }}</p>
          }
          @case ('active') {
            <p>
              {{
                'admin.detail.trialActive'
                  | transloco: { date: formatDate(d.user.trialEndsAt!), days: trialDaysLeft() }
              }}
            </p>
          }
          @case ('expired') {
            <p [class.flag]="suspendedByTrial()">
              {{
                (suspendedByTrial() ? 'admin.detail.trialSuspended' : 'admin.detail.trialExpired')
                  | transloco: { date: formatDate(d.user.trialEndsAt!) }
              }}
            </p>
          }
        }

        <div class="limit-control">
          <label>
            {{ 'admin.detail.trialDays' | transloco }}
            <input type="number" min="1" max="3650" [value]="trialDays()"
              (input)="trialDays.set(+$any($event.target).value)" />
          </label>
          <app-button size="sm" (click)="startTrial()">
            {{ 'admin.detail.startTrial' | transloco }}
          </app-button>
          @if (trialState() !== 'none') {
            <app-button size="sm" variant="danger-outline" (click)="clearTrial()">
              {{ 'admin.detail.makePermanent' | transloco }}
            </app-button>
          }
        </div>

        <div class="limit-control">
          <label>
            {{ 'admin.detail.maxFeeds' | transloco }}
            <input type="number" min="1"
              [placeholder]="'admin.detail.maxFeedsDefault' | transloco: { limit: d.footprint.feedsLimit }"
              [value]="maxFeeds() ?? ''"
              (input)="maxFeeds.set($any($event.target).value === '' ? null : +$any($event.target).value)" />
          </label>
          <app-button size="sm" (click)="saveMaxFeeds()">
            {{ 'admin.detail.saveLimit' | transloco }}
          </app-button>
        </div>
```

- [ ] **Step 5: Style the controls** — in `frontend/src/app/admin/admin-user-detail.component.scss`, add `.limit-control` (flex row, token gap/margins; no hex, no raw px), following the file's existing token usage.

- [ ] **Step 6: Add i18n keys** — under `admin.detail` in `frontend/public/i18n/en.json`:

```json
"trialNone": "No trial",
"trialActive": "Trial ends {{date}} ({{days}} days left)",
"trialExpired": "Trial expired {{date}}",
"trialSuspended": "Suspended — trial ended {{date}}",
"trialDays": "Trial length (days)",
"startTrial": "Start trial",
"makePermanent": "Make permanent",
"maxFeeds": "Max feeds",
"maxFeedsDefault": "Default ({{limit}})",
"saveLimit": "Save"
```

Under `admin.detail` in `frontend/public/i18n/de.json`:

```json
"trialNone": "Keine Testphase",
"trialActive": "Testphase endet am {{date}} (noch {{days}} Tage)",
"trialExpired": "Testphase am {{date}} abgelaufen",
"trialSuspended": "Gesperrt — Testphase am {{date}} beendet",
"trialDays": "Testphase (Tage)",
"startTrial": "Testphase starten",
"makePermanent": "Dauerhaft freischalten",
"maxFeeds": "Max. Feeds",
"maxFeedsDefault": "Standard ({{limit}})",
"saveLimit": "Speichern"
```

- [ ] **Step 7: Run test to verify it passes**

Run: `cd frontend && npx jest admin-user-detail`
Expected: PASS.

- [ ] **Step 8: Lint + commit**

Run: `cd frontend && npm run check`

```bash
git add frontend/src/app/admin/admin-user-detail.component.ts frontend/src/app/admin/admin-user-detail.component.html frontend/src/app/admin/admin-user-detail.component.scss frontend/src/app/admin/admin-user-detail.component.spec.ts frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#66): admin detail Limits card for trial and feed cap"
```

---

### Task 10: admin users list — expired-trial badge

**Files:**
- Modify: `frontend/src/app/admin/admin-users.component.ts`
- Modify: `frontend/src/app/admin/admin-users.component.html`
- Modify: `frontend/src/app/admin/admin-users.component.scss`
- Modify: `frontend/src/app/admin/admin-users.component.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `AdminUserDto.trialEndsAt` (Task 8).
- Produces: a per-row `trialExpired(user): boolean` helper and a badge in the list.

- [ ] **Step 1: Write the failing test** — add to `frontend/src/app/admin/admin-users.component.spec.ts`, following its existing harness (stubbed `AdminApi.listUsers` returning rows):

```ts
it('flags a row whose trial has expired', () => {
  // one row with trialEndsAt = <-1 day ISO>, one with null
  // expect exactly one '.trial-expired' badge in the rendered list
  expect(fixture.nativeElement.querySelectorAll('.trial-expired').length).toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx jest admin-users`
Expected: FAIL.

- [ ] **Step 3: Add the helper** — in `frontend/src/app/admin/admin-users.component.ts`:

```ts
  /** True when the account's trial end date is in the past — the account is
   *  (or will be, on its next request) suspended by the trial. */
  trialExpired(user: AdminUserDto): boolean {
    return user.trialEndsAt !== null && new Date(user.trialEndsAt).getTime() <= Date.now();
  }
```

Confirm `AdminUserDto` is imported in the component (it is used to type the list).

- [ ] **Step 4: Render the badge** — in `frontend/src/app/admin/admin-users.component.html`, in each user row (next to the status badge), add:

```html
    @if (trialExpired(user)) {
      <span class="trial-expired">{{ 'admin.trialExpiredBadge' | transloco }}</span>
    }
```

Use the row's loop variable name from the existing template (likely `user`); match it.

- [ ] **Step 5: Style the badge** — in `frontend/src/app/admin/admin-users.component.scss`, add a `.trial-expired` rule using warning/accent tokens (no hex, no raw px), following the existing `.badge` styling in the file.

- [ ] **Step 6: Add i18n keys** — under `admin` in `frontend/public/i18n/en.json`: `"trialExpiredBadge": "Trial expired"`. Under `admin` in `de.json`: `"trialExpiredBadge": "Testphase abgelaufen"`.

- [ ] **Step 7: Run test to verify it passes**

Run: `cd frontend && npx jest admin-users`
Expected: PASS.

- [ ] **Step 8: Full frontend gate + commit**

Run: `cd frontend && npm run check`

```bash
git add frontend/src/app/admin/admin-users.component.ts frontend/src/app/admin/admin-users.component.html frontend/src/app/admin/admin-users.component.scss frontend/src/app/admin/admin-users.component.spec.ts frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#66): flag expired trials in the admin users list"
```

---

### Task 11: full verification + PR

**Files:** none (verification only).

- [ ] **Step 1: Backend, both engines**

Run: `cd backend && composer check && composer md && php bin/phpunit`
Run (MySQL leg): `docker compose exec php vendor/bin/phpunit`
Run (migrate-from-empty, both engines as CI does): `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec php bin/console doctrine:schema:validate`
Expected: all green.

- [ ] **Step 2: PhpStorm inspections on changed PHP**

Run `mcp__phpstorm__lint_files` over the touched `backend/src` files. Block on ERROR/WARNING; weak warnings advisory.

- [ ] **Step 3: Frontend gate**

Run: `cd frontend && npm run check`
Expected: green.

- [ ] **Step 4: Scan the backend log**

Run: `tail -40 backend/var/log/dev.log`
Expected: no new deprecations or swallowed errors from this work.

- [ ] **Step 5: Open the PR into `develop`**

```bash
git push -u origin feature/66-per-user-trial-and-feed-cap
```

Create a PR into `develop` titled `feat(#66): per-user trial period and configurable max feeds`, body summarising: the two `User` columns + migration, the lazy trial→suspended transition via `TrialExpiryGuard`, the `SubscriptionLimitResolver`, the admin endpoints + UI, and the sidebar countdown. Reference "Closes #66". After merge, confirm the issue closed (or close it manually — PRs target `develop`).

---

## Self-Review

**Spec coverage:**
- Data model (two nullable columns + migration) → Task 1. ✓
- Lazy trial→suspended, both checkers, no scheduler → Task 3. ✓
- Trial expiry blocks API (401) + login (403, `accountStatus`) → Task 3 functional tests. ✓
- "Trial ended vs admin suspended" derived, no extra column → Task 5 (`isTrialExpired`), Task 9 (`suspendedByTrial`), Task 10 badge. ✓
- Configurable max feeds via resolver at all three sites, fallback to default, lowering keeps existing subs → Task 2. ✓
- Admin API: start trial / clear trial / set limit → Task 6; service → Task 5. ✓
- Admin UI: Limits card + list badge → Tasks 9, 10. ✓
- Sidebar countdown + `MeJson`/`CurrentUser` → Tasks 4, 7. ✓
- Tests (cap boundary, trial transition through firewall, admin edits, sidebar, admin detail/list) → Tasks 2, 3, 6, 7, 9, 10. ✓
- Quality gates + both DB legs + migration verification → Task 11. ✓

**Placeholder scan:** The two spots that must be matched against existing code (the `FeedDiscoveryResult` direct-feed factory in Task 2 Step 8; the `LoginTest` request shape in Task 3 Step 10) are flagged inline with the exact existing test to copy, not left as "TODO". No unresolved TBDs.

**Type consistency:** `trialEndsAt` is `?\DateTimeImmutable` (entity) / ISO `string|null` (JSON, DTOs, TS). `maxSubscriptions` is `?int` / `number|null`. `TrialExpiryGuard::enforce`, `UserLimits::{startTrial,clearTrial,setSubscriptionLimit}`, `SubscriptionLimitResolver::resolve`, and the three `AdminApi` methods are named identically everywhere they appear.
