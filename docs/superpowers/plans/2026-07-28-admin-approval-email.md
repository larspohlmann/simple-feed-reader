# Admin Approval-Notification Email — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Email every active admin when a new user enters the approval queue (`UserStatus::PendingApproval`), rendered in each admin's own locale, with the applicant's email, how they signed up, the current pending-approval count, and a deep link to the admin users page.

**Architecture:** A domain event `UserAwaitingApproval` is dispatched *after flush* from the three transition sites (`RegistrationService::verifyEmail`, and the create/claim branches of `OAuthAccountLinker::resolve`). A single `#[AsEventListener]` resolves active admins, builds one `PendingApprovalNotice`, and sends one localized plain-text mail per admin through the existing `AccountMailer` → `DeferredMailer` (queued during the request, flushed on `kernel.terminate`). Reuses `APP_FRONTEND_URL`; no new env var.

**Tech Stack:** Symfony 7.4 (PHP 8.4), Doctrine ORM, Symfony Mailer + Translation, PHPUnit. Backend only.

**Spec:** [docs/superpowers/specs/2026-07-28-admin-approval-email-design.md](../specs/2026-07-28-admin-approval-email-design.md) · **Issue:** #164

---

## File Structure

**Create:**
- `backend/src/Enum/RegistrationMethod.php` — how an account reached the queue (`EmailPassword` | `OAuth`).
- `backend/src/Event/UserAwaitingApproval.php` — domain event carrying the new user + method + optional provider.
- `backend/src/Dto/Mail/PendingApprovalNotice.php` — the assembled notification payload (built once, sent to each admin).
- `backend/src/EventListener/NotifyAdminsOfPendingApproval.php` — the listener that fans out the mail.
- `backend/tests/Repository/UserRepositoryTest.php` — covers the two new repository queries.

**Modify:**
- `backend/src/Repository/UserRepository.php` — add `findActiveAdmins()` and `countByStatus()`.
- `backend/src/Service/Mail/AccountMailer.php` — add `sendPendingApprovalNotice()` (+ private `methodLabel()`).
- `backend/translations/emails.en.yaml`, `backend/translations/emails.de.yaml` — add the `admin_pending_approval` keys.
- `backend/src/Service/Auth/RegistrationService.php` — inject the dispatcher, dispatch after the verify flush.
- `backend/src/Service/OAuth/OAuthAccountLinker.php` — inject the dispatcher, make `findLinkTarget()` pure, add `claimIfUnverified()`, dispatch after flush.
- `backend/tests/Support/UserFactory.php` — add a `locale` parameter (needed to seed a `de` admin).
- `backend/tests/Service/Mail/AccountMailerTest.php` — add notice tests + a flush-left body case.
- `backend/tests/Controller/Api/RegistrationTest.php` — functional tests for the whole password-path chain.
- `backend/tests/Service/Auth/RegistrationServiceTest.php` — update the direct constructor call.
- `backend/tests/Service/OAuth/OAuthAccountLinkerTest.php` — update the direct constructor call + add dispatch assertions.

**Conventions (apply to every task):**
- `declare(strict_types=1);` at the top of every PHP file.
- Run commands from `backend/`.
- Every touched `src` file must end the plan `composer check`-clean (PSR-12 + PHPStan max), `composer md`-clean, and PhpStorm-inspection-clean (see Task 8).
- Commit after each task with the shown message.

---

### Task 1: `RegistrationMethod` enum + `UserAwaitingApproval` event

**Files:**
- Create: `backend/src/Enum/RegistrationMethod.php`
- Create: `backend/src/Event/UserAwaitingApproval.php`

These are value/data types with no branching logic, so there is no unit test — they are exercised end-to-end by Tasks 5 and 6. Verification is that the suite still boots and PHPStan is clean.

- [ ] **Step 1: Create the enum**

`backend/src/Enum/RegistrationMethod.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How an account reached the approval queue. Passed on UserAwaitingApproval so
 * the admin notification can say how someone signed up without re-deriving it
 * from the user row (a null password hash is not a reliable "is OAuth" signal).
 */
enum RegistrationMethod
{
    case EmailPassword;
    case OAuth;
}
```

- [ ] **Step 2: Create the event**

`backend/src/Event/UserAwaitingApproval.php`:

```php
<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\User;
use App\Enum\RegistrationMethod;

/**
 * A user has just entered UserStatus::PendingApproval and needs an admin to act.
 *
 * Dispatched AFTER the transition is flushed, so a listener that counts the
 * queue sees this user in it, and a failed flush produces no notification.
 */
final readonly class UserAwaitingApproval
{
    public function __construct(
        public User $user,
        public RegistrationMethod $method,
        public ?string $oauthProvider = null,
    ) {
    }
}
```

