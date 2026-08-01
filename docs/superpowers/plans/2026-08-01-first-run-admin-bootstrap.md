# First-run admin bootstrap — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give a fresh install a documented, hijack-resistant way to create the first admin — a prod-safe CLI command for shell operators, and an `ADMIN_SETUP_SECRET`-gated web wizard for no-shell Docker hosts — while the SPA hides login/registration until an admin exists and never auto-promotes a registrant.

**Architecture:** Both bootstrap paths funnel through one `BootstrapAdminProvisioner` service that creates the `Active` + `ROLE_ADMIN` + `approvedAt` account. A single `UserRepository::hasAnyAdmin()` invariant gates every path: the CLI command refuses (unless `--force`), and the web endpoint self-disables (404) the instant an admin exists. The web path additionally requires an operator-set environment secret compared with `hash_equals` and is rate-limited. A public `GET /api/setup/status` drives the SPA, which routes to a setup screen and hides login/register while `needsSetup` is true.

**Tech Stack:** Symfony 7.4 (PHP 8.4), Doctrine, Lexik JWT, Symfony RateLimiter, Angular 20 (standalone components + signals), Transloco i18n, Jest.

## Global Constraints

- `declare(strict_types=1);` in every PHP file; PSR-12 (`composer cs`).
- PHPStan level max and PHPMD codesize clean on **every touched `src` file** — not merely free of new findings (`composer stan`, `composer md`). Warm the cache first: `bin/console cache:warmup`.
- `ThinControllerRule`: controllers read the request, delegate, return a response. No private methods carrying responsibility; query/security/mutation logic lives in a service or repository.
- No boolean flag parameters; guard clauses over nesting; `final readonly` with constructor promotion; depend on injected interfaces; errors are typed exceptions extending `App\Exception\ApiException`.
- Datetimes come from an injected `ClockInterface`, never `new \DateTimeImmutable()`.
- Native-iOS contract: JSON in, `application/problem+json` out, bearer token, stateless, no CSRF, no `text/html` fallback.
- Frontend: standalone components + signals; component styles in a sibling `.scss` via `styleUrl` (never inline); **no hex colours or raw `px`/media-query literals in `.scss` outside `src/app/theme/`**; `npm run check` is the gate.
- i18n: every key must exist in **both** `frontend/public/i18n/en.json` and `de.json` with a non-empty value (enforced by `i18n-dictionaries.spec.ts`).
- Password floor is 12 characters everywhere (matches `RegisterRequest`).
- Tests are production code; the web endpoint gets a functional test through the real firewall.

---

## File Structure

**Backend — create:**
- `backend/src/Service/Auth/BootstrapAdminProvisioner.php` — shared "create/promote the bootstrap admin" service.
- `backend/src/Service/Auth/WebAdminSetup.php` — web-path orchestration: secret + invariant + provision + JWT.
- `backend/src/Command/CreateAdminCommand.php` — `app:admin:create` (Path A).
- `backend/src/Controller/Api/SetupController.php` — `GET /api/setup/status`, `POST /api/setup/admin`.
- `backend/src/Dto/Setup/SetupAdminRequest.php` — request shape for the web path.
- `backend/src/Exception/SetupUnavailableException.php` — 404 (secret unset or admin exists).
- `backend/src/Exception/InvalidSetupSecretException.php` — 403 (wrong secret).
- Tests mirroring each under `backend/tests/`.

**Backend — modify:**
- `backend/src/Repository/UserRepository.php` — add `hasAnyAdmin()`.
- `backend/config/packages/rate_limiter.yaml` — add the `setup` limiter.
- `backend/config/packages/security.yaml` — make `^/api/setup/` public.
- `backend/.env` — declare `ADMIN_SETUP_SECRET=` (empty default).

**Frontend — create:**
- `frontend/src/app/setup/setup-api.ts`, `setup.service.ts`, `setup.guard.ts`.
- `frontend/src/app/setup/setup.component.ts` / `.html` / `.scss`.
- Sibling `.spec.ts` files.

**Frontend — modify:**
- `frontend/src/app/app.routes.ts` — add `/setup`; add `setupRedirectGuard` to `login` and `register`.
- `frontend/public/i18n/en.json`, `de.json` — add the `setup` block.

**Docs:**
- `docs/first-run-setup.md` (new), `README.md` (link).

---

## Task 1: `UserRepository::hasAnyAdmin()`

**Files:**
- Modify: `backend/src/Repository/UserRepository.php`
- Test: `backend/tests/Repository/UserRepositoryTest.php` (create if absent)

