# Mailless-capable instance + registration-gate toggles — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an operator run the instance with no outgoing mail as an explicit opt-in, and give the admin two runtime switches (require email confirmation, require admin approval) that gate new registrations.

**Architecture:** Two layers behind one source of truth. Mail capability is a deploy-time env flag (`MAIL_DISABLED`) read by a `MailCapability` service, because compose and the config guard act before the database is reachable. Registration gates live in a typed single-row `InstanceSetting` entity read through an `InstanceSettings` service. A `RegistrationPolicy` service combines both and is the only thing registration code consults.

**Tech Stack:** Symfony 7.4 (PHP 8.4), Doctrine ORM + migrations, PHPUnit; Angular 20 (standalone + signals), Jest; Docker Compose (prod stack); Bash install scripts.

## Global Constraints

- `declare(strict_types=1);` in every PHP file.
- PSR-12 (`composer cs`; `composer cs:fix` autofixes). PHPStan level max over `src` and `tests`, no new baselines, no `@phpstan-ignore` without a reason comment (`composer stan`; warm cache first with `bin/console cache:warmup`). PHPMD codesize clean on every touched `src` file (`composer md`). PhpStorm inspections clean on changed PHP (block on ERROR/WARNING).
- Clean Code is mandatory: intention-revealing names, functions do one thing, ≤3 params (no boolean-flag params — split the method), guard clauses over nesting, no hidden side effects in `get…`, `final readonly` with constructor promotion, depend on injected interfaces, typed namespaced exceptions, comments explain *why*. Controllers stay thin (`ThinControllerRule`): read request, delegate, return response — no private methods that carry responsibility.
- Datetimes are stored as naive UTC — use the injected `Psr\Clock\ClockInterface`; never `new \DateTime()`.
- Migrations are platform-aware (MySQL + SQLite branches) and additive; the test bootstrap never runs them, so a dialect error is caught only by CI's migrate-from-empty leg. Keep DDL correct for both.
- Native-iOS constraint: every endpoint is bearer-token, stateless, JSON in / `application/problem+json` out. No CSRF, no `text/html` fallback, no browser-only inputs.
- Frontend: standalone components + signals, no NgModules. Component styles in a sibling `.scss` via `styleUrl` (never inline). No hex colours, no raw `px` spacing, no media-query literals in `.scss` outside `src/app/theme/` (Stylelint). `npm run check` is the gate and runs under **Node 22 only** — verify `node --version` before blaming code.
- Backend tests run natively on SQLite (`php bin/phpunit`) and on MySQL via `docker compose exec php vendor/bin/phpunit`. Run both legs before the PR.
- Toggle defaults are **on/on** — no behaviour change on upgrade. Toggles affect **future** registrations only.
- git-flow: this branch is `feature/230-mailless-instance` off `develop`. Commit after every task. The PR references and closes **#230 and #224**.

---

## File structure

**Backend — create**
- `backend/src/Service/Mail/MailCapability.php` — reads `MAIL_DISABLED`; `isEnabled(): bool`.
- `backend/src/Entity/InstanceSetting.php` — single-row typed settings entity.
- `backend/src/Repository/InstanceSettingRepository.php` — `findSingleton(): ?InstanceSetting`.
- `backend/src/Service/Settings/InstanceSettings.php` — typed accessors + `update()`.
- `backend/src/Service/Auth/RegistrationPolicy.php` — combines the two layers.
- `backend/src/Service/Mail/AccountMailerInterface.php` — the four send methods.
- `backend/src/Service/Mail/MailGatedAccountMailer.php` — decorator: log-and-skip when mail disabled.
- `backend/src/Dto/Admin/InstanceSettingsRequest.php` — admin update payload.
- `backend/src/Http/Admin/InstanceSettingsJson.php` — response mapper.
- `backend/src/Controller/Admin/AdminSettingsController.php` — GET/PUT `/api/admin/settings`.
- `backend/src/Service/Auth/PasswordResetter.php` — set-a-password service (shared by CLI + admin endpoint).
- `backend/src/Command/ResetUserPasswordCommand.php` — `app:user:reset-password`.
- `backend/migrations/Version20260801NNNNNN.php` — `instance_setting` table.

**Backend — modify**
- `backend/src/EventListener/InsecureProductionConfigGuard.php` — accept `null://null` iff `MAIL_DISABLED`.
- `backend/src/Service/Mail/AccountMailer.php` — implement `AccountMailerInterface`.
- `backend/src/Service/Auth/RegistrationService.php` — policy-driven `register()` + `verifyEmail()`; typehint `AccountMailerInterface`.
- `backend/src/Service/OAuth/OAuthAccountLinker.php` — approval-gate.
- `backend/src/Controller/Api/AuthController.php` — register response = prospective status.
- `backend/src/Controller/Api/SetupController.php` — add `mailEnabled` to status.
- `backend/src/Controller/Admin/AdminUserController.php` — typehint `AccountMailerInterface`; add reset-password action.
- `backend/src/EventListener/NotifyAdminsOfPendingApproval.php` — typehint `AccountMailerInterface` (no logic change).

**Frontend — create**
- `frontend/src/app/settings/admin/admin-settings/admin-settings.component.ts` (+ `.html` + `.scss`).
- `frontend/src/app/settings/admin/admin-settings/admin-settings-api.ts`.

**Frontend — modify**
- `frontend/src/app/setup/setup-api.ts`, `setup.service.ts` — carry `mailEnabled`.
- `frontend/src/app/auth/login/login.component.ts` + `.html` — hide forgot-password when mailless.
- `frontend/src/app/auth/register/register.component.ts` + `.html` — message per resulting status.
- `frontend/src/app/settings/settings-sections.ts`, `settings.routes.ts` — new admin settings section.
- `frontend/src/app/auth/reset-request/reset-request.component.ts` + `.html` — graceful when mailless.

**Infra & docs — modify**
- `docker-compose.prod.yml`, `.env.prod.example`, `scripts/lib.sh`.
- `docs/docker-production.md`, `docs/first-run-setup.md`.

---

## Task 1: `MailCapability` service

**Files:**
- Create: `backend/src/Service/Mail/MailCapability.php`
- Test: `backend/tests/Service/Mail/MailCapabilityTest.php`

**Interfaces:**
- Produces: `MailCapability::isEnabled(): bool` — `false` when `MAIL_DISABLED` is a truthy value (`1`, `true`, `yes`, `on`), `true` otherwise.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\MailCapability;
use PHPUnit\Framework\TestCase;

