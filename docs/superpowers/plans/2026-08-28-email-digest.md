# Email Digest Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user with a verified email on a mail-capable instance receive a daily/weekly plain-text digest of new entries matching their saved searches, configured in a new "Email" settings section and toggled per search.

**Architecture:** Digest config lives on the existing `Preferences` entity and a per-search flag on `SavedSearch`; a verified-email marker is added to `User`. A `Service/Mail/Digest/` package composes a render-agnostic `DigestModel`, renders it to plain text, and sends it through a `MailCapability`-gated mailer. A single `SendDueDigests` service is driven both by a new hourly worker message and by the existing worker-less `MaintenanceTick`. The frontend gains a new `email` settings section and a per-search mail icon in the reader sidebar.

**Tech Stack:** PHP 8.4 / Symfony 7.4 LTS, Doctrine ORM, Symfony Scheduler + Messenger (Doctrine transport), Symfony Mailer + Translation; Angular 20 standalone components + signals, Transloco, Jest.

**Spec:** [docs/superpowers/plans/2026-08-28-email-digest.spec.md](2026-08-28-email-digest.spec.md)

## Global Constraints

- PHP 8.4, Symfony 7.4; `declare(strict_types=1)` in every PHP file; PSR-12.
- Clean Code (CLAUDE.md): `final readonly` value objects, guard clauses, no boolean-flag parameters (split the method), depend on injected interfaces, typed namespaced exceptions under `Service/*/Exception/`, thin controllers (no responsibility-carrying private methods — `ThinControllerRule`).
- Every `src` file touched must pass `composer check` (cs + PHPStan max + tramp), `composer md` (PHPMD codesize), and PhpStorm inspections (block on ERROR/WARNING).
- Doctrine stores **naive UTC**; normalise before persist. Datetime columns use `Types::DATETIME_IMMUTABLE`.
- Every migration handles **both** MySQL and SQLite (copy the `assertSupportedPlatform()` guard from an existing migration) and is exercised only by the dedicated CI leg — verify it by hand too.
- Mutation testing gates changed files (`composer infection:diff`, `minMsi` in `infection.json5`). Parallel test runs set `TEST_TOKEN`.
- Frontend: standalone components + signals, no NgModules; component styles in a sibling `.scss` via `styleUrl`; no hex colours or raw `px`/media literals in `.scss` outside `src/app/theme/`; run `npm run check`.
- **Every new i18n key is added to BOTH `frontend/public/i18n/en.json` and `frontend/public/i18n/de.json`** — `i18n-dictionaries.spec.ts` fails the build if the key sets differ.
- Backend translations for mail live in `backend/translations/emails.en.xlf` and `emails.de.xlf` (domain `emails`) — add keys to both.
- Native-iOS constraint: bearer auth, stateless, JSON in / `application/problem+json` out, no browser-only inputs on any endpoint.
- Branch: `feature/636-email-digest` (already created). Commit message format: `type(#636): summary`.
- After backend work, scan today's dev log: `ls -t backend/var/log/dev-*.log | head -1`.

---

## File Structure

**Backend — new files**
- `backend/src/Service/Mail/Digest/DigestCadence.php` — enum `daily|weekly`.
- `backend/src/Service/Mail/Digest/DigestSchedule.php` — timezone owner + dueness.
- `backend/src/Service/Mail/Digest/DigestModel.php`, `DigestGroup.php`, `DigestEntry.php` — value objects (the HTML-later seam).
- `backend/src/Service/Mail/Digest/DigestLinkBuilder.php` — deep-link URLs from `APP_FRONTEND_URL`.
- `backend/src/Service/Mail/Digest/DigestEntryFinder.php` — capped hydrated matches since a datetime.
- `backend/src/Service/Mail/Digest/DigestComposer.php` — builds `?DigestModel` for a user + window.
- `backend/src/Service/Mail/Digest/DigestTextRenderer.php` — `DigestModel` → subject+body.
- `backend/src/Service/Mail/Digest/DigestMailerInterface.php`, `DigestMailer.php`, `MailGatedDigestMailer.php`.
- `backend/src/Service/Mail/Digest/SendDueDigests.php` — the sweep.
- `backend/src/Dto/Me/UpdateDigestRequest.php`, `backend/src/Dto/Me/SendTestDigestRequest.php`.
- `backend/src/Service/Worker/Message/SendDueDigests.php` (marker) + `backend/src/Service/Worker/MessageHandler/SendDueDigestsHandler.php`.
- `backend/migrations/Version20260828NNNNNN.php`.

**Backend — modified**
- `Entity/Preferences.php`, `Entity/SavedSearch.php`, `Entity/User.php`.
- `Service/Auth/RegistrationService.php`, `Service/OAuth/OAuthAccountLinker.php`.
- `Http/MeJson.php`, `Http/SavedSearchJson.php`.
- `Controller/Api/MeController.php`, `Controller/Api/SavedSearchController.php`.
- `Service/Maintenance/MaintenanceTick.php` (+ `MaintenanceTickReport`), `Service/Worker/WorkerSchedule.php`.
- `config/packages/rate_limiter.yaml`, `backend/.env`.

**Frontend — new**
- `settings/email-section.component.{ts,html,scss}`.
- `core/digest.service.ts`, `core/digest-writer.ts`, `core/http-digest-writer.ts`.

**Frontend — modified**
- `settings/settings-sections.ts`, `settings/settings.routes.ts`.
- `core/auth.service.ts` (adopt/reset + `CurrentUser` type), `core/api.ts` if needed.
- `reader/models.ts`, `reader/saved-searches.store.ts`, `reader/reader-api.ts`.
- `reader/sidebar/sidebar.component.{ts,html,scss}`, `reader/reader-shell.component.{ts,html}`.
- i18n dictionaries; `emails.en.xlf` / `emails.de.xlf`.

---

# Slice 1 — Data model + migration

### Task 1: DigestCadence enum and Preferences digest fields

**Files:**
- Create: `backend/src/Service/Mail/Digest/DigestCadence.php`
- Modify: `backend/src/Entity/Preferences.php`
- Test: `backend/tests/Entity/PreferencesTest.php` (create)

**Produces:** `DigestCadence::Daily|Weekly` (string-backed `daily|weekly`); `Preferences::isDigestEnabled(): bool`, `getDigestCadence(): DigestCadence`, `getDigestSendHour(): int`, `getDigestWeekday(): int`, `getDigestLastSentAt(): ?\DateTimeImmutable`, and setters `setDigestEnabled(bool)`, `setDigestCadence(DigestCadence)`, `setDigestSendHour(int)`, `setDigestWeekday(int)`, `setDigestLastSentAt(?\DateTimeImmutable)`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Entity;

use App\Entity\Preferences;
use App\Entity\User;
use App\Service\Mail\Digest\DigestCadence;
use PHPUnit\Framework\TestCase;

final class PreferencesTest extends TestCase
{
    public function testDigestDefaultsAreOffDailyEightMonday(): void
    {
        // Parenthesised new: PDepend (composer md) cannot parse a chained
        // `new Foo()->bar()` yet — keep the parens (repo note #183).
        $prefs = (new User('a@b.example', new \DateTimeImmutable()))->getPreferences();

        self::assertFalse($prefs->isDigestEnabled());
        self::assertSame(DigestCadence::Daily, $prefs->getDigestCadence());
        self::assertSame(8, $prefs->getDigestSendHour());
        self::assertSame(1, $prefs->getDigestWeekday());
        self::assertNull($prefs->getDigestLastSentAt());
    }

    public function testDigestFieldsRoundTrip(): void
    {
        $prefs = (new User('a@b.example', new \DateTimeImmutable()))->getPreferences();
        $sentAt = new \DateTimeImmutable('2026-08-28 06:00:00');

        $prefs->setDigestEnabled(true);
        $prefs->setDigestCadence(DigestCadence::Weekly);
        $prefs->setDigestSendHour(20);
        $prefs->setDigestWeekday(6);
        $prefs->setDigestLastSentAt($sentAt);

        self::assertTrue($prefs->isDigestEnabled());
        self::assertSame(DigestCadence::Weekly, $prefs->getDigestCadence());
        self::assertSame(20, $prefs->getDigestSendHour());
        self::assertSame(6, $prefs->getDigestWeekday());
        self::assertSame($sentAt, $prefs->getDigestLastSentAt());
    }
}
```

- [ ] **Step 2: Run the test, expect failure**

Run: `cd backend && php bin/phpunit tests/Entity/PreferencesTest.php`
Expected: FAIL — `DigestCadence` and the getters do not exist.

- [ ] **Step 3: Create the enum**

```php
<?php
declare(strict_types=1);
namespace App\Service\Mail\Digest;

enum DigestCadence: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
}
```

- [ ] **Step 4: Add the columns and accessors to `Preferences`**

Add after the `scrapeFallbackEnabled` property (keep the enumType import `use App\Service\Mail\Digest\DigestCadence;` and `use Doctrine\DBAL\Types\Types;`):

```php
    #[ORM\Column(name: 'digest_enabled', options: ['default' => false])]
    private bool $digestEnabled = false;

    #[ORM\Column(name: 'digest_cadence', length: 10, enumType: DigestCadence::class, options: ['default' => 'daily'])]
    private DigestCadence $digestCadence = DigestCadence::Daily;

    /** The local hour (0–23) the digest is sent, interpreted in the instance timezone. */
    #[ORM\Column(name: 'digest_send_hour', type: Types::SMALLINT, options: ['default' => 8])]
    private int $digestSendHour = 8;

    /** ISO-8601 weekday (1=Mon … 7=Sun); only meaningful for the weekly cadence. */
    #[ORM\Column(name: 'digest_weekday', type: Types::SMALLINT, options: ['default' => 1])]
    private int $digestWeekday = 1;

    /** Naive UTC. Null until the digest is first enabled; the "since" marker. */
    #[ORM\Column(name: 'digest_last_sent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $digestLastSentAt = null;
```

Add the five getters and five setters following the existing `isScrapeFallbackEnabled`/`setScrapeFallbackEnabled` style (one-line bodies).

- [ ] **Step 5: Run the test, expect pass**

Run: `cd backend && php bin/phpunit tests/Entity/PreferencesTest.php`
Expected: PASS.

- [ ] **Step 6: Gates + commit**

Run: `cd backend && composer cs:fix && composer stan && composer md`
```bash
git add backend/src/Service/Mail/Digest/DigestCadence.php backend/src/Entity/Preferences.php backend/tests/Entity/PreferencesTest.php
git commit -m "feat(#636): add digest cadence fields to Preferences"
```

---

### Task 2: SavedSearch.includeInDigest

**Files:**
- Modify: `backend/src/Entity/SavedSearch.php`
- Test: `backend/tests/Entity/SavedSearchTest.php` (create)

**Produces:** `SavedSearch::isIncludeInDigest(): bool` (default false), `setIncludeInDigest(bool): void`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Entity;

use App\Entity\SavedSearch;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class SavedSearchTest extends TestCase
{
    public function testIncludeInDigestDefaultsFalseAndToggles(): void
    {
        $search = new SavedSearch(new User('a@b.example', new \DateTimeImmutable()), 'rust', false);

        self::assertFalse($search->isIncludeInDigest());

        $search->setIncludeInDigest(true);
        self::assertTrue($search->isIncludeInDigest());
    }
}
```

- [ ] **Step 2: Run the test, expect failure**

Run: `cd backend && php bin/phpunit tests/Entity/SavedSearchTest.php`
Expected: FAIL — method not found.

- [ ] **Step 3: Add the column and accessors**

After the `position` property in `SavedSearch`:

```php
    /** Whether new matches feed this user's email digest (#636). */
    #[ORM\Column(name: 'include_in_digest', options: ['default' => false])]
    private bool $includeInDigest = false;
```

Add `isIncludeInDigest()` and `setIncludeInDigest(bool)` matching the file's accessor style.