**Interfaces:**
- Produces: `UserRepository::hasAnyAdmin(): bool` — true if any user carries `ROLE_ADMIN` in **any** status.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Repository/UserRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserRepositoryTest extends DbTestCase
{
    private function repository(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }

    private function factory(): UserFactory
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return new UserFactory($this->em, $hasher);
    }

    public function testEmptyInstanceHasNoAdmin(): void
    {
        self::assertFalse($this->repository()->hasAnyAdmin());
    }

    public function testAPlainActiveUserIsNotAnAdmin(): void
    {
        $this->factory()->create('plain@example.com', roles: []);

        self::assertFalse($this->repository()->hasAnyAdmin());
    }

    public function testASuspendedAdminStillCounts(): void
    {
        $this->factory()->create('boss@example.com', status: UserStatus::Suspended, roles: ['ROLE_ADMIN']);

        self::assertTrue($this->repository()->hasAnyAdmin());
    }

    public function testARoleAdministratorSubstringDoesNotCount(): void
    {
        $this->factory()->create('fake@example.com', roles: ['ROLE_ADMINISTRATOR']);

        self::assertFalse($this->repository()->hasAnyAdmin());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Repository/UserRepositoryTest.php`
Expected: FAIL — `hasAnyAdmin()` does not exist.

- [ ] **Step 3: Implement the method**

Add to `backend/src/Repository/UserRepository.php` (after `findActiveAdmins()`):

```php
    /**
     * The bootstrap invariant: does an administrator exist yet? Any status
     * counts — gating on Active only would let a hijacker re-open first-run
     * setup by getting the sole admin suspended.
     *
     * `roles` is portable JSON-as-text on SQLite and MySQL, so the LIKE narrows
     * the hydration set but STILL needs the in-PHP recheck to reject a
     * `ROLE_ADMINISTRATOR` substring — the same reasoning as findActiveAdmins().
     */
    public function hasAnyAdmin(): bool
    {
        /** @var list<User> $candidates */
        $candidates = $this->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%ROLE_ADMIN%')
            ->getQuery()
            ->getResult();

        foreach ($candidates as $candidate) {
            if (\in_array('ROLE_ADMIN', $candidate->getRoles(), true)) {
                return true;
            }
        }

        return false;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Repository/UserRepositoryTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Lint touched files**

Run: `composer cs && composer stan && composer md`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Repository/UserRepository.php backend/tests/Repository/UserRepositoryTest.php
git commit -m "feat(#64): UserRepository::hasAnyAdmin bootstrap invariant"
```

---

## Task 2: `BootstrapAdminProvisioner` service

**Files:**
- Create: `backend/src/Service/Auth/BootstrapAdminProvisioner.php`
- Test: `backend/tests/Service/Auth/BootstrapAdminProvisionerTest.php`

**Interfaces:**
- Consumes: `UserRepository::findOneByEmail()`, `UserRepository::hasAnyAdmin()`.
- Produces: `BootstrapAdminProvisioner::provision(string $email, string $password): User` — creates or promotes an account to `Active` + `['ROLE_ADMIN']` with `approvedAt` set; idempotent by email.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Enum\UserStatus;
use App\Service\Auth\BootstrapAdminProvisioner;
use App\Tests\DbTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BootstrapAdminProvisionerTest extends DbTestCase
{
    private function provisioner(): BootstrapAdminProvisioner
    {
        $service = self::getContainer()->get(BootstrapAdminProvisioner::class);
        self::assertInstanceOf(BootstrapAdminProvisioner::class, $service);

        return $service;
    }

    public function testProvisionsAnActiveAdmin(): void
    {
        $admin = $this->provisioner()->provision('root@example.com', 'a-strong-password-123');

        self::assertSame(UserStatus::Active, $admin->getStatus());
        self::assertContains('ROLE_ADMIN', $admin->getRoles());
        self::assertNotNull($admin->getApprovedAt());

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertTrue($hasher->isPasswordValid($admin, 'a-strong-password-123'));
    }

    public function testIsIdempotentByEmail(): void
    {
        $first = $this->provisioner()->provision('root@example.com', 'a-strong-password-123');
        $second = $this->provisioner()->provision('root@example.com', 'a-different-password-456');

        self::assertSame($first->getId(), $second->getId());
        self::assertCount(1, $this->em->getRepository($first::class)->findAll());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/Auth/BootstrapAdminProvisionerTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement the service**

Create `backend/src/Service/Auth/BootstrapAdminProvisioner.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates the first administrator, or promotes an existing account into that
 * role. The bootstrap admin skips email verification and the approval queue: it
 * is Active with ROLE_ADMIN and approvedAt stamped. Both bootstrap paths — the
 * app:admin:create command and the web setup endpoint — funnel through here, so
 * the provisioning rule lives in exactly one place.
 *
 * Find-or-create by email makes a re-run idempotent rather than duplicating.
 * This service does NOT decide whether provisioning is allowed; the hasAnyAdmin
 * invariant is enforced by each caller (the command refuses, the endpoint 404s).
 */
final readonly class BootstrapAdminProvisioner
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $hasher,
        private ClockInterface $clock,
    ) {
    }

    public function provision(string $email, string $password): User
    {
        $now = $this->clock->now();
        $admin = $this->users->findOneByEmail($email) ?? new User($email, $now);

        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setStatus(UserStatus::Active);
        $admin->setApprovedAt($now);
        $admin->setPasswordHash($this->hasher->hashPassword($admin, $password), $now);

        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        return $admin;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/Auth/BootstrapAdminProvisionerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Lint**

Run: `composer cs && composer stan && composer md`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Auth/BootstrapAdminProvisioner.php backend/tests/Service/Auth/BootstrapAdminProvisionerTest.php
git commit -m "feat(#64): BootstrapAdminProvisioner shared by both bootstrap paths"
```

---

## Task 3: `app:admin:create` command (Path A)

**Files:**
- Create: `backend/src/Command/CreateAdminCommand.php`
- Test: `backend/tests/Command/CreateAdminCommandTest.php`

**Interfaces:**
- Consumes: `UserRepository::hasAnyAdmin()`, `BootstrapAdminProvisioner::provision()`.
- Produces: console command `app:admin:create <email> [--force]`, password read from a hidden prompt.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Repository\UserRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateAdminCommandTest extends DbTestCase
{
    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:admin:create'));
    }

    private function repository(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }

    private function seedAdmin(): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        (new UserFactory($this->em, $hasher))->create('existing@example.com', roles: ['ROLE_ADMIN']);
    }

    public function testCreatesAdminOnAnEmptyInstance(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['a-strong-password-123']);
        $tester->execute(['email' => 'root@example.com']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertTrue($this->repository()->hasAnyAdmin());
    }

    public function testRefusesWhenAnAdminAlreadyExists(): void
    {
        $this->seedAdmin();

        $tester = $this->tester();
        $tester->setInputs(['a-strong-password-123']);
        $tester->execute(['email' => 'second@example.com']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertNull($this->repository()->findOneByEmail('second@example.com'));
    }

    public function testForceCreatesASecondAdmin(): void
    {
        $this->seedAdmin();

        $tester = $this->tester();
        $tester->setInputs(['a-strong-password-123']);
        $tester->execute(['email' => 'second@example.com', '--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertNotNull($this->repository()->findOneByEmail('second@example.com'));
    }

    public function testRejectsATooShortPassword(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['short']);
        $tester->execute(['email' => 'root@example.com']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertFalse($this->repository()->hasAnyAdmin());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Command/CreateAdminCommandTest.php`
Expected: FAIL — command `app:admin:create` not found.

- [ ] **Step 3: Implement the command**

Create `backend/src/Command/CreateAdminCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\Auth\BootstrapAdminProvisioner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates the first administrator on a fresh install. Unlike app:e2e:seed-admin
 * this is prod-safe: it is the supported bootstrap for a shell/exec operator.
 *
 * Refuses when an administrator already exists, so a re-run cannot silently mint
 * a second bootstrap admin; --force overrides for recovery. The password is read
 * from a hidden prompt, never a CLI argument — an argument leaks into shell
 * history and the process list.
 */
#[AsCommand(
    name: 'app:admin:create',
    description: 'Create the first administrator (prod-safe; refuses if one exists unless --force).',
)]
final class CreateAdminCommand extends Command
{
    private const int MINIMUM_PASSWORD_LENGTH = 12;

    public function __construct(
        private readonly UserRepository $users,
        private readonly BootstrapAdminProvisioner $provisioner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Administrator email')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Create even if an administrator already exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (true !== $input->getOption('force') && $this->users->hasAnyAdmin()) {
            $io->error('An administrator already exists. Re-run with --force to create another.');

            return Command::FAILURE;
        }

        $password = (string) $io->askHidden('Administrator password (min 12 characters)');
        if (mb_strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            $io->error('The password must be at least 12 characters.');

            return Command::INVALID;
        }

        /** @var string $email */
        $email = $input->getArgument('email');
        $admin = $this->provisioner->provision($email, $password);

        $io->success(\sprintf('Administrator ready: %s', $admin->getEmail()));

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Command/CreateAdminCommandTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Lint**

Run: `composer cs && composer stan && composer md`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Command/CreateAdminCommand.php backend/tests/Command/CreateAdminCommandTest.php
git commit -m "feat(#64): app:admin:create prod-safe first-admin command"
```

---

## Task 4: Web setup service, exceptions, and DTO

**Files:**
- Create: `backend/src/Exception/SetupUnavailableException.php`, `backend/src/Exception/InvalidSetupSecretException.php`
- Create: `backend/src/Dto/Setup/SetupAdminRequest.php`
- Create: `backend/src/Service/Auth/WebAdminSetup.php`
- Test: `backend/tests/Service/Auth/WebAdminSetupTest.php`

**Interfaces:**
- Consumes: `UserRepository::hasAnyAdmin()`, `BootstrapAdminProvisioner::provision()`, `JWTTokenManagerInterface::create()`.
- Produces: `WebAdminSetup::createFirstAdmin(string $email, string $password, string $secret): string` — returns a JWT; throws `SetupUnavailableException` (secret unset **or** admin exists) or `InvalidSetupSecretException` (wrong secret).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Exception\InvalidSetupSecretException;
use App\Exception\SetupUnavailableException;
use App\Repository\UserRepository;
use App\Service\Auth\BootstrapAdminProvisioner;
use App\Service\Auth\WebAdminSetup;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class WebAdminSetupTest extends DbTestCase
{
    private const string SECRET = 'test-setup-secret-abcdef0123456789';

    private function setup(string $configuredSecret): WebAdminSetup
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $provisioner = self::getContainer()->get(BootstrapAdminProvisioner::class);
        self::assertInstanceOf(BootstrapAdminProvisioner::class, $provisioner);
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $jwt);

        return new WebAdminSetup($users, $provisioner, $jwt, $configuredSecret);
    }

    private function seedAdmin(): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        (new UserFactory($this->em, $hasher))->create('existing@example.com', roles: ['ROLE_ADMIN']);
    }

    public function testCreatesAdminAndReturnsAToken(): void
    {
        $token = $this->setup(self::SECRET)->createFirstAdmin('root@example.com', 'a-strong-password-123', self::SECRET);

        self::assertNotSame('', $token);
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        self::assertTrue($users->hasAnyAdmin());
    }

    public function testUnavailableWhenNoSecretConfigured(): void
    {
        $this->expectException(SetupUnavailableException::class);
        $this->setup('')->createFirstAdmin('root@example.com', 'a-strong-password-123', self::SECRET);
    }

    public function testUnavailableWhenAnAdminExists(): void
    {
        $this->seedAdmin();

        $this->expectException(SetupUnavailableException::class);
        $this->setup(self::SECRET)->createFirstAdmin('root@example.com', 'a-strong-password-123', self::SECRET);
    }

    public function testRejectsAWrongSecret(): void
    {
        $this->expectException(InvalidSetupSecretException::class);
        $this->setup(self::SECRET)->createFirstAdmin('root@example.com', 'a-strong-password-123', 'wrong-secret');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/Auth/WebAdminSetupTest.php`
Expected: FAIL — classes do not exist.

- [ ] **Step 3: Implement the exceptions**

`backend/src/Exception/SetupUnavailableException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The web setup endpoint is closed: either no ADMIN_SETUP_SECRET is configured,
 * or an administrator already exists. Both answer 404 so a closed endpoint
 * reveals nothing about which of the two it is.
 */
final class SetupUnavailableException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'setup_unavailable',
            404,
            'Not found',
            'Setup is not available.',
        );
    }
}
```

`backend/src/Exception/InvalidSetupSecretException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