- [ ] **Step 3: Verify the suite still boots**

Run: `php bin/phpunit --filter testRegisterCreatesAPendingUserAndSendsOneMail`
Expected: PASS (nothing wired yet; this only proves the new files parse and autoload).

- [ ] **Step 4: Commit**

```bash
git add backend/src/Enum/RegistrationMethod.php backend/src/Event/UserAwaitingApproval.php
git commit -m "feat(auth): add UserAwaitingApproval event and RegistrationMethod enum (#164)"
```

---

### Task 2: `PendingApprovalNotice` DTO

**Files:**
- Create: `backend/src/Dto/Mail/PendingApprovalNotice.php`

Data holder, no logic — no unit test; exercised by Tasks 4–5.

- [ ] **Step 1: Create the DTO**

`backend/src/Dto/Mail/PendingApprovalNotice.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Mail;

use App\Enum\RegistrationMethod;

/**
 * Everything an admin-approval notification needs, assembled once and reused for
 * every recipient. Only the locale differs per admin (resolved by AccountMailer
 * from the recipient), so the rest of the payload — applicant, method, count and
 * the review link — is identical across recipients and computed a single time.
 *
 * A DTO rather than a five-argument send method: it keeps
 * AccountMailer::sendPendingApprovalNotice() to two parameters.
 */
final readonly class PendingApprovalNotice
{
    public function __construct(
        public string $applicantEmail,
        public RegistrationMethod $method,
        public ?string $oauthProvider,
        public string $reviewUrl,
        public int $pendingApprovalCount,
    ) {
    }
}
```

- [ ] **Step 2: Verify autoload**

Run: `php bin/phpunit --filter testRegisterCreatesAPendingUserAndSendsOneMail`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add backend/src/Dto/Mail/PendingApprovalNotice.php
git commit -m "feat(mail): add PendingApprovalNotice DTO (#164)"
```

---

### Task 3: Repository queries — `findActiveAdmins()` and `countByStatus()`

**Files:**
- Modify: `backend/src/Repository/UserRepository.php`
- Create: `backend/tests/Repository/UserRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

`backend/tests/Repository/UserRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Tests\DbTestCase;

final class UserRepositoryTest extends DbTestCase
{
    private function users(): UserRepository
    {
        /** @var UserRepository $repository */
        $repository = $this->em->getRepository(User::class);

        return $repository;
    }

    private function persist(string $email, UserStatus $status, string ...$roles): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01 10:00:00'));
        $user->setStatus($status);
        $user->setRoles(array_values($roles));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testFindActiveAdminsReturnsOnlyActiveRoleAdminUsers(): void
    {
        $activeAdmin = $this->persist('admin@example.com', UserStatus::Active, 'ROLE_ADMIN');
        $this->persist('suspended-admin@example.com', UserStatus::Suspended, 'ROLE_ADMIN');
        $this->persist('active-user@example.com', UserStatus::Active);
        // A role whose name merely contains the admin string must not match.
        $this->persist('lookalike@example.com', UserStatus::Active, 'ROLE_ADMINISTRATOR');

        $admins = $this->users()->findActiveAdmins();

        self::assertCount(1, $admins);
        self::assertSame($activeAdmin->getId(), $admins[0]->getId());
    }

    public function testFindActiveAdminsIsEmptyWhenNoActiveAdminExists(): void
    {
        $this->persist('pending-admin@example.com', UserStatus::PendingApproval, 'ROLE_ADMIN');

        self::assertSame([], $this->users()->findActiveAdmins());
    }

    public function testCountByStatusCountsOnlyTheGivenStatus(): void
    {
        $this->persist('a@example.com', UserStatus::PendingApproval);
        $this->persist('b@example.com', UserStatus::PendingApproval);
        $this->persist('c@example.com', UserStatus::Active);

        self::assertSame(2, $this->users()->countByStatus(UserStatus::PendingApproval));
        self::assertSame(1, $this->users()->countByStatus(UserStatus::Active));
        self::assertSame(0, $this->users()->countByStatus(UserStatus::Rejected));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Repository/UserRepositoryTest.php`
Expected: FAIL — `Call to undefined method App\Repository\UserRepository::findActiveAdmins()`.

- [ ] **Step 3: Add both methods to the repository**

In `backend/src/Repository/UserRepository.php`, add these two methods after `findUnverifiedCreatedBefore()` (before the closing brace of the class):