- [ ] **Step 4: Run the test, expect pass**

Run: `cd backend && php bin/phpunit tests/Entity/SavedSearchTest.php`
Expected: PASS.

- [ ] **Step 5: Gates + commit**

```bash
cd backend && composer cs:fix && composer stan && composer md
git add backend/src/Entity/SavedSearch.php backend/tests/Entity/SavedSearchTest.php
git commit -m "feat(#636): add includeInDigest flag to SavedSearch"
```

---

### Task 3: User.emailVerifiedAt and its wiring

**Files:**
- Modify: `backend/src/Entity/User.php`
- Modify: `backend/src/Service/Auth/RegistrationService.php` (`verifyEmail`)
- Modify: `backend/src/Service/OAuth/OAuthAccountLinker.php` (`createUser`, `claimIfUnverified`)
- Test: `backend/tests/Entity/UserTest.php` (add), `backend/tests/Service/Auth/RegistrationServiceTest.php` (extend if present; else a focused functional test), `backend/tests/Service/OAuth/OAuthAccountLinkerTest.php` (extend if present)

**Interfaces produced:** `User::getEmailVerifiedAt(): ?\DateTimeImmutable`, `User::markEmailVerified(\DateTimeImmutable): void` (guarded no-op if already set — see below), `User::isEmailVerified(): bool`.

- [ ] **Step 1: Write the failing entity test**

Add to `backend/tests/Entity/UserTest.php` (create if absent):

```php
public function testEmailVerifiedAtStartsNullAndIsStampedOnce(): void
{
    $user = new User('a@b.example', new \DateTimeImmutable());
    self::assertNull($user->getEmailVerifiedAt());
    self::assertFalse($user->isEmailVerified());

    $first = new \DateTimeImmutable('2026-08-28 10:00:00');
    $user->markEmailVerified($first);
    self::assertSame($first, $user->getEmailVerifiedAt());
    self::assertTrue($user->isEmailVerified());

    // Idempotent: a later verification does not move the original instant.
    $user->markEmailVerified(new \DateTimeImmutable('2026-09-01 10:00:00'));
    self::assertSame($first, $user->getEmailVerifiedAt());
}
```

- [ ] **Step 2: Run, expect failure**

Run: `cd backend && php bin/phpunit tests/Entity/UserTest.php`
Expected: FAIL — methods missing.

- [ ] **Step 3: Add column + accessors to `User`**

After the `approvedAt` property:

```php
    /**
     * When this account proved it can read mail at its address: a verify-email
     * token was consumed, or an OIDC provider vouched for a real address (#636).
     * Null means unverified — the digest will not mail an unverified address.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;
```

```php
    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    /** Stamps the first verification only; re-verifying never moves the instant. */
    public function markEmailVerified(\DateTimeImmutable $verifiedAt): void
    {
        $this->emailVerifiedAt ??= $verifiedAt;
    }
```

- [ ] **Step 4: Run, expect pass**

Run: `cd backend && php bin/phpunit tests/Entity/UserTest.php`
Expected: PASS.

- [ ] **Step 5: Wire `RegistrationService::verifyEmail`**

In the `UserStatus::PendingVerification === $user->getStatus()` branch, stamp verification on **both** the approval-required and the active outcomes. Add `$user->markEmailVerified($now)` where `$now = $this->clock->now()` (introduce the local once, reuse it for `setApprovedAt`). The token was already consumed, proving the address, so verification is stamped regardless of the approval path.

- [ ] **Step 6: Wire `OAuthAccountLinker`**

A provider-verified, linkable address means the address is proven. In `createUser`, after computing `$now`, when the login identifier is a real address stamp it:

```php
        $user = new User($this->loginIdentifierFor($identity), $now);
        if ($identity->isLinkableByEmail()) {
            $user->markEmailVerified($now);
        }
```

In `claimIfUnverified`, when the promoted account's address is linkable (it is the link target, matched by verified email), add `$user->markEmailVerified($now);` alongside the status change. Do **not** stamp the `.invalid` placeholder case.

- [ ] **Step 7: Back the wiring with functional tests**

Add a test asserting that consuming a verify-email token stamps `emailVerifiedAt` (extend the existing registration/verify functional test — find it with `grep -rln "verifyEmail" backend/tests`), and that an OAuth first sign-in with a linkable provider email creates a user with `isEmailVerified() === true` while a private-relay/unlinkable identity does not. Run them, expect pass.

Run: `cd backend && php bin/phpunit --filter 'Verify|OAuthAccountLinker'`

- [ ] **Step 8: Gates + commit**

```bash
cd backend && composer cs:fix && composer stan && composer md
git add backend/src/Entity/User.php backend/src/Service/Auth/RegistrationService.php backend/src/Service/OAuth/OAuthAccountLinker.php backend/tests
git commit -m "feat(#636): stamp emailVerifiedAt on verify-email and OIDC login"
```

---

### Task 4: The Doctrine migration (three tables + backfill)

**Files:**
- Create: `backend/migrations/Version20260828120000.php` (use the next free timestamp; check `ls backend/migrations`)
- Test: manual migrate-from-empty on SQLite + `doctrine:schema:validate`

**Interfaces:** none (schema only). This is one task, not five commits — the schema for the whole model lands together so `schema:validate` can pass.

- [ ] **Step 1: Write the migration**

```php
<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260828120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Email digest: per-user cadence, per-search flag, verified-email marker (#636).';
    }

    public function up(Schema $schema): void
    {
        $this->assertSupportedPlatform();

        $this->addSql("ALTER TABLE user_preferences ADD digest_enabled TINYINT(1) DEFAULT 0 NOT NULL");
        $this->addSql("ALTER TABLE user_preferences ADD digest_cadence VARCHAR(10) DEFAULT 'daily' NOT NULL");
        $this->addSql("ALTER TABLE user_preferences ADD digest_send_hour SMALLINT DEFAULT 8 NOT NULL");
        $this->addSql("ALTER TABLE user_preferences ADD digest_weekday SMALLINT DEFAULT 1 NOT NULL");
        $this->addSql("ALTER TABLE user_preferences ADD digest_last_sent_at DATETIME DEFAULT NULL");

        $this->addSql("ALTER TABLE saved_search ADD include_in_digest TINYINT(1) DEFAULT 0 NOT NULL");

        $this->addSql("ALTER TABLE app_user ADD email_verified_at DATETIME DEFAULT NULL");
        // Backfill: any account already Active or awaiting approval reached that
        // state by proving its address (email verify token or provider), on an
        // instance that had mail enabled at registration. Seed the marker from
        // the best timestamp we hold.
        $this->addSql(
            "UPDATE app_user SET email_verified_at = COALESCE(approved_at, created_at) "
            . "WHERE status IN ('active', 'pending_approval')",
        );
    }

    public function down(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $this->addSql('ALTER TABLE user_preferences DROP digest_enabled');
        $this->addSql('ALTER TABLE user_preferences DROP digest_cadence');
        $this->addSql('ALTER TABLE user_preferences DROP digest_send_hour');
        $this->addSql('ALTER TABLE user_preferences DROP digest_weekday');
        $this->addSql('ALTER TABLE user_preferences DROP digest_last_sent_at');
        $this->addSql('ALTER TABLE saved_search DROP include_in_digest');
        $this->addSql('ALTER TABLE app_user DROP email_verified_at');
    }

    private function assertSupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof AbstractMySQLPlatform) && !($platform instanceof SQLitePlatform),
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
```

Note: the `enumType` string maps to the `VARCHAR` column; `UserStatus` values are the lowercase strings in `Enum/UserStatus.php` (`active`, `pending_approval`) — confirm those exact values before committing.

- [ ] **Step 2: Verify against an empty SQLite database**

```bash
cd backend
php bin/console doctrine:database:create --env=test --if-not-exists
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/console doctrine:schema:validate --env=test
```
Expected: migrations run clean; schema validate reports the mapping is in sync.

- [ ] **Step 3: Verify against MySQL (Docker)**

```bash
docker compose up -d
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```
Expected: clean on MySQL too.

- [ ] **Step 4: Run the full suite (schema built from metadata)**

Run: `cd backend && php bin/phpunit`
Expected: PASS (existing tests unaffected).

- [ ] **Step 5: Commit**

```bash
git add backend/migrations/Version20260828120000.php
git commit -m "feat(#636): migration for digest fields and emailVerifiedAt"
```

---

# Slice 2 — Timezone/dueness, read payloads

### Task 5: APP_TIMEZONE and DigestSchedule (timezone + dueness)

**Files:**
- Modify: `backend/.env` (add `APP_TIMEZONE=UTC`)
- Create: `backend/src/Service/Mail/Digest/DigestSchedule.php`
- Test: `backend/tests/Service/Mail/Digest/DigestScheduleTest.php`

**Produces:** `DigestSchedule::mostRecentDue(Preferences $prefs, \DateTimeImmutable $nowUtc): ?\DateTimeImmutable` — the most recent scheduled occurrence at/before `$nowUtc`, as a **naive UTC** instant, or `null` if none has occurred yet within the cadence window. Callers treat a non-null return that is newer than `digestLastSentAt` as "due".

Rationale for `.env` (not autowire default): the env default processor treats empty as unset (`env-default-processor-treats-empty-as-unset` memory), so default in `.env` and read the plain value.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Service\Mail\Digest;

use App\Entity\Preferences;
use App\Entity\User;
use App\Service\Mail\Digest\DigestCadence;
use App\Service\Mail\Digest\DigestSchedule;
use PHPUnit\Framework\TestCase;

final class DigestScheduleTest extends TestCase
{
    private function prefs(DigestCadence $cadence, int $hour, int $weekday): Preferences
    {
        $prefs = (new User('a@b.example', new \DateTimeImmutable()))->getPreferences();
        $prefs->setDigestEnabled(true);
        $prefs->setDigestCadence($cadence);
        $prefs->setDigestSendHour($hour);
        $prefs->setDigestWeekday($weekday);
        return $prefs;
    }

    public function testDailyBeforeSendHourHasNoOccurrenceToday(): void
    {
        $schedule = new DigestSchedule('UTC');
        // 07:00 UTC, send hour 8 → most recent occurrence is yesterday 08:00.
        $due = $schedule->mostRecentDue($this->prefs(DigestCadence::Daily, 8, 1), new \DateTimeImmutable('2026-08-28 07:00:00'));
        self::assertEquals(new \DateTimeImmutable('2026-08-27 08:00:00'), $due);
    }

    public function testDailyAtOrAfterSendHourIsToday(): void
    {
        $schedule = new DigestSchedule('UTC');
        $due = $schedule->mostRecentDue($this->prefs(DigestCadence::Daily, 8, 1), new \DateTimeImmutable('2026-08-28 09:30:00'));
        self::assertEquals(new \DateTimeImmutable('2026-08-28 08:00:00'), $due);
    }

    public function testInstanceTimezoneShiftsTheUtcInstant(): void
    {
        $schedule = new DigestSchedule('Europe/Berlin'); // +02:00 in August
        // Send hour 8 Berlin = 06:00 UTC. At 06:30 UTC that occurrence has passed.
        $due = $schedule->mostRecentDue($this->prefs(DigestCadence::Daily, 8, 1), new \DateTimeImmutable('2026-08-28 06:30:00'));
        self::assertEquals(new \DateTimeImmutable('2026-08-28 06:00:00'), $due);
    }

    public function testWeeklyReturnsMostRecentMatchingWeekday(): void
    {
        $schedule = new DigestSchedule('UTC');
        // Weekday 1 = Monday. 2026-08-28 is a Friday; most recent Monday 08:00 is 2026-08-24.
        $due = $schedule->mostRecentDue($this->prefs(DigestCadence::Weekly, 8, 1), new \DateTimeImmutable('2026-08-28 09:00:00'));
        self::assertEquals(new \DateTimeImmutable('2026-08-24 08:00:00'), $due);
    }