final class InvalidSetupSecretException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'invalid_setup_secret',
            403,
            'Forbidden',
            'The setup secret is incorrect.',
        );
    }
}
```

- [ ] **Step 4: Implement the DTO**

`backend/src/Dto/Setup/SetupAdminRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Setup;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SetupAdminRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public string $email = '',
        #[Assert\NotBlank]
        #[Assert\Length(min: 12, max: 4096)]
        public string $password = '',
        #[Assert\NotBlank]
        public string $secret = '',
    ) {
    }
}
```

- [ ] **Step 5: Implement the service**

`backend/src/Service/Auth/WebAdminSetup.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Exception\InvalidSetupSecretException;
use App\Exception\SetupUnavailableException;
use App\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The no-shell bootstrap path. Available only while an operator-set secret is
 * configured AND no administrator exists yet; it self-disables the instant an
 * admin exists, so the endpoint has no standing attack surface.
 *
 * The secret is sourced from the environment — the one configuration channel
 * every cheap Docker host offers — and compared with hash_equals in constant
 * time. On success the caller is handed a JWT so the operator lands logged-in.
 */
final readonly class WebAdminSetup
{
    public function __construct(
        private UserRepository $users,
        private BootstrapAdminProvisioner $provisioner,
        private JWTTokenManagerInterface $jwtManager,
        #[Autowire('%env(ADMIN_SETUP_SECRET)%')]
        private string $configuredSecret,
    ) {
    }

    public function createFirstAdmin(string $email, string $password, string $secret): string
    {
        if ('' === $this->configuredSecret || $this->users->hasAnyAdmin()) {
            throw new SetupUnavailableException();
        }

        if (!hash_equals($this->configuredSecret, $secret)) {
            throw new InvalidSetupSecretException();
        }

        return $this->jwtManager->create($this->provisioner->provision($email, $password));
    }
}
```

- [ ] **Step 6: Declare the env var**

Add to `backend/.env` (near the other app secrets), so the placeholder always resolves and the variable is discoverable:

```dotenv
###> app/setup ###
# Set to a high-entropy value (openssl rand -hex 32) ONLY to enable the no-shell
# web setup wizard for the first admin. Leave empty when you bootstrap over a
# shell with `bin/console app:admin:create`. Remove it again after setup.
ADMIN_SETUP_SECRET=
###< app/setup ###
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/Auth/WebAdminSetupTest.php`
Expected: PASS (4 tests). (The test env leaves `ADMIN_SETUP_SECRET` empty; the service is constructed with an explicit secret, so it does not depend on the ambient value.)

- [ ] **Step 8: Lint**

Run: `composer cs && composer stan && composer md`
Expected: clean.

- [ ] **Step 9: Commit**

```bash
git add backend/src/Exception/SetupUnavailableException.php backend/src/Exception/InvalidSetupSecretException.php backend/src/Dto/Setup/SetupAdminRequest.php backend/src/Service/Auth/WebAdminSetup.php backend/.env backend/tests/Service/Auth/WebAdminSetupTest.php
git commit -m "feat(#64): WebAdminSetup service, setup exceptions, DTO, env secret"
```

---

## Task 5: `SetupController`, rate limiter, and firewall wiring

**Files:**
- Create: `backend/src/Controller/Api/SetupController.php`
- Modify: `backend/config/packages/rate_limiter.yaml`, `backend/config/packages/security.yaml`
- Test: `backend/tests/Controller/Api/SetupControllerTest.php`

**Interfaces:**
- Consumes: `UserRepository::hasAnyAdmin()`, `WebAdminSetup::createFirstAdmin()`, `RateLimitGuard::enforceForClient()`, the `setup` limiter (autowired as `$setupLimiter`).
- Produces: `GET /api/setup/status` → `{ "needsSetup": bool }`; `POST /api/setup/admin` → `201 { "token": string }`.

- [ ] **Step 1: Add the rate limiter**

Append to `framework.rate_limiter` in `backend/config/packages/rate_limiter.yaml`:

```yaml
        # The web first-admin bootstrap. Anonymous, so keyed on client IP like
        # registration. Brute-forcing a 32-byte secret is infeasible anyway;
        # this bounds scripted probing and matches the login/registration story
        # of "five tries a quarter hour, per IP". Same sliding window and pool
        # as its neighbours, for the reasons documented above.
        setup:
            policy: 'sliding_window'
            limit: 5
            interval: '15 minutes'
            cache_pool: cache.rate_limiter