```php
    /**
     * The admins to notify when a new account needs approving: those who can
     * actually act on it. A suspended or rejected admin is not a working
     * recipient, so active status gates the list the same way the firewall
     * gates the admin API.
     *
     * The role check is done in PHP rather than in the query on purpose. `roles`
     * is a portable JSON-as-text column on both SQLite (tests) and MySQL (prod),
     * and the active set is tiny — every account passes a human (see
     * findForAdminList) — so loading it and inspecting the decoded roles is both
     * correct across dialects and simpler than a `LIKE` that would still need
     * this same in-PHP recheck to reject a `ROLE_ADMINISTRATOR` substring.
     *
     * @return list<User>
     */
    public function findActiveAdmins(): array
    {
        /** @var list<User> $active */
        $active = $this->createQueryBuilder('u')
            ->andWhere('u.status = :active')
            ->setParameter('active', UserStatus::Active)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $active,
            static fn (User $user): bool => \in_array('ROLE_ADMIN', $user->getRoles(), true),
        ));
    }

    public function countByStatus(UserStatus $status): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php bin/phpunit tests/Repository/UserRepositoryTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repository/UserRepository.php backend/tests/Repository/UserRepositoryTest.php
git commit -m "feat(user): query active admins and count users by status (#164)"
```

---

### Task 4: `AccountMailer::sendPendingApprovalNotice()` + translations

**Files:**
- Modify: `backend/src/Service/Mail/AccountMailer.php`
- Modify: `backend/translations/emails.en.yaml`
- Modify: `backend/translations/emails.de.yaml`
- Modify: `backend/tests/Service/Mail/AccountMailerTest.php`

- [ ] **Step 1: Add the translation keys**

Append to `backend/translations/emails.en.yaml`:

```yaml
admin_pending_approval:
    subject: 'A new user is awaiting approval'
    body: "A new user has registered and needs your approval.\n\nEmail: %applicant_email%\nSigned up via: %method%\nUsers awaiting approval: %pending_count%\n\nReview and approve them here:\n\n%review_url%"
    method_email_password: 'email and password'
```

Append to `backend/translations/emails.de.yaml`:

```yaml
admin_pending_approval:
    subject: 'Ein neuer Nutzer wartet auf Freischaltung'
    body: "Ein neuer Nutzer hat sich registriert und benötigt deine Freischaltung.\n\nE-Mail: %applicant_email%\nRegistriert über: %method%\nNutzer, die auf Freischaltung warten: %pending_count%\n\nHier prüfen und freischalten:\n\n%review_url%"
    method_email_password: 'E-Mail und Passwort'
```

- [ ] **Step 2: Write the failing tests**

In `backend/tests/Service/Mail/AccountMailerTest.php`, add these imports at the top (next to the existing `use` lines):

```php
use App\Dto\Mail\PendingApprovalNotice;
use App\Enum\RegistrationMethod;
```

Add these test methods to the class (e.g. after `testEnglishIsTheDefaultLanguage`):

```php
    private function admin(string $locale = 'en'): User
    {
        $admin = new User('admin@example.com', new \DateTimeImmutable('2026-07-21 12:00:00'));
        $admin->setLocale($locale);

        return $admin;
    }

    public function testPendingApprovalNoticeCarriesApplicantMethodCountAndLink(): void
    {
        $notice = new PendingApprovalNotice(
            'newcomer@example.com',
            RegistrationMethod::EmailPassword,
            null,
            'https://feeds.example.com/admin/users',
            3,
        );

        $this->mailer->sendPendingApprovalNotice($this->admin(), $notice);

        self::assertCount(1, $this->sent);
        $email = $this->sent[0];
        self::assertSame('admin@example.com', $email->getTo()[0]->getAddress());
        self::assertSame('A new user is awaiting approval', $email->getSubject());

        $body = (string) $email->getTextBody();
        self::assertStringContainsString('newcomer@example.com', $body);
        self::assertStringContainsString('email and password', $body);
        self::assertStringContainsString('Users awaiting approval: 3', $body);
        self::assertStringContainsString('https://feeds.example.com/admin/users', $body);
    }

    public function testPendingApprovalNoticeNamesTheOAuthProvider(): void
    {
        $notice = new PendingApprovalNotice(
            'newcomer@example.com',
            RegistrationMethod::OAuth,
            'google',
            'https://feeds.example.com/admin/users',
            1,
        );

        $this->mailer->sendPendingApprovalNotice($this->admin(), $notice);

        self::assertStringContainsString('Signed up via: Google', (string) $this->sent[0]->getTextBody());
    }

    public function testPendingApprovalNoticeIsLocalisedToTheAdmin(): void
    {
        $notice = new PendingApprovalNotice(
            'newcomer@example.com',
            RegistrationMethod::EmailPassword,
            null,
            'https://feeds.example.com/admin/users',
            1,
        );

        $this->mailer->sendPendingApprovalNotice($this->admin('de'), $notice);

        $email = $this->sent[0];
        self::assertSame('Ein neuer Nutzer wartet auf Freischaltung', $email->getSubject());
        self::assertStringContainsString('E-Mail und Passwort', (string) $email->getTextBody());
    }
```

