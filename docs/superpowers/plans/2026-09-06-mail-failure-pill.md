# Automated-email failure pill + recent-error log Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist automated-email and manual-test send failures and surface them as a red pill + expandable error log in admin Mail, shown only when the last send failed.

**Architecture:** A new `mail_send_failure` table, written by a small `MailDeliveryHealth` service from all three send paths (digest, deferred account mail, manual test). Any successful send clears the table (clear-on-success, global), so "count > 0" means "the last send failed". A thin admin endpoint exposes the rows; the admin Mail section renders a disclosure card mirroring the Organise feed-health card.

**Tech Stack:** Symfony 7.4 / PHP 8.4 backend (Doctrine ORM, PHPUnit), Angular 20 / signals frontend (Jest).

**Spec:** [docs/superpowers/specs/2026-09-06-mail-failure-pill-design.md](../specs/2026-09-06-mail-failure-pill-design.md)

## Global Constraints

- **PHP:** `declare(strict_types=1)` in every file; PSR-12; PHPStan level max; PHPMD codesize clean on every touched file; `final readonly` house style; guard clauses; typed namespaced exceptions; no boolean flag params; thin controllers (no private methods carrying work). Datetimes persisted as **naive UTC** — normalise `clock->now()` to UTC before persisting (Strato workers run Europe/Berlin).
- **Backend gate:** `composer check` (cs + stan + tramp) and `composer md` and `php bin/phpunit` all green.
- **Frontend:** standalone components + signals; component styles in sibling `.scss` (never inline); **no hex colours, no raw `px`, no media-query literals** in `.scss` outside `src/app/theme/` — use tokens; copy added to **both** `public/i18n/en.json` and `public/i18n/de.json`.
- **Frontend gate:** `npm run check`; run frontend Jest inside Docker: `docker compose exec -T frontend npm test`.
- **Native-iOS readiness:** the endpoint is JSON in / `application/problem+json` out, bearer-auth only — no cookie, no browser-only input, no `text/html` fallback.
- **Retention constant:** `MailSendFailureRepository::RETENTION = 50`.
- **MailKind values:** `Digest = 'digest'`, `Account = 'account'`, `Test = 'test'`.
- **Working dir:** backend commands run from `backend/`, frontend from `frontend/`. Branch: `feature/882-mail-failure-pill` (already created off `develop`).
- **Commit format:** `type(#882): summary`.

---

### Task 1: Persistence layer — `MailKind`, `MailSendFailure`, repository, migration

**Files:**
- Create: `backend/src/Entity/MailKind.php`
- Create: `backend/src/Entity/MailSendFailure.php`
- Create: `backend/src/Repository/MailSendFailureRepository.php`
- Create: `backend/migrations/VersionYYYYMMDDHHMMSS.php` (generated)
- Test: `backend/tests/Repository/MailSendFailureRepositoryTest.php`

**Interfaces:**
- Produces:
  - `enum MailKind: string { case Digest = 'digest'; case Account = 'account'; case Test = 'test'; }`
  - `new MailSendFailure(MailKind $kind, string $recipient, string $errorDetail, \DateTimeImmutable $createdAt)`; getters `getId(): ?int`, `getKind(): MailKind`, `getRecipient(): string`, `getErrorDetail(): string`, `getCreatedAt(): \DateTimeImmutable`.
  - `MailSendFailureRepository`: `const int RETENTION = 50`; `add(MailSendFailure $failure): void`; `deleteAll(): void`; `recent(int $limit): list<MailSendFailure>` (newest first); `countAll(): int`.

- [ ] **Step 1: Write the failing repository test**

Create `backend/tests/Repository/MailSendFailureRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\MailKind;
use App\Entity\MailSendFailure;
use App\Repository\MailSendFailureRepository;
use App\Tests\DbTestCase;

final class MailSendFailureRepositoryTest extends DbTestCase
{
    private MailSendFailureRepository $failures;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var MailSendFailureRepository $failures */
        $failures = self::getContainer()->get(MailSendFailureRepository::class);
        $this->failures = $failures;
    }

    public function testAddPersistsAndRecentReturnsNewestFirst(): void
    {
        $this->failures->add($this->failure('a@example.test', '2026-09-06T10:00:00Z'));
        $this->failures->add($this->failure('b@example.test', '2026-09-06T11:00:00Z'));

        $recent = $this->failures->recent(10);

        self::assertCount(2, $recent);
        self::assertSame('b@example.test', $recent[0]->getRecipient());
        self::assertSame('a@example.test', $recent[1]->getRecipient());
        self::assertSame(2, $this->failures->countAll());
    }

    public function testDeleteAllClearsTheTable(): void
    {
        $this->failures->add($this->failure('a@example.test', '2026-09-06T10:00:00Z'));

        $this->failures->deleteAll();

        self::assertSame(0, $this->failures->countAll());
        self::assertSame([], $this->failures->recent(10));
    }

    public function testAddPrunesToRetentionNewestFirst(): void
    {
        for ($minute = 0; $minute < MailSendFailureRepository::RETENTION + 5; ++$minute) {
            $stamp = sprintf('2026-09-06T10:%02d:00Z', $minute);
            $this->failures->add($this->failure("user{$minute}@example.test", $stamp));
        }

        self::assertSame(MailSendFailureRepository::RETENTION, $this->failures->countAll());
        // The five oldest were pruned; the newest survives.
        self::assertSame(
            'user' . (MailSendFailureRepository::RETENTION + 4) . '@example.test',
            $this->failures->recent(1)[0]->getRecipient(),
        );
    }

    private function failure(string $recipient, string $createdAt): MailSendFailure
    {
        return new MailSendFailure(
            MailKind::Digest,
            $recipient,
            'SMTP transport failed',
            new \DateTimeImmutable($createdAt),
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Repository/MailSendFailureRepositoryTest.php`
Expected: FAIL — classes `MailKind` / `MailSendFailure` / `MailSendFailureRepository` do not exist.

- [ ] **Step 3: Create the `MailKind` enum**

`backend/src/Entity/MailKind.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

/** Which automated (or manually triggered) mail failed to send (#882). */
enum MailKind: string
{
    case Digest = 'digest';
    case Account = 'account';
    case Test = 'test';
}
```

- [ ] **Step 4: Create the `MailSendFailure` entity**