```

- [ ] **Step 2: Open the firewall path**

In `backend/config/packages/security.yaml`, add the public rule **above** the `^/api/` catch-all (place it right after the `^/api/auth/` line):

```yaml
        - { path: ^/api/setup/, roles: PUBLIC_ACCESS }
```

Resulting `access_control` order (verify): `^/api/health$`, `^/api/auth/`, `^/api/setup/`, catalog favicon, `^/api/admin/`, `^/api/`.

- [ ] **Step 3: Write the failing functional test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Repository\UserRepository;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SetupControllerTest extends WebTestCase
{
    private const string SECRET = 'test-setup-secret-abcdef0123456789';

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_SETUP_SECRET'], $_SERVER['ADMIN_SETUP_SECRET']);
        putenv('ADMIN_SETUP_SECRET');
        parent::tearDown();
    }

    private function enableSecret(): void
    {
        $_ENV['ADMIN_SETUP_SECRET'] = self::SECRET;
        $_SERVER['ADMIN_SETUP_SECRET'] = self::SECRET;
        putenv('ADMIN_SETUP_SECRET=' . self::SECRET);
    }

    private function seedAdmin(KernelBrowser $client): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        (new UserFactory($em, $hasher))->create('existing@example.com', roles: ['ROLE_ADMIN']);
    }

    /** @return array<string, mixed> */
    private function body(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function post(KernelBrowser $client, string $email, string $password, string $secret): void
    {
        $client->request(
            'POST',
            '/api/setup/admin',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => $password, 'secret' => $secret], \JSON_THROW_ON_ERROR),
        );
    }

    public function testStatusReportsNeedsSetupOnEmptyInstance(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/setup/status');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->body($client)['needsSetup']);
    }

    public function testStatusReportsFalseOnceAnAdminExists(): void
    {
        $client = self::createClient();
        $this->seedAdmin($client);

        $client->request('GET', '/api/setup/status');

        self::assertFalse($this->body($client)['needsSetup']);
    }

    public function testCreatesAdminWithTheCorrectSecret(): void
    {
        $this->enableSecret();
        $client = self::createClient();

        $this->post($client, 'root@example.com', 'a-strong-password-123', self::SECRET);

        self::assertResponseStatusCodeSame(201);
        self::assertArrayHasKey('token', $this->body($client));

        $users = $client->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        self::assertTrue($users->hasAnyAdmin());
    }

    public function testWrongSecretIsForbidden(): void
    {
        $this->enableSecret();
        $client = self::createClient();

        $this->post($client, 'root@example.com', 'a-strong-password-123', 'wrong-secret');

        self::assertResponseStatusCodeSame(403);
    }

    public function testEndpointIs404WhenNoSecretConfigured(): void
    {
        $client = self::createClient();

        $this->post($client, 'root@example.com', 'a-strong-password-123', 'anything');

        self::assertResponseStatusCodeSame(404);
    }
}
```