Also add a flush-left guarantee: in `bodyProvider()`, add a case (the provider is static, so build the notice inline):

```php
        yield 'admin pending approval' => [static function (AccountMailer $m) use ($user): void {
            $m->sendPendingApprovalNotice($user, new PendingApprovalNotice(
                'newcomer@example.com',
                \App\Enum\RegistrationMethod::EmailPassword,
                null,
                'https://feeds.example.com/admin/users',
                1,
            ));
        }];
```

- [ ] **Step 3: Run to verify they fail**

Run: `php bin/phpunit tests/Service/Mail/AccountMailerTest.php`
Expected: FAIL — `Call to undefined method App\Service\Mail\AccountMailer::sendPendingApprovalNotice()`.

- [ ] **Step 4: Implement the method**

In `backend/src/Service/Mail/AccountMailer.php`, add these imports:

```php
use App\Dto\Mail\PendingApprovalNotice;
use App\Enum\RegistrationMethod;
```

Add the public method after `sendPasswordReset()`:

```php
    public function sendPendingApprovalNotice(User $admin, PendingApprovalNotice $notice): void
    {
        $this->send($admin, 'admin_pending_approval', [
            '%applicant_email%' => $notice->applicantEmail,
            '%method%' => $this->methodLabel($notice, $admin->getLocale()),
            '%pending_count%' => (string) $notice->pendingApprovalCount,
            '%review_url%' => $notice->reviewUrl,
        ]);
    }
```

And add the private helper (e.g. after `link()`):

```php
    private function methodLabel(PendingApprovalNotice $notice, string $locale): string
    {
        if (RegistrationMethod::OAuth === $notice->method) {
            \assert(null !== $notice->oauthProvider);

            return ucfirst($notice->oauthProvider);
        }

        return $this->translator->trans('admin_pending_approval.method_email_password', [], 'emails', $locale);
    }
```

- [ ] **Step 5: Run to verify they pass**

Run: `php bin/phpunit tests/Service/Mail/AccountMailerTest.php`
Expected: PASS (all cases, including the new flush-left body case).

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Mail/AccountMailer.php backend/translations/emails.en.yaml backend/translations/emails.de.yaml backend/tests/Service/Mail/AccountMailerTest.php
git commit -m "feat(mail): compose the admin pending-approval notice (#164)"
```

---

### Task 5: Listener + password-path dispatch (end-to-end)

This task makes the password path work all the way through: the listener, the dispatch in `RegistrationService::verifyEmail`, and the functional tests that prove the whole chain (real dispatch → listener → deferred mail flushed on `kernel.terminate`).

**Files:**
- Create: `backend/src/EventListener/NotifyAdminsOfPendingApproval.php`
- Modify: `backend/src/Service/Auth/RegistrationService.php`
- Modify: `backend/tests/Support/UserFactory.php`
- Modify: `backend/tests/Service/Auth/RegistrationServiceTest.php`
- Modify: `backend/tests/Controller/Api/RegistrationTest.php`

- [ ] **Step 1: Create the listener**

`backend/src/EventListener/NotifyAdminsOfPendingApproval.php`:

```php
<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Dto\Mail\PendingApprovalNotice;
use App\Enum\UserStatus;
use App\Event\UserAwaitingApproval;
use App\Repository\UserRepository;
use App\Service\Mail\AccountMailer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Tells the admins who can act on it that a new account is waiting. One mail per
 * active admin, each in that admin's own language; the send is deferred, so this
 * adds nothing to the latency of the request that triggered the approval.
 */