final class MailCapabilityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideFlags(): iterable
    {
        yield 'empty means enabled' => ['', true];
        yield 'zero means enabled' => ['0', true];
        yield 'false means enabled' => ['false', true];
        yield 'one disables' => ['1', false];
        yield 'true disables' => ['true', false];
        yield 'yes disables' => ['yes', false];
        yield 'on disables' => ['on', false];
        yield 'case-insensitive' => ['TRUE', false];
        yield 'whitespace tolerated' => [' 1 ', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideFlags')]
    public function testReadsTheDisableFlag(string $flag, bool $expectedEnabled): void
    {
        self::assertSame($expectedEnabled, (new MailCapability($flag))->isEnabled());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Mail/MailCapabilityTest.php`
Expected: FAIL — class `App\Service\Mail\MailCapability` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether this instance may send mail at all.
 *
 * A DEPLOY-TIME fact, not a runtime setting: docker-compose.prod.yml and
 * InsecureProductionConfigGuard both act before the database is reachable, so
 * the switch has to be an environment variable rather than a stored row.
 * MAIL_DISABLED=1 is the deliberate, opt-in "no mail" mode (issue #230); a
 * MAILER_DSN left at null://null WITHOUT this flag stays a forgotten-config
 * failure, not a mailless instance.
 */
final readonly class MailCapability
{
    public function __construct(
        #[Autowire('%env(default::MAIL_DISABLED)%')]
        private string $disabledFlag,
    ) {
    }

    public function isEnabled(): bool
    {
        return !\in_array(strtolower(trim($this->disabledFlag)), ['1', 'true', 'yes', 'on'], true);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Mail/MailCapabilityTest.php`
Expected: PASS.

- [ ] **Step 5: Add the env default so the container resolves it in every environment**

In `backend/.env`, add under the mailer section:

```dotenv
# Deliberate "no mail" mode for a private instance (issue #230). Empty = mail on.
MAIL_DISABLED=
```

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Mail/MailCapability.php backend/tests/Service/Mail/MailCapabilityTest.php backend/.env
git commit -m "feat(#230): MailCapability reads the MAIL_DISABLED deploy flag"
```

---

## Task 2: `InstanceSetting` entity, repository, `InstanceSettings` service, migration

**Files:**
- Create: `backend/src/Entity/InstanceSetting.php`, `backend/src/Repository/InstanceSettingRepository.php`, `backend/src/Service/Settings/InstanceSettings.php`
- Create: `backend/migrations/Version20260801NNNNNN.php` (use a real UTC timestamp for `NNNNNN`)
- Test: `backend/tests/Service/Settings/InstanceSettingsTest.php`

**Interfaces:**
- Produces:
  - `InstanceSettings::requireEmailConfirmation(): bool` — stored value, or `true` when no row exists.
  - `InstanceSettings::requireApproval(): bool` — stored value, or `true` when no row exists.
  - `InstanceSettings::update(bool $requireEmailConfirmation, bool $requireApproval): void` — creates-or-updates the single row and flushes.
  - `InstanceSettingRepository::findSingleton(): ?InstanceSetting`.

- [ ] **Step 1: Write the failing test** (integration test hitting a real EM — mirrors existing repository/service tests using `KernelTestCase`)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings;

use App\Service\Settings\InstanceSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InstanceSettingsTest extends KernelTestCase
{
    private InstanceSettings $settings;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->settings = $container->get(InstanceSettings::class);
        $this->em = $container->get(EntityManagerInterface::class);
    }

    public function testDefaultsToBothGatesOnWhenNoRowExists(): void
    {
        self::assertTrue($this->settings->requireEmailConfirmation());
        self::assertTrue($this->settings->requireApproval());
    }

    public function testUpdatePersistsAndIsReadBack(): void
    {
        $this->settings->update(requireEmailConfirmation: false, requireApproval: true);
        $this->em->clear();

        self::assertFalse($this->settings->requireEmailConfirmation());
        self::assertTrue($this->settings->requireApproval());
    }

    public function testUpdateReusesTheSingleRowRatherThanInsertingASecond(): void
    {
        $this->settings->update(false, false);
        $this->settings->update(true, false);
        $this->em->clear();

        $count = (int) $this->em->createQuery('SELECT COUNT(s.id) FROM App\Entity\InstanceSetting s')->getSingleScalarResult();
        self::assertSame(1, $count);
        self::assertTrue($this->settings->requireEmailConfirmation());
        self::assertFalse($this->settings->requireApproval());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Settings/InstanceSettingsTest.php`
Expected: FAIL — service/entity not found.

- [ ] **Step 3: Create the entity**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InstanceSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Instance-wide settings the admin edits at runtime, held in a single row.
 *
 * Deliberately NOT a key/value table: two typed booleans read and validate
 * without stringly-typed parsing, and PHPStan sees real types. A future flag
 * costs one nullable-safe migration, which is an honest price for that safety.
 * Absence of the row means "defaults" (see InstanceSettings), so a fresh
 * database needs no seeding.
 */
#[ORM\Entity(repositoryClass: InstanceSettingRepository::class)]
#[ORM\Table(name: 'instance_setting')]
class InstanceSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $requireEmailConfirmation = true;

    #[ORM\Column]
    private bool $requireApproval = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function requireEmailConfirmation(): bool
    {
        return $this->requireEmailConfirmation;
    }

    public function requireApproval(): bool
    {
        return $this->requireApproval;
    }

    public function apply(bool $requireEmailConfirmation, bool $requireApproval): void
    {
        $this->requireEmailConfirmation = $requireEmailConfirmation;
        $this->requireApproval = $requireApproval;
    }
}
```

- [ ] **Step 4: Create the repository**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InstanceSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InstanceSetting>
 */
final class InstanceSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstanceSetting::class);
    }

    /**
     * The one row, or null when the instance has never been configured (in which
     * case the caller applies defaults). Ordered by id so a stray second row —
     * which update() prevents — never changes which row we read.
     */
    public function findSingleton(): ?InstanceSetting
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
```

- [ ] **Step 5: Create the `InstanceSettings` service**

```php
<?php

declare(strict_types=1);

namespace App\Service\Settings;

use App\Entity\InstanceSetting;
use App\Repository\InstanceSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and writes the instance-wide settings row, defaulting to "both gates on"
 * when no row exists. The rest of the app depends on this, never on the entity
 * or repository directly, so "no row yet" is handled in exactly one place.
 */
final readonly class InstanceSettings
{
    public function __construct(
        private InstanceSettingRepository $repository,
        private EntityManagerInterface $em,
    ) {
    }

    public function requireEmailConfirmation(): bool
    {
        return $this->repository->findSingleton()?->requireEmailConfirmation() ?? true;
    }

    public function requireApproval(): bool
    {
        return $this->repository->findSingleton()?->requireApproval() ?? true;
    }

    public function update(bool $requireEmailConfirmation, bool $requireApproval): void
    {
        $setting = $this->repository->findSingleton();

        if (null === $setting) {
            $setting = new InstanceSetting();
            $this->em->persist($setting);
        }

        $setting->apply($requireEmailConfirmation, $requireApproval);
        $this->em->flush();
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Settings/InstanceSettingsTest.php`
Expected: PASS (the test bootstrap builds the schema from ORM metadata, so the entity table exists without a migration).

- [ ] **Step 7: Create the migration** (`Version20260801NNNNNN.php`, `NNNNNN` = current UTC `HHMMSS`)

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the instance_setting table for the admin registration-gate toggles
 * (#224). A single row holds require_email_confirmation and require_approval.
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration, so a dialect error here is caught only by CI's
 * migrate-from-empty leg. ADDITIVE ONLY: no existing table is touched, and an
 * absent row reads as both gates on.
 */
final class Version20260801NNNNNN extends AbstractMigration
{
    private const TABLE = 'instance_setting';

    public function getDescription(): string
    {
        return 'Add instance_setting table for registration-gate toggles (#224).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'instance_setting already exists.');

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql(
                'CREATE TABLE instance_setting (id INT AUTO_INCREMENT NOT NULL, '
                . 'require_email_confirmation TINYINT(1) NOT NULL, '
                . 'require_approval TINYINT(1) NOT NULL, '
                . 'PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB',
            );

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql(
                'CREATE TABLE instance_setting (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
                . 'require_email_confirmation BOOLEAN NOT NULL, '
                . 'require_approval BOOLEAN NOT NULL)',
            );

            return;
        }

        throw new \RuntimeException('Unsupported database platform for instance_setting migration.');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'instance_setting does not exist.');
        $this->addSql('DROP TABLE instance_setting');
    }
}
```

- [ ] **Step 8: Verify the migration matches ORM metadata**

Run: `cd backend && bin/console doctrine:migrations:migrate --no-interaction && bin/console doctrine:schema:validate`
Expected: migration runs; schema validates as in sync. (If validate complains, align the DDL — do not add a baseline.)

- [ ] **Step 9: Commit**

```bash
git add backend/src/Entity/InstanceSetting.php backend/src/Repository/InstanceSettingRepository.php backend/src/Service/Settings/InstanceSettings.php backend/migrations/Version20260801NNNNNN.php backend/tests/Service/Settings/InstanceSettingsTest.php
git commit -m "feat(#224): instance_setting store with admin registration-gate toggles"
```

---

## Task 3: `RegistrationPolicy` service

**Files:**
- Create: `backend/src/Service/Auth/RegistrationPolicy.php`
- Test: `backend/tests/Service/Auth/RegistrationPolicyTest.php`

**Interfaces:**
- Consumes: `MailCapability::isEnabled()` (Task 1), `InstanceSettings::requireEmailConfirmation()` / `requireApproval()` (Task 2).
- Produces:
  - `RegistrationPolicy::mailEnabled(): bool`
  - `RegistrationPolicy::emailConfirmationRequired(): bool` — store value AND `mailEnabled()`
  - `RegistrationPolicy::approvalRequired(): bool` — store value
  - `RegistrationPolicy::prospectiveStatusForEmailSignup(): UserStatus`

- [ ] **Step 1: Write the failing test** (pure unit test with stubbed collaborators — both are `final readonly`, so create real instances or use test doubles; here use small fakes)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Enum\UserStatus;
use App\Repository\InstanceSettingRepository;
use App\Service\Auth\RegistrationPolicy;
use App\Service\Mail\MailCapability;
use App\Service\Settings\InstanceSettings;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class RegistrationPolicyTest extends TestCase
{
    private function policy(bool $mailOn, bool $confirm, bool $approve): RegistrationPolicy
    {
        $repository = $this->createStub(InstanceSettingRepository::class);
        $settings = $this->createStub(InstanceSettings::class);
        $settings->method('requireEmailConfirmation')->willReturn($confirm);
        $settings->method('requireApproval')->willReturn($approve);

        return new RegistrationPolicy(
            new MailCapability($mailOn ? '' : '1'),
            $settings,
        );
    }

    public function testMailOffForcesEmailConfirmationOff(): void
    {
        $policy = $this->policy(mailOn: false, confirm: true, approve: true);
        self::assertFalse($policy->emailConfirmationRequired());
        self::assertFalse($policy->mailEnabled());
        self::assertTrue($policy->approvalRequired());
    }

    public function testProspectiveStatusMatrix(): void
    {
        self::assertSame(UserStatus::PendingVerification, $this->policy(true, true, true)->prospectiveStatusForEmailSignup());
        self::assertSame(UserStatus::PendingVerification, $this->policy(true, true, false)->prospectiveStatusForEmailSignup());
        self::assertSame(UserStatus::PendingApproval, $this->policy(true, false, true)->prospectiveStatusForEmailSignup());
        self::assertSame(UserStatus::Active, $this->policy(true, false, false)->prospectiveStatusForEmailSignup());
        // Mail off collapses the confirm rows to their approval fallback.
        self::assertSame(UserStatus::PendingApproval, $this->policy(false, true, true)->prospectiveStatusForEmailSignup());
        self::assertSame(UserStatus::Active, $this->policy(false, true, false)->prospectiveStatusForEmailSignup());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Auth/RegistrationPolicyTest.php`
Expected: FAIL — `RegistrationPolicy` not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Enum\UserStatus;
use App\Service\Mail\MailCapability;
use App\Service\Settings\InstanceSettings;

/**
 * The single source of truth for what a new registration becomes.
 *
 * Combines the deploy-time mail capability (#230) with the admin's runtime gate
 * toggles (#224). Registration, verification, the OAuth linker and the register
 * API response all read from here so the rules live in one place:
 *
 *  - mail off forces email confirmation off (nothing can deliver the link);
 *  - approval is independent of mail (an admin can still approve by hand).
 */
final readonly class RegistrationPolicy
{
    public function __construct(
        private MailCapability $mail,
        private InstanceSettings $settings,
    ) {
    }

    public function mailEnabled(): bool
    {
        return $this->mail->isEnabled();
    }

    public function emailConfirmationRequired(): bool
    {
        return $this->settings->requireEmailConfirmation() && $this->mailEnabled();
    }

    public function approvalRequired(): bool
    {
        return $this->settings->requireApproval();
    }

    /**
     * The status any new email/password signup would receive under the current
     * policy. Instance-wide and public — it depends on no address, which is what
     * lets the register endpoint return it without becoming an existence oracle.
     */
    public function prospectiveStatusForEmailSignup(): UserStatus
    {
        if ($this->emailConfirmationRequired()) {
            return UserStatus::PendingVerification;
        }

        if ($this->approvalRequired()) {
            return UserStatus::PendingApproval;
        }

        return UserStatus::Active;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Auth/RegistrationPolicyTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Auth/RegistrationPolicy.php backend/tests/Service/Auth/RegistrationPolicyTest.php
git commit -m "feat(#230,#224): RegistrationPolicy combines mail capability and gate toggles"
```

---

## Task 4: Config guard accepts a deliberately mailless instance

**Files:**
- Modify: `backend/src/EventListener/InsecureProductionConfigGuard.php`
- Test: `backend/tests/EventListener/InsecureProductionConfigGuardTest.php` (extend existing; if none, create)

**Interfaces:**
- Consumes: `MailCapability::isEnabled()` (Task 1).
- Behaviour: `null://null` is a problem **only** when mail is enabled. A real DSN is never a problem. The ALTCHA placeholder check is unchanged.

- [ ] **Step 1: Write/extend the failing test**

```php
public function testNullMailerIsAllowedWhenMailIsDeliberatelyDisabled(): void
{
    $guard = new InsecureProductionConfigGuard(
        'prod',
        'a-real-secret-key',
        InsecureProductionConfigGuard::NULL_MAILER_DSN,
        new MailCapability('1'),
    );

    self::assertSame([], $guard->problems());
}

public function testNullMailerStillFailsWhenMailIsNotDisabled(): void
{
    $guard = new InsecureProductionConfigGuard(
        'prod',
        'a-real-secret-key',
        InsecureProductionConfigGuard::NULL_MAILER_DSN,
        new MailCapability(''),
    );

    self::assertCount(1, $guard->problems());
}
```

(Add `use App\Service\Mail\MailCapability;`. Keep the existing ALTCHA-placeholder and real-DSN cases, updating their constructor calls to pass a `new MailCapability('')` fourth argument.)

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/EventListener/InsecureProductionConfigGuardTest.php`
Expected: FAIL — constructor arity / behaviour mismatch.

- [ ] **Step 3: Modify the guard**

Add the dependency and change the mailer branch. Replace the constructor and the mailer check:

```php
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment,
        #[Autowire('%env(ALTCHA_HMAC_KEY)%')]
        private string $altchaHmacKey,
        #[Autowire('%env(MAILER_DSN)%')]
        private string $mailerDsn,
        private MailCapability $mail,
    ) {
    }
```

In `problems()`, guard the mailer check with mail capability:

```php
        if (self::NULL_MAILER_DSN === $this->mailerDsn && $this->mail->isEnabled()) {
            $problems[] = 'Set MAILER_DSN to a real transport, or set MAIL_DISABLED=1 to run '
                . 'this instance without mail; it is still null://null with mail enabled, which '
                . 'discards every message and reports success, so verification and password-reset '
                . 'mail is silently lost and nothing logs an error.';
        }
```

Update the class docblock: add a paragraph recording that `null://null` is an accepted, deliberate state when `MAIL_DISABLED=1` (issue #230), while a bare `null://null` remains a forgotten-config failure. Add `use App\Service\Mail\MailCapability;`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/EventListener/InsecureProductionConfigGuardTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/EventListener/InsecureProductionConfigGuard.php backend/tests/EventListener/InsecureProductionConfigGuardTest.php
git commit -m "feat(#230): config guard accepts null mailer only when MAIL_DISABLED is set"
```

---

## Task 5: `AccountMailerInterface` + mail-gated decorator

**Files:**
- Create: `backend/src/Service/Mail/AccountMailerInterface.php`, `backend/src/Service/Mail/MailGatedAccountMailer.php`
- Modify: `backend/src/Service/Mail/AccountMailer.php` (implement the interface)
- Modify (typehints only): `backend/src/Service/Auth/RegistrationService.php`, `backend/src/Controller/Admin/AdminUserController.php`, `backend/src/EventListener/NotifyAdminsOfPendingApproval.php`
- Test: `backend/tests/Service/Mail/MailGatedAccountMailerTest.php`

**Interfaces:**
- Produces: `AccountMailerInterface` with `sendVerification(User, string): void`, `sendApproved(User): void`, `sendPasswordReset(User, string): void`, `sendPendingApprovalNotice(User, PendingApprovalNotice): void`. Consumers typehint the interface. When mail is disabled the decorator logs one line per skipped send and delegates nothing.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\User;
use App\Service\Mail\AccountMailerInterface;
use App\Service\Mail\MailCapability;
use App\Service\Mail\MailGatedAccountMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MailGatedAccountMailerTest extends TestCase
{
    public function testDelegatesWhenMailEnabled(): void
    {
        $inner = $this->createMock(AccountMailerInterface::class);
        $inner->expects(self::once())->method('sendApproved');

        $gated = new MailGatedAccountMailer($inner, new MailCapability(''), new NullLogger());
        $gated->sendApproved(new User('a@b.test', new \DateTimeImmutable()));
    }

    public function testSkipsAndDoesNotDelegateWhenMailDisabled(): void
    {
        $inner = $this->createMock(AccountMailerInterface::class);
        $inner->expects(self::never())->method('sendApproved');
        $inner->expects(self::never())->method('sendPasswordReset');
        $inner->expects(self::never())->method('sendPendingApprovalNotice');
        $inner->expects(self::never())->method('sendVerification');

        $gated = new MailGatedAccountMailer($inner, new MailCapability('1'), new NullLogger());
        $gated->sendApproved(new User('a@b.test', new \DateTimeImmutable()));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Mail/MailGatedAccountMailerTest.php`
Expected: FAIL — interface/decorator not found.

- [ ] **Step 3: Extract the interface**

Create `AccountMailerInterface` with the four signatures (copy from `AccountMailer`'s public methods, including the `PendingApprovalNotice` import):

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Dto\Mail\PendingApprovalNotice;
use App\Entity\User;

interface AccountMailerInterface
{
    public function sendVerification(User $user, string $plainToken): void;

    public function sendApproved(User $user): void;

    public function sendPasswordReset(User $user, string $plainToken): void;

    public function sendPendingApprovalNotice(User $admin, PendingApprovalNotice $notice): void;
}
```

(Confirm the real namespace of `PendingApprovalNotice` from `AccountMailer.php`'s `use` block and match it exactly.)

- [ ] **Step 4: Make `AccountMailer` implement it** — change the class line to `final readonly class AccountMailer implements AccountMailerInterface`. No method-body changes.

- [ ] **Step 5: Write the decorator**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Dto\Mail\PendingApprovalNotice;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * On a mailless instance (issue #230) every account mail is a no-op that leaves
 * a log line, instead of a send that silently succeeds into null://null. The
 * log line is what makes "no approval mail went out" visible to the operator
 * rather than a mystery. Decorates AccountMailer so no send site has to know
 * whether mail is on.
 */
#[AsDecorator(decorates: AccountMailer::class)]
final readonly class MailGatedAccountMailer implements AccountMailerInterface
{
    public function __construct(
        private AccountMailerInterface $inner,
        private MailCapability $mail,
        private LoggerInterface $logger,
    ) {
    }

    public function sendVerification(User $user, string $plainToken): void
    {
        $this->send('verification', $user, fn () => $this->inner->sendVerification($user, $plainToken));
    }

    public function sendApproved(User $user): void
    {
        $this->send('approved', $user, fn () => $this->inner->sendApproved($user));
    }

    public function sendPasswordReset(User $user, string $plainToken): void
    {
        $this->send('password reset', $user, fn () => $this->inner->sendPasswordReset($user, $plainToken));
    }

    public function sendPendingApprovalNotice(User $admin, PendingApprovalNotice $notice): void
    {
        $this->send('pending-approval notice', $admin, fn () => $this->inner->sendPendingApprovalNotice($admin, $notice));
    }

    private function send(string $kind, User $recipient, callable $deliver): void
    {
        if (!$this->mail->isEnabled()) {
            $this->logger->info('Mail disabled (MAIL_DISABLED); skipped {kind} mail to {email}.', [
                'kind' => $kind,
                'email' => $recipient->getEmail(),
            ]);

            return;
        }

        $deliver();
    }
}
```

- [ ] **Step 6: Retype the consumers** — change the constructor typehint from `AccountMailer` to `AccountMailerInterface` in `RegistrationService`, `AdminUserController`, and `NotifyAdminsOfPendingApproval` (update `use` statements). No logic changes. Symfony's `#[AsDecorator]` makes the interface resolve to the decorator automatically.

- [ ] **Step 7: Run the full mail + auth test slice**

Run: `cd backend && php bin/phpunit tests/Service/Mail tests/Service/Auth tests/EventListener`
Expected: PASS (existing tests keep working through the interface).

- [ ] **Step 8: Commit**

```bash
git add backend/src/Service/Mail/ backend/src/Service/Auth/RegistrationService.php backend/src/Controller/Admin/AdminUserController.php backend/src/EventListener/NotifyAdminsOfPendingApproval.php backend/tests/Service/Mail/MailGatedAccountMailerTest.php
git commit -m "feat(#230): mail-gated AccountMailer decorator logs instead of sending when mailless"
```

---

## Task 6: Policy-driven `register()` + register response + anti-enumeration test

**Files:**
- Modify: `backend/src/Service/Auth/RegistrationService.php`, `backend/src/Controller/Api/AuthController.php`
- Test: `backend/tests/Service/Auth/RegistrationServiceTest.php`, `backend/tests/Controller/Api/AuthControllerRegisterTest.php` (functional — extend existing register test if present)

**Interfaces:**
- Consumes: `RegistrationPolicy` (Task 3), `AccountMailerInterface` (Task 5).
- Produces: `register()` transitions the new user to `RegistrationPolicy::prospectiveStatusForEmailSignup()`, issuing a verification token/mail only for `PendingVerification`, dispatching `UserAwaitingApproval` only for `PendingApproval`, stamping `approvedAt` for `Active`. `AuthController::register()` returns `{status: <prospective status value>}`, identical for new and duplicate addresses.

- [ ] **Step 1: Write the failing service tests** (matrix over the three resulting statuses)

```php
public function testConfirmationOnLandsInPendingVerificationAndMails(): void
{
    // policy: confirm on, approval on (defaults). Assert user status ==
    // PendingVerification, a VerifyEmail token was issued, sendVerification called.
}

public function testConfirmationOffApprovalOnLandsInPendingApprovalAndDispatches(): void
{
    // Assert status == PendingApproval, NO verification token/mail,
    // UserAwaitingApproval dispatched, no sendVerification.
}

public function testBothGatesOffLandsActiveWithApprovedAtAndNoEventNoMail(): void
{
    // Assert status == Active, approvedAt stamped, no event, no mail.
}
```

Write these against the real service wired with an in-memory EM (follow the existing `RegistrationServiceTest` setup) and a spy `EventDispatcher` / mock `AccountMailerInterface`. Drive the policy by setting `InstanceSettings::update(...)` and constructing with a `MailCapability('')`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Auth/RegistrationServiceTest.php`
Expected: FAIL — current `register()` always uses `PendingVerification`.

- [ ] **Step 3: Rewrite `register()`** — inject `RegistrationPolicy $policy`; replace the body after the duplicate guard:

```php
        $now = $this->clock->now();
        $user = new User($email, $now);
        $user->setLocale(\in_array($locale, SupportedLocale::ALL, true) ? $locale : SupportedLocale::ENGLISH);
        $user->setPasswordHash($this->hasher->hashPassword($user, $plainPassword), $now);

        $status = $this->policy->prospectiveStatusForEmailSignup();
        $user->setStatus($status);
        if (UserStatus::Active === $status) {
            $user->setApprovedAt($now);
        }

        $this->em->persist($user);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent signup for the same address; the
            // winner has already done the post-flush work. Saying nothing keeps
            // this path's response identical to the duplicate path above. (See
            // the original comment for the full reasoning.)
            return;
        }

        $this->completeRegistration($user, $status);
    }

    /**
     * The one post-flush side effect that each resulting status implies. Active
     * needs none — the account can log in already.
     */
    private function completeRegistration(User $user, UserStatus $status): void
    {
        match ($status) {
            UserStatus::PendingVerification => $this->mailer->sendVerification(
                $user,
                $this->tokens->issue($user, TokenPurpose::VerifyEmail),
            ),
            UserStatus::PendingApproval => $this->events->dispatch(
                new UserAwaitingApproval($user, RegistrationMethod::EmailPassword),
            ),
            default => null,
        };
    }
```

Keep the duplicate-address guard (with `spendOneHash()`) exactly as it is. Update the `register()` docblock to note the status now follows `RegistrationPolicy`.

- [ ] **Step 4: Change the register response** — in `AuthController`, inject `RegistrationPolicy $policy` and return the prospective status:

```php
        $this->registration->register($request->email, $request->password, $request->locale);

        // The status a new signup receives under the current policy. Instance-
        // wide and identical for a duplicate address, so it never becomes an
        // existence oracle. 202: the account may still need verification or
        // approval before it can log in.
        return new JsonResponse(
            ['status' => $this->policy->prospectiveStatusForEmailSignup()->value],
            Response::HTTP_ACCEPTED,
        );
```

- [ ] **Step 5: Write the anti-enumeration functional test**

```php
public function testRegisterReturnsIdenticalResponseForNewAndDuplicateAddress(): void
{
    // POST /api/auth/register for a fresh address -> capture status code + body.
    // POST again for the SAME address -> assert byte-identical status code + body.
    // Repeat with InstanceSettings::update(false, false) to cover the Active path.
}
```

Follow the existing register functional test for ALTCHA solving and rate-limit handling.

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Auth/RegistrationServiceTest.php tests/Controller/Api/AuthControllerRegisterTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Auth/RegistrationService.php backend/src/Controller/Api/AuthController.php backend/tests/Service/Auth/RegistrationServiceTest.php backend/tests/Controller/Api/AuthControllerRegisterTest.php
git commit -m "feat(#224): registration follows policy; register response reports the real prospective status"
```

---

## Task 7: `verifyEmail()` honours the approval toggle

**Files:**
- Modify: `backend/src/Service/Auth/RegistrationService.php`
- Test: `backend/tests/Service/Auth/RegistrationServiceTest.php`

**Interfaces:**
- Behaviour: when `approvalRequired()` is off, `verifyEmail()` transitions `PendingVerification → Active` (stamps `approvedAt`) and does **not** dispatch `UserAwaitingApproval`. When on, unchanged (`→ PendingApproval` + dispatch).

- [ ] **Step 1: Write the failing tests**

```php
public function testVerifyEmailWithApprovalOnQueuesForApprovalAndDispatches(): void { /* existing behaviour */ }

public function testVerifyEmailWithApprovalOffActivatesDirectlyWithoutEvent(): void
{
    // approval off: consume a VerifyEmail token for a PendingVerification user.
    // Assert status == Active, approvedAt stamped, NO UserAwaitingApproval.
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Auth/RegistrationServiceTest.php --filter VerifyEmail`
Expected: FAIL — approval-off path activates today’s code sends to PendingApproval.

- [ ] **Step 3: Modify `verifyEmail()`** — replace the promotion block:

```php
        if (UserStatus::PendingVerification === $user->getStatus()) {
            if ($this->policy->approvalRequired()) {
                $user->setStatus(UserStatus::PendingApproval);
                $this->em->flush();
                $this->events->dispatch(new UserAwaitingApproval($user, RegistrationMethod::EmailPassword));
            } else {
                $user->setStatus(UserStatus::Active);
                $user->setApprovedAt($this->clock->now());
                $this->em->flush();
            }
        }

        return $user->getStatus();
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Auth/RegistrationServiceTest.php --filter VerifyEmail`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Auth/RegistrationService.php backend/tests/Service/Auth/RegistrationServiceTest.php
git commit -m "feat(#224): verifyEmail activates directly when admin approval is disabled"
```

---

## Task 8: OAuth linker honours the approval toggle

**Files:**
- Modify: `backend/src/Service/OAuth/OAuthAccountLinker.php`
- Test: `backend/tests/Service/OAuth/OAuthAccountLinkerTest.php`

**Interfaces:**
- Consumes: `RegistrationPolicy::approvalRequired()`.
- Behaviour: approval off → new/claimed OAuth accounts become `Active` (+ `approvedAt`) and dispatch **no** `UserAwaitingApproval`; approval on → unchanged. Email-confirmation toggle never affects OAuth.

- [ ] **Step 1: Write the failing tests**

```php
public function testNewOAuthUserWithApprovalOnEntersQueueAndDispatches(): void { /* existing */ }

public function testNewOAuthUserWithApprovalOffIsActiveWithNoEvent(): void
{
    // approval off: resolve a brand-new identity -> user Active, approvedAt set,
    // no UserAwaitingApproval dispatched.
}

public function testClaimUnverifiedWithApprovalOffActivatesAndWipesPasswordNoEvent(): void
{
    // A PendingVerification local account claimed by a provider-verified identity,
    // approval off -> Active + approvedAt, password wiped, no event.
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/OAuth/OAuthAccountLinkerTest.php`
Expected: FAIL.

- [ ] **Step 3: Modify the linker** — inject `RegistrationPolicy $policy`. In `resolve()`, the dispatch already keys off `$enteredApprovalQueue`; make that flag mean "a queue was actually entered":

```php
        if (null === $linkTarget) {
            $user = $this->createUser($identity);
            $enteredApprovalQueue = $this->policy->approvalRequired();
        } else {
            $user = $linkTarget;
            $enteredApprovalQueue = $this->claimIfUnverified($linkTarget);
        }
```

`createUser()`:

```php
    private function createUser(OAuthIdentity $identity): User
    {
        $now = $this->clock->now();
        $user = new User($this->loginIdentifierFor($identity), $now);

        if ($this->policy->approvalRequired()) {
            $user->setStatus(UserStatus::PendingApproval);
        } else {
            $user->setStatus(UserStatus::Active);
            $user->setApprovedAt($now);
        }

        $this->em->persist($user);

        return $user;
    }
```

`claimIfUnverified()` — return whether a queue was entered (so `false` when approval off, matching "no dispatch"):

```php
    private function claimIfUnverified(User $user): bool
    {
        if (UserStatus::PendingVerification !== $user->getStatus()) {
            return false;
        }

        $now = $this->clock->now();
        if ($this->policy->approvalRequired()) {
            $user->setStatus(UserStatus::PendingApproval);
        } else {
            $user->setStatus(UserStatus::Active);
            $user->setApprovedAt($now);
        }
        $user->setPasswordHash(null, $now);

        return $this->policy->approvalRequired();
    }
```

Update the method docblocks to note the approval-off path (Active, no event). Keep the security reasoning about the password wipe intact.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/OAuth/OAuthAccountLinkerTest.php`
Expected: PASS. Also run `tests/Service/OAuth/OidcBoundaryTest.php` to confirm the OIDC boundary is untouched.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/OAuth/OAuthAccountLinker.php backend/tests/Service/OAuth/OAuthAccountLinkerTest.php
git commit -m "feat(#224): OAuth linker activates directly when admin approval is disabled"
```

---

## Task 9: Admin settings endpoint (`GET`/`PUT /api/admin/settings`)

**Files:**
- Create: `backend/src/Dto/Admin/InstanceSettingsRequest.php`, `backend/src/Http/Admin/InstanceSettingsJson.php`, `backend/src/Controller/Admin/AdminSettingsController.php`
- Test: `backend/tests/Controller/Admin/AdminSettingsControllerTest.php`

**Interfaces:**
- Consumes: `InstanceSettings` (Task 2).
- Produces: `GET /api/admin/settings` → `{requireEmailConfirmation: bool, requireApproval: bool, mailEnabled: bool}`; `PUT /api/admin/settings` with `{requireEmailConfirmation, requireApproval}` → the same shape. ROLE_ADMIN, JSON, `application/problem+json` on error.

- [ ] **Step 1: Confirm the access rule** — `/api/admin/*` is already restricted to `ROLE_ADMIN` in `backend/config/packages/security.yaml`. Read it and confirm the new path is covered by the existing `/api/admin` access_control entry (it is, by prefix). No change needed; note it in the test.

- [ ] **Step 2: Write the failing functional test**

```php
public function testGetReturnsCurrentSettingsForAnAdmin(): void
{
    // Authenticate as an admin (existing helper), GET /api/admin/settings,
    // assert 200 and the three boolean keys, defaulting true/true.
}

public function testPutUpdatesTheToggles(): void
{
    // PUT {requireEmailConfirmation:false, requireApproval:true}, assert 200 and
    // the echoed values; GET again and assert they persisted.
}

public function testPutRejectsANonBooleanPayload(): void
{
    // PUT {requireEmailConfirmation:"nope"} -> 422 application/problem+json.
}

public function testNonAdminIsForbidden(): void
{
    // Authenticate as a normal active user, GET -> 403.
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Controller/Admin/AdminSettingsControllerTest.php`
Expected: FAIL — controller not found.

- [ ] **Step 4: Create the request DTO**

```php
<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class InstanceSettingsRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Type('bool')]
        public bool $requireEmailConfirmation = true,
        #[Assert\NotNull]
        #[Assert\Type('bool')]
        public bool $requireApproval = true,
    ) {
    }
}
```

- [ ] **Step 5: Create the response mapper**

```php
<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Service\Auth\RegistrationPolicy;

/**
 * The admin settings payload. mailEnabled is read-only here — it reflects the
 * deploy-time MAIL_DISABLED flag, not a toggle the admin can flip — but the UI
 * needs it to explain why the email-confirmation switch is disabled.
 */
final readonly class InstanceSettingsJson
{
    /**
     * @return array{requireEmailConfirmation: bool, requireApproval: bool, mailEnabled: bool}
     */
    public static function from(RegistrationPolicy $policy): array
    {
        return [
            // The stored toggle, not the effective value: the admin sees what
            // they set, and mailEnabled explains any divergence.
            'requireEmailConfirmation' => $policy->storedEmailConfirmationRequired(),
            'requireApproval' => $policy->approvalRequired(),
            'mailEnabled' => $policy->mailEnabled(),
        ];
    }
}
```

Add `RegistrationPolicy::storedEmailConfirmationRequired(): bool` returning the raw store value (`$this->settings->requireEmailConfirmation()`), so the admin UI shows the stored toggle rather than the mail-forced effective value. Add a matching one-line test in `RegistrationPolicyTest`.

- [ ] **Step 6: Create the controller** (thin: read, delegate, return)

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\InstanceSettingsRequest;
use App\Http\Admin\InstanceSettingsJson;
use App\Service\Auth\RegistrationPolicy;
use App\Service\Settings\InstanceSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/settings')]
final readonly class AdminSettingsController
{
    public function __construct(
        private InstanceSettings $settings,
        private RegistrationPolicy $policy,
    ) {
    }

    #[Route('', name: 'api_admin_settings_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse(InstanceSettingsJson::from($this->policy));
    }

    #[Route('', name: 'api_admin_settings_update', methods: ['PUT'])]
    public function update(#[MapRequestPayload] InstanceSettingsRequest $request): JsonResponse
    {
        $this->settings->update($request->requireEmailConfirmation, $request->requireApproval);

        return new JsonResponse(InstanceSettingsJson::from($this->policy));
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Controller/Admin/AdminSettingsControllerTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Dto/Admin/InstanceSettingsRequest.php backend/src/Http/Admin/InstanceSettingsJson.php backend/src/Controller/Admin/AdminSettingsController.php backend/src/Service/Auth/RegistrationPolicy.php backend/tests/Controller/Admin/AdminSettingsControllerTest.php backend/tests/Service/Auth/RegistrationPolicyTest.php
git commit -m "feat(#224): admin settings endpoint for the registration-gate toggles"
```

---

## Task 10: Extend `GET /api/setup/status` with `mailEnabled`

**Files:**
- Modify: `backend/src/Controller/Api/SetupController.php`
- Test: `backend/tests/Controller/Api/SetupControllerTest.php`

**Interfaces:**
- Produces: `GET /api/setup/status` → `{needsSetup: bool, mailEnabled: bool}`. Stays public (no auth), native-safe.

- [ ] **Step 1: Write the failing test**

```php
public function testStatusReportsMailEnabled(): void
{
    // GET /api/setup/status -> 200, body has needsSetup (bool) and mailEnabled (bool).
    // With MAIL_DISABLED unset in test env, mailEnabled === true.
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Controller/Api/SetupControllerTest.php --filter MailEnabled`
Expected: FAIL — key absent.

- [ ] **Step 3: Modify the controller** — inject `RegistrationPolicy $policy`; extend the status body:

```php
    public function status(): JsonResponse
    {
        return new JsonResponse([
            'needsSetup' => !$this->users->hasAnyAdmin(),
            'mailEnabled' => $this->policy->mailEnabled(),
        ]);
    }
```

Add the constructor dependency and `use App\Service\Auth\RegistrationPolicy;`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Controller/Api/SetupControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Controller/Api/SetupController.php backend/tests/Controller/Api/SetupControllerTest.php
git commit -m "feat(#230): expose mailEnabled on the public setup-status endpoint"
```

---

## Task 11: Password-reset service + `app:user:reset-password` command

**Files:**
- Create: `backend/src/Service/Auth/PasswordResetter.php`, `backend/src/Command/ResetUserPasswordCommand.php`
- Test: `backend/tests/Service/Auth/PasswordResetterTest.php`, `backend/tests/Command/ResetUserPasswordCommandTest.php`

**Interfaces:**
- Produces:
  - `PasswordResetter::setPassword(User $user, string $plainPassword): void` — hashes, sets, stamps `passwordChangedAt` (via `User::setPasswordHash(hash, now)`), flushes.
  - `PasswordResetter::generateAndSet(User $user): string` — sets a fresh random password and returns the plaintext once.
  - Command `app:user:reset-password <email>`: prompts for a password (hidden) or `--generate` to print a random one; refuses an unknown email.

- [ ] **Step 1: Write the failing service test**

```php
public function testSetPasswordStampsPasswordChangedAtAndAuthenticates(): void
{
    // Create a user; call setPassword($user, 'a-strong-passphrase');
    // Assert the hash verifies and passwordChangedAt advanced (invalidates old JWTs).
}

public function testGenerateAndSetReturnsAUsablePlaintext(): void
{
    // generateAndSet -> returned string length >= 16, and verifies against the hash.
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Auth/PasswordResetterTest.php`
Expected: FAIL — service not found.

- [ ] **Step 3: Write the service**

```php
<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Operator-driven password reset for a mailless instance (issue #230), where the
 * self-service email flow cannot run. Shared by the CLI command and the admin
 * endpoint. Stamping passwordChangedAt evicts every JWT issued before the reset
 * (see PasswordChangeTokenInvalidator), so a leaked session dies here too.
 */
final readonly class PasswordResetter
{
    private const int GENERATED_LENGTH = 24;

    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private ClockInterface $clock,
    ) {
    }

    public function setPassword(User $user, string $plainPassword): void
    {
        $user->setPasswordHash($this->hasher->hashPassword($user, $plainPassword), $this->clock->now());
        $this->em->flush();
    }

    /**
     * Sets a fresh random password and returns it once. url-safe base64 so the
     * operator can relay it without escaping surprises.
     */
    public function generateAndSet(User $user): string
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(self::GENERATED_LENGTH)), '+/', '-_'), '=');
        $this->setPassword($user, $plain);

        return $plain;
    }
}
```

- [ ] **Step 4: Run service test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Auth/PasswordResetterTest.php`
Expected: PASS.