> **Rate limiter in tests:** the `cache.rate_limiter` pool persists between cases. If any assertion 429s, mirror how `tests/Controller/Api/RegistrationTest.php` clears that pool in `setUp()` and copy the same reset into this test.

- [ ] **Step 4: Run test to verify it fails**

Run: `php bin/phpunit tests/Controller/Api/SetupControllerTest.php`
Expected: FAIL — no route matches `/api/setup/*` (404 on status).

- [ ] **Step 5: Implement the controller**

`backend/src/Controller/Api/SetupController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Setup\SetupAdminRequest;
use App\Repository\UserRepository;
use App\Service\Auth\WebAdminSetup;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/setup')]
final readonly class SetupController
{
    public function __construct(
        private UserRepository $users,
        private WebAdminSetup $setup,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $setupLimiter,
    ) {
    }

    #[Route('/status', name: 'api_setup_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse(['needsSetup' => !$this->users->hasAnyAdmin()]);
    }

    #[Route('/admin', name: 'api_setup_admin', methods: ['POST'])]
    public function createAdmin(#[MapRequestPayload] SetupAdminRequest $request, Request $httpRequest): JsonResponse
    {
        $this->rateLimitGuard->enforceForClient($this->setupLimiter, $httpRequest);

        $token = $this->setup->createFirstAdmin($request->email, $request->password, $request->secret);

        return new JsonResponse(['token' => $token], Response::HTTP_CREATED);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php bin/phpunit tests/Controller/Api/SetupControllerTest.php`
Expected: PASS (5 tests).

- [ ] **Step 7: Full backend suite + lint + log scan**

Run: `php bin/phpunit && composer cs && composer stan && composer md`
Then scan `backend/var/log/dev.log` for new deprecations or swallowed errors.
Expected: green and clean.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Controller/Api/SetupController.php backend/config/packages/rate_limiter.yaml backend/config/packages/security.yaml backend/tests/Controller/Api/SetupControllerTest.php
git commit -m "feat(#64): setup endpoint (status + web bootstrap), rate-limited and public"
```

---

## Task 6: Frontend setup API, service, and guards

**Files:**
- Create: `frontend/src/app/setup/setup-api.ts`, `setup.service.ts`, `setup.guard.ts`
- Test: `frontend/src/app/setup/setup.service.spec.ts`, `setup.guard.spec.ts`

**Interfaces:**
- Produces:
  - `SetupApi.status(): Observable<{ needsSetup: boolean }>`, `SetupApi.createAdmin(email, password, secret): Observable<{ token: string }>`
  - `SetupService.ensureLoaded(): Observable<boolean>`, `SetupService.markComplete(): void`, `SetupService.needsSetup: Signal<boolean | null>`
  - `setupRedirectGuard: CanActivateFn` (login/register → `/setup` when setup needed), `requireSetupGuard: CanActivateFn` (`/setup` → `/login` when not needed)

- [ ] **Step 1: Write the service + guard failing tests**

`frontend/src/app/setup/setup.service.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { SetupApi } from './setup-api';
import { SetupService } from './setup.service';

describe('SetupService', () => {
  function configure(status: jest.Mock): SetupService {
    TestBed.configureTestingModule({
      providers: [SetupService, { provide: SetupApi, useValue: { status } }],
    });
    return TestBed.inject(SetupService);
  }

  it('fetches status once and caches it', (done) => {
    const status = jest.fn().mockReturnValue(of({ needsSetup: true }));
    const service = configure(status);

    service.ensureLoaded().subscribe(() => {
      service.ensureLoaded().subscribe((needs) => {
        expect(needs).toBe(true);
        expect(status).toHaveBeenCalledTimes(1);
        done();
      });
    });
  });

  it('markComplete flips needsSetup to false without another call', () => {
    const status = jest.fn().mockReturnValue(of({ needsSetup: true }));
    const service = configure(status);

    service.markComplete();

    expect(service.needsSetup()).toBe(false);
  });
});
```

`frontend/src/app/setup/setup.guard.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { Router, UrlTree } from '@angular/router';
import { of } from 'rxjs';
import { runInInjectionContext, EnvironmentInjector } from '@angular/core';
import { firstValueFrom, isObservable } from 'rxjs';
import { SetupService } from './setup.service';
import { requireSetupGuard, setupRedirectGuard } from './setup.guard';

function resolve(result: boolean | UrlTree | ReturnType<typeof of>): Promise<boolean | UrlTree> {
  return isObservable(result) ? firstValueFrom(result) : Promise.resolve(result);
}