#[AsEventListener(event: UserAwaitingApproval::class, method: '__invoke')]
final readonly class NotifyAdminsOfPendingApproval
{
    public function __construct(
        private UserRepository $users,
        private AccountMailer $mailer,
        private LoggerInterface $logger,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
    ) {
    }

    public function __invoke(UserAwaitingApproval $event): void
    {
        $admins = $this->users->findActiveAdmins();
        if ([] === $admins) {
            // Not an error: a single-admin instance whose one admin is the
            // person being approved, or a fresh install before the first admin
            // exists, both land here. Nothing to send, and nothing is wrong.
            $this->logger->debug('User entered the approval queue but there are no active admins to notify.');

            return;
        }

        $notice = new PendingApprovalNotice(
            $event->user->getEmail(),
            $event->method,
            $event->oauthProvider,
            rtrim($this->frontendUrl, '/') . '/admin/users',
            $this->users->countByStatus(UserStatus::PendingApproval),
        );

        foreach ($admins as $admin) {
            $this->mailer->sendPendingApprovalNotice($admin, $notice);
        }
    }
}
```

- [ ] **Step 2: Add a `locale` parameter to `UserFactory`**

In `backend/tests/Support/UserFactory.php`, change the `create()` signature and body so tests can seed a `de` admin. Replace the method with:

```php
    /**
     * $passwordChangedAt defaults to the fixed createdAt rather than to "now".
     * Tokens minted during a test therefore always carry an `iat` well after
     * it, so App\Security\PasswordChangeTokenInvalidator stays out of the way
     * of fixtures that are not about password changes. Tests that DO exercise
     * the boundary set the stamp explicitly.
     *
     * @param list<string> $roles
     */
    public function create(
        string $email,
        string $password = 'correct-horse-battery',
        UserStatus $status = UserStatus::Active,
        array $roles = [],
        string $locale = 'en',
    ): User {
        $createdAt = new \DateTimeImmutable('2026-07-01 10:00:00');
        $user = new User($email, $createdAt);
        $user->setStatus($status);
        $user->setRoles($roles);
        $user->setLocale($locale);
        $user->setPasswordHash($this->hasher->hashPassword($user, $password), $createdAt);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
```

- [ ] **Step 3: Write the failing functional tests**

In `backend/tests/Controller/Api/RegistrationTest.php`, add these tests to the class (after `testVerificationMovesTheUserToPendingApproval`). They reuse the file's existing `register()`, `post()`, `tokenFromMail()`, `factory()` and `users()` helpers.

```php
    public function testVerificationNotifiesAnActiveAdmin(): void
    {
        $this->factory()->create('boss@example.com', status: UserStatus::Active, roles: ['ROLE_ADMIN']);

        $this->register();
        $token = $this->tokenFromMail();

        $this->post('/api/auth/verify-email', ['token' => $token]);
        self::assertResponseIsSuccessful();

        // The kernel reboots between requests, so the only mail attributable to
        // this verify-email request is the admin notification.
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('boss@example.com', $mail->getTo()[0]->getAddress());

        $body = (string) $mail->getTextBody();
        self::assertStringContainsString('newcomer@example.com', $body);
        self::assertStringContainsString('/admin/users', $body);
        self::assertStringContainsString('Users awaiting approval: 1', $body);
    }

    public function testEachActiveAdminIsNotifiedInTheirOwnLanguage(): void
    {
        $this->factory()->create('en-boss@example.com', status: UserStatus::Active, roles: ['ROLE_ADMIN'], locale: 'en');
        $this->factory()->create('de-boss@example.com', status: UserStatus::Active, roles: ['ROLE_ADMIN'], locale: 'de');

        $this->register();
        $this->post('/api/auth/verify-email', ['token' => $this->tokenFromMail()]);

        self::assertEmailCount(2);

        $byRecipient = [];
        foreach (self::getMailerMessages() as $message) {
            self::assertInstanceOf(Email::class, $message);
            $byRecipient[$message->getTo()[0]->getAddress()] = $message->getSubject();
        }

        self::assertSame('A new user is awaiting approval', $byRecipient['en-boss@example.com']);
        self::assertSame('Ein neuer Nutzer wartet auf Freischaltung', $byRecipient['de-boss@example.com']);
    }

    public function testInactiveAdminsAndNonAdminsAreNotNotified(): void
    {
        $this->factory()->create('suspended-boss@example.com', status: UserStatus::Suspended, roles: ['ROLE_ADMIN']);
        $this->factory()->create('plain-user@example.com', status: UserStatus::Active);

        $this->register();
        $this->post('/api/auth/verify-email', ['token' => $this->tokenFromMail()]);

        self::assertEmailCount(0);
    }

    public function testVerificationWithNoActiveAdminSendsNothingAndDoesNotError(): void
    {
        $this->register();

        $this->post('/api/auth/verify-email', ['token' => $this->tokenFromMail()]);

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'pending_approval'], $this->payload());
        self::assertEmailCount(0);
    }
```

- [ ] **Step 4: Run to verify they fail**

Run: `php bin/phpunit tests/Controller/Api/RegistrationTest.php --filter Notif`
Expected: FAIL — no notification is sent yet (`assertEmailCount(1)` sees 0), because `RegistrationService` does not dispatch.

- [ ] **Step 5: Dispatch from `RegistrationService::verifyEmail`**

In `backend/src/Service/Auth/RegistrationService.php`:

Add imports:

```php
use App\Enum\RegistrationMethod;
use App\Event\UserAwaitingApproval;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
```

Add the dispatcher to the constructor (append as the last promoted property):

```php
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private UserPasswordHasherInterface $hasher,
        private ActionTokenService $tokens,
        private AccountMailer $mailer,
        private ClockInterface $clock,
        private PasswordWorkEqualizerInterface $work,
        private EventDispatcherInterface $events,
    ) {
    }