    public function testDisabledReturnsNull(): void
    {
        $schedule = new DigestSchedule('UTC');
        $prefs = $this->prefs(DigestCadence::Daily, 8, 1);
        $prefs->setDigestEnabled(false);
        self::assertNull($schedule->mostRecentDue($prefs, new \DateTimeImmutable('2026-08-28 09:00:00')));
    }
}
```

- [ ] **Step 2: Run, expect failure**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Digest/DigestScheduleTest.php`
Expected: FAIL — class missing.

- [ ] **Step 3: Implement `DigestSchedule`**

```php
<?php
declare(strict_types=1);
namespace App\Service\Mail\Digest;

use App\Entity\Preferences;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The single owner of "what local time does this user's send hour mean, and has
 * that occurrence passed?". Interprets the send hour in the instance timezone
 * (APP_TIMEZONE) for v1; a per-user timezone would replace only this class.
 */
final readonly class DigestSchedule
{
    public function __construct(
        #[Autowire('%env(string:APP_TIMEZONE)%')]
        private string $timezone,
    ) {
    }

    /** The most recent scheduled occurrence at/before now, as naive UTC, or null. */
    public function mostRecentDue(Preferences $preferences, \DateTimeImmutable $nowUtc): ?\DateTimeImmutable
    {
        if (!$preferences->isDigestEnabled()) {
            return null;
        }

        $localNow = $nowUtc->setTimezone(new \DateTimeZone($this->timezone));

        $occurrence = match ($preferences->getDigestCadence()) {
            DigestCadence::Daily => $this->dailyOccurrence($localNow, $preferences->getDigestSendHour()),
            DigestCadence::Weekly => $this->weeklyOccurrence($localNow, $preferences->getDigestSendHour(), $preferences->getDigestWeekday()),
        };

        // Return the same instant as naive UTC, to compare against digestLastSentAt.
        return $occurrence->setTimezone(new \DateTimeZone('UTC'));
    }

    private function dailyOccurrence(\DateTimeImmutable $localNow, int $hour): \DateTimeImmutable
    {
        $today = $localNow->setTime($hour, 0, 0);

        return $today <= $localNow ? $today : $today->modify('-1 day');
    }

    private function weeklyOccurrence(\DateTimeImmutable $localNow, int $hour, int $weekday): \DateTimeImmutable
    {
        $candidate = $localNow->setTime($hour, 0, 0);
        // Walk back at most 7 days to the most recent matching weekday-at-hour.
        for ($back = 0; $back < 7; ++$back) {
            $day = $candidate->modify(\sprintf('-%d days', $back));
            if ((int) $day->format('N') === $weekday && $day <= $localNow) {
                return $day;
            }
        }

        return $candidate->modify('-7 days');
    }
}
```

Note: simplify the `mostRecentDue` return — convert the local occurrence to UTC and strip sub-minute noise. If the triple-conversion above trips PHPMD/inspections, extract a private `toNaiveUtc(\DateTimeImmutable $local): \DateTimeImmutable` that does `->setTimezone(UTC)` once and returns it; the returned value is compared against the naive-UTC `digestLastSentAt`, so keep both naive UTC.

- [ ] **Step 4: Run, expect pass**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Digest/DigestScheduleTest.php`
Expected: PASS. Fix off-by-timezone issues against the asserted instants.

- [ ] **Step 5: Add the env default**

Add to `backend/.env` near the other app env (e.g. below `APP_FRONTEND_URL`):
```
###> app/digest ###
# Timezone the digest send hour is interpreted in. IANA name; default UTC.
APP_TIMEZONE=UTC
###< app/digest ###
```

- [ ] **Step 6: Gates + commit**

```bash
cd backend && composer cs:fix && composer stan && composer md
git add backend/src/Service/Mail/Digest/DigestSchedule.php backend/tests/Service/Mail/Digest/DigestScheduleTest.php backend/.env
git commit -m "feat(#636): DigestSchedule interprets send hour in instance timezone"
```

---

### Task 6: MeJson mail/verified/digest and MeController wiring

**Files:**
- Modify: `backend/src/Http/MeJson.php`
- Modify: `backend/src/Controller/Api/MeController.php`
- Test: `backend/tests/Http/MeJsonTest.php` (create) + extend the `/api/me` functional test if present

**Interfaces:** `MeJson::profile(User $user, bool $mailEnabled): array` — a second required param `$mailEnabled`. All three `MeController` call sites pass `$this->mail->isEnabled()`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Http;

use App\Entity\User;
use App\Http\MeJson;
use App\Service\Mail\Digest\DigestCadence;
use PHPUnit\Framework\TestCase;

final class MeJsonTest extends TestCase
{
    public function testProfileEmitsMailDigestAndVerification(): void
    {
        $user = new User('a@b.example', new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-02 00:00:00'));
        $prefs = $user->getPreferences();
        $prefs->setDigestEnabled(true);
        $prefs->setDigestCadence(DigestCadence::Weekly);
        $prefs->setDigestSendHour(9);
        $prefs->setDigestWeekday(3);

        $json = MeJson::profile($user, true);

        self::assertSame(['enabled' => true], $json['mail']);
        self::assertTrue($json['emailVerified']);
        self::assertSame(
            ['enabled' => true, 'cadence' => 'weekly', 'sendHour' => 9, 'weekday' => 3],
            $json['preferences']['digest'],
        );
    }
}
```

- [ ] **Step 2: Run, expect failure**

Run: `cd backend && php bin/phpunit tests/Http/MeJsonTest.php`
Expected: FAIL — `profile` takes one arg; keys missing.

- [ ] **Step 3: Update `MeJson::profile`**

Change the signature to `public static function profile(User $user, bool $mailEnabled): array` and add to the returned array:

```php
            'mail' => ['enabled' => $mailEnabled],
            'emailVerified' => $user->isEmailVerified(),
```

Extend the `preferences` array:

```php
            'preferences' => [
                'scrapeFallbackEnabled' => $user->getPreferences()->isScrapeFallbackEnabled(),
                'digest' => [
                    'enabled' => $user->getPreferences()->isDigestEnabled(),
                    'cadence' => $user->getPreferences()->getDigestCadence()->value,
                    'sendHour' => $user->getPreferences()->getDigestSendHour(),
                    'weekday' => $user->getPreferences()->getDigestWeekday(),
                ],
            ],
```

- [ ] **Step 4: Update `MeController`**

Inject `MailCapability`:

```php
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AccountDeleter $accountDeleter,
        private MailCapability $mail,
    ) {
    }
```

Add `use App\Service\Mail\MailCapability;`. Replace each `MeJson::profile($user)` (three sites) with `MeJson::profile($user, $this->mail->isEnabled())`.

- [ ] **Step 5: Run, expect pass**

Run: `cd backend && php bin/phpunit tests/Http/MeJsonTest.php`
Expected: PASS.

- [ ] **Step 6: Gates + commit**

```bash
cd backend && composer cs:fix && composer stan && composer md
git add backend/src/Http/MeJson.php backend/src/Controller/Api/MeController.php backend/tests/Http/MeJsonTest.php
git commit -m "feat(#636): expose mail capability, verification and digest on /api/me"
```

---

### Task 7: SavedSearchJson.includeInDigest

**Files:**
- Modify: `backend/src/Http/SavedSearchJson.php`
- Test: `backend/tests/Http/SavedSearchJsonTest.php` (create)

**Interfaces:** `SavedSearchJson::one` return type gains `includeInDigest: bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Http;

use App\Entity\SavedSearch;
use App\Entity\User;
use App\Http\SavedSearchJson;
use PHPUnit\Framework\TestCase;

final class SavedSearchJsonTest extends TestCase
{
    public function testOneEmitsIncludeInDigest(): void
    {
        $search = new SavedSearch(new User('a@b.example', new \DateTimeImmutable()), 'rust', false);
        $search->setIncludeInDigest(true);

        $json = SavedSearchJson::one($search, [7, 8]);

        self::assertTrue($json['includeInDigest']);
        self::assertSame([7, 8], $json['unreadEntryIds']);
    }
}
```

- [ ] **Step 2: Run, expect failure**

Run: `cd backend && php bin/phpunit tests/Http/SavedSearchJsonTest.php`
Expected: FAIL — key missing.

- [ ] **Step 3: Add the field**

Update the docblock return shape to include `includeInDigest: bool` and add `'includeInDigest' => $savedSearch->isIncludeInDigest(),` to the array.

- [ ] **Step 4: Run, expect pass**

Run: `cd backend && php bin/phpunit tests/Http/SavedSearchJsonTest.php`
Expected: PASS.

- [ ] **Step 5: Gates + commit**

```bash
cd backend && composer cs:fix && composer stan && composer md
git add backend/src/Http/SavedSearchJson.php backend/tests/Http/SavedSearchJsonTest.php
git commit -m "feat(#636): emit includeInDigest on saved-search JSON"
```

---

# Slice 3 — Write endpoints

### Task 8: PATCH /api/me/digest

**Files:**
- Create: `backend/src/Dto/Me/UpdateDigestRequest.php`
- Modify: `backend/src/Controller/Api/MeController.php`
- Test: `backend/tests/Controller/Api/MeDigestControllerTest.php` (functional)

**Interfaces produced:** `UpdateDigestRequest { bool $enabled; DigestCadence $cadence; int $sendHour; int $weekday; }`.

- [ ] **Step 1: Write the failing functional test**

Model it on the existing `/api/me/preferences` functional test (find one: `grep -rln "api/me/preferences" backend/tests`). Authenticate a user, `PATCH /api/me/digest` with `{"enabled":true,"cadence":"weekly","sendHour":9,"weekday":3}`, assert 200 and that the response `preferences.digest` echoes those values; assert an out-of-range `sendHour` (e.g. 30) returns 422 `application/problem+json`.

- [ ] **Step 2: Run, expect failure**

Run: `cd backend && php bin/phpunit tests/Controller/Api/MeDigestControllerTest.php`
Expected: FAIL — route missing (404/405).

- [ ] **Step 3: Create the request DTO**

```php
<?php
declare(strict_types=1);
namespace App\Dto\Me;

use App\Service\Mail\Digest\DigestCadence;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The whole digest configuration in one write. Every field is required, with no
 * default, for the same reason UpdatePreferencesRequest is: a value that
 * degrades quietly to a default is indistinguishable from one the user set.
 * Kept separate from UpdatePreferencesRequest so a scrape-fallback toggle need
 * not resend the digest and vice versa (#180's reasoning, #636).
 */
final readonly class UpdateDigestRequest
{
    public function __construct(
        public bool $enabled,
        public DigestCadence $cadence,
        #[Assert\Range(min: 0, max: 23)]
        public int $sendHour,
        #[Assert\Range(min: 1, max: 7)]
        public int $weekday,
    ) {
    }
}
```

Note: `MapRequestPayload` denormalizes the `cadence` string to the `DigestCadence` enum and returns 422 on an unknown value before validation.

- [ ] **Step 4: Add the controller action**

```php
    /**
     * The email-digest configuration (#636). Its own PATCH, not folded into
     * preferences: see updatePreferences() for why each settings write stays
     * independent.
     */
    #[Route('/api/me/digest', name: 'api_me_update_digest', methods: ['PATCH'])]
    public function updateDigest(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateDigestRequest $request,
    ): JsonResponse {
        $preferences = $user->getPreferences();
        $preferences->setDigestEnabled($request->enabled);
        $preferences->setDigestCadence($request->cadence);
        $preferences->setDigestSendHour($request->sendHour);
        $preferences->setDigestWeekday($request->weekday);
        $this->entityManager->flush();

        return new JsonResponse(MeJson::profile($user, $this->mail->isEnabled()));
    }
```

Add `use App\Dto\Me\UpdateDigestRequest;`.