describe('setup guards', () => {
  function inject(needsSetup: boolean) {
    TestBed.configureTestingModule({
      providers: [{ provide: SetupService, useValue: { ensureLoaded: () => of(needsSetup) } }],
    });
    return TestBed.inject(EnvironmentInjector);
  }

  it('setupRedirectGuard sends to /setup when setup is needed', async () => {
    const injector = inject(true);
    const result = await runInInjectionContext(injector, () =>
      resolve(setupRedirectGuard({} as never, {} as never)),
    );
    expect((result as UrlTree).toString()).toBe('/setup');
  });

  it('setupRedirectGuard allows navigation when no setup is needed', async () => {
    const injector = inject(false);
    const result = await runInInjectionContext(injector, () =>
      resolve(setupRedirectGuard({} as never, {} as never)),
    );
    expect(result).toBe(true);
  });

  it('requireSetupGuard sends to /login when no setup is needed', async () => {
    const injector = inject(false);
    const result = await runInInjectionContext(injector, () =>
      resolve(requireSetupGuard({} as never, {} as never)),
    );
    expect((result as UrlTree).toString()).toBe('/login');
  });
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `npm test -- setup`
Expected: FAIL — modules not found.

- [ ] **Step 3: Implement `SetupApi`**

`frontend/src/app/setup/setup-api.ts`:

```ts
// src/app/setup/setup-api.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';

@Injectable({ providedIn: 'root' })
export class SetupApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  status(): Observable<{ needsSetup: boolean }> {
    return this.http.get<{ needsSetup: boolean }>(`${this.base}/api/setup/status`);
  }

  createAdmin(email: string, password: string, secret: string): Observable<{ token: string }> {
    return this.http.post<{ token: string }>(`${this.base}/api/setup/admin`, {
      email,
      password,
      secret,
    });
  }
}
```

- [ ] **Step 4: Implement `SetupService`**

`frontend/src/app/setup/setup.service.ts`:

```ts
// src/app/setup/setup.service.ts
import { Injectable, inject, signal } from '@angular/core';
import { Observable, map, of, tap } from 'rxjs';
import { SetupApi } from './setup-api';

/** Caches the one-time first-run check so the guards do not re-hit the API on
 *  every navigation. `markComplete()` closes the window the moment the operator
 *  finishes setup, without another round-trip. */
@Injectable({ providedIn: 'root' })
export class SetupService {
  private readonly api = inject(SetupApi);
  private cached: boolean | null = null;
  readonly needsSetup = signal<boolean | null>(null);

  ensureLoaded(): Observable<boolean> {
    if (this.cached !== null) return of(this.cached);
    return this.api.status().pipe(
      tap((r) => {
        this.cached = r.needsSetup;
        this.needsSetup.set(r.needsSetup);
      }),
      map((r) => r.needsSetup),
    );
  }

  markComplete(): void {
    this.cached = false;
    this.needsSetup.set(false);
  }
}
```

- [ ] **Step 5: Implement the guards**

`frontend/src/app/setup/setup.guard.ts`:

```ts
// src/app/setup/setup.guard.ts
import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { catchError, map, of } from 'rxjs';
import { SetupService } from './setup.service';

/** On login/register: while the instance has no admin, force the operator to the
 *  setup screen. If the status call fails, fail open — do not trap the user. */
export const setupRedirectGuard: CanActivateFn = () => {
  const setup = inject(SetupService);
  const router = inject(Router);
  return setup.ensureLoaded().pipe(
    map((needsSetup) => (needsSetup ? router.createUrlTree(['/setup']) : true)),
    catchError(() => of(true)),
  );
};

/** On /setup: once an admin exists the wizard is over — send the user to login. */
export const requireSetupGuard: CanActivateFn = () => {
  const setup = inject(SetupService);
  const router = inject(Router);
  return setup.ensureLoaded().pipe(
    map((needsSetup) => (needsSetup ? true : router.createUrlTree(['/login']))),
    catchError(() => of(router.createUrlTree(['/login']))),
  );
};
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `npm test -- setup`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/setup/setup-api.ts frontend/src/app/setup/setup.service.ts frontend/src/app/setup/setup.guard.ts frontend/src/app/setup/setup.service.spec.ts frontend/src/app/setup/setup.guard.spec.ts
git commit -m "feat(#64): frontend setup api, cached status service, route guards"
```

---

## Task 7: Frontend setup screen, routes, and i18n

**Files:**
- Create: `frontend/src/app/setup/setup.component.ts` / `.html` / `.scss`, `setup.component.spec.ts`
- Modify: `frontend/src/app/app.routes.ts`, `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `SetupApi.createAdmin()`, `SetupService.markComplete()`, `AuthService.loadMe()`, `TokenStore.set()`.

- [ ] **Step 1: Add i18n keys (both languages)**

In `frontend/public/i18n/en.json`, add a top-level `setup` block:

```json
  "setup": {
    "title": "Set up this instance",
    "subtitle": "Create the first administrator account.",
    "secret": "Setup secret",
    "secretHint": "Paste the ADMIN_SETUP_SECRET you configured in the environment. Prefer a shell? Run bin/console app:admin:create instead.",
    "submit": "Create administrator",
    "invalidInput": "Enter a valid email, a password of at least 12 characters, and the setup secret.",
    "failed": "Setup failed. Check the secret and try again."
  }
```

In `frontend/public/i18n/de.json`, add the same block with German values:

```json
  "setup": {
    "title": "Diese Instanz einrichten",
    "subtitle": "Erstellen Sie das erste Administratorkonto.",
    "secret": "Einrichtungsgeheimnis",
    "secretHint": "Fügen Sie das in der Umgebung konfigurierte ADMIN_SETUP_SECRET ein. Lieber per Shell? Führen Sie stattdessen bin/console app:admin:create aus.",
    "submit": "Administrator erstellen",
    "invalidInput": "Geben Sie eine gültige E-Mail-Adresse, ein Passwort mit mindestens 12 Zeichen und das Einrichtungsgeheimnis ein.",
    "failed": "Einrichtung fehlgeschlagen. Prüfen Sie das Geheimnis und versuchen Sie es erneut."
  }
```

> Reuse existing `auth.email` and `auth.password` keys for the email/password field labels. Keep the JSON key order consistent with the file; do not leave any value blank.

- [ ] **Step 2: Write the failing component test**

`frontend/src/app/setup/setup.component.spec.ts`:

```ts
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { of, throwError } from 'rxjs';
import { SetupApi } from './setup-api';
import { SetupService } from './setup.service';
import { SetupComponent } from './setup.component';

describe('SetupComponent', () => {
  let createAdmin: jest.Mock;
  let markComplete: jest.Mock;

  async function mount(): Promise<ComponentFixture<SetupComponent>> {
    createAdmin = jest.fn().mockReturnValue(of({ token: 'jwt-token' }));
    markComplete = jest.fn();
    await TestBed.configureTestingModule({
      imports: [SetupComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideRouter([]),
        { provide: SetupApi, useValue: { createAdmin } },
        { provide: SetupService, useValue: { markComplete } },
      ],
    }).compileComponents();
    const fixture = TestBed.createComponent(SetupComponent);
    fixture.detectChanges();
    return fixture;
  }

  it('posts the form and marks setup complete on success', async () => {
    const fixture = await mount();
    const component = fixture.componentInstance;
    component.form.setValue({
      email: 'root@example.com',
      password: 'a-strong-password-123',
      secret: 'the-secret',
    });

    component.submit();

    expect(createAdmin).toHaveBeenCalledWith('root@example.com', 'a-strong-password-123', 'the-secret');
    expect(markComplete).toHaveBeenCalled();
  });

  it('surfaces an error and does not complete when the API rejects', async () => {
    const fixture = await mount();
    createAdmin.mockReturnValue(throwError(() => ({ error: { detail: 'nope' } })));
    fixture.componentInstance.form.setValue({
      email: 'root@example.com',
      password: 'a-strong-password-123',
      secret: 'bad',
    });

    fixture.componentInstance.submit();

    expect(fixture.componentInstance.error()).not.toBeNull();
    expect(markComplete).not.toHaveBeenCalled();
  });
});
```

> `provideTranslocoTesting()` is placed in the `imports` array, mirroring `frontend/src/app/auth/login/login.component.spec.ts`. The relative path differs by one level: login uses `../../../testing/transloco-testing`, the setup spec (one directory shallower) uses `../../testing/transloco-testing` as shown.

- [ ] **Step 3: Run to verify it fails**

Run: `npm test -- setup.component`
Expected: FAIL — component not found.

- [ ] **Step 4: Implement the component**

`frontend/src/app/setup/setup.component.ts`:

```ts
// src/app/setup/setup.component.ts
import { Component, ElementRef, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { AuthService } from '../core/auth.service';
import { TokenStore } from '../core/token.store';
import { parseProblem } from '../core/problem';
import { adoptAutofilledValues } from '../auth/autofill';
import { AuthShellComponent } from '../auth/auth-shell/auth-shell.component';
import { ButtonComponent } from '../shared/button/button.component';
import { FormErrorComponent } from '../shared/form-error/form-error.component';
import { FieldComponent } from '../shared/field/field.component';
import { SetupApi } from './setup-api';
import { SetupService } from './setup.service';

@Component({
  selector: 'app-setup',
  imports: [
    ReactiveFormsModule,
    TranslocoPipe,
    AuthShellComponent,
    ButtonComponent,
    FormErrorComponent,
    FieldComponent,
  ],
  templateUrl: './setup.component.html',
  styleUrl: './setup.component.scss',
})
export class SetupComponent {
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly api = inject(SetupApi);
  private readonly setup = inject(SetupService);
  private readonly auth = inject(AuthService);
  private readonly tokens = inject(TokenStore);
  private readonly router = inject(Router);
  private readonly i18n = inject(TranslocoService);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);

  readonly form = this.fb.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required, Validators.minLength(12)]],
    secret: ['', [Validators.required]],
  });
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

  submit(): void {
    if (this.loading()) return;
    adoptAutofilledValues(this.host.nativeElement, this.form);

    if (this.form.invalid) {
      this.error.set(this.i18n.translate('setup.invalidInput'));
      return;
    }
    this.loading.set(true);
    this.error.set(null);
    const { email, password, secret } = this.form.getRawValue();
    this.api.createAdmin(email, password, secret).subscribe({
      next: (res) => {
        this.tokens.set(res.token);
        this.setup.markComplete();
        this.auth.loadMe().subscribe({
          next: () => void this.router.navigate(['/']),
          error: () => void this.router.navigate(['/']),
        });
      },
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e).detail ?? this.i18n.translate('setup.failed'));
        this.loading.set(false);
      },
    });
  }
}
```

`frontend/src/app/setup/setup.component.html`:

```html
<app-auth-shell
  [title]="'setup.title' | transloco"
  [subtitle]="'setup.subtitle' | transloco"