- [ ] **Step 5: Write the failing command test**

```php
public function testGenerateResetsAnExistingUserAndPrintsThePassword(): void
{
    // Seed a user; run the command with email + --generate; assert exit 0 and the
    // output contains a password line; assert the new hash verifies.
}

public function testUnknownEmailFails(): void
{
    // Run with a non-existent email; assert Command::FAILURE and an error message.
}
```

- [ ] **Step 6: Run command test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Command/ResetUserPasswordCommandTest.php`
Expected: FAIL — command not found.

- [ ] **Step 7: Write the command** (mirror `CreateAdminCommand`: password never a CLI argument)

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\Auth\PasswordResetter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Resets one user's password from a shell. The supported recovery path on a
 * mailless instance (issue #230), where the email reset flow cannot deliver.
 * With --generate the command mints a random password and prints it once, for
 * the operator to relay out of band; otherwise it reads one from a hidden
 * prompt. Never takes the password as an argument — that leaks into shell
 * history and the process list.
 */
#[AsCommand(
    name: 'app:user:reset-password',
    description: 'Reset a user password from the shell (mailless-instance recovery).',
)]
final class ResetUserPasswordCommand extends Command
{
    private const int MINIMUM_PASSWORD_LENGTH = 12;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetter $resetter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The account email')
            ->addOption('generate', null, InputOption::VALUE_NONE, 'Generate a random password and print it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');
        $user = $this->users->findOneByEmail($email);
        if (null === $user) {
            $io->error(\sprintf('No account with email %s.', $email));

            return Command::FAILURE;
        }

        if (true === $input->getOption('generate')) {
            $generated = $this->resetter->generateAndSet($user);
            $io->success(\sprintf('Password reset for %s.', $user->getEmail()));
            $io->writeln(\sprintf('New password: %s', $generated));
            $io->note('Relay this to the user over a trusted channel. It is shown only once.');

            return Command::SUCCESS;
        }

        $answer = $io->askHidden('New password (min 12 characters)');
        $password = \is_string($answer) ? $answer : '';
        if (mb_strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            $io->error('The password must be at least 12 characters.');

            return Command::INVALID;
        }

        $this->resetter->setPassword($user, $password);
        $io->success(\sprintf('Password reset for %s.', $user->getEmail()));

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 8: Run both tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Auth/PasswordResetterTest.php tests/Command/ResetUserPasswordCommandTest.php`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add backend/src/Service/Auth/PasswordResetter.php backend/src/Command/ResetUserPasswordCommand.php backend/tests/Service/Auth/PasswordResetterTest.php backend/tests/Command/ResetUserPasswordCommandTest.php
git commit -m "feat(#230): operator password-reset service and app:user:reset-password command"
```

---

## Task 12: Admin reset-password endpoint (generated once)

**Files:**
- Modify: `backend/src/Controller/Admin/AdminUserController.php`
- Test: `backend/tests/Controller/Admin/AdminUserControllerTest.php`

**Interfaces:**
- Consumes: `PasswordResetter::generateAndSet()` (Task 11), the existing `SelfActionGuard`.
- Produces: `POST /api/admin/users/{id}/reset-password` → `{password: <generated plaintext>}`, 200. ROLE_ADMIN. The generated secret is returned exactly once for the admin to relay.

- [ ] **Step 1: Write the failing test**

```php
public function testResetPasswordReturnsAFreshGeneratedSecretOnce(): void
{
    // As admin, POST /api/admin/users/{targetId}/reset-password.
    // Assert 200, body has a non-empty 'password'; the target's new hash verifies
    // against it; passwordChangedAt advanced.
}