`backend/src/Entity/MailSendFailure.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MailSendFailureRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One failed outgoing-mail attempt (#882): the intended recipient, which kind
 * of mail it was, and the transport message already run through
 * {@see \App\Service\Fetch\ProxyHandshakeFailure::explain()} for proxied SMTP
 * (#880). Cleared wholesale on the next successful send, so the table only ever
 * holds failures since the last success.
 */
#[ORM\Entity(repositoryClass: MailSendFailureRepository::class)]
#[ORM\Table(name: 'mail_send_failure')]
class MailSendFailure
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: MailKind::class)]
    private MailKind $kind;

    #[ORM\Column(length: 255)]
    private string $recipient;

    #[ORM\Column(type: Types::TEXT)]
    private string $errorDetail;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        MailKind $kind,
        string $recipient,
        string $errorDetail,
        \DateTimeImmutable $createdAt,
    ) {
        $this->kind = $kind;
        $this->recipient = $recipient;
        $this->errorDetail = $errorDetail;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): MailKind
    {
        return $this->kind;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function getErrorDetail(): string
    {
        return $this->errorDetail;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
```

- [ ] **Step 5: Create the repository**

`backend/src/Repository/MailSendFailureRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MailSendFailure;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The failure log stays bounded: {@see self::add()} prunes to the newest
 * RETENTION rows on every write, so a proxy outage sending every five minutes
 * for hours cannot grow it without limit (#882).
 *
 * @extends ServiceEntityRepository<MailSendFailure>
 */
final class MailSendFailureRepository extends ServiceEntityRepository
{
    public const int RETENTION = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailSendFailure::class);
    }

    public function add(MailSendFailure $failure): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($failure);
        $manager->flush();

        $this->pruneToRetention();
    }

    public function deleteAll(): void
    {
        $this->createQueryBuilder('f')->delete()->getQuery()->execute();
    }

    /** @return list<MailSendFailure> newest first */
    public function recent(int $limit): array
    {
        /** @var list<MailSendFailure> $rows */
        $rows = $this->createQueryBuilder('f')
            ->orderBy('f.createdAt', 'DESC')
            ->addOrderBy('f.id', 'DESC')
            ->setMaxResults(min($limit, self::RETENTION))
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countAll(): int
    {
        return $this->count([]);
    }

    private function pruneToRetention(): void
    {
        /** @var list<int> $ids */
        $ids = array_column(
            $this->createQueryBuilder('f')
                ->select('f.id AS id')
                ->orderBy('f.createdAt', 'DESC')
                ->addOrderBy('f.id', 'DESC')
                ->getQuery()
                ->getArrayResult(),
            'id',
        );

        $overflow = array_slice($ids, self::RETENTION);
        if ([] === $overflow) {
            return;
        }

        $this->createQueryBuilder('f')
            ->delete()
            ->where('f.id IN (:ids)')
            ->setParameter('ids', $overflow)
            ->getQuery()
            ->execute();
    }
}
```

- [ ] **Step 6: Run the repository test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Repository/MailSendFailureRepositoryTest.php`
Expected: PASS (3 tests). The suite builds the schema from ORM metadata, so no migration is needed for the test to pass.

- [ ] **Step 7: Generate and verify the migration**

Run:
```bash
cd backend && bin/console doctrine:migrations:diff --no-interaction
```
Open the generated `migrations/VersionYYYYMMDDHHMMSS.php`. It must `CREATE TABLE mail_send_failure` and drop it in `down()`, and contain **no unrelated schema changes** (if it does, the dev DB is behind — delete the file, run `doctrine:migrations:migrate --no-interaction`, and diff again). Keep the generated docblock/description trimmed to one line.

Then prove it applies from empty on both dialects the way CI does:
```bash
cd backend && bin/console doctrine:migrations:migrate --no-interaction && bin/console doctrine:schema:validate
```
Expected: migration applies; schema validate reports mapping and database in sync.

- [ ] **Step 8: Static analysis on the new files**

Run: `cd backend && bin/console cache:warmup && composer cs && composer stan && composer md`
Expected: all clean. (`composer stan` needs the warm cache.)

- [ ] **Step 9: Commit**

```bash
cd backend && git add src/Entity/MailKind.php src/Entity/MailSendFailure.php src/Repository/MailSendFailureRepository.php tests/Repository/MailSendFailureRepositoryTest.php migrations/
git commit -m "feat(#882): persist mail send failures with bounded retention"
```

---

### Task 2: `MailDeliveryHealth` service + JSON mapper

**Files:**
- Create: `backend/src/Service/Mail/MailDeliveryHealth.php`
- Create: `backend/src/Http/MailDeliveryHealthJson.php`
- Test: `backend/tests/Service/Mail/MailDeliveryHealthTest.php`

**Interfaces:**
- Consumes: `MailSendFailureRepository` (Task 1), `MailKind` (Task 1).
- Produces:
  - `MailDeliveryHealth::recordFailure(MailKind $kind, string $recipient, string $error): void`
  - `MailDeliveryHealth::recordSuccess(): void`
  - `MailDeliveryHealth::view(): array{count: int, failures: list<array{kind: string, recipient: string, error: string, at: string}>}`
  - `MailDeliveryHealthJson::view(list<MailSendFailure> $recent, int $count): array{count: int, failures: list<...>}`

- [ ] **Step 1: Write the failing service test**

`backend/tests/Service/Mail/MailDeliveryHealthTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\MailKind;
use App\Repository\MailSendFailureRepository;
use App\Service\Mail\MailDeliveryHealth;
use App\Tests\DbTestCase;