Design note (verify during review): first-enable seeding of `digestLastSentAt` (spec Q5) is applied when the digest transitions **off→on** — set `digestLastSentAt` to "now" only if `false === wasEnabled && true === $request->enabled && null === getDigestLastSentAt()`. Introduce a `ClockInterface` into the controller *only* if this stays a single expression; otherwise move the enable-transition seeding into a tiny `DigestEnablement` service and call it here (keeps the controller thin per `ThinControllerRule`). Prefer the service.

- [ ] **Step 5: Run, expect pass**

Run: `cd backend && php bin/phpunit tests/Controller/Api/MeDigestControllerTest.php`
Expected: PASS. Add a test asserting first enable seeds `digestLastSentAt` (fetch the user, assert non-null after the enabling PATCH; assert a second enabling PATCH does not move it).

- [ ] **Step 6: Gates + commit**

```bash
cd backend && composer cs:fix && composer stan && composer md
git add backend/src/Dto/Me/UpdateDigestRequest.php backend/src/Controller/Api/MeController.php backend/src/Service/Mail/Digest/DigestEnablement.php backend/tests
git commit -m "feat(#636): PATCH /api/me/digest with first-enable seeding"
```

---

### Task 9: PATCH /api/saved-searches/{id} (includeInDigest)

**Files:**
- Create: `backend/src/Dto/SavedSearch/UpdateSavedSearchRequest.php`
- Modify: `backend/src/Controller/Api/SavedSearchController.php`
- Test: `backend/tests/Controller/Api/SavedSearchControllerTest.php` (extend)

**Interfaces produced:** `UpdateSavedSearchRequest { bool $includeInDigest; }`.

- [ ] **Step 1: Write the failing functional test**

Add a test: create a saved search for user A, `PATCH /api/saved-searches/{id}` `{"includeInDigest":true}` as A → 200, response `savedSearch.includeInDigest === true`; as user B (not owner) → 404; unknown id → 404.

- [ ] **Step 2: Run, expect failure**

Run: `cd backend && php bin/phpunit tests/Controller/Api/SavedSearchControllerTest.php`
Expected: FAIL — no PATCH route.

- [ ] **Step 3: Create the DTO**

```php
<?php
declare(strict_types=1);
namespace App\Dto\SavedSearch;

final readonly class UpdateSavedSearchRequest
{
    public function __construct(
        public bool $includeInDigest,
    ) {
    }
}
```