public function testResetPasswordIsAdminOnly(): void
{
    // As a normal user -> 403.
}
```

(Decide whether an admin may reset their own password here; the existing `SelfActionGuard` blocks self-approve/suspend. Reusing it for reset is optional — resetting one's own password by generating a new one is harmless. Follow the existing pattern: if `SelfActionGuard` reads naturally as "no self target", allow self-reset by NOT calling it here; document the choice in a one-line comment.)

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Controller/Admin/AdminUserControllerTest.php --filter ResetPassword`
Expected: FAIL — route not found.

- [ ] **Step 3: Add the action** — inject `PasswordResetter $passwordResetter` into `AdminUserController` and add:

```php
    #[Route('/{id}/reset-password', name: 'api_admin_users_reset_password', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resetPassword(int $id): JsonResponse
    {
        $user = $this->users->getById($id);

        // Returned once, in the response body only, for the admin to relay out of
        // band. The supported recovery path when the instance sends no mail.
        return new JsonResponse(['password' => $this->passwordResetter->generateAndSet($user)]);
    }
```

(Keep the controller thin: one repository read + one service call + response. No logic.)

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Controller/Admin/AdminUserControllerTest.php --filter ResetPassword`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Controller/Admin/AdminUserController.php backend/tests/Controller/Admin/AdminUserControllerTest.php
git commit -m "feat(#230): admin reset-password endpoint returns a generated secret once"
```