```

Dispatch after the flush inside `verifyEmail()`. Replace the `if` block:

```php
        // Re-verifying an already-approved account must not demote it back to
        // the admin queue.
        if (UserStatus::PendingVerification === $user->getStatus()) {
            $user->setStatus(UserStatus::PendingApproval);
            $this->em->flush();

            // After the flush: the account is now persisted in the queue, so a
            // listener that counts it sees the true number, and a failed flush
            // above means no notification goes out.
            $this->events->dispatch(new UserAwaitingApproval($user, RegistrationMethod::EmailPassword));
        }
```

- [ ] **Step 6: Fix the direct-construction unit test**

In `backend/tests/Service/Auth/RegistrationServiceTest.php`, the helper builds `RegistrationService` directly and now needs the dispatcher. Add the import:

```php
use Symfony\Component\EventDispatcher\EventDispatcher;
```

Add `new EventDispatcher()` as the final argument of the `new RegistrationService(...)` call:

```php
        return new RegistrationService(
            $this->em,
            $blindRepository,
            $hasher,
            $tokens,
            $mailer,
            $clock,
            $work,
            new EventDispatcher(),
        );
```

- [ ] **Step 7: Run to verify everything passes**

Run: `php bin/phpunit tests/Controller/Api/RegistrationTest.php tests/Service/Auth/RegistrationServiceTest.php`
Expected: PASS (new notification tests green; existing registration/verify tests unaffected because they seed no active admin).

- [ ] **Step 8: Guard against a regression in the shared onboarding journey**

Run: `php bin/phpunit tests/Controller/Api/AuthJourneyTest.php`
Expected: PASS. (This test verifies email at step 3 with an admin present; its `assertEmailCount(1)` is after a later, separate approve request, so the reboot-scoped mail log is unaffected. If it fails, the cause is a mistaken dispatch on the wrong request boundary — re-check Step 5.)

- [ ] **Step 9: Commit**

```bash
git add backend/src/EventListener/NotifyAdminsOfPendingApproval.php backend/src/Service/Auth/RegistrationService.php backend/tests/Support/UserFactory.php backend/tests/Service/Auth/RegistrationServiceTest.php backend/tests/Controller/Api/RegistrationTest.php
git commit -m "feat(auth): email active admins when email verification queues a user (#164)"
```

---

### Task 6: OAuth-path dispatch

Users also enter the queue through OAuth: a brand-new signup (`createUser`) and an OAuth login claiming a never-verified local account (`claimIfUnverified`). This task makes `resolve()` dispatch once, after flush, for exactly those two cases — and not for a returning identity or an already-decided account.

It also removes a hidden side effect: today `findLinkTarget()` mutates status by calling `claimUnverifiedAccount()`. The claim moves up to `resolve()` so the transition is explicit and the "did they just enter the queue?" answer is local to where the notification is dispatched.

**Files:**
- Modify: `backend/src/Service/OAuth/OAuthAccountLinker.php`
- Modify: `backend/tests/Service/OAuth/OAuthAccountLinkerTest.php`

- [ ] **Step 1: Write the failing dispatch tests**

In `backend/tests/Service/OAuth/OAuthAccountLinkerTest.php`, add imports:

```php
use App\Enum\RegistrationMethod;
use App\Event\UserAwaitingApproval;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
```

Change the `linker()` helper to accept an optional dispatcher (existing callers pass nothing and get a no-op dispatcher):

```php
    private function linker(?EventDispatcherInterface $events = null): OAuthAccountLinker
    {
        /** @var \App\Repository\UserRepository $users */
        $users = $this->em->getRepository(User::class);
        /** @var \App\Repository\UserIdentityRepository $identities */
        $identities = $this->em->getRepository(UserIdentity::class);

        return new OAuthAccountLinker(
            $this->em,
            $users,
            $identities,
            new MockClock(self::NOW),
            $events ?? new EventDispatcher(),
        );
    }

    /**
     * @return array{EventDispatcher, list<UserAwaitingApproval>}
     */
    private function recordingDispatcher(): array
    {
        $captured = [];
        $events = new EventDispatcher();
        $events->addListener(
            UserAwaitingApproval::class,
            static function (UserAwaitingApproval $event) use (&$captured): void {
                $captured[] = $event;
            },
        );

        return [$events, &$captured];
    }