final class MailDeliveryHealthTest extends DbTestCase
{
    private MailDeliveryHealth $health;
    private MailSendFailureRepository $failures;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var MailDeliveryHealth $health */
        $health = self::getContainer()->get(MailDeliveryHealth::class);
        $this->health = $health;
        /** @var MailSendFailureRepository $failures */
        $failures = self::getContainer()->get(MailSendFailureRepository::class);
        $this->failures = $failures;
    }

    public function testRecordFailurePersistsAViewableRow(): void
    {
        $this->health->recordFailure(MailKind::Digest, 'reader@example.test', 'SMTP is down');

        $view = $this->health->view();

        self::assertSame(1, $view['count']);
        self::assertSame('digest', $view['failures'][0]['kind']);
        self::assertSame('reader@example.test', $view['failures'][0]['recipient']);
        self::assertSame('SMTP is down', $view['failures'][0]['error']);
        self::assertNotEmpty($view['failures'][0]['at']);
    }

    public function testRecordSuccessClearsEveryStoredFailure(): void
    {
        $this->health->recordFailure(MailKind::Digest, 'reader@example.test', 'SMTP is down');
        $this->health->recordFailure(MailKind::Account, 'new@example.test', 'relay refused');

        $this->health->recordSuccess();

        self::assertSame(0, $this->health->view()['count']);
        self::assertSame(0, $this->failures->countAll());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Mail/MailDeliveryHealthTest.php`
Expected: FAIL — `MailDeliveryHealth` does not exist.

- [ ] **Step 3: Create the JSON mapper**

`backend/src/Http/MailDeliveryHealthJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\MailSendFailure;

/** Wire shape for the admin mail failure log (#882). */
final class MailDeliveryHealthJson
{
    /**
     * @param list<MailSendFailure> $recent
     *
     * @return array{count: int, failures: list<array{kind: string, recipient: string, error: string, at: string}>}
     */
    public static function view(array $recent, int $count): array
    {
        return [
            'count' => $count,
            'failures' => array_map(
                static fn (MailSendFailure $failure): array => [
                    'kind' => $failure->getKind()->value,
                    'recipient' => $failure->getRecipient(),
                    'error' => $failure->getErrorDetail(),
                    'at' => $failure->getCreatedAt()->format(\DATE_ATOM),
                ],
                $recent,
            ),
        ];
    }
}
```

- [ ] **Step 4: Create the service**

`backend/src/Service/Mail/MailDeliveryHealth.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\MailKind;
use App\Entity\MailSendFailure;
use App\Http\MailDeliveryHealthJson;
use App\Repository\MailSendFailureRepository;
use Psr\Clock\ClockInterface;

/**
 * The in-app signal that automated mail is failing (#882). Every send path
 * records its outcome here; any success clears the whole log, so a non-empty
 * log means the most recent send failed.
 */
final readonly class MailDeliveryHealth
{
    public function __construct(
        private MailSendFailureRepository $failures,
        private ClockInterface $clock,
    ) {
    }

    public function recordFailure(MailKind $kind, string $recipient, string $error): void
    {
        // Naive UTC: the Strato workers run Europe/Berlin, so normalise before persisting.
        $occurredAt = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

        $this->failures->add(new MailSendFailure($kind, $recipient, $error, $occurredAt));
    }

    public function recordSuccess(): void
    {
        $this->failures->deleteAll();
    }

    /**
     * @return array{count: int, failures: list<array{kind: string, recipient: string, error: string, at: string}>}
     */
    public function view(): array
    {
        return MailDeliveryHealthJson::view(
            $this->failures->recent(MailSendFailureRepository::RETENTION),
            $this->failures->countAll(),
        );
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Mail/MailDeliveryHealthTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Static analysis**

Run: `cd backend && composer cs && composer stan && composer md`
Expected: clean.

- [ ] **Step 7: Commit**

```bash
cd backend && git add src/Service/Mail/MailDeliveryHealth.php src/Http/MailDeliveryHealthJson.php tests/Service/Mail/MailDeliveryHealthTest.php
git commit -m "feat(#882): add MailDeliveryHealth service and JSON mapper"
```

---

### Task 3: Admin endpoint `GET /api/admin/mail/errors`

**Files:**
- Modify: `backend/src/Controller/Admin/AdminMailController.php`
- Test: `backend/tests/Controller/Admin/AdminMailErrorsControllerTest.php`

**Interfaces:**
- Consumes: `MailDeliveryHealth::view()` (Task 2), `MailDeliveryHealth::recordFailure()` (to seed a row in the test).
- Produces: route `api_admin_mail_errors` at `GET /api/admin/mail/errors` returning `{count, failures[]}`.

- [ ] **Step 1: Write the failing controller test**

`backend/tests/Controller/Admin/AdminMailErrorsControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\MailKind;
use App\Entity\User;
use App\Service\Mail\MailDeliveryHealth;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** `/api/admin/mail/errors` is covered by the `^/api/admin/` ROLE_ADMIN prefix rule. */
final class AdminMailErrorsControllerTest extends ApiTestCase
{
    private const string ERRORS = '/api/admin/mail/errors';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
    }

    public function testItReturnsTheRecentFailuresAndCountForAnAdmin(): void
    {
        /** @var MailDeliveryHealth $health */
        $health = self::getContainer()->get(MailDeliveryHealth::class);
        $health->recordFailure(MailKind::Digest, 'reader@example.test', 'SMTP is down');

        $admin = $this->factory()->create('boss@example.com', roles: ['ROLE_ADMIN']);
        $this->client->request(
            'GET',
            self::ERRORS,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(1, $body['count']);
        self::assertSame('digest', $body['failures'][0]['kind']);
        self::assertSame('reader@example.test', $body['failures'][0]['recipient']);
    }

    public function testItRefusesAnAnonymousRequest(): void
    {
        $this->client->request('GET', self::ERRORS);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    private function tokenFor(User $user): string
    {
        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);

        return $manager->create($user);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Controller/Admin/AdminMailErrorsControllerTest.php`
Expected: FAIL — route does not exist (404 for the admin case).

- [ ] **Step 3: Add the thin action**

In `backend/src/Controller/Admin/AdminMailController.php`, add the import and a new action (do not add any private helper — keep the controller thin):

```php
use App\Service\Mail\MailDeliveryHealth;
```

```php
    #[Route('/errors', name: 'api_admin_mail_errors', methods: ['GET'])]
    public function errors(MailDeliveryHealth $health): JsonResponse
    {
        return new JsonResponse($health->view());
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Controller/Admin/AdminMailErrorsControllerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Static analysis (incl. ThinControllerRule)**

Run: `cd backend && composer stan && composer cs && composer md`
Expected: clean — `ThinControllerRule` passes because the action only reads the request and delegates.

- [ ] **Step 6: Commit**

```bash
cd backend && git add src/Controller/Admin/AdminMailController.php tests/Controller/Admin/AdminMailErrorsControllerTest.php
git commit -m "feat(#882): expose recent mail failures on GET /api/admin/mail/errors"
```

---

### Task 4: Wire the digest send path

**Files:**
- Modify: `backend/src/Service/Mail/Digest/SendDueDigests.php`
- Test: `backend/tests/Service/Mail/Digest/SendDueDigestsHealthTest.php`

**Interfaces:**
- Consumes: `MailDeliveryHealth::recordFailure(MailKind::Digest, ...)`, `recordSuccess()` (Task 2); `MailSendFailureRepository::countAll()` (Task 1) in the test.

- [ ] **Step 1: Write the failing functional test**

This drives the real catch block and real persistence. Prefs/entries are stubbed (as in the existing `SendDueDigestsTest`) but `MailDeliveryHealth` and its repository are real, so the failure row is genuinely written.

`backend/tests/Service/Mail/Digest/SendDueDigestsHealthTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Entity\Preferences;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\MailSendFailureRepository;
use App\Repository\PreferencesRepository;
use App\Repository\SavedSearchRepository;
use App\Service\Mail\Digest\DigestComposer;
use App\Service\Mail\Digest\DigestEntryFinder;
use App\Service\Mail\Digest\DigestLinkBuilder;
use App\Service\Mail\Digest\DigestMailerInterface;
use App\Service\Mail\Digest\DigestModel;
use App\Service\Mail\Digest\DigestSchedule;
use App\Service\Mail\Digest\SendDueDigests;
use App\Service\Mail\MailCapability;
use App\Service\Mail\MailDeliveryHealth;
use App\Tests\DbTestCase;
use App\Tests\Support\FixedPublicBaseUrl;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Exception\TransportException;

final class SendDueDigestsHealthTest extends DbTestCase
{
    private const string NOW = '2026-08-28T09:30:00Z';
    private const string OCCURRENCE = '2026-08-28T08:00:00Z';

    private MailSendFailureRepository $failures;
    private MailDeliveryHealth $health;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var MailSendFailureRepository $failures */
        $failures = self::getContainer()->get(MailSendFailureRepository::class);
        $this->failures = $failures;
        /** @var MailDeliveryHealth $health */
        $health = self::getContainer()->get(MailDeliveryHealth::class);
        $this->health = $health;
    }

    public function testAFailedDigestSendIsRecorded(): void
    {
        $mailer = $this->createMock(DigestMailerInterface::class);
        $mailer->method('send')->willThrowException(new TransportException('SMTP is down'));

        $this->sweep($mailer)->run();

        self::assertSame(1, $this->failures->countAll());
        self::assertSame('reader@example.test', $this->failures->recent(1)[0]->getRecipient());
        self::assertSame('SMTP is down', $this->failures->recent(1)[0]->getErrorDetail());
    }

    public function testASuccessfulDigestSendClearsPriorFailures(): void
    {
        $this->health->recordFailure(\App\Entity\MailKind::Digest, 'old@example.test', 'earlier outage');

        $mailer = $this->createMock(DigestMailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $this->sweep($mailer)->run();

        self::assertSame(0, $this->failures->countAll());
    }

    private function sweep(DigestMailerInterface $mailer): SendDueDigests
    {
        $user = (new User())->setEmail('reader@example.test');
        $user->setEmailVerified(true);
        $prefs = (new Preferences())
            ->setUser($user)
            ->setDigestEnabled(true)
            ->setDigestCadence(\App\Service\Mail\Digest\DigestCadence::Daily)
            ->setDigestHour(8);

        $preferences = $this->createStub(PreferencesRepository::class);
        $preferences->method('findWithDigestEnabled')->willReturn([$prefs]);

        $entries = $this->createStub(EntryListRepository::class);
        $entries->method('page')->willReturn([$this->row()]);

        $savedSearches = $this->createStub(SavedSearchRepository::class);
        $mail = $this->createStub(MailCapability::class);
        $mail->method('isEnabled')->willReturn(true);

        $composer = new DigestComposer(
            new DigestEntryFinder($entries, $savedSearches),
            new DigestLinkBuilder(new FixedPublicBaseUrl()),
        );

        return new SendDueDigests(
            $preferences,
            new DigestSchedule(),
            $composer,
            $mailer,
            $mail,
            new MockClock(new \DateTimeImmutable(self::NOW)),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            $this->health,
        );
    }

    private function row(): EntryListRow
    {
        // Mirror the row shape the existing SendDueDigestsTest builds.
        return EntryListRowFactoryForDigestHealthTest::one(self::OCCURRENCE);
    }
}
```

> **Executor note:** the exact constructors of `Preferences`, `DigestComposer`, `DigestEntryFinder`, `DigestLinkBuilder`, `DigestSchedule`, and `EntryListRow`, plus the entry-row helper, must be copied verbatim from the existing `backend/tests/Service/Mail/Digest/SendDueDigestsTest.php` (read it first — it already assembles exactly this graph). Reuse its private `user()`, `duePreferences()`, `givenOneMatch()` / row helpers rather than the sketch above; the sketch names the intent, the existing test names the real API. If `givenOneMatch()` stubs on `$this->entries`, build the composer against that same stub. The only additions over that file are: extend `DbTestCase`, pull `MailDeliveryHealth` + `MailSendFailureRepository` from the container, pass `$this->health` as the ninth `SendDueDigests` argument, and assert on `$this->failures`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Digest/SendDueDigestsHealthTest.php`
Expected: FAIL — `SendDueDigests::__construct()` has no ninth `MailDeliveryHealth` argument.

- [ ] **Step 3: Wire `MailDeliveryHealth` into `SendDueDigests`**

Add the import and constructor dependency:

```php
use App\Service\Mail\MailDeliveryHealth;
```

Add `private MailDeliveryHealth $health,` as the last promoted constructor parameter. Then change `sendAndAdvance()` so the catch records a failure and the success path records a success:

```php
    private function sendAndAdvance(
        User $user,
        DigestModel $model,
        Preferences $prefs,
        \DateTimeImmutable $occurrence,
    ): ?bool {
        try {
            $this->mailer->send($user, $model);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error(
                'Digest send failed: {userId} <{email}>',
                ['userId' => $user->getId(), 'email' => $user->getEmail(), 'exception' => $e],
            );
            $this->health->recordFailure(MailKind::Digest, $user->getEmail(), $e->getMessage());

            return null;
        }

        $this->health->recordSuccess();
        $prefs->setDigestLastSentAt($occurrence);
        $this->em->flush();

        return true;
    }
```

Add `use App\Entity\MailKind;`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Digest/SendDueDigestsHealthTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the existing digest test — no regression**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Digest/SendDueDigestsTest.php`
Expected: PASS — the existing unit test constructs `SendDueDigests` too; if it fails to compile, update its constructor call to pass a `MailDeliveryHealth` (a stub is fine there).

> **Executor note:** the existing `SendDueDigestsTest` will not compile until its `SendDueDigests` instantiation gets the ninth argument. Add a `MailDeliveryHealth` there — either `$this->createStub(MailDeliveryHealth::class)` or the real one — as part of this task, and keep that test green.

- [ ] **Step 6: Static analysis (watch PHPMD param count)**

Run: `cd backend && composer stan && composer md && composer tramp`
Expected: clean. `SendDueDigests` now has nine constructor params — under the PHPMD `ExcessiveParameterList` limit (10). If `composer tramp` reports something surprising, check `composer show larspohlmann/phptramp` first (CI runs its `develop` tip).

- [ ] **Step 7: Commit**

```bash
cd backend && git add src/Service/Mail/Digest/SendDueDigests.php tests/Service/Mail/Digest/
git commit -m "feat(#882): record digest send failures and clear on success"
```

---

### Task 5: Wire the deferred account-mail path

**Files:**
- Modify: `backend/src/EventListener/DeferredMailFlushListener.php`
- Test: `backend/tests/EventListener/DeferredMailFlushHealthTest.php`

**Interfaces:**
- Consumes: `MailDeliveryHealth` (Task 2), `DeferredMailer` (`send`, container instance), the real `event_dispatcher`.

- [ ] **Step 1: Write the failing tests**

The failure case uses direct construction with a throwing mailer (mirrors the existing `DeferredMailFlushListenerTest::testATransportFailureIsSwallowed...`), but asserts persistence against the **real** repository. The success case goes through the **real dispatcher** to prove the listener actually fires on `TerminateEvent` and clears.

`backend/tests/EventListener/DeferredMailFlushHealthTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\MailKind;
use App\EventListener\DeferredMailFlushListener;
use App\Repository\MailSendFailureRepository;
use App\Service\Mail\DeferredMailer;
use App\Service\Mail\MailDeliveryHealth;
use App\Tests\DbTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class DeferredMailFlushHealthTest extends DbTestCase
{
    public function testAFailedDeferredSendRecordsAnAccountFailure(): void
    {
        /** @var MailDeliveryHealth $health */
        $health = self::getContainer()->get(MailDeliveryHealth::class);
        /** @var MailSendFailureRepository $failures */
        $failures = self::getContainer()->get(MailSendFailureRepository::class);

        $exploding = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('relay refused');
            }
        };
        $deferred = new DeferredMailer($exploding);
        $deferred->send(new Email()->to('new@example.test'), new Envelope(
            new Address('noreply@example.test'),
            [new Address('new@example.test')],
        ));

        (new DeferredMailFlushListener($deferred, new NullLogger(), $health))->onKernelTerminate();

        self::assertSame(1, $failures->countAll());
        self::assertSame(MailKind::Account, $failures->recent(1)[0]->getKind());
        self::assertSame('new@example.test', $failures->recent(1)[0]->getRecipient());
    }

    public function testASuccessfulDeferredFlushViaTheRealDispatcherClearsFailures(): void
    {
        /** @var MailDeliveryHealth $health */
        $health = self::getContainer()->get(MailDeliveryHealth::class);
        $health->recordFailure(MailKind::Digest, 'old@example.test', 'earlier outage');

        /** @var DeferredMailer $deferred */
        $deferred = self::getContainer()->get(DeferredMailer::class);
        $deferred->send(new Email()
            ->from('noreply@example.test')
            ->to('new@example.test')
            ->subject('Verify your email')
            ->text('link'));

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        /** @var HttpKernelInterface $kernel */
        $kernel = self::getContainer()->get(HttpKernelInterface::class);
        $dispatcher->dispatch(
            new TerminateEvent($kernel, Request::create('/'), new Response()),
            'kernel.terminate',
        );

        /** @var MailSendFailureRepository $failures */
        $failures = self::getContainer()->get(MailSendFailureRepository::class);
        self::assertSame(0, $failures->countAll());
    }
}
```

> **Executor note:** confirm `App\Tests\Support\EnablesMailInTests` is not required here — the success test uses the framework test transport, which delivers into the in-memory collector without throwing. If the container's `DeferredMailer` is not fetchable by class id, get it by the id the existing `DeferredMailFlushListenerTest` uses. `new Email()->to(...)` is PHP 8.4 “new without parentheses”; keep the codebase’s existing style if it wraps `(new Email())`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/EventListener/DeferredMailFlushHealthTest.php`
Expected: FAIL — `DeferredMailFlushListener::__construct()` has no third `MailDeliveryHealth` argument.

- [ ] **Step 3: Wire `MailDeliveryHealth` into the listener**

Add imports and the constructor dependency, then record in the loop:

```php
use App\Entity\MailKind;
use App\Service\Mail\MailDeliveryHealth;
use Symfony\Component\Mailer\Envelope;
```

Constructor gains `private MailDeliveryHealth $health,`. Rewrite `flush()`:

```php
    private function flush(): void
    {
        foreach ($this->mailer->take() as [$message, $envelope]) {
            try {
                $this->mailer->sendNow($message, $envelope);
            } catch (\Throwable $exception) {
                $this->logger->error('Deferred mail delivery failed', ['exception' => $exception]);
                $this->health->recordFailure(
                    MailKind::Account,
                    $this->recipientOf($envelope),
                    $exception->getMessage(),
                );

                continue;
            }

            $this->health->recordSuccess();
        }
    }

    private function recipientOf(?Envelope $envelope): string
    {
        if (null === $envelope) {
            return 'unknown';
        }

        $addresses = array_map(
            static fn (Address $address): string => $address->getAddress(),
            $envelope->getRecipients(),
        );

        return [] === $addresses ? 'unknown' : implode(', ', $addresses);
    }
```

Add `use Symfony\Component\Mime\Address;`.

> **Design note for reviewer:** `recipientOf()` is a private helper on an event listener, not a controller — the ThinControllerRule does not apply. It is a single-purpose formatter with no persistence or security decision, which is within Clean Code norms for a listener.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/EventListener/DeferredMailFlushHealthTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the existing listener test — no regression**

Run: `cd backend && php bin/phpunit tests/EventListener/DeferredMailFlushListenerTest.php`
Expected: PASS — its `testATransportFailureIsSwallowed...` constructs the listener directly and now needs the third argument. Update that construction to pass `new MailDeliveryHealth(...)` or a stub as part of this task.

> **Executor note:** update `DeferredMailFlushListenerTest::testATransportFailureIsSwallowedAndTheQueueStillDrains()` to pass a third `MailDeliveryHealth` argument (a stub is fine) so it compiles and stays green.

- [ ] **Step 6: Static analysis**

Run: `cd backend && composer stan && composer cs && composer md`
Expected: clean.

- [ ] **Step 7: Commit**

```bash
cd backend && git add src/EventListener/DeferredMailFlushListener.php tests/EventListener/
git commit -m "feat(#882): record deferred account-mail failures and clear on success"
```

---

### Task 6: Wire the manual test path

**Files:**
- Modify: `backend/src/Service/Mail/Settings/MailConnectionTester.php`
- Test: `backend/tests/Service/Mail/Settings/MailConnectionTesterHealthTest.php`

**Interfaces:**
- Consumes: `MailDeliveryHealth::recordFailure(MailKind::Test, ...)`, `recordSuccess()` (Task 2).

- [ ] **Step 1: Write the failing test**

The tester needs a resolvable transport to reach the send. The simplest deterministic path to a failure is the `no_from_address` branch (a saved row with a blank from-address), which returns `failed('no_from_address')` without touching SMTP. The success path needs a working transport; if that is awkward to assemble in isolation, assert the failure recording (primary behaviour) plus a direct `recordSuccess`-on-ok unit at the service seam.

`backend/tests/Service/Mail/Settings/MailConnectionTesterHealthTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Entity\MailKind;
use App\Repository\MailSendFailureRepository;
use App\Service\Mail\MailDeliveryHealth;
use App\Service\Mail\Settings\MailConnectionTester;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MailConnectionTesterHealthTest extends DbTestCase
{
    public function testAFailedTestRecordsATestFailure(): void
    {
        // Arrange an admin as the acting user and a mail state that fails at
        // 'no_from_address' (a saved SMTP row with a blank from-address and no
        // MAIL_FROM fallback). Reuse the existing MailConnectionTester test's
        // arrangement helpers for the settings/transport graph.
        [$tester, $health, $failures] = $this->testerFailingWithNoFromAddress();

        $result = $tester->test();

        self::assertFalse($result->ok);
        self::assertSame(1, $failures->countAll());
        self::assertSame(MailKind::Test, $failures->recent(1)[0]->getKind());
    }

    public function testASuccessfulTestClearsPriorFailures(): void
    {
        [$tester, $health, $failures] = $this->testerThatSendsSuccessfully();
        $health->recordFailure(MailKind::Digest, 'old@example.test', 'earlier outage');

        $tester->test();

        self::assertSame(0, $failures->countAll());
    }

    // testerFailingWithNoFromAddress() and testerThatSendsSuccessfully() build
    // MailConnectionTester with a real MailDeliveryHealth + repository from the
    // container and the settings/transport collaborators arranged as in the
    // existing MailConnectionTester test. Return [tester, health, failures].
}
```

> **Executor note:** read the existing `MailConnectionTester` test (search `tests/` for `MailConnectionTester`) and reuse its arrangement of `MailSettings`, `ActiveMailTransportFactory`, and `Security` (acting admin). Build the two `MailConnectionTester` instances with a real `MailDeliveryHealth` + `MailSendFailureRepository` from the container. For the success case, use the framework test transport / an in-memory transport the existing tests already use, so `send()` does not throw. If assembling a genuinely-sending tester in isolation proves brittle, keep `testAFailedTestRecordsATestFailure` as the functional proof and cover the ok→`recordSuccess` branch by asserting the refactored `test()` calls `recordSuccess()` when `attempt()` returns `MailTestResult::ok()` (inject a `MailDeliveryHealth` mock). Do not leave the ok branch unmutated — Infection gates it.

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Settings/MailConnectionTesterHealthTest.php`
Expected: FAIL — `MailConnectionTester::__construct()` has no `MailDeliveryHealth` argument.

- [ ] **Step 3: Refactor `test()` to record once, then wire it**

Rename the current body of `test()` to a private `attempt(): MailTestResult` (identical logic, unchanged), add `MailDeliveryHealth $health` to the constructor, and make `test()` record based on the outcome:

```php
    public function test(): MailTestResult
    {
        $result = $this->attempt();

        if ($result->ok) {
            $this->health->recordSuccess();
        } else {
            $this->health->recordFailure(
                MailKind::Test,
                $this->actingAdminEmail() ?? 'unknown',
                $result->reason ?? 'failed',
            );
        }

        return $result;
    }

    private function attempt(): MailTestResult
    {
        // ... the exact body that test() had before ...
    }
```

Add `use App\Entity\MailKind;` and `use App\Service\Mail\MailDeliveryHealth;`. `actingAdminEmail()` already exists.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Settings/MailConnectionTesterHealthTest.php`
Expected: PASS.

- [ ] **Step 5: Run any existing MailConnectionTester test — no regression**

Run: `cd backend && php bin/phpunit --filter MailConnectionTester`
Expected: PASS — update any existing construction of `MailConnectionTester` to pass a `MailDeliveryHealth` (stub is fine).

- [ ] **Step 6: Static analysis**

Run: `cd backend && composer stan && composer cs && composer md`
Expected: clean.

- [ ] **Step 7: Commit**

```bash
cd backend && git add src/Service/Mail/Settings/MailConnectionTester.php tests/Service/Mail/Settings/
git commit -m "feat(#882): record manual mail-test outcome, clearing on success"
```

---

### Task 7: Frontend service — load failures, expose count

**Files:**
- Modify: `frontend/src/app/settings/admin/mail/mail-settings.service.ts`
- Modify: `frontend/src/app/settings/admin/mail/mail-settings.service.spec.ts`
- Modify: `frontend/src/app/settings/admin/mail/mail-section.component.spec.ts` (flush the new endpoint in `mount()`)

**Interfaces:**
- Produces:
  - `interface MailFailure { readonly kind: 'digest' | 'account' | 'test'; readonly recipient: string; readonly error: string; readonly at: string; }`
  - `MailSettingsService.failures: Signal<MailFailure[]>`
  - `MailSettingsService.failureCount: Signal<number>`
  - `MailSettingsService.loadFailures(): void`

- [ ] **Step 1: Write the failing service test**

Add to `mail-settings.service.spec.ts` (or create a focused describe). It must flush `${ENDPOINT}/errors`:

```ts
it('loads recent failures and exposes the count', () => {
  // service constructed; state load already flushed per the file's setup.
  service.loadFailures();
  http.expectOne(`${ENDPOINT}/errors`).flush({
    count: 2,
    failures: [
      { kind: 'digest', recipient: 'a@example.test', error: 'SMTP is down', at: '2026-09-06T10:00:00Z' },
      { kind: 'test', recipient: 'boss@example.test', error: 'no_from_address', at: '2026-09-06T10:05:00Z' },
    ],
  });

  expect(service.failureCount()).toBe(2);
  expect(service.failures()[0].kind).toBe('digest');
});
```

> **Executor note:** match the existing spec's harness for constructing `MailSettingsService` and its `ENDPOINT` constant. If the service auto-loads failures in its constructor, assert on that initial request instead of calling `loadFailures()` manually, and flush it.

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -T frontend npm test -- mail-settings.service`
Expected: FAIL — `failures` / `failureCount` / `loadFailures` do not exist.

- [ ] **Step 3: Implement in the service**

In `mail-settings.service.ts`, add the interface and members. Import `computed`:

```ts
import { Injectable, computed, signal } from '@angular/core';
```

```ts
export interface MailFailure {
  readonly kind: 'digest' | 'account' | 'test';
  readonly recipient: string;
  readonly error: string;
  readonly at: string;
}

interface MailErrorsResponse {
  readonly count: number;
  readonly failures: MailFailure[];
}
```

Inside the class:

```ts
  readonly failures = signal<MailFailure[]>([]);
  private readonly failureTotal = signal(0);
  /** The pill counts every failure since the last success, even past the
   *  server's retained window, so it reads the response count, not the list. */
  readonly failureCount = computed(() => this.failureTotal());

  loadFailures(): void {
    this.http.get<MailErrorsResponse>(`${this.endpoint}/errors`).subscribe((response) => {
      this.failures.set(response.failures);
      this.failureTotal.set(response.count);
    });
  }
```

Call `loadFailures()` once on construction (add a constructor that calls `super()` then `this.loadFailures()`, matching how the base loads state), and again after a test resolves — in `testConnection()`, call `this.loadFailures()` in both the `next` and `error` callbacks after setting the probe.

> **Executor note:** check `DraftSettingsService` for how/when the base triggers the initial state GET, and place the `loadFailures()` init call so it runs once per mount (constructor after `super()` is the expected spot). `this.http` and `this.endpoint` are inherited (endpoint is `${this.base}/api/admin/mail`).

- [ ] **Step 4: Update the component spec's `mount()` to flush the new endpoint**

Every `MailSectionComponent` test now triggers a GET to `${ENDPOINT}/errors` on mount. In `mail-section.component.spec.ts`, after the existing `http.expectOne(ENDPOINT).flush(initial)` in `mount()`, add:

```ts
http.expectOne(`${ENDPOINT}/errors`).flush({ count: 0, failures: [] });
fixture.detectChanges();
```

- [ ] **Step 5: Run both specs to verify they pass**

Run: `docker compose exec -T frontend npm test -- mail-settings.service mail-section`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd frontend && git add src/app/settings/admin/mail/mail-settings.service.ts src/app/settings/admin/mail/mail-settings.service.spec.ts src/app/settings/admin/mail/mail-section.component.spec.ts
git commit -m "feat(#882): load recent mail failures in the mail settings service"
```

---

### Task 8: Frontend UI — the failure pill + log card

**Files:**
- Modify: `frontend/src/app/settings/admin/mail/mail-section.component.ts` (imports)
- Modify: `frontend/src/app/settings/admin/mail/mail-section.component.html`
- Modify: `frontend/src/app/settings/admin/mail/mail-section.component.scss`
- Modify: `frontend/public/i18n/en.json`
- Modify: `frontend/public/i18n/de.json`
- Test: `frontend/src/app/settings/admin/mail/mail-section.component.spec.ts` (add card cases)

**Interfaces:**
- Consumes: `svc.failureCount()`, `svc.failures()` (Task 7); `DisclosureComponent`, `WarningBoxComponent`, `IconComponent` (shared).

- [ ] **Step 1: Write the failing component tests**

Add to `mail-section.component.spec.ts`. Use a `mount()` variant that lets the test control the errors flush:

```ts
it('shows no failure card when the last send succeeded', () => {
  const fixture = mount(); // flushes errors with { count: 0, failures: [] }
  expect(fixture.nativeElement.querySelector('.mail-health')).toBeNull();
});

it('shows the pill and one row per failure when the last send failed', () => {
  const fixture = mountWithFailures({
    count: 2,
    failures: [
      { kind: 'digest', recipient: 'a@example.test', error: 'SMTP is down', at: '2026-09-06T10:00:00Z' },
      { kind: 'account', recipient: 'b@example.test', error: 'relay refused', at: '2026-09-06T10:05:00Z' },
    ],
  });
  const card = fixture.nativeElement.querySelector('.mail-health');
  expect(card).not.toBeNull();
  expect(card.querySelectorAll('.mail-failure-row').length).toBe(2);
  expect(card.textContent).toContain('SMTP is down');
});
```

> **Executor note:** add a `mountWithFailures(payload)` helper that mirrors `mount()` but flushes `${ENDPOINT}/errors` with the given payload instead of the empty one. Keep the existing `mount()` flushing the empty payload so the other tests are unaffected.

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec -T frontend npm test -- mail-section`
Expected: FAIL — no `.mail-health` element rendered.

- [ ] **Step 3: Add the card markup**

In `mail-section.component.html`, add — as the first child inside `<app-settings-stack>`, before the `@if (svc.state())` block:

```html
@if (svc.failureCount()) {
  <section class="mail-health">
    <app-disclosure appearance="drill-in">
      <div summary class="health-head">
        <app-icon class="health-head-icon" name="heart_broken" size="sm" />
        <span class="health-head-title">{{ 'settings.mail.health.heading' | transloco }}</span>
        <span class="health-head-count">{{
          (svc.failureCount() === 1
            ? 'settings.mail.health.failedCountOne'
            : 'settings.mail.health.failedCountOther'
          ) | transloco: { count: svc.failureCount() }
        }}</span>
      </div>

      <div class="health-list">
        @for (failure of svc.failures(); track failure.at + failure.recipient) {
          <div class="mail-failure-row">
            <div class="meta">
              <span class="kind">{{ 'settings.mail.health.kind.' + failure.kind | transloco }}</span>
              <span class="recipient">{{ failure.recipient }}</span>
            </div>
            <app-warning-box class="error"><code>{{ failure.error }}</code></app-warning-box>
          </div>
        }
      </div>
    </app-disclosure>
  </section>
}
```

- [ ] **Step 4: Register the imports**

In `mail-section.component.ts`, import and add to the `imports` array `DisclosureComponent` (from `../../../shared/disclosure/...`) and `WarningBoxComponent` (from `../../../shared/warning-box/...`). `IconComponent` and `TranslocoPipe` are already imported. Verify the exact paths/class names against `organise-section.component.ts` and `unhealthy-feed-row.component.ts`.

- [ ] **Step 5: Add the styles (tokens only)**

In `mail-section.component.scss`, add rules for `.mail-health`, `.health-head`, `.health-head-count` (the red pill), `.health-list`, `.mail-failure-row`, `.meta`, `.kind`, `.recipient`. Copy the layout and the danger/pill tokens from `organise-section.component.scss` `.health-*` rules — no hex, no raw `px`, no media literals. The red pill uses the same danger colour token Organise uses for its counts.

> **Executor note:** open `frontend/src/app/settings/organise/organise-section.component.scss` and reuse its `.health-head*` token choices verbatim for the count/pill colour and spacing so the two cards read as one system.

- [ ] **Step 6: Add the i18n copy**

Add under `settings.mail` in `frontend/public/i18n/en.json`:

```json
"health": {
  "heading": "Mail delivery problems",
  "failedCountOne": "{{count}} failed send",
  "failedCountOther": "{{count}} failed sends",
  "kind": {
    "digest": "Digest",
    "account": "Account email",
    "test": "Test message"
  }
}
```

And under `settings.mail` in `frontend/public/i18n/de.json`:

```json
"health": {
  "heading": "Probleme beim Mailversand",
  "failedCountOne": "{{count}} fehlgeschlagener Versand",
  "failedCountOther": "{{count}} fehlgeschlagene Sendungen",
  "kind": {
    "digest": "Zusammenfassung",
    "account": "Konto-E-Mail",
    "test": "Testnachricht"
  }
}
```

> **Executor note:** insert the `health` object as a sibling key inside the existing `settings.mail` object in each file; keep the surrounding keys intact and the JSON valid.

- [ ] **Step 7: Run the component tests to verify they pass**

Run: `docker compose exec -T frontend npm test -- mail-section`
Expected: PASS (old + new cases).

- [ ] **Step 8: Full frontend gate**

Run: `cd frontend && npm run check`
Expected: ESLint + Prettier + Stylelint + Jest all pass. Stylelint will fail if any hex/px/media literal slipped into the `.scss` — fix by using tokens.

- [ ] **Step 9: Commit**

```bash
cd frontend && git add src/app/settings/admin/mail/ public/i18n/en.json public/i18n/de.json
git commit -m "feat(#882): show a red pill and recent-error log in admin Mail"
```

---

### Task 9: Full-suite verification + dev-log scan

**Files:** none (verification only).

- [ ] **Step 1: Backend suite (SQLite leg)**

Run: `cd backend && php bin/phpunit`
Expected: green.

- [ ] **Step 2: Backend suite (MySQL leg) + migration leg**

Run:
```bash
docker compose up -d
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
docker compose exec php vendor/bin/phpunit
```
Expected: migration applies on MySQL; schema validates; suite green.

- [ ] **Step 3: Backend quality gate + mutation on changed files**

Run: `cd backend && composer check && composer md && composer infection:diff`
Expected: `check` and `md` clean; `infection:diff` meets `minMsi`. Address escaped mutants on the new service/repository/wiring by strengthening assertions.

- [ ] **Step 4: Frontend gate**

Run: `cd frontend && npm run check`
Expected: green.

- [ ] **Step 5: Scan today's dev log**

Run: `ls -t backend/var/log/dev-*.log | head -1` then read it.
Expected: no new deprecations or swallowed errors from this work.

- [ ] **Step 6: Push and open the PR**

```bash
git push -u origin feature/882-mail-failure-pill
gh pr create --base develop --title "feat(#882): automated-email failure pill + recent-error log in admin Mail" --body "Closes #882"
```

The PR body must contain `Closes #882` so the merge auto-closes the issue. After merge, verify #882 closed.

---

## Self-Review

**Spec coverage:**
- Persist failures (entity/repo/pruning) → Task 1. ✓
- `MailDeliveryHealth` record/clear/view + mapper → Task 2. ✓
- Endpoint `GET /api/admin/mail/errors` → Task 3. ✓
- Clear-on-success wired in all three paths → Tasks 4 (digest), 5 (deferred), 6 (test). ✓
- Frontend pill + log, shown only when count>0, i18n en+de → Tasks 7–8. ✓
- Migration verified by the dedicated CI-style leg → Task 1 Step 7 + Task 9 Step 2. ✓
- Tests: repository incl. pruning (T1); digest write via functional real-DB drive (T4); deferred write via real dispatcher for success + direct-construction for the throw, both against the real repo (T5); endpoint (T3); frontend pill/log (T8). ✓
- Mutation gate on changed files → Task 9 Step 3. ✓

**Placeholder scan:** No "TBD"/"add error handling"/"similar to Task N". The two heaviest test arrangements (digest graph in T4, tester graph in T6) carry explicit executor notes pointing at the exact existing test to copy the real API from, rather than inventing signatures that might not match — this is deliberate, since those constructors live in files the executor must read anyway.

**Type consistency:** `MailKind` values `digest/account/test` are identical in the enum (T1), the mapper output (T2), the frontend `MailFailure.kind` union (T7), and the i18n `kind.*` keys (T8). `view()` shape `{count, failures[{kind,recipient,error,at}]}` is identical across mapper (T2), endpoint test (T3), service response `MailErrorsResponse` (T7), and component tests (T8). `MailDeliveryHealth` method names `recordFailure`/`recordSuccess`/`view` are consistent across Tasks 2–6. `MailSendFailureRepository::RETENTION` / `add` / `deleteAll` / `recent` / `countAll` consistent across Tasks 1–2.

**Known executor risks (flagged, not placeholders):**
- Tasks 4 and 6 depend on copying real collaborator constructors from existing tests — the notes name those files.
- Every existing constructor call of `SendDueDigests`, `DeferredMailFlushListener`, and `MailConnectionTester` must gain the new argument (Steps 5 in Tasks 4–6) or the suite will not compile — each task calls this out.
- The existing `mail-section` and `mail-settings.service` specs must flush the new `/errors` GET (Task 7) or every mount fails on an unexpected request — called out explicitly.