---

## Task 13: Compose, installer choice, `.env.prod.example`

**Files:**
- Modify: `docker-compose.prod.yml`, `.env.prod.example`, `scripts/lib.sh`
- Test: manual, plus `composer e2e` guard behaviour is exercised in Task 4's unit test.

- [ ] **Step 1: Add `MAIL_DISABLED` to the compose `php` environment**

In `docker-compose.prod.yml`, under `services.php.environment`, after the `MAILER_DSN` line, add:

```yaml
      # Deliberate "no mail" mode (#230). When 1, InsecureProductionConfigGuard
      # accepts MAILER_DSN=null://null instead of 500ing. MAILER_DSN stays
      # required above, so a FORGOTTEN mailer still fails compose at start; the
      # installer's "No mail" choice writes both MAIL_DISABLED=1 and
      # MAILER_DSN=null://null together.
      MAIL_DISABLED: ${MAIL_DISABLED:-}
```

- [ ] **Step 2: Document the flag in `.env.prod.example`**

Next to `MAILER_DSN`, add a block:

```dotenv
# --- No-mail mode (optional) ---------------------------------------------
# Set MAIL_DISABLED=1 to run a private instance with NO outgoing mail. Then set
# MAILER_DSN=null://null (both are required together). Consequences:
#   - email confirmation is forced off; new users skip the verification step;
#   - password reset by email is unavailable — use `app:user:reset-password`
#     or the admin "reset password" button;
#   - an account can go active with an UNVERIFIED address (typo/squatting risk).
# Leave MAIL_DISABLED empty and set a real MAILER_DSN for a normal instance.
MAIL_DISABLED=
```