```

Add the tests:

```php
    public function testANewOAuthAccountAnnouncesItselfToTheApprovalQueue(): void
    {
        [$events, $captured] = $this->recordingDispatcher();

        $this->linker($events)->resolve(new OAuthIdentity('google', 'sub-1', 'new@example.com', true));

        self::assertCount(1, $captured);
        self::assertSame(RegistrationMethod::OAuth, $captured[0]->method);
        self::assertSame('google', $captured[0]->oauthProvider);
        self::assertSame('new@example.com', $captured[0]->user->getEmail());
    }

    public function testClaimingAnUnverifiedAccountAnnouncesItToTheApprovalQueue(): void
    {
        $this->persistUser('bob@example.com', UserStatus::PendingVerification);
        [$events, $captured] = $this->recordingDispatcher();

        $this->linker($events)->resolve(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        self::assertCount(1, $captured);
        self::assertSame(RegistrationMethod::OAuth, $captured[0]->method);
    }

    public function testAReturningIdentityAnnouncesNothing(): void
    {
        $user = $this->persistUser('bob@example.com', UserStatus::Active);
        $this->em->persist(new UserIdentity($user, 'google', 'sub-1', $this->now()));
        $this->em->flush();
        [$events, $captured] = $this->recordingDispatcher();

        $this->linker($events)->resolve(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        self::assertSame([], $captured);
    }

    public function testLinkingToAnAlreadyActiveAccountAnnouncesNothing(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        [$events, $captured] = $this->recordingDispatcher();

        $this->linker($events)->resolve(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        self::assertSame([], $captured);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/OAuth/OAuthAccountLinkerTest.php`
Expected: FAIL — the `OAuthAccountLinker` constructor still takes 4 arguments (`Too few arguments` / the new tests see 0 captured events).

- [ ] **Step 3: Restructure `OAuthAccountLinker` and dispatch**

In `backend/src/Service/OAuth/OAuthAccountLinker.php`:

Add imports:

```php
use App\Enum\RegistrationMethod;
use App\Event\UserAwaitingApproval;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
```

Add the dispatcher to the constructor:

```php
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private UserIdentityRepository $identities,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
    ) {
    }
```

Replace `resolve()` so it tracks whether this resolution put someone in the queue, and dispatches once after the flush:

```php
    public function resolve(OAuthIdentity $identity): User
    {
        $existing = $this->identities->findOneByProviderAndSubject(
            $identity->provider,
            $identity->providerUserId,
        );

        if (null !== $existing) {
            return $this->refresh($existing, $identity);
        }

        $linkTarget = $this->findLinkTarget($identity);

        if (null === $linkTarget) {
            $user = $this->createUser($identity);
            $enteredApprovalQueue = true;
        } else {
            $user = $linkTarget;
            $enteredApprovalQueue = $this->claimIfUnverified($linkTarget);
        }

        $this->attach($user, $identity);
        $this->em->flush();

        if ($enteredApprovalQueue) {
            // After the flush, for the same reason RegistrationService dispatches
            // after its own: the account is persisted in the queue before an
            // admin is told to look at it.
            $this->events->dispatch(new UserAwaitingApproval($user, RegistrationMethod::OAuth, $identity->provider));
        }

        return $user;
    }
```

Replace `findLinkTarget()` so it only *finds* (no status mutation) — keep the whole existing docblock, but the body becomes:

```php
    private function findLinkTarget(OAuthIdentity $identity): ?User
    {
        if (!$identity->isLinkableByEmail()) {
            return null;
        }

        \assert(null !== $identity->email);

        return $this->users->findOneByEmail($identity->email);
    }
```

Replace `claimUnverifiedAccount()` with `claimIfUnverified()` — keep the entire existing explanatory docblock above it (it still applies verbatim), and fold in the "every other status is returned untouched" reasoning that previously lived in `findLinkTarget`. The method now decides *and reports* whether it claimed:

```php
    /**
     * <PRESERVE the existing multi-paragraph docblock from claimUnverifiedAccount
     * here verbatim — the HOW THE OWNER GETS BACK IN reasoning, the wipe
     * justification, and the rejected-alternative discussion are all still true.>
     *
     * Returns whether this call promoted the account into the approval queue, so
     * resolve() knows when a fresh approval is now pending. Every status other
     * than pending_verification is returned untouched: OAuth proves an address,
     * it does not overrule an admin, so linking never revives a rejected
     * account, never unsuspends a suspended one, and never re-stamps an active
     * account's password — that last one would revoke the live sessions of a
     * user who did nothing but sign in a second way.
     */
    private function claimIfUnverified(User $user): bool
    {
        if (UserStatus::PendingVerification !== $user->getStatus()) {
            return false;
        }

        $user->setStatus(UserStatus::PendingApproval);
        $user->setPasswordHash(null, $this->clock->now());

        return true;
    }
```

Leave `createUser()`, `refresh()`, `attach()`, `loginIdentifierFor()`, and `placeholderEmail()` unchanged.

- [ ] **Step 4: Run to verify all OAuth tests pass**

Run: `php bin/phpunit tests/Service/OAuth/OAuthAccountLinkerTest.php`
Expected: PASS — the 4 new dispatch tests plus all pre-existing account-resolution tests (their `linker()` calls now pass a no-op dispatcher; behaviour is unchanged).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/OAuth/OAuthAccountLinker.php backend/tests/Service/OAuth/OAuthAccountLinkerTest.php
git commit -m "feat(oauth): email active admins when an OAuth signup queues a user (#164)"
```

---

### Task 7: Full suite + quality gates

**Files:** none new — this task verifies and, if needed, cleans up.

- [ ] **Step 1: Run the whole backend suite (SQLite leg)**

Run: `php bin/phpunit`
Expected: PASS, no errors, no risky/skipped surprises. If any pre-existing test now sees an extra mail, it means an active admin is seeded in a test that also drives a real verify/OAuth transition and asserts a count in that same request — reconcile by asserting the specific recipient rather than a raw count, but per the design scan this should not occur.

- [ ] **Step 2: Warm the cache and run PHPStan (max)**

Run: `php bin/console cache:warmup && composer stan`
Expected: no errors. (PHPStan needs a warm dev cache to resolve the container.)

- [ ] **Step 3: Coding standards + autofix**

Run: `composer cs`
If it reports fixable issues: `composer cs:fix` then `composer cs` again.
Expected: clean.

- [ ] **Step 4: PHPMD on every touched `src` file**

Run: `composer md`
Expected: clean. The standing rule is that each touched `src` file is PHPMD-clean, not merely free of new findings. Files touched: `Repository/UserRepository.php`, `Service/Mail/AccountMailer.php`, `Service/Auth/RegistrationService.php`, `Service/OAuth/OAuthAccountLinker.php`, plus the four new `src` files.

- [ ] **Step 5: PhpStorm inspections on changed PHP**

Run the `mcp__phpstorm__lint_files` tool over the created/modified `src` files (and the changed tests). Block on ERROR and WARNING; weak warnings are advisory.

- [ ] **Step 6: Scan the dev log**

Run: `tail -n 60 backend/var/log/dev.log`
Expected: no new deprecations or swallowed errors from the run.

- [ ] **Step 7: Commit any cleanup**

```bash
git add -A
git commit -m "chore(auth): satisfy quality gates for admin approval notifications (#164)"
```

(If Steps 1–6 produced no changes, skip this commit.)

---

## MySQL leg (before the PR is merged)

`findActiveAdmins()` and `countByStatus()` are dialect-sensitive. CI runs the MySQL leg, but if a Docker stack is up locally you can pre-flight it:

```bash
docker compose exec php vendor/bin/phpunit tests/Repository/UserRepositoryTest.php tests/Service/OAuth/OAuthAccountLinkerTest.php
```

---

## Self-Review

**Spec coverage:**
- Active-admins-only recipients → `UserRepository::findActiveAdmins()` (Task 3) + listener (Task 5). ✓
- Both entry paths → `RegistrationService::verifyEmail` dispatch (Task 5) + `OAuthAccountLinker::resolve` dispatch (Task 6). ✓
- Per-recipient locale → `AccountMailer::sendPendingApprovalNotice` reuses `send()`'s recipient-locale rendering (Task 4); asserted in Task 5 Step 3. ✓
- Content: applicant email + signup method + pending count + deep link → translations + `PendingApprovalNotice` (Tasks 2, 4). ✓
- Deep link reuses `APP_FRONTEND_URL` → listener (Task 5). ✓
- Dispatch after flush for an accurate count → Tasks 5 and 6. ✓
- No spurious notifications (returning identity / already-decided account) → Task 6 dispatch tests. ✓
- No active admins → quiet return → Task 5 listener + `testVerificationWithNoActiveAdminSendsNothingAndDoesNotError`. ✓
- Native-iOS-safe, no new endpoint → nothing client-facing added. ✓

**Placeholder scan:** The only intentional prose placeholder is the `<PRESERVE …>` marker in Task 6 Step 3, which instructs the implementer to carry the existing docblock verbatim rather than re-typing ~40 lines of security reasoning. All code steps contain complete code.

**Type consistency:** `RegistrationMethod` (`EmailPassword`/`OAuth`), `UserAwaitingApproval(User, RegistrationMethod, ?string)`, `PendingApprovalNotice(string, RegistrationMethod, ?string, string, int)`, `findActiveAdmins(): list<User>`, `countByStatus(UserStatus): int`, `sendPendingApprovalNotice(User, PendingApprovalNotice)` are used identically everywhere they appear. The dispatcher is type-hinted `Symfony\Contracts\EventDispatcher\EventDispatcherInterface` in services and constructed as `Symfony\Component\EventDispatcher\EventDispatcher` in tests (the concrete class implements that interface). ✓