- [ ] **Step 4: Add the action** (mirrors `delete`'s ownership lookup)

```php
    #[Route('/{id}', name: 'api_saved_searches_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(
        int $id,
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateSavedSearchRequest $request,
    ): JsonResponse {
        $userId = (int) $user->getId();
        $savedSearch = $this->savedSearches->findOneOwnedBy($id, $userId)
            ?? throw new NotFoundHttpException('No such saved search.');

        $savedSearch->setIncludeInDigest($request->includeInDigest);
        $this->em->flush();

        return new JsonResponse(
            ['savedSearch' => SavedSearchJson::one($savedSearch, $this->matches->forOne($savedSearch, $userId))],
        );
    }
```

Add `use App\Dto\SavedSearch\UpdateSavedSearchRequest;`.

- [ ] **Step 5: Run, expect pass**

Run: `cd backend && php bin/phpunit tests/Controller/Api/SavedSearchControllerTest.php`
Expected: PASS.

- [ ] **Step 6: Gates + commit**

```bash
cd backend && composer cs:fix && composer stan && composer md
git add backend/src/Dto/SavedSearch/UpdateSavedSearchRequest.php backend/src/Controller/Api/SavedSearchController.php backend/tests/Controller/Api/SavedSearchControllerTest.php
git commit -m "feat(#636): PATCH saved search to set includeInDigest"
```

---

### Task 10: POST /api/me/resend-verification

**Files:**
- Modify: `backend/src/Controller/Api/MeController.php`
- Modify (maybe): `backend/src/Service/Auth/RegistrationService.php` — add `resendVerification(User): void`
- Test: `backend/tests/Controller/Api/MeResendVerificationTest.php` (functional)

**Interfaces produced:** `RegistrationService::resendVerification(User $user): void` — issues a fresh `VerifyEmail` token and mails it, only when the user is not yet verified; a no-op for an already-verified user. Gated by the mail decorator already.

- [ ] **Step 1: Write the failing functional test**

Authenticate an unverified `PendingVerification` user, `POST /api/me/resend-verification` → 204; assert a mail was queued (use the profiler mailer collector, as the existing verification/registration tests do — find the pattern with `grep -rln "getMailerMessages\|MailerAssertionsTrait" backend/tests`). For an already-verified user → 204 and no new mail.

- [ ] **Step 2: Run, expect failure**

Run: `cd backend && php bin/phpunit tests/Controller/Api/MeResendVerificationTest.php`
Expected: FAIL — route missing.

- [ ] **Step 3: Add the service method**

```php
    /**
     * Re-sends the address-verification mail for an account that has not yet
     * proved its address (#636). A no-op once verified, so the endpoint is safe
     * to call idempotently. Mail is skipped by the gated mailer when disabled.
     *
     * @throws TransportExceptionInterface
     * @throws RandomException
     */
    public function resendVerification(User $user): void
    {
        if ($user->isEmailVerified()) {
            return;
        }

        $this->mailer->sendVerification($user, $this->tokens->issue($user, TokenPurpose::VerifyEmail));
    }
```

- [ ] **Step 4: Add the controller action**

```php
    #[Route('/api/me/resend-verification', name: 'api_me_resend_verification', methods: ['POST'])]
    public function resendVerification(#[CurrentUser] User $user): JsonResponse
    {
        $this->registration->resendVerification($user);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
```

Inject `RegistrationService $registration` into `MeController` and `use App\Service\Auth\RegistrationService;`.

- [ ] **Step 5: Run, expect pass**

Run: `cd backend && php bin/phpunit tests/Controller/Api/MeResendVerificationTest.php`
Expected: PASS.

- [ ] **Step 6: Gates + commit**

```bash
cd backend && composer cs:fix && composer stan && composer md
git add backend/src/Service/Auth/RegistrationService.php backend/src/Controller/Api/MeController.php backend/tests/Controller/Api/MeResendVerificationTest.php
git commit -m "feat(#636): POST /api/me/resend-verification"
```

---

# Slice 4 — Digest engine (compose, render, send, test)

### Task 11: EntryListRepository.unreadMatchIdsSince + DigestEntryFinder

**Files:**
- Modify: `backend/src/Repository/EntryListRepository.php`
- Create: `backend/src/Service/Mail/Digest/DigestEntryFinder.php`
- Test: `backend/tests/Repository/EntryListRepositoryDigestTest.php` (integration), `backend/tests/Service/Mail/Digest/DigestEntryFinderTest.php`

**Interfaces produced:**
- `EntryListRepository::unreadMatchIdsSince(EntrySearchQuery $query, \DateTimeImmutable $since): list<int>` — mirror of `unreadMatchingEntryIdsForUser` with `e.effectiveDate > :since`.
- `DigestEntryFinder::matchesSince(SavedSearch $search, int $userId, \DateTimeImmutable $since): DigestSearchMatches` where `DigestSearchMatches { list<EntryListRow> $entries; int $totalCount; }` (entries capped at `DigestEntryFinder::PER_SEARCH = 10`, newest first).

- [ ] **Step 1: Write the failing repository test**

Model on the existing saved-search match integration tests (`grep -rln "unreadMatchingEntryIdsForUser\|unreadMatchIdsForUser" backend/tests`). Seed a user, a subscription, two entries — one with `effectiveDate` before a cutoff and one after — matching a term; assert `unreadMatchIdsSince` returns only the id after the cutoff, and drops entries already read.

- [ ] **Step 2: Run, expect failure**

Run: `cd backend && php bin/phpunit tests/Repository/EntryListRepositoryDigestTest.php`
Expected: FAIL — method missing.

- [ ] **Step 3: Add the repository method** (next to `unreadMatchingEntryIdsForUser`)

```php
    /**
     * The ids of every unread entry that matches this search and is NEWER than
     * $since, for the user's subscribed feeds — the digest's "new since the last
     * send" window (#636). The mirror of unreadMatchingEntryIdsForUser's `<=`.
     *
     * @return list<int>
     */
    public function unreadMatchIdsSince(EntrySearchQuery $query, \DateTimeImmutable $since): array
    {
        $qb = $this->unreadMatchQueryBuilder($query)
            ->select('e.id')
            ->distinct()
            ->andWhere('e.effectiveDate > :since')
            ->setParameter('since', $since);

        /** @var list<array{id: int}> $rows */
        $rows = $qb->getQuery()->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }
```

- [ ] **Step 4: Run the repo test, expect pass**

Run: `cd backend && php bin/phpunit tests/Repository/EntryListRepositoryDigestTest.php`
Expected: PASS.

- [ ] **Step 5: Write the finder test** (unit, mocking the repository)

Assert `matchesSince` returns `totalCount = 12`, `entries` capped to 10, newest first, by stubbing `unreadMatchIdsSince` to return 12 ids and `rowsByIdsForUser` to return matching rows.

- [ ] **Step 6: Implement `DigestEntryFinder` + `DigestSearchMatches`**

```php
<?php
declare(strict_types=1);
namespace App\Service\Mail\Digest;

use App\Entity\SavedSearch;
use App\Repository\EntryListRepository;
use App\Repository\EntrySearchQuery;
use App\Service\Search\SearchTerms;

final readonly class DigestEntryFinder
{
    public const int PER_SEARCH = 10;

    public function __construct(private EntryListRepository $entries)
    {
    }

    public function matchesSince(SavedSearch $search, int $userId, \DateTimeImmutable $since): DigestSearchMatches
    {
        $terms = SearchTerms::fromTermAndMode($search->getTerm(), $search->isWholeWord());
        $ids = $this->entries->unreadMatchIdsSince(new EntrySearchQuery($userId, $terms), $since);

        if ($ids === []) {
            return new DigestSearchMatches([], 0);
        }

        $rows = $this->entries->rowsByIdsForUser($ids, $userId); // newest first
        $capped = \array_slice($rows, 0, self::PER_SEARCH);

        return new DigestSearchMatches($capped, \count($rows));
    }
}
```

```php
<?php
declare(strict_types=1);
namespace App\Service\Mail\Digest;

use App\Repository\EntryListRow;

/** @psalm-immutable */
final readonly class DigestSearchMatches
{
    /** @param list<EntryListRow> $entries */
    public function __construct(
        public array $entries,
        public int $totalCount,
    ) {
    }
}
```

Note: `rowsByIdsForUser` orders newest-first and re-applies the subscription gate, so slicing to 10 keeps the newest. `totalCount` counts all matched-and-subscribed rows (post-gate), which is what the "+N more" and subject total should reflect.

- [ ] **Step 7: Run the finder test, expect pass**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Digest/DigestEntryFinderTest.php`
Expected: PASS.

- [ ] **Step 8: Gates + commit**

```bash
cd backend && composer cs:fix && composer stan && composer md
git add backend/src/Repository/EntryListRepository.php backend/src/Service/Mail/Digest/DigestEntryFinder.php backend/src/Service/Mail/Digest/DigestSearchMatches.php backend/tests
git commit -m "feat(#636): find capped unread matches since a datetime for the digest"
```

---

### Task 12: DigestModel value objects + DigestLinkBuilder

**Files:**
- Create: `DigestModel.php`, `DigestGroup.php`, `DigestEntry.php`, `DigestLinkBuilder.php` under `backend/src/Service/Mail/Digest/`
- Test: `backend/tests/Service/Mail/Digest/DigestLinkBuilderTest.php`

**Interfaces produced:**
- `DigestEntry { string $title; string $feedName; string $shortDescription; string $url; }`
- `DigestGroup { string $term; int $totalCount; list<DigestEntry> $entries; bool $hasMore; string $moreUrl; }`
- `DigestModel { list<DigestGroup> $groups; int $totalCount; }` with `isEmpty(): bool`.
- `DigestLinkBuilder::entryUrl(int $entryId): string` → `{base}/?entry={id}`; `savedSearchUrl(string $term, bool $wholeWord): string` → `{base}/?q={term}` (whole-word appends a trailing space, url-encoded).

- [ ] **Step 1: Write the failing link-builder test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Service\Mail\Digest;

use App\Service\Mail\Digest\DigestLinkBuilder;
use PHPUnit\Framework\TestCase;

final class DigestLinkBuilderTest extends TestCase
{
    public function testEntryUrlUsesBareId(): void
    {
        $builder = new DigestLinkBuilder('https://lars-pohlmann.de/reader/');
        self::assertSame('https://lars-pohlmann.de/reader/?entry=514', $builder->entryUrl(514));
    }

    public function testSavedSearchUrlEncodesTermAndWholeWordSpace(): void
    {
        $builder = new DigestLinkBuilder('https://lars-pohlmann.de/reader');
        self::assertSame('https://lars-pohlmann.de/reader/?q=rust', $builder->savedSearchUrl('rust', false));
        self::assertSame('https://lars-pohlmann.de/reader/?q=rust%20', $builder->savedSearchUrl('rust', true));
    }
}
```

- [ ] **Step 2: Run, expect failure**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Digest/DigestLinkBuilderTest.php`
Expected: FAIL — class missing.

- [ ] **Step 3: Implement `DigestLinkBuilder`**

```php
<?php
declare(strict_types=1);
namespace App\Service\Mail\Digest;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds absolute reader deep-links from APP_FRONTEND_URL — the same base
 * AccountMailer uses for verification links, so the digest cannot drift from a
 * value already known to be correct on this host (#636). Deep links are query
 * params, not path segments, so no server rewrite is involved.
 */
final readonly class DigestLinkBuilder
{
    private string $base;

    public function __construct(
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        string $frontendUrl,
    ) {
        $this->base = rtrim($frontendUrl, '/') . '/';
    }

    public function entryUrl(int $entryId): string
    {
        return $this->base . '?entry=' . $entryId;
    }

    public function savedSearchUrl(string $term, bool $wholeWord): string
    {
        $query = $wholeWord ? $term . ' ' : $term;

        return $this->base . '?q=' . rawurlencode($query);
    }
}
```

- [ ] **Step 4: Run, expect pass**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Digest/DigestLinkBuilderTest.php`
Expected: PASS.

- [ ] **Step 5: Add the value objects**

```php
<?php
declare(strict_types=1);
namespace App\Service\Mail\Digest;

final readonly class DigestEntry
{
    public function __construct(
        public string $title,
        public string $feedName,
        public string $shortDescription,
        public string $url,
    ) {
    }
}
```

```php
<?php
declare(strict_types=1);
namespace App\Service\Mail\Digest;

final readonly class DigestGroup
{
    /** @param list<DigestEntry> $entries */
    public function __construct(
        public string $term,
        public int $totalCount,
        public array $entries,
        public bool $hasMore,
        public string $moreUrl,
    ) {
    }
}
```

```php
<?php
declare(strict_types=1);
namespace App\Service\Mail\Digest;

final readonly class DigestModel
{
    /** @param list<DigestGroup> $groups */
    public function __construct(
        public array $groups,
        public int $totalCount,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }
}
```

- [ ] **Step 6: Gates + commit**

```bash
cd backend && composer cs:fix && composer stan && composer md
git add backend/src/Service/Mail/Digest/DigestEntry.php backend/src/Service/Mail/Digest/DigestGroup.php backend/src/Service/Mail/Digest/DigestModel.php backend/src/Service/Mail/Digest/DigestLinkBuilder.php backend/tests/Service/Mail/Digest/DigestLinkBuilderTest.php
git commit -m "feat(#636): digest content model and deep-link builder"
```

---

### Task 13: DigestComposer

**Files:**
- Create: `backend/src/Service/Mail/Digest/DigestComposer.php`
- Test: `backend/tests/Service/Mail/Digest/DigestComposerTest.php`

**Interfaces produced:** `DigestComposer::compose(User $user, \DateTimeImmutable $since): ?DigestModel` — builds groups for the user's `includeInDigest` searches with matches since `$since`; returns `null` when no group has any entry (the empty-skip). Each group's entries are `DigestEntry`s built from `EntryListRow` + `DigestLinkBuilder`; `shortDescription` is a plain-text, length-capped summary.

Needs to read the row's fields. Confirm `EntryListRow` accessors first: `grep -n "public function get" backend/src/Repository/EntryListRow.php` (expect id, title, feed title, summary/description). Use the feed's display title (custom title if present, else feed title) for `feedName`, matching how the list shows it.

- [ ] **Step 1: Write the failing test**

Stub `DigestEntryFinder` to return, for search "rust", a `DigestSearchMatches` of 2 capped rows out of 3 total, and for search "go" an empty match. Assert:
- `compose` returns a `DigestModel` with exactly one group (term "rust"), `totalCount 3`, `hasMore true`, `entries` count 2, and `moreUrl` ending `?q=rust`.
- With all searches empty, `compose` returns `null`.
- A user with no `includeInDigest` searches returns `null`.

- [ ] **Step 2: Run, expect failure**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Digest/DigestComposerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement `DigestComposer`**

```php
<?php
declare(strict_types=1);
namespace App\Service\Mail\Digest;

use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\EntryListRow;
use App\Repository\SavedSearchRepository;

final readonly class DigestComposer
{
    private const int SUMMARY_MAX = 200;

    public function __construct(
        private SavedSearchRepository $savedSearches,
        private DigestEntryFinder $finder,
        private DigestLinkBuilder $links,
    ) {
    }

    public function compose(User $user, \DateTimeImmutable $since): ?DigestModel
    {
        $userId = (int) $user->getId();
        $groups = [];
        $total = 0;

        foreach ($this->savedSearches->findIncludedInDigestForUser($userId) as $search) {
            $matches = $this->finder->matchesSince($search, $userId, $since);
            if ($matches->totalCount === 0) {
                continue;
            }

            $groups[] = $this->group($search, $matches);
            $total += $matches->totalCount;
        }

        return $groups === [] ? null : new DigestModel($groups, $total);
    }

    private function group(SavedSearch $search, DigestSearchMatches $matches): DigestGroup
    {
        $entries = array_map(fn (EntryListRow $row): DigestEntry => $this->entry($row), $matches->entries);

        return new DigestGroup(
            $search->getTerm(),
            $matches->totalCount,
            $entries,
            $matches->totalCount > \count($entries),
            $this->links->savedSearchUrl($search->getTerm(), $search->isWholeWord()),
        );
    }

    private function entry(EntryListRow $row): DigestEntry
    {
        return new DigestEntry(
            $row->getTitle(),
            $row->getDisplayTitle(),           // custom feed title, else feed title
            $this->shortDescription($row),
            $this->links->entryUrl($row->getId()),
        );
    }

    private function shortDescription(EntryListRow $row): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($row->getSummary() ?? '')) ?? '');

        return mb_strlen($text) > self::SUMMARY_MAX
            ? rtrim(mb_substr($text, 0, self::SUMMARY_MAX)) . '…'
            : $text;
    }
}
```

Note: adjust `getTitle`/`getDisplayTitle`/`getSummary`/`getId` to the real `EntryListRow` accessor names found in Step 0. If `EntryListRow` has no summary field, either extend `rowQueryBuilder` to select `e.summary` (a wider change — prefer a dedicated projection) or drop `shortDescription` to an empty string and note it; decide during implementation and keep the model field.

- [ ] **Step 4: Add the repository finder**

Add to `backend/src/Repository/SavedSearchRepository.php`:

```php
    /**
     * The user's saved searches flagged for the email digest, in list order.
     *
     * @return list<SavedSearch>
     */
    public function findIncludedInDigestForUser(int $userId): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')->setParameter('user', $userId)
            ->andWhere('s.includeInDigest = true')
            ->orderBy('s.position', 'ASC')->addOrderBy('s.id', 'ASC')
            ->getQuery()->getResult();
    }
```

- [ ] **Step 5: Run, expect pass**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Digest/DigestComposerTest.php`
Expected: PASS.

- [ ] **Step 6: Gates + commit**

```bash
cd backend && composer cs:fix && composer stan && composer md
git add backend/src/Service/Mail/Digest/DigestComposer.php backend/src/Repository/SavedSearchRepository.php backend/tests/Service/Mail/Digest/DigestComposerTest.php
git commit -m "feat(#636): compose the digest content model, skipping empties"
```

---

### Task 14: DigestTextRenderer + emails translations

**Files:**
- Create: `backend/src/Service/Mail/Digest/DigestTextRenderer.php`
- Modify: `backend/translations/emails.en.xlf`, `backend/translations/emails.de.xlf`
- Test: `backend/tests/Service/Mail/Digest/DigestTextRendererTest.php`

**Interfaces produced:** `DigestRenderedMail { string $subject; string $body; }`; `DigestTextRenderer::render(DigestModel $model, string $locale): DigestRenderedMail`.

- [ ] **Step 1: Write the failing test**

Assert that rendering a two-group model in `en` produces a subject containing the total count and a body that: lists each term with its count, one line per entry (`title — feedName`), the short description, the entry URL, and a "more" line with `moreUrl` for a group with `hasMore`. Assert the `de` locale changes the fixed strings. Register the translator with the real `emails` domain (or a stub translator that echoes keys — prefer a functional test using the container translator so the xlf keys are exercised).

- [ ] **Step 2: Run, expect failure**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Digest/DigestTextRendererTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement the renderer**

```php
<?php
declare(strict_types=1);
namespace App\Service\Mail\Digest;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class DigestTextRenderer
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function render(DigestModel $model, string $locale): DigestRenderedMail
    {
        $subject = $this->translator->trans('digest.subject', ['%count%' => $model->totalCount], 'emails', $locale);

        $blocks = array_map(fn (DigestGroup $group): string => $this->group($group, $locale), $model->groups);
        $footer = $this->translator->trans('digest.footer', [], 'emails', $locale);

        return new DigestRenderedMail($subject, implode("\n\n", $blocks) . "\n\n" . $footer);
    }

    private function group(DigestGroup $group, string $locale): string
    {
        $heading = $this->translator->trans(
            'digest.group_heading',
            ['%term%' => $group->term, '%count%' => $group->totalCount],
            'emails',
            $locale,
        );

        $lines = array_map(fn (DigestEntry $entry): string => $this->entry($entry), $group->entries);
        $block = $heading . "\n" . implode("\n", $lines);

        if ($group->hasMore) {
            $block .= "\n" . $this->translator->trans(
                'digest.more',
                ['%url%' => $group->moreUrl],
                'emails',
                $locale,
            );
        }

        return $block;
    }

    private function entry(DigestEntry $entry): string
    {
        $description = $entry->shortDescription === '' ? '' : "\n  " . $entry->shortDescription;

        return \sprintf('• %s — %s%s\n  %s', $entry->title, $entry->feedName, $description, $entry->url);
    }
}
```

Add `DigestRenderedMail`:

```php
<?php
declare(strict_types=1);
namespace App\Service\Mail\Digest;

final readonly class DigestRenderedMail
{
    public function __construct(
        public string $subject,
        public string $body,
    ) {
    }
}
```

- [ ] **Step 4: Add translation keys** to both `emails.en.xlf` and `emails.de.xlf` (match the file's existing `<trans-unit>` structure). Keys: `digest.subject` (`New in your feeds: %count% entries`), `digest.group_heading` (`%term% (%count%)`), `digest.more` (`+ more → %url%`), `digest.footer` (`Manage your digest in Settings → Email.`). Provide German equivalents.

- [ ] **Step 5: Run, expect pass**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Digest/DigestTextRendererTest.php`
Expected: PASS.

- [ ] **Step 6: Gates + commit**

```bash
cd backend && composer cs:fix && composer stan && composer md
git add backend/src/Service/Mail/Digest/DigestTextRenderer.php backend/src/Service/Mail/Digest/DigestRenderedMail.php backend/translations/emails.en.xlf backend/translations/emails.de.xlf backend/tests/Service/Mail/Digest/DigestTextRendererTest.php
git commit -m "feat(#636): render the digest to plain text"
```

---

### Task 15: DigestMailer + gated decorator

**Files:**
- Create: `DigestMailerInterface.php`, `DigestMailer.php`, `MailGatedDigestMailer.php` under `backend/src/Service/Mail/Digest/`
- Test: `backend/tests/Service/Mail/Digest/MailGatedDigestMailerTest.php`, `backend/tests/Service/Mail/Digest/DigestMailerTest.php`

**Interfaces produced:** `DigestMailerInterface::send(User $user, DigestModel $model): void`. `DigestMailer` renders (per `User::getLocale()`) and sends a plain-text `Email` from `MAIL_FROM`/`MAIL_FROM_NAME` to `User::getEmail()`. `MailGatedDigestMailer` (`#[AsDecorator(decorates: DigestMailer::class)]`) skips + logs when `MailCapability` is off.

- [ ] **Step 1: Write the failing gated-decorator test**

Assert that with mail disabled the decorator does not call the inner mailer and logs at info; with mail enabled it delegates. Mirror `MailGatedAccountMailer`'s test (find it: `grep -rln "MailGatedAccountMailer" backend/tests`).

- [ ] **Step 2: Run, expect failure**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Digest/MailGatedDigestMailerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement the interface + mailer**

```php
<?php
declare(strict_types=1);
namespace App\Service\Mail\Digest;

use App\Entity\User;

interface DigestMailerInterface
{
    public function send(User $user, DigestModel $model): void;
}
```

```php
<?php
declare(strict_types=1);
namespace App\Service\Mail\Digest;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final readonly class DigestMailer implements DigestMailerInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private DigestTextRenderer $renderer,
        #[Autowire('%env(MAIL_FROM)%')]
        private string $fromAddress,
        #[Autowire('%env(MAIL_FROM_NAME)%')]
        private string $fromName,
    ) {
    }

    public function send(User $user, DigestModel $model): void
    {
        $mail = $this->renderer->render($model, $user->getLocale());

        $this->mailer->send(
            (new Email())
                ->from(new Address($this->fromAddress, $this->fromName))
                ->to($user->getEmail())
                ->subject($mail->subject)
                ->text($mail->body),
        );
    }
}
```

```php
<?php
declare(strict_types=1);
namespace App\Service\Mail\Digest;

use App\Entity\User;
use App\Service\Mail\MailCapability;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

#[AsDecorator(decorates: DigestMailer::class)]
final readonly class MailGatedDigestMailer implements DigestMailerInterface
{
    public function __construct(
        private DigestMailerInterface $inner,
        private MailCapability $mail,
        private LoggerInterface $logger,
    ) {
    }

    public function send(User $user, DigestModel $model): void
    {
        if (!$this->mail->isEnabled()) {
            $this->logger->info('Mail disabled (MAIL_DISABLED); skipped digest mail to {email}.', [
                'email' => $user->getEmail(),
            ]);

            return;
        }

        $this->inner->send($user, $model);
    }
}
```

- [ ] **Step 4: Run, expect pass**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Digest/MailGatedDigestMailerTest.php tests/Service/Mail/Digest/DigestMailerTest.php`
Expected: PASS.

- [ ] **Step 5: Gates + commit**

```bash
cd backend && composer cs:fix && composer stan && composer md
git add backend/src/Service/Mail/Digest/DigestMailerInterface.php backend/src/Service/Mail/Digest/DigestMailer.php backend/src/Service/Mail/Digest/MailGatedDigestMailer.php backend/tests/Service/Mail/Digest
git commit -m "feat(#636): send the digest through a mail-gated mailer"
```

---

### Task 16: POST /api/me/digest/test (+ rate limiter)

**Files:**
- Create: `backend/src/Dto/Me/SendTestDigestRequest.php`
- Modify: `backend/src/Controller/Api/MeController.php`, `backend/config/packages/rate_limiter.yaml`
- Create: `backend/src/Service/Mail/Digest/SendTestDigest.php`
- Test: `backend/tests/Controller/Api/MeDigestTestControllerTest.php`

**Interfaces produced:** `SendTestDigestRequest { int $days; }` (`Assert\Range(min:1,max:30)`); `SendTestDigest::send(User $user, int $days): bool` — composes over `[now - days, now]`, sends if non-empty, returns whether a mail was produced; does NOT touch `digestLastSentAt`.

- [ ] **Step 1: Add the `digest_test` limiter** to `rate_limiter.yaml` (per-user, like `refresh`), with a documenting comment:

```yaml
        # Per-user cap on the "send a test digest" button (#636). Each accepted
        # call hands one mail to the relay, so this guards outbound abuse from a
        # signed-in account. Keyed on the user id; sliding window, same pool.
        digest_test:
            policy: 'sliding_window'
            limit: 5
            interval: '15 minutes'
            cache_pool: cache.rate_limiter
```

- [ ] **Step 2: Write the failing functional test**

Verified user with an included saved search that has a recent match → `POST /api/me/digest/test` `{"days":7}` → 200, one mail queued. Assert the 6th call within the window returns 429. Assert an unverified user gets 403 (see gating in Step 4). Assert `digestLastSentAt` is unchanged.

- [ ] **Step 3: Run, expect failure**

Run: `cd backend && php bin/phpunit tests/Controller/Api/MeDigestTestControllerTest.php`
Expected: FAIL — route missing.

- [ ] **Step 4: Implement `SendTestDigest`**

```php
<?php
declare(strict_types=1);
namespace App\Service\Mail\Digest;

use App\Entity\User;
use Psr\Clock\ClockInterface;

/**
 * The "send me a test digest" action (#636): compose over the last N days and
 * send immediately, WITHOUT advancing digestLastSentAt — a preview, not a real
 * send. Returns whether there was anything to send.
 */
final readonly class SendTestDigest
{
    public function __construct(
        private DigestComposer $composer,
        private DigestMailerInterface $mailer,
        private ClockInterface $clock,
    ) {
    }

    public function send(User $user, int $days): bool
    {
        $since = $this->clock->now()->modify(\sprintf('-%d days', $days));
        $model = $this->composer->compose($user, $since);
        if (null === $model) {
            return false;
        }

        $this->mailer->send($user, $model);

        return true;
    }
}
```

- [ ] **Step 5: Add the DTO + controller action**

```php
<?php
declare(strict_types=1);
namespace App\Dto\Me;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SendTestDigestRequest
{
    public function __construct(
        #[Assert\Range(min: 1, max: 30)]
        public int $days,
    ) {
    }
}
```

In `MeController` (inject `RateLimiterFactoryInterface $digestTestLimiter`, `RateLimitGuard $rateLimitGuard`, `SendTestDigest $sendTestDigest`; and `MailCapability` is already there after Task 6):

```php
    #[Route('/api/me/digest/test', name: 'api_me_digest_test', methods: ['POST'])]
    public function sendTestDigest(
        #[CurrentUser] User $user,
        #[MapRequestPayload] SendTestDigestRequest $request,
    ): JsonResponse {
        if (!$this->mail->isEnabled() || !$user->isEmailVerified()) {
            throw new AccessDeniedHttpException('Mail is unavailable for this account.');
        }

        $this->rateLimitGuard->enforceForUser($this->digestTestLimiter, $user);
        $sent = $this->sendTestDigest->send($user, $request->days);

        return new JsonResponse(['sent' => $sent]);
    }
```

Add `use` imports: `SendTestDigestRequest`, `SendTestDigest`, `RateLimitGuard`, `RateLimiterFactoryInterface`, `AccessDeniedHttpException`. Confirm `RateLimitGuard::enforceForUser` throws a 429 (`TooManyRequestsHttpException`) — it does in `FeedPreviewController`'s usage.

Thin-controller note: the gate is a single guard expression; if `ThinControllerRule` flags it, fold the mail/verified check into `SendTestDigest` (throw a typed `DigestUnavailable` exception mapped to 403) and keep the action to guard-limiter-delegate.

- [ ] **Step 6: Run, expect pass**

Run: `cd backend && php bin/phpunit tests/Controller/Api/MeDigestTestControllerTest.php`
Expected: PASS. (Rate-limit test needs the limiter pool cleared between cases — follow the pattern the registration limiter tests use.)

- [ ] **Step 7: Gates + commit**

```bash
cd backend && composer cs:fix && composer stan && composer md
git add backend/src/Dto/Me/SendTestDigestRequest.php backend/src/Service/Mail/Digest/SendTestDigest.php backend/src/Controller/Api/MeController.php backend/config/packages/rate_limiter.yaml backend/tests/Controller/Api/MeDigestTestControllerTest.php
git commit -m "feat(#636): POST /api/me/digest/test with per-user rate limit"
```

---

# Slice 5 — Scheduling

### Task 17: SendDueDigests service

**Files:**
- Create: `backend/src/Service/Mail/Digest/SendDueDigests.php`
- Modify: `backend/src/Repository/PreferencesRepository.php` (a finder for enabled digests)
- Test: `backend/tests/Service/Mail/Digest/SendDueDigestsTest.php`

**Interfaces produced:** `SendDueDigests::run(): DigestSweepReport` where `DigestSweepReport { int $considered; int $sent; int $skippedEmpty; }`. For each user with `digestEnabled`, mail on, verified, and `DigestSchedule::mostRecentDue(...)` newer than `digestLastSentAt`: compose over `[digestLastSentAt ?? occurrence, now]`, send if non-empty and advance `digestLastSentAt` to the occurrence; on empty, leave it. Verification and mail-capability are re-checked here (the sweep is the security boundary, not just the UI).

- [ ] **Step 1: Add the repository finder**

```php
    /**
     * Every preferences row with the digest enabled, joined to its user, for the
     * scheduler to test dueness against. Enabled is the only cheap pre-filter;
     * dueness needs the timezone maths, so it is applied in PHP (#636).
     *
     * @return list<Preferences>
     */
    public function findWithDigestEnabled(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.digestEnabled = true')
            ->getQuery()->getResult();
    }
```

- [ ] **Step 2: Write the failing test**

Given two enabled users — one due (occurrence after `digestLastSentAt`) with matches, one not due (already sent this period) — assert the composer/mailer is called once, `digestLastSentAt` advanced to the occurrence for the due user, and the report is `considered ≥ 2, sent 1`. Add a case: due user with no matches → `skippedEmpty 1`, `digestLastSentAt` unchanged. Add a case: due user unverified or mail-off → skipped, not counted as sent.

- [ ] **Step 3: Run, expect failure**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Digest/SendDueDigestsTest.php`
Expected: FAIL.

- [ ] **Step 4: Implement `SendDueDigests` + `DigestSweepReport`**

```php
<?php
declare(strict_types=1);
namespace App\Service\Mail\Digest;

use App\Entity\Preferences;
use App\Repository\PreferencesRepository;
use App\Service\Mail\MailCapability;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class SendDueDigests
{
    public function __construct(
        private PreferencesRepository $preferences,
        private DigestSchedule $schedule,
        private DigestComposer $composer,
        private DigestMailerInterface $mailer,
        private MailCapability $mail,
        private ClockInterface $clock,
        private EntityManagerInterface $em,
    ) {
    }

    public function run(): DigestSweepReport
    {
        if (!$this->mail->isEnabled()) {
            return new DigestSweepReport(0, 0, 0);
        }

        $now = $this->clock->now();
        $considered = 0;
        $sent = 0;
        $skippedEmpty = 0;

        foreach ($this->preferences->findWithDigestEnabled() as $prefs) {
            ++$considered;
            $occurrence = $this->dueOccurrence($prefs, $now);
            if (null === $occurrence) {
                continue;
            }

            $user = $prefs->getUser();
            if (!$user->isEmailVerified()) {
                continue;
            }

            $since = $prefs->getDigestLastSentAt() ?? $occurrence;
            $model = $this->composer->compose($user, $since);
            if (null === $model) {
                ++$skippedEmpty;
                continue;
            }

            $this->mailer->send($user, $model);
            $prefs->setDigestLastSentAt($occurrence);
            $this->em->flush();
            ++$sent;
        }

        return new DigestSweepReport($considered, $sent, $skippedEmpty);
    }

    private function dueOccurrence(Preferences $prefs, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $occurrence = $this->schedule->mostRecentDue($prefs, $now);
        if (null === $occurrence) {
            return null;
        }

        $lastSent = $prefs->getDigestLastSentAt();

        return (null === $lastSent || $lastSent < $occurrence) ? $occurrence : null;
    }
}
```

```php
<?php
declare(strict_types=1);
namespace App\Service\Mail\Digest;

final readonly class DigestSweepReport
{
    public function __construct(
        public int $considered,
        public int $sent,
        public int $skippedEmpty,
    ) {
    }

    /** @return array{considered: int, sent: int, skippedEmpty: int} */
    public function toArray(): array
    {
        return ['considered' => $this->considered, 'sent' => $this->sent, 'skippedEmpty' => $this->skippedEmpty];
    }
}
```

- [ ] **Step 5: Run, expect pass**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Digest/SendDueDigestsTest.php`
Expected: PASS.

- [ ] **Step 6: Gates + commit**

```bash
cd backend && composer cs:fix && composer stan && composer md
git add backend/src/Service/Mail/Digest/SendDueDigests.php backend/src/Service/Mail/Digest/DigestSweepReport.php backend/src/Repository/PreferencesRepository.php backend/tests/Service/Mail/Digest/SendDueDigestsTest.php
git commit -m "feat(#636): sweep and send due digests, advancing the sent marker"
```

---

### Task 18: Worker message, handler, and schedule entry

**Files:**
- Create: `backend/src/Service/Worker/Message/SendDueDigests.php` (marker), `backend/src/Service/Worker/MessageHandler/SendDueDigestsHandler.php`
- Modify: `backend/src/Service/Worker/WorkerSchedule.php`
- Test: `backend/tests/Service/Worker/SendDueDigestsHandlerTest.php`

**Interfaces:** the marker `App\Service\Worker\Message\SendDueDigests` (empty class, mirror `RefreshDueFeeds`). Handler `#[AsMessageHandler]` invokes the `SendDueDigests` service. Note the name collision: the **message** is `Service\Worker\Message\SendDueDigests`, the **service** is `Service\Mail\Digest\SendDueDigests`; alias one in `use` to avoid confusion.

- [ ] **Step 1: Write the failing handler test**

Assert `SendDueDigestsHandler::__invoke(new SendDueDigests())` calls the service's `run()` once. (Match the style of the existing `RefreshDueFeeds` handler test — `grep -rln "RefreshDueFeeds" backend/tests`.)

- [ ] **Step 2: Run, expect failure**

Expected: FAIL — classes missing.

- [ ] **Step 3: Create the marker + handler**

```php
<?php
declare(strict_types=1);
namespace App\Service\Worker\Message;

/** Scheduler tick: send every digest that is due (#636). A sweep — it does
 *  whatever is outstanding when it runs, so a missed tick catches up in one. */
final readonly class SendDueDigests
{
}
```

```php
<?php
declare(strict_types=1);
namespace App\Service\Worker\MessageHandler;

use App\Service\Mail\Digest\SendDueDigests as SendDueDigestsService;
use App\Service\Worker\Message\SendDueDigests;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendDueDigestsHandler
{
    public function __construct(private SendDueDigestsService $sendDueDigests)
    {
    }

    public function __invoke(SendDueDigests $message): void
    {
        $this->sendDueDigests->run();
    }
}
```

- [ ] **Step 4: Add the schedule entry**

In `WorkerSchedule::getSchedule()` add before `.stateful(...)`:

```php
            ->add(RecurringMessage::every('1 hour', new SendDueDigests()))
```

Add `use App\Service\Worker\Message\SendDueDigests;`. Update the class docblock's "Four entries" count to five and describe the digest sweep.

- [ ] **Step 5: Run, expect pass**

Run: `cd backend && php bin/phpunit tests/Service/Worker/SendDueDigestsHandlerTest.php`
Expected: PASS.

- [ ] **Step 6: Gates + commit**

```bash
cd backend && composer cs:fix && composer stan && composer md
git add backend/src/Service/Worker backend/tests/Service/Worker/SendDueDigestsHandlerTest.php
git commit -m "feat(#636): hourly worker schedule entry for due digests"
```

---

### Task 19: Fold the digest sweep into MaintenanceTick

**Files:**
- Modify: `backend/src/Service/Maintenance/MaintenanceTick.php`, `backend/src/Service/Maintenance/MaintenanceTickReport.php`
- Test: `backend/tests/Service/Maintenance/MaintenanceTickTest.php` (extend)

**Interfaces:** `MaintenanceTickReport` gains a `digests` array; `MaintenanceTick::run()` calls `SendDueDigests::run()` after the recommendation sweep and includes its report.

- [ ] **Step 1: Write/extend the failing test**

Assert `MaintenanceTick::run()` invokes the digest sweep and the report's `toArray()` carries a `digests` key with `considered/sent/skippedEmpty`. Assert the digest sweep still runs when refresh aborted? Decide: the digest sweep uses the shared EM too, so if refresh aborted (EM closed) the digest sweep must be skipped that tick, exactly like the recommendation half. Assert that on `refresh->isAborted()` the digests report is a skipped marker.

- [ ] **Step 2: Run, expect failure**

Expected: FAIL.

- [ ] **Step 3: Update `MaintenanceTick`**

Inject `SendDueDigests $sendDueDigests`. After the recommendation sweep (only on the non-aborted path):

```php
        $recommendations = $this->forYouSweep->sweepOnce();
        $digests = $this->sendDueDigests->run()->toArray();

        return new MaintenanceTickReport($refresh->toArray(), $recommendations->toArray(), $digests);
```

On the aborted path, pass a skipped digests marker (mirror `skippedRecommendations()`):

```php
    /** @return array{considered: int, sent: int, skippedEmpty: int, skipped: string} */
    private function skippedDigests(): array
    {
        return ['considered' => 0, 'sent' => 0, 'skippedEmpty' => 0, 'skipped' => 'refresh aborted: the shared EntityManager is unusable this tick'];
    }
```

Update `MaintenanceTickReport` constructor + `toArray()` to carry `$digests` under a `digests` key.

- [ ] **Step 4: Run, expect pass**

Run: `cd backend && php bin/phpunit tests/Service/Maintenance/MaintenanceTickTest.php`
Expected: PASS.

- [ ] **Step 5: Gates + full suite + commit**

```bash
cd backend && composer cs:fix && composer stan && composer md && php bin/phpunit
git add backend/src/Service/Maintenance backend/tests/Service/Maintenance/MaintenanceTickTest.php
git commit -m "feat(#636): drive due digests from the worker-less maintenance tick"
```

---

# Slice 6 — Frontend

### Task 20: Digest client state (models, service, writer, auth wiring)

**Files:**
- Modify: `frontend/src/app/core/auth.service.ts` (the `CurrentUser` type + adopt/reset)
- Create: `frontend/src/app/core/digest.service.ts`, `core/digest-writer.ts`, `core/http-digest-writer.ts`
- Test: `frontend/src/app/core/digest.service.spec.ts`

**Interfaces produced:**
- `CurrentUser` gains `mail: { enabled: boolean }`, `emailVerified: boolean`, and `preferences.digest: { enabled: boolean; cadence: 'daily'|'weekly'; sendHour: number; weekday: number }`.
- `DigestConfig` interface = that digest shape.
- `DIGEST_WRITER` token with `write(config: DigestConfig): Observable<boolean>`; `HttpDigestWriter` → `PATCH /api/me/digest` body `{ enabled, cadence, sendHour, weekday }`.
- `DigestService` signals: `enabled`, `cadence`, `sendHour`, `weekday`, `saveFailed`; `adopt(user)`, `reset()`, and setters that apply locally then write the whole config.

- [ ] **Step 1: Write the failing service test**

Model on `preferences.service` tests (`grep -rln "PreferencesService\|PREFERENCES_WRITER" frontend/src`). Provide a stub `DIGEST_WRITER`; assert a setter applies the signal locally and calls `write` with the full config; assert `saveFailed` flips on a `false` result; assert `adopt` seeds all four signals and `reset` clears them.

- [ ] **Step 2: Run, expect failure**

Run: `docker compose exec -T frontend npx jest src/app/core/digest.service.spec.ts` (or `./node_modules/.bin/jest` — never `npx jest` on a `+` path, per memory)
Expected: FAIL.

- [ ] **Step 3: Create the writer token + impl** (mirror `preferences-writer.ts` / `http-preferences-writer.ts`)

```ts
// core/digest-writer.ts
import { InjectionToken } from '@angular/core';
import { Observable, of } from 'rxjs';

export interface DigestConfig {
  enabled: boolean;
  cadence: 'daily' | 'weekly';
  sendHour: number;
  weekday: number;
}

export interface DigestWriter {
  write(config: DigestConfig): Observable<boolean>;
}

export const DIGEST_WRITER = new InjectionToken<DigestWriter>('DIGEST_WRITER', {
  providedIn: 'root',
  factory: (): DigestWriter => ({ write: () => of(true) }),
});
```

```ts
// core/http-digest-writer.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, of } from 'rxjs';
import { API_BASE_URL } from './api';
import { DigestConfig, DigestWriter } from './digest-writer';

@Injectable({ providedIn: 'root' })
export class HttpDigestWriter implements DigestWriter {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  write(config: DigestConfig): Observable<boolean> {
    return this.http.patch(`${this.base}/api/me/digest`, config).pipe(
      map(() => true),
      catchError(() => of(false)),
    );
  }
}
```

Register `HttpDigestWriter` for `DIGEST_WRITER` in the same provider file where `HttpPreferencesWriter` is bound (find it: `grep -rn "PREFERENCES_WRITER" frontend/src/app --include=*.ts | grep -i provide`).

- [ ] **Step 4: Implement `DigestService`** (mirror `PreferencesService`, but writing the whole config on each change)

Signals `enabled/cadence/sendHour/weekday/saveFailed`; a private `writeAll()` that reads the four signals into a `DigestConfig` and calls `this.writer.write(config)`. Setters call `writeAll()`. `adopt(user)` copies `user.preferences.digest.*`; `reset()` restores defaults (`false/'daily'/8/1`).

- [ ] **Step 5: Extend `CurrentUser` and wire auth**

Add the new fields to the `CurrentUser` interface in `auth.service.ts`. In `loadMe()` add `this.digest.adopt(u)` beside `this.preferences.adopt(u)`; in `clear()` add `this.digest.reset()`. Inject `DigestService`.

- [ ] **Step 6: Run, expect pass**

Run: `docker compose exec -T frontend npx jest src/app/core/digest.service.spec.ts`
Expected: PASS.

- [ ] **Step 7: Gates + commit**

```bash
cd frontend && npm run check
git add frontend/src/app/core
git commit -m "feat(#636): digest client state, writer and auth wiring"
```

---

### Task 21: The Email settings section (nav, route, gated states, resend)

**Files:**
- Modify: `frontend/src/app/settings/settings-sections.ts`, `settings/settings.routes.ts`
- Create: `frontend/src/app/settings/email-section.component.{ts,html,scss}`
- Modify: i18n `en.json` + `de.json`
- Test: `frontend/src/app/settings/email-section.component.spec.ts`

**Interfaces:** the component reads `AuthService.user()` for `mail.enabled` + `emailVerified`, `DigestService` for the config, and calls `POST /api/me/resend-verification` (add `resendVerification()` to a small API or `AuthService`). Three render states as in the spec.

- [ ] **Step 1: Add the section entry + route**

`settings-sections.ts`: insert after the `preferences` entry:
```ts
  { path: 'email', icon: 'mail', labelKey: 'settings.email.title', group: 'general' },
```
`settings.routes.ts`: add a child route:
```ts
      {
        path: 'email',
        title: sectionLabelKey('email'),
        loadComponent: () =>
          import('./email-section.component').then((m) => m.EmailSectionComponent),
      },
```

- [ ] **Step 2: Write the failing component test**

Render the component with a stub `AuthService.user()` in each of the three states; assert: mail-disabled shows the disabled box and no toggle interactivity; unverified shows the resend button and disabled controls; verified shows the enabled master toggle + cadence + hour + weekday(when weekly). Assert clicking resend calls the resend API once.

- [ ] **Step 3: Run, expect failure**

Run: `docker compose exec -T frontend npx jest src/app/settings/email-section.component.spec.ts`
Expected: FAIL — component missing.

- [ ] **Step 4: Build the component** (model on `preferences-section.component.*`)

- `.ts`: standalone, OnPush, imports the shared settings primitives + `ToggleComponent` + `TranslocoPipe` + `IconComponent`; injects `AuthService`, `DigestService`, and a resend caller. Computed `state(): 'mailDisabled' | 'unverified' | 'ready'` from `auth.user()`.
- `.html`: an `app-settings-stack` → `app-settings-group icon="mail"`; at the top, an `@if` block per state rendering the callout (a local `.callout` element styled in the sibling `.scss` using theme tokens — check `docs/design-language.md` for an existing callout token before inventing one). The `ready` branch renders the controls; cadence is a segmented control or a `<select>`; the weekday `@if (digest.cadence() === 'weekly')`.
- `.scss`: only the `.callout` and `.muted` chrome; spacing/dividers come from the primitives. No hex, no raw px — use theme tokens.

Keep the included-searches list and the test-mail row for Task 22 (a follow-up commit) so this task stays reviewable.

- [ ] **Step 5: Add i18n keys** to both dictionaries: `settings.email.title`, `settings.email.mailDisabled`, `settings.email.unverified`, `settings.email.resend`, `settings.email.enable`, `settings.email.cadence`, `settings.email.daily`, `settings.email.weekly`, `settings.email.sendHour`, `settings.email.weekday`, plus weekday names if not already present.

- [ ] **Step 6: Run, expect pass**

Run: `docker compose exec -T frontend npx jest src/app/settings/email-section.component.spec.ts`
Expected: PASS.

- [ ] **Step 7: Gates + commit**

```bash
cd frontend && npm run check
git add frontend/src/app/settings frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#636): Email settings section with gated states and resend"
```

---

### Task 22: Included-searches list + test-mail row

**Files:**
- Modify: `frontend/src/app/settings/email-section.component.{ts,html,scss}`
- Modify: `frontend/src/app/reader/saved-searches.store.ts` (reuse) or a settings-scoped fetch
- Modify: i18n
- Test: extend `email-section.component.spec.ts`

**Interfaces consumed:** `SavedSearchesStore.savedSearches()` (add `includeInDigest` in Task 23 — sequence Task 23 before this if executing strictly; the store field is needed here) and `SavedSearchesStore.setIncludeInDigest(id, value)` (Task 23). `DigestService`/a test-mail caller → `POST /api/me/digest/test`.

- [ ] **Step 1: Write the failing test**

Assert the section lists the user's saved searches each with a toggle bound to `includeInDigest`, toggling calls `setIncludeInDigest`; assert the test-mail row has a days input (1–30, default 7) and a send button that calls the test endpoint with the chosen days and is disabled when no search is included.

- [ ] **Step 2–4: Implement**

- Add an "Included saved searches" `app-settings-group` listing `savedSearches()` rows, each an `app-toggle` → `store.setIncludeInDigest(id, checked)`.
- Add a test-mail `app-settings-row`: a number input (`min=1 max=30`, default 7) + a button calling a `sendTest(days)` method that `POST`s `/api/me/digest/test`; disable it when `savedSearches().every((s) => !s.includeInDigest)`. Surface a rate-limit (429) and empty (`sent:false`) result as a small inline message.
- i18n keys for the list heading, the empty state, the test row + result messages, in both dictionaries.

- [ ] **Step 5: Run, expect pass**; then `npm run check`.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/settings frontend/public/i18n
git commit -m "feat(#636): included-searches list and test-mail row in Email section"
```

---

### Task 23: Saved-search includeInDigest on the frontend model + store + API

**Files:**
- Modify: `frontend/src/app/reader/models.ts`, `reader/reader-api.ts`, `reader/saved-searches.store.ts`
- Test: `frontend/src/app/reader/saved-searches.store.spec.ts` (extend)

**Interfaces produced:** `SavedSearchWire.includeInDigest: boolean`, `SavedSearchDto.includeInDigest: boolean`; `ReaderApi.updateSavedSearch(id, { includeInDigest }): Observable<{ savedSearch: SavedSearchWire }>`; `SavedSearchesStore.setIncludeInDigest(id: number, value: boolean): void` (optimistic patch + PATCH, reverting on error).

- [ ] **Step 1: Write the failing store test**

Assert `setIncludeInDigest(id, true)` patches the loaded row's flag immediately and calls `api.updateSavedSearch`; on error it reverts. Assert the wire→DTO map carries `includeInDigest`.

- [ ] **Step 2: Run, expect failure**; then implement:

- `models.ts`: add `includeInDigest: boolean` to both `SavedSearchWire` and `SavedSearchDto`.
- store `savedSearches` computed: copy `includeInDigest: wire.includeInDigest` through.
- `reader-api.ts`:
```ts
  updateSavedSearch(id: number, body: { includeInDigest: boolean }): Observable<{ savedSearch: SavedSearchWire }> {
    return this.http.patch<{ savedSearch: SavedSearchWire }>(`${this.base}/api/saved-searches/${id}`, body);
  }
```
- store `setIncludeInDigest`: optimistic update of `loaded`, subscribe to `updateSavedSearch`, revert on error.

- [ ] **Step 3: Run, expect pass**; `npm run check`.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/app/reader/models.ts frontend/src/app/reader/reader-api.ts frontend/src/app/reader/saved-searches.store.ts frontend/src/app/reader/saved-searches.store.spec.ts
git commit -m "feat(#636): frontend saved-search includeInDigest model, api and store"
```

---

### Task 24: Reader sidebar mail icon + confirm dialog

**Files:**
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.{ts,html,scss}`, `reader/reader-shell.component.{ts,html}`
- Modify: i18n
- Test: `frontend/src/app/reader/sidebar/sidebar.component.spec.ts` (extend), `reader/reader-shell.component.spec.ts` (extend)

**Interfaces:** sidebar gains `mailEnabled = input<boolean>(false)` and `toggleDigest = output<SavedSearchDto>()`; shell binds `[mailEnabled]="auth.user()?.mail?.enabled ?? false"` and `(toggleDigest)="confirmToggleDigest($event)"`, opening `ConfirmDialogComponent` and calling `savedSearchesStore.setIncludeInDigest(...)` on confirm.

- [ ] **Step 1: Write the failing tests**

- Sidebar: when `mailEnabled` is false, no mail icon renders on saved-search rows; when true, each row has a mail icon button that emits `toggleDigest` with the row; the icon carries a `.muted` class when `!includeInDigest`.
- Shell: `confirmToggleDigest(row)` opens the confirm dialog and, on `true`, calls `setIncludeInDigest(row.id, !row.includeInDigest)`; on `false`, does nothing. Model on the existing `confirmRemoveSavedSearch` test.

- [ ] **Step 2: Run, expect failure**

- [ ] **Step 3: Implement the sidebar row action**

In `sidebar.component.html`, inside the saved-search `@for` row, after the term/badges, add (only when mail is enabled):
```html
@if (mailEnabled()) {
  <button
    class="dots digest-toggle"
    type="button"
    [attr.aria-pressed]="saved.includeInDigest"
    [attr.aria-label]="'reader.digest.toggleAria' | transloco: { term: saved.term }"
    (click)="toggleDigest.emit(saved); $event.stopPropagation(); $event.preventDefault()"
  >
    <app-icon name="mail" size="sm" [class.muted]="!saved.includeInDigest" />
  </button>
}
```
`.scss`: `.digest-toggle .muted { opacity: <token>; }` — a low opacity via a theme token/opacity var (no raw literal if Stylelint forbids; an `opacity` number is allowed, but keep it a named `--…` if the file has one). The icon stays outlined in both states (do **not** set `[fill]`).

`sidebar.component.ts`: add `readonly mailEnabled = input<boolean>(false);` and `readonly toggleDigest = output<SavedSearchDto>();`.

Note: the saved-search row is currently an `<a>` navigation link; the button must stop propagation so a click on the icon does not also navigate. Verify the row still navigates when the term (not the icon) is clicked.

- [ ] **Step 4: Wire the shell**

In `reader-shell.component.html`, on `<app-sidebar>`, add `[mailEnabled]="(auth.user()?.mail?.enabled) ?? false"` and `(toggleDigest)="confirmToggleDigest($event)"`. In `reader-shell.component.ts` add `confirmToggleDigest(row: SavedSearchDto)` modelled on `confirmRemoveSavedSearch` (open `ConfirmDialogComponent` with `title/message/confirmLabel` keyed by whether it is enabling or disabling), calling `savedSearchesStore.setIncludeInDigest(row.id, !row.includeInDigest)` on `true`. Inject `AuthService` if not already present.

- [ ] **Step 5: i18n** keys for the aria-label and the two confirm dialogs (enable/disable) in both dictionaries.

- [ ] **Step 6: Run, expect pass**; `npm run check`.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/sidebar frontend/src/app/reader/reader-shell.component.ts frontend/src/app/reader/reader-shell.component.html frontend/public/i18n
git commit -m "feat(#636): reader sidebar digest toggle with confirmation"
```

---

## Final verification (before the PR)

- [ ] `cd backend && composer check && composer md && php bin/phpunit`
- [ ] `docker compose exec php vendor/bin/phpunit` (MySQL leg)
- [ ] Migrate-from-empty on both engines + `doctrine:schema:validate` (Task 4 steps).
- [ ] `composer infection:diff` clean against `minMsi`.
- [ ] PhpStorm inspections on every changed PHP file — block on ERROR/WARNING (`mcp__phpstorm__lint_files`).
- [ ] `cd frontend && npm run check`.
- [ ] Scan today's dev log: `ls -t backend/var/log/dev-*.log | head -1` — no new deprecations/errors from digest work.
- [ ] Manual smoke: enable the digest, flag a search, use "Send test", confirm the mail lands with working `?entry=` and `?q=` links; toggle the sidebar icon and confirm the dialog + persistence.
- [ ] PR body says `Closes #636`; verify the issue auto-closes on merge into `develop`.

## Self-review notes (author)

- **Spec coverage:** model (T1–T4), timezone/dueness (T5), read payloads (T6–T7), write endpoints (T8–T10), engine (T11–T16), scheduling both shapes (T17–T19), Email section + gated states + resend + test (T21–T22), per-search flag in two places (T23–T24). Empty-skip (T13, T17), first-enable seeding (T8), verified gate (T3, T16, T17), deep-link base reuse (T12).
- **Open confirmations for the executor** (do not guess — verify against the code): `UserStatus` enum string values used in the backfill; `EntryListRow` accessor names and whether it exposes a summary (T13 note); the provider-file that binds `HttpPreferencesWriter` (to bind `HttpDigestWriter` the same way); `RateLimitGuard::enforceForUser` throwing behaviour; the `emails.*.xlf` trans-unit structure.
- **Naming consistency:** message class `Service\Worker\Message\SendDueDigests` vs service `Service\Mail\Digest\SendDueDigests` — always alias in `use` (T18).