- [ ] **Step 3: Add the "No mail" installer choice** — in `scripts/lib.sh` `configure_mail()`, add option 4 to the menu and case:

Menu (after option 3):

```sh
  printf '  4) No mail: run without outgoing mail (registration/reset email off)\n' >/dev/tty
```

Case (before the `*)` fallthrough):

```sh
    4)
      env_prod_set MAIL_DISABLED 1
      env_prod_set MAILER_DSN 'null://null'
      say 'Running without mail. Email confirmation and password-reset email are off.'
      say 'Recover a password with: docker compose ... exec php bin/console app:user:reset-password <email> --generate'
      ;;
```

The other branches must clear the flag so switching back to a real transport re-enables mail. At the top of each of the `1)` and `2)` branches (and where the current transport is kept), ensure `MAIL_DISABLED` is unset:

```sh
      env_prod_set MAIL_DISABLED ''
```

(Place it once at the start of branches 1 and 2, right before setting `MAILER_DSN`.)

- [ ] **Step 4: Confirm the required-vars check still passes for the no-mail path** — `ENV_PROD_REQUIRED` includes `MAILER_DSN`; the "No mail" branch sets it to `null://null`, so the presence check is satisfied. Read the validation that consumes `ENV_PROD_REQUIRED` and confirm it only checks presence, not a real-transport shape. If it rejects `null://null`, add an exception for the `MAIL_DISABLED=1` case. Note the finding in the commit message.