>
  <form [formGroup]="form">
    <app-field [label]="'auth.email' | transloco">
      <input type="email" formControlName="email" name="email" autocomplete="email" />
    </app-field>
    <app-field [label]="'auth.password' | transloco">
      <input
        type="password"
        formControlName="password"
        name="password"
        autocomplete="new-password"
      />
    </app-field>
    <app-field [label]="'setup.secret' | transloco">
      <input type="password" formControlName="secret" name="secret" autocomplete="off" />
    </app-field>
    <p class="hint">{{ 'setup.secretHint' | transloco }}</p>
    <app-form-error [message]="error()" />
    <app-button block variant="primary" [loading]="loading()" (click)="submit()">{{
      'setup.submit' | transloco
    }}</app-button>
  </form>
</app-auth-shell>
```

`frontend/src/app/setup/setup.component.scss`:

```scss
// src/app/setup/setup.component.scss
// Base copied from login.component.scss so the setup screen matches the auth
// surface. Add a `.hint` rule using the SAME token variables already used in
// login.component.scss (spacing + muted-text tokens) — no hex, no raw px.
```

> Open `frontend/src/app/auth/login/login.component.scss`, copy it as the base, and add a `.hint` selector styled with the muted-text colour token and a top-margin spacing token that file already references. Import paths for `auth-shell`, `autofill`, and the shared components differ by one directory level from the login component (setup lives at `src/app/setup/`, login at `src/app/auth/login/`) — the imports above already account for that; verify they resolve.

- [ ] **Step 5: Wire the routes**

In `frontend/src/app/app.routes.ts`:

1. Import the guards:

```ts
import { requireSetupGuard, setupRedirectGuard } from './setup/setup.guard';
```

2. Add `setupRedirectGuard` ahead of `guestGuard` on the `login` and `register` routes:

```ts
  {
    path: 'login',
    canActivate: [setupRedirectGuard, guestGuard],
    loadComponent: () => import('./auth/login/login.component').then((m) => m.LoginComponent),
  },
  {
    path: 'register',
    canActivate: [setupRedirectGuard, guestGuard],
    loadComponent: () =>
      import('./auth/register/register.component').then((m) => m.RegisterComponent),
  },