- [ ] **Step 5: Shellcheck the script**

Run: `shellcheck scripts/lib.sh`
Expected: no findings (CI fails on any severity, including info-level SC2015 — use guard clauses, not `A && B || C`).

- [ ] **Step 6: Commit**

```bash
git add docker-compose.prod.yml .env.prod.example scripts/lib.sh
git commit -m "feat(#230): installer/compose/env support for a deliberately mailless instance"
```

---

## Task 14: Docs — mailless section

**Files:**
- Modify: `docs/docker-production.md`, `docs/first-run-setup.md`

- [ ] **Step 1: Add a "Running without mail" section to `docs/docker-production.md`** covering: what `MAIL_DISABLED=1` does; that it must be paired with `MAILER_DSN=null://null`; that a forgotten mailer still fails loud; the consequences (no email confirmation, no email password reset, unverified-address risk); and the recovery commands (`app:user:reset-password --generate`, the admin reset button).

- [ ] **Step 2: Add a matching note to `docs/first-run-setup.md`** explaining the installer's "No mail" choice and the two admin registration-gate toggles (where to find them in Settings, what each does, defaults on/on).

- [ ] **Step 3: Commit**

```bash
git add docs/docker-production.md docs/first-run-setup.md
git commit -m "docs(#230,#224): document mailless mode and the registration-gate toggles"
```

---

## Task 15: Frontend — capability plumbing, login link, register message

**Files:**
- Modify: `frontend/src/app/setup/setup-api.ts`, `frontend/src/app/setup/setup.service.ts`
- Modify: `frontend/src/app/auth/login/login.component.ts` + `login.component.html`
- Modify: `frontend/src/app/auth/register/register.component.ts` + `register.component.html`
- Test: `frontend/src/app/setup/setup.service.spec.ts`, `login.component.spec.ts`, `register.component.spec.ts`

**Interfaces:**
- Consumes: `GET /api/setup/status` → `{needsSetup, mailEnabled}` (Task 10).
- Produces: `SetupService.mailEnabled` signal (`boolean | null`, null = unknown); login hides the forgot-password link when `mailEnabled() === false`; register shows a message keyed to the returned `status`.

- [ ] **Step 1: Update `SetupApi.status()` return type**

```ts
  status(): Observable<{ needsSetup: boolean; mailEnabled: boolean }> {
    return this.http.get<{ needsSetup: boolean; mailEnabled: boolean }>(`${this.base}/api/setup/status`);
  }
```

- [ ] **Step 2: Write the failing `SetupService` test** — asserting `mailEnabled` signal is set from the response.

```ts
it('exposes mailEnabled from the status response', (done) => {
  // mock SetupApi.status() -> of({ needsSetup: false, mailEnabled: false })
  service.ensureLoaded().subscribe(() => {
    expect(service.mailEnabled()).toBe(false);
    done();
  });
});
```

- [ ] **Step 3: Extend `SetupService`** — add the signal and set it in the `tap`:

```ts
  readonly mailEnabled = signal<boolean | null>(null);

  ensureLoaded(): Observable<boolean> {
    if (this.cached !== null) return of(this.cached);
    return this.api.status().pipe(
      tap((r) => {
        this.cached = r.needsSetup;
        this.needsSetup.set(r.needsSetup);
        this.mailEnabled.set(r.mailEnabled);
      }),
      map((r) => r.needsSetup),
    );
  }
```

- [ ] **Step 4: Run the `SetupService` test**

Run: `cd frontend && npx jest src/app/setup/setup.service.spec.ts`
Expected: PASS.

- [ ] **Step 5: Hide the forgot-password link when mailless** — in `login.component.ts` inject `SetupService` and expose `mailEnabled`:

```ts
  private readonly setup = inject(SetupService);
  readonly mailEnabled = this.setup.mailEnabled;
```

In `login.component.html`, wrap the reset link (show unless explicitly disabled, so it stays visible while unknown/true):

```html
@if (mailEnabled() !== false) {
  <a routerLink="/reset-password-request">{{ 'auth.login.forgotPassword' | transloco }}</a>
}
```

Add a login spec: when `SetupService.mailEnabled` is `false`, the reset link is absent; when `true`, present.

- [ ] **Step 6: Register message per status** — in `register.component.ts`, capture the response status instead of a bare boolean:

```ts
  readonly resultStatus = signal<string | null>(null);
```

Replace the POST + `done` handling:

```ts
      const response = await firstValueFrom(
        this.http.post<{ status: string }>(`${this.base}/api/auth/register`, {
          email,
          password,
          altcha: solution,
          locale: this.i18n.getActiveLang(),
        }),
      );
      this.resultStatus.set(response.status);
```

Keep `done` as a derived flag if the template uses it, or replace `@if (done())` with `@if (resultStatus())`. In `register.component.html`, branch the success panel on the status value:

```html
@switch (resultStatus()) {
  @case ('pending_verification') { <p>{{ 'auth.register.checkEmail' | transloco }}</p> }
  @case ('pending_approval') { <p>{{ 'auth.register.awaitingApproval' | transloco }}</p> }
  @case ('active') { <p>{{ 'auth.register.activeNow' | transloco }}</p> }
}
```

Add the three i18n keys (`checkEmail` is the existing message; add `awaitingApproval`, `activeNow`) to every locale file under `frontend/src/assets/i18n/` (or wherever Transloco loads them — check the existing `auth.register.*` keys and mirror all languages). Add a register spec: response `{status:'active'}` shows the active message, `{status:'pending_verification'}` shows check-email.

- [ ] **Step 7: Run the frontend gate**

Run: `cd frontend && npm run check`
Expected: PASS (Node 22). Fix any Prettier/Stylelint findings.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/setup frontend/src/app/auth/login frontend/src/app/auth/register frontend/src/assets/i18n
git commit -m "feat(#230): SPA learns mail capability; hides forgot-password and reports real register status"
```

---

## Task 16: Frontend — admin settings section with the two toggles

**Files:**
- Create: `frontend/src/app/settings/admin/admin-settings/admin-settings.component.ts` + `.html` + `.scss`, `frontend/src/app/settings/admin/admin-settings/admin-settings-api.ts`
- Modify: `frontend/src/app/settings/settings-sections.ts`, `frontend/src/app/settings/settings.routes.ts`
- Test: `admin-settings.component.spec.ts`

**Interfaces:**
- Consumes: `GET`/`PUT /api/admin/settings` (Task 9), `SetupService.mailEnabled` (Task 15).
- Produces: an admin settings section at `settings/admin/settings` with two toggles; the email-confirmation toggle is disabled with an explanation when `mailEnabled() === false`; the mailless state is surfaced.

- [ ] **Step 1: Add the section entry** — in `settings-sections.ts`, add to the `admin` group:

```ts
  { path: 'admin/settings', icon: 'toggle_on', labelKey: 'settings.instance.title', group: 'admin' },
```

- [ ] **Step 2: Add the route** — in `settings.routes.ts`, add a child route mirroring the existing admin routes (lazy `loadComponent` to `AdminSettingsComponent`). Follow the exact pattern of the `admin/users` route already there.

- [ ] **Step 3: Write the API service** (`admin-settings-api.ts`)

```ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';

export interface InstanceSettings {
  requireEmailConfirmation: boolean;
  requireApproval: boolean;
  mailEnabled: boolean;
}

@Injectable({ providedIn: 'root' })
export class AdminSettingsApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  get(): Observable<InstanceSettings> {
    return this.http.get<InstanceSettings>(`${this.base}/api/admin/settings`);
  }

  update(requireEmailConfirmation: boolean, requireApproval: boolean): Observable<InstanceSettings> {
    return this.http.put<InstanceSettings>(`${this.base}/api/admin/settings`, {
      requireEmailConfirmation,
      requireApproval,
    });
  }
}
```

- [ ] **Step 4: Write the failing component spec** — load returns `{requireEmailConfirmation:true, requireApproval:true, mailEnabled:false}`; assert the email-confirmation control renders disabled and an explanation is shown; toggling approval calls `update`.

- [ ] **Step 5: Write the component** — standalone, signals; load on init, bind two toggles, save on change (optimistic or explicit save button — follow whatever the existing admin components do). Disable the email-confirmation toggle when `mailEnabled() === false` and render an explanatory line (use the existing shared toggle/switch component if `docs/design-language.md` lists one; otherwise a labelled checkbox consistent with the settings area). Styles go in the sibling `.scss` (no hex, no raw px — use theme tokens).

- [ ] **Step 6: Add i18n keys** — `settings.instance.title`, and labels/help for both toggles and the mailless explanation, across all locale files.

- [ ] **Step 7: Run the frontend gate**

Run: `cd frontend && npm run check`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/settings
git commit -m "feat(#224): admin settings section with registration-gate toggles"
```

---

## Task 17: Frontend — reset-request graceful when mailless

**Files:**
- Modify: `frontend/src/app/auth/reset-request/reset-request.component.ts` + `.html`
- Test: `reset-request.component.spec.ts`

**Interfaces:**
- Consumes: `SetupService.mailEnabled`.
- Behaviour: when `mailEnabled() === false`, the reset-request page shows an "unavailable — ask your administrator" message instead of the form (defense-in-depth; the link is already hidden in Task 15).

- [ ] **Step 1: Write the failing spec** — with `mailEnabled` false, the email input is absent and an "unavailable" message is present.

- [ ] **Step 2: Implement** — inject `SetupService`, expose `mailEnabled`; in the template wrap the form in `@if (mailEnabled() !== false) { … } @else { <p>{{ 'auth.reset.unavailable' | transloco }}</p> }`. Add the i18n key across locales.

- [ ] **Step 3: Run the frontend gate**

Run: `cd frontend && npm run check`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/app/auth/reset-request frontend/src/assets/i18n
git commit -m "feat(#230): reset-request page shows an unavailable state on a mailless instance"
```

---

## Final verification (before the PR)

- [ ] **Backend, both legs.** `cd backend && composer check && composer md && php bin/phpunit`, then `docker compose up -d && docker compose exec php vendor/bin/phpunit`. Both green. Scan `backend/var/log/dev.log` for deprecations/errors after the run.
- [ ] **Migration leg.** `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction` from empty on both SQLite (native) and MySQL (Docker), then `doctrine:schema:validate` — in sync.
- [ ] **Frontend gate.** `cd frontend && npm run check` under Node 22.
- [ ] **Manual smoke — mailless.** Bring up the prod stack with `MAIL_DISABLED=1` + `MAILER_DSN=null://null`; confirm it serves (no guard 500), `/api/setup/status` reports `mailEnabled:false`, register lands active/pending-approval per toggles with a log line and no send, forgot-password link is hidden, and `app:user:reset-password --generate` works.
- [ ] **Manual smoke — forgotten mailer.** Unset `MAILER_DSN` entirely → compose refuses to start. Set `MAILER_DSN=null://null` WITHOUT `MAIL_DISABLED` → guard 500s. (Both must still fail loud.)
- [ ] **Native-iOS checklist.** Run the `docs/architecture.md` §6 checklist against the new `/api/admin/settings` and `/api/admin/users/{id}/reset-password` endpoints and the extended `/api/setup/status`: bearer/stateless, JSON in / `problem+json` out, no browser-only inputs. OIDC boundary untouched (`OidcBoundaryTest` green).
- [ ] **Open the PR** referencing and closing both issues: `Closes #230` and `Closes #224`. Manually close both on merge if `develop` is not wired to auto-close.

---

## Self-review notes (author)

- **Spec coverage:** mail flag (T1,4,13), settings store (T2,9), policy (T3), register/verify/OAuth gating (T6,7,8), mail-send log-line (T5), password reset CLI+admin (T11,12), capability discovery (T10,15), frontend hide/adjust + admin section (T15,16,17), docs (T14). Anti-enumeration invariant tested (T6). All acceptance criteria of #230 and the table in #224 are covered.
- **Type consistency:** `RegistrationPolicy` method names (`mailEnabled`, `emailConfirmationRequired`, `approvalRequired`, `prospectiveStatusForEmailSignup`, `storedEmailConfirmationRequired`) are used identically across T3, T6–T10. `AccountMailerInterface` method set matches `AccountMailer`'s public methods. `InstanceSettings` (`requireEmailConfirmation`/`requireApproval`/`update`) consistent across T2, T3, T9.
- **Confirm-before-build items resolved:** typed single-row entity (T2); admin reset returns a generated secret once (T12).