```

3. Add the `setup` route (place it near the auth routes, before the `''` catch-all):

```ts
  {
    path: 'setup',
    canActivate: [requireSetupGuard],
    loadComponent: () => import('./setup/setup.component').then((m) => m.SetupComponent),
  },
```

- [ ] **Step 6: Run the component test + full check**

Run: `npm test -- setup` then `npm run check`
Expected: PASS and clean (ESLint, Prettier, Stylelint, Jest, i18n key parity).

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/setup/ frontend/src/app/app.routes.ts frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#64): first-run setup screen; hide login/register until an admin exists"
```

---

## Task 8: Documentation

**Files:**
- Create: `docs/first-run-setup.md`
- Modify: `README.md`

- [ ] **Step 1: Write the guide**

Create `docs/first-run-setup.md`:

```markdown
# First-run setup: creating the first administrator

A fresh install has no administrator, so no registered user can be approved and
the instance shows a one-time setup screen instead of login. Create the first
admin one of two ways. Both refuse to create a second bootstrap admin once one
exists.

## Why it is gated

The first-admin path is the one moment an instance can be hijacked: whoever
creates that account owns the instance. So the path requires something only you,
the operator, hold — shell access, or a secret you set in the environment. The
first person to *register* is never promoted to admin.

## Option 1 — you have shell / `docker exec` access (recommended)

```bash
docker compose exec php bin/console app:admin:create you@example.com
```

Enter a password (at least 12 characters) at the hidden prompt. The account is
created active, with the admin role, and can immediately reach `/api/admin`.
Re-running refuses once an admin exists; `--force` overrides for recovery.

Leave `ADMIN_SETUP_SECRET` unset in this case — the web setup endpoint then does
not exist at all.

## Option 2 — no shell (cheap Docker hosts)

1. Generate a high-entropy secret:

   ```bash
   openssl rand -hex 32
   ```

2. Set it as the `ADMIN_SETUP_SECRET` environment variable in your host's
   dashboard (the same place you set `DATABASE_URL`), and redeploy.
3. Open the app. The setup screen asks for an email, a password, and the secret.
4. Submit. You are created as the administrator and logged in.
5. Remove `ADMIN_SETUP_SECRET` from the environment. (The endpoint self-disables
   once an admin exists regardless, but removing the secret is tidy.)

The endpoint returns 404 whenever the secret is unset or an admin already
exists, and it is rate-limited (5 attempts per 15 minutes per IP).
```

- [ ] **Step 2: Link it from the README**

Add a line to `README.md` under the setup/installation section:

```markdown
- **First-run setup:** creating the initial admin — see [docs/first-run-setup.md](docs/first-run-setup.md).
```

> Find the existing docs-links or installation section in `README.md` and place the line consistently with the surrounding list style.

- [ ] **Step 3: Commit**

```bash
git add docs/first-run-setup.md README.md
git commit -m "docs(#64): document first-run admin bootstrap (shell + no-shell)"
```

---

## Final verification

- [ ] `cd backend && php bin/phpunit` — full suite green (SQLite).
- [ ] `cd backend && composer cs && composer stan && composer md` — clean on all touched files.
- [ ] `mcp__phpstorm__lint_files` on the changed PHP — no ERROR/WARNING.
- [ ] Scan `backend/var/log/dev.log` — no new deprecations or swallowed errors.
- [ ] `cd frontend && npm run check` — ESLint + Prettier + Stylelint + Jest + i18n parity green.
- [ ] Manual smoke (optional): with `ADMIN_SETUP_SECRET` set on the Docker stack, load the app, confirm login/register are hidden and the setup screen appears; create the admin; confirm login/register return and the setup screen 404s.
- [ ] Open the PR against `develop` with `Closes #64`.

---

## Self-review notes (author)

- **Spec coverage:** CLI path → Task 3; no-shell web path → Tasks 4–5; shared invariant → Task 1; single provisioning point → Task 2; SPA guidance + hide-login-until-setup + no auto-promote → Tasks 6–7; docs → Task 8; tests for empty-instance create / refusal-when-exists / wrong-secret / unset-secret / status flip → Tasks 1,3,4,5. All covered.
- **Type consistency:** `hasAnyAdmin(): bool`, `provision(email, password): User`, `createFirstAdmin(email, password, secret): string`, `SetupService.ensureLoaded(): Observable<boolean>` / `markComplete()`, guard names `setupRedirectGuard` / `requireSetupGuard` are used identically across tasks.
- **Verified before finalising:** `User::getEmail()` exists (Task 3); the Transloco test helper is `provideTranslocoTesting()` used in `imports` (Task 7). **Still flagged for the implementer:** the rate-limiter pool reset pattern in `RegistrationTest` (Task 5) and the login SCSS token names to copy (Task 7).
```
