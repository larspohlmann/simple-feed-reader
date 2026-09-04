# Admin Mail Configuration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin configure outgoing SMTP mail (transport + identity) from the admin UI, overriding the env, with a live test-send — no `.env` edit, no redeploy.

**Architecture:** A singleton `MailServerSettings` entity stores the SMTP transport and identity, the password sealed at rest (mirroring `ProxyServerSettings`). A `MailSettings` service owns load-or-create, sealing, and resolution (DB-when-present, else env fallback). `MAILER_DSN` becomes `dynamic://default`, handled by a `DynamicMailTransportFactory`/`DynamicMailTransport` that resolves the active transport per-send from the DB or the renamed `MAILER_FALLBACK_DSN`. Enablement, identity, the config guard, and a `POST /api/admin/mail/test` all resolve through `MailSettings`. An Angular `mail/` settings section (twin of `proxy/`) edits it.

**Tech Stack:** Symfony 7.4 (PHP 8.4), Symfony Mailer 7.4, Doctrine (MySQL + SQLite), Angular 20 (standalone, signals), Transloco, Jest, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-04-834-admin-mail-config-design.md`

## Global Constraints

- **Clean Code is mandatory** (see `CLAUDE.md`): intention-revealing names, one-thing functions, guard clauses, `final readonly` with constructor promotion, depend on interfaces, typed namespaced exceptions, no boolean flag params, no comment that restates code. Every touched `src` file must be PHPMD-clean and pass `composer check` (cs + PHPStan level max + tramp) and `mcp__phpstorm__lint_files` (block on ERROR/WARNING).
- **`declare(strict_types=1)`** in every PHP file.
- **Thin controllers**: actions read the request, delegate, return a response. No private methods carrying responsibility (`ThinControllerRule`).
- **Datetimes**: none introduced here; not applicable.
- **Frontend**: standalone components + signals, no NgModules. No hex colours / raw px / media-query literals in `.scss` outside `src/app/theme/`. Component styles in a sibling `.scss` (`styleUrl`), never inline. Prettier 100-col. `npm run check` is the gate.
- **Native jest skips the typecheck** — run frontend tests through the Docker frontend container (`docker compose exec -T frontend npm test`), and run `npm run check` for the type/lint gate.
- **Mutation testing gates changed files** (`composer infection:diff`, `minMsi` in `infection.json5`); escaped mutants arrive as PR annotations.
- **Anything parallel sets `TEST_TOKEN`** (worker isolation).
- **git-flow**: branch is `feature/834-admin-mail-config` off `develop`. Commit style `type(#834): summary`. No attribution lines.
- **Master secret**: after Task 1 the env var is `INSTANCE_SECRET_KEY` (was `AI_KEY_SECRET`). All new ciphers read `%env(INSTANCE_SECRET_KEY)%`.

## File Structure

Backend, new:
- `src/Enum/MailEncryption.php` — `none|starttls|tls`.
- `src/Entity/MailServerSettings.php`, `src/Repository/MailServerSettingsRepository.php` — the singleton row.
- `src/Service/Mail/Settings/MailConnection.php` — non-secret transport fields as one value.
- `src/Service/Mail/Settings/ResolvedMailTransport.php` — a fully resolved SMTP transport spec (host/port/username/plain password/encryption).
- `src/Service/Mail/Settings/MailIdentity.php` — resolved from-address + from-name.
- `src/Service/Mail/Settings/MailFallback.php` + `MailFallbackContext.php` — parses `MAILER_FALLBACK_DSN` + `MAIL_FROM(_NAME)` into form defaults and the "is real" flag.
- `src/Service/Mail/Settings/MailSettings.php` — read/write/resolve facade.
- `src/Service/Mail/Settings/Crypto/{MailPasswordCipher,SealedMailPassword}.php` + `Crypto/Exception/MailPasswordUnreadableException.php`.
- `src/Service/Mail/Transport/{DynamicMailTransportFactory,DynamicMailTransport}.php`.
- `src/Service/Mail/Settings/{MailConnectionTester,MailTestResult}.php`.
- `src/Http/Admin/MailSettingsJson.php`.
- `src/Dto/Admin/MailSettingsRequest.php`.
- `src/Controller/Admin/AdminMailController.php`.
- `migrations/Version<stamp>.php` — create `mail_server_settings`.

Backend, modified: `config/packages/mailer.yaml`, `src/Service/Mail/MailCapability.php`, `src/Service/Mail/AccountMailer.php`, `src/EventListener/InsecureProductionConfigGuard.php`, plus the rename set in Task 1 and the `MAIL_DISABLED` retirement in Task 16.

Frontend, new: `src/app/settings/admin/mail/{mail-settings.service.ts,mail-section.component.ts,.html,.scss}` (+ specs). Modified: `settings.routes.ts`, the admin section list, `i18n` files.

---

### Task 1: Rename `AI_KEY_SECRET` → `INSTANCE_SECRET_KEY`

Foundational and self-contained. The env is no longer AI-specific; it seals AI keys, the proxy password, and (soon) the mail password.

**Files:**
- Modify: `backend/src/Service/Ai/Crypto/ApiKeyCipher.php:31,38` (autowire + exception message)
- Modify: `backend/src/Service/Proxy/Crypto/ProxyPasswordCipher.php:14,24,29` (docblock + autowire + message)
- Modify: `backend/src/Service/Proxy/Crypto/SealedProxyPassword.php:8` (docblock)
- Modify: `backend/src/Exception/AiKeyUnreadableApiException.php:10` (docblock)
- Modify: `backend/.env:84`
- Modify: `docker-compose.prod.yml:57`
- Modify: `.env.prod.example` (if it declares the key)
- Modify: `deploy/strato/.env.local.example:15,133`
- Modify: `deploy/strato/README.md:42,143,151`
- Modify: `deploy/strato/activate-release.sh:79,87-91`
- Modify: `scripts/install.sh:188`
- Modify: `scripts/lib.sh:605,645-659`
- Test: existing `backend/tests/**` that boot the kernel exercise this; add none.

**Interfaces:**
- Produces: env var `INSTANCE_SECRET_KEY` read by `ApiKeyCipher`, `ProxyPasswordCipher`, and `MailPasswordCipher` (Task 3).

- [ ] **Step 1: Find every occurrence**

Run: `grep -rn "AI_KEY_SECRET" backend/src scripts deploy docker-compose.prod.yml backend/.env .env.prod.example 2>/dev/null | grep -v /vendor/`
Expected: the file:line set listed above (the `docs/superpowers/**` hits are historical records — do NOT edit them).

- [ ] **Step 2: Rename the value in each file**

Replace the identifier `AI_KEY_SECRET` with `INSTANCE_SECRET_KEY` in every file above. In the two ciphers the exception text becomes e.g. `'INSTANCE_SECRET_KEY must be at least %d characters; got %d.'`. In `backend/.env` keep the placeholder value, only the key changes:

```
INSTANCE_SECRET_KEY=test-ai-key-secret-not-for-production-0123456789
```

In `deploy/strato/activate-release.sh` the placeholder-detection grep and the two `die` messages rename the key; the placeholder literal string (`test-ai-key-secret-not-for-production-0123456789`) is unchanged. Update the surrounding prose in `activate-release.sh`, `scripts/lib.sh`, and `README.md` to say `INSTANCE_SECRET_KEY`.

- [ ] **Step 3: Warm the cache and boot the suite**

Run: `cd backend && bin/console cache:warmup && php bin/phpunit --filter 'ProxyConnectionTester|ApiKeyCipher|AiSettings'`
Expected: PASS. A `%env(AI_KEY_SECRET)%` left anywhere fails container compilation with "Environment variable not found".

- [ ] **Step 4: Full check**

Run: `cd backend && composer check`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "refactor(#834): rename AI_KEY_SECRET to INSTANCE_SECRET_KEY"
```

> **Deploy note for the PR body:** the Strato server's `shared/.env.local` must be edited by hand to rename this key in lockstep with the deploy, or AI keys, proxy, and mail all fail closed.

---

### Task 2: `MailEncryption` enum

**Files:**
- Create: `backend/src/Enum/MailEncryption.php`
- Test: covered by later tasks (pure enum).

**Interfaces:**
- Produces: `enum MailEncryption: string { None='none'; Starttls='starttls'; Tls='tls'; }`.

- [ ] **Step 1: Write the enum**

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum MailEncryption: string
{
    case None = 'none';
    case Starttls = 'starttls';
    case Tls = 'tls';
}
```

- [ ] **Step 2: Lint**

Run: `cd backend && composer cs && vendor/bin/phpstan analyse src/Enum/MailEncryption.php`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add backend/src/Enum/MailEncryption.php && git commit -m "feat(#834): add MailEncryption enum"
```

---

### Task 3: The mail password cipher

Mirror `ProxyPasswordCipher` exactly; change the binding string only.

**Files:**
- Create: `backend/src/Service/Mail/Settings/Crypto/SealedMailPassword.php`
- Create: `backend/src/Service/Mail/Settings/Crypto/Exception/MailPasswordUnreadableException.php`
- Create: `backend/src/Service/Mail/Settings/Crypto/MailPasswordCipher.php`
- Test: `backend/tests/Service/Mail/Settings/Crypto/MailPasswordCipherTest.php`

**Interfaces:**
- Consumes: env `INSTANCE_SECRET_KEY` (Task 1).
- Produces: `MailPasswordCipher::seal(string): SealedMailPassword`, `::open(SealedMailPassword): string` (throws `MailPasswordUnreadableException`). `SealedMailPassword(string $ciphertext, string $nonce, string $salt, int $version)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings\Crypto;

use App\Service\Mail\Settings\Crypto\Exception\MailPasswordUnreadableException;
use App\Service\Mail\Settings\Crypto\MailPasswordCipher;
use App\Service\Mail\Settings\Crypto\SealedMailPassword;
use PHPUnit\Framework\TestCase;

final class MailPasswordCipherTest extends TestCase
{
    private const SECRET = 'a-test-instance-secret-key-32-chars-min-0123456789';

    public function testSealThenOpenReturnsThePlaintext(): void
    {
        $cipher = new MailPasswordCipher(self::SECRET);
        $sealed = $cipher->seal('hunter2-smtp');

        self::assertSame('hunter2-smtp', $cipher->open($sealed));
    }

    public function testASecondSealOfTheSameValueDiffers(): void
    {
        $cipher = new MailPasswordCipher(self::SECRET);

        self::assertNotSame($cipher->seal('x')->ciphertext, $cipher->seal('x')->ciphertext);
    }

    public function testATamperedCiphertextFailsItsIntegrityCheck(): void
    {
        $cipher = new MailPasswordCipher(self::SECRET);
        $sealed = $cipher->seal('secret');
        $tampered = new SealedMailPassword(
            base64_encode('not the real ciphertext'),
            $sealed->nonce,
            $sealed->salt,
            $sealed->version,
        );

        $this->expectException(MailPasswordUnreadableException::class);
        $cipher->open($tampered);
    }

    public function testTooShortASecretIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MailPasswordCipher('too-short');
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Settings/Crypto/MailPasswordCipherTest.php`
Expected: FAIL (classes not found).

- [ ] **Step 3: Write the three classes**

`SealedMailPassword.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings\Crypto;

/**
 * The mail password at rest. Without INSTANCE_SECRET_KEY the ciphertext is noise.
 * The byte strings are base64 so one migration serves MySQL and SQLite.
 */
final readonly class SealedMailPassword
{
    public function __construct(
        public string $ciphertext,
        public string $nonce,
        public string $salt,
        public int $version,
    ) {
    }
}
```

`Exception/MailPasswordUnreadableException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings\Crypto\Exception;

final class MailPasswordUnreadableException extends \RuntimeException
{
}
```

`MailPasswordCipher.php` — copy `ProxyPasswordCipher` verbatim with three changes: namespace `App\Service\Mail\Settings\Crypto`; the `SealedProxyPassword` type and exception become the mail ones; the autowire env is `INSTANCE_SECRET_KEY`; the binding is `mail-password|v%d|instance`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings\Crypto;

use App\Service\Mail\Settings\Crypto\Exception\MailPasswordUnreadableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Seals the instance-wide mail password. Same construction as ApiKeyCipher and
 * ProxyPasswordCipher; the distinct binding keeps this secret cryptographically
 * separate even though all three derive from INSTANCE_SECRET_KEY.
 */
final readonly class MailPasswordCipher
{
    public const int CURRENT_VERSION = 1;

    private const int SALT_BYTES = 16;
    private const int MINIMUM_SECRET_LENGTH = 32;

    public function __construct(
        #[Autowire('%env(INSTANCE_SECRET_KEY)%')]
        private string $masterSecret,
    ) {
        if (\strlen($masterSecret) < self::MINIMUM_SECRET_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'INSTANCE_SECRET_KEY must be at least %d characters; got %d.',
                self::MINIMUM_SECRET_LENGTH,
                \strlen($masterSecret),
            ));
        }
    }

    public function seal(string $plainPassword): SealedMailPassword
    {
        $salt = random_bytes(self::SALT_BYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $rowKey = $this->deriveRowKey(self::CURRENT_VERSION, $salt);

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plainPassword,
            $this->binding(self::CURRENT_VERSION),
            $nonce,
            $rowKey,
        );

        sodium_memzero($rowKey);

        return new SealedMailPassword(
            base64_encode($ciphertext),
            base64_encode($nonce),
            base64_encode($salt),
            self::CURRENT_VERSION,
        );
    }

    public function open(SealedMailPassword $sealed): string
    {
        if (self::CURRENT_VERSION !== $sealed->version) {
            throw new MailPasswordUnreadableException(sprintf('Unknown scheme version %d.', $sealed->version));
        }

        $salt = $this->decode($sealed->salt);
        $ciphertext = $this->decode($sealed->ciphertext);
        $nonce = $this->decode($sealed->nonce);

        $rowKey = $this->deriveRowKey($sealed->version, $salt);

        $plainPassword = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $this->binding($sealed->version),
            $nonce,
            $rowKey,
        );

        sodium_memzero($rowKey);

        if (false === $plainPassword) {
            throw new MailPasswordUnreadableException('The stored mail password failed its integrity check.');
        }

        return $plainPassword;
    }

    private function deriveRowKey(int $version, string $salt): string
    {
        return hash_hkdf(
            'sha256',
            $this->masterSecret,
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $this->binding($version),
            $salt,
        );
    }

    private function binding(int $version): string
    {
        return sprintf('mail-password|v%d|instance', $version);
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode($value, true);

        if (false === $decoded) {
            throw new MailPasswordUnreadableException('Stored mail secret is not valid base64.');
        }

        return $decoded;
    }
}
```

- [ ] **Step 4: Run it, verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Settings/Crypto/MailPasswordCipherTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Mail/Settings/Crypto backend/tests/Service/Mail/Settings/Crypto && git commit -m "feat(#834): seal the mail password at rest"
```

---

### Task 4: `MailServerSettings` entity, repository, migration

**Files:**
- Create: `backend/src/Entity/MailServerSettings.php`
- Create: `backend/src/Repository/MailServerSettingsRepository.php`
- Create: `backend/migrations/Version<stamp>.php`
- Test: schema validation leg (below)

**Interfaces:**
- Consumes: `MailConnection` (Task 5), `SealedMailPassword` (Task 3), `MailEncryption` (Task 2).
- Produces: `MailServerSettings` with getters `isEnabled(): bool`, `getHost(): string`, `getPort(): int`, `getUsername(): ?string`, `getEncryption(): MailEncryption`, `getFromAddress(): string`, `getFromName(): string`, `getPasswordHint(): string`, `hasPassword(): bool`, `getSealedPassword(): SealedMailPassword`; mutators `apply(MailConnection, SealedMailPassword, string $hint): void`, `applyWithoutPassword(MailConnection): void`. `MailServerSettingsRepository::findSingleton(): ?MailServerSettings`.

- [ ] **Step 1: Write the entity**

Mirror `ProxyServerSettings`. Column defaults: `enabled=false`, `host=''`, `port=MailConnection::DEFAULT_PORT`, `username=null`, `encryption=MailEncryption::Starttls`, sealed-password columns empty, `keyVersion=1`, `fromAddress=''`, `fromName=''`.

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MailEncryption;
use App\Repository\MailServerSettingsRepository;
use App\Service\Mail\Settings\Crypto\SealedMailPassword;
use App\Service\Mail\Settings\MailConnection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The instance-wide outgoing-mail settings, held in a single row (see
 * InstanceSetting for the singleton rationale). Absence of the row means "not
 * configured": enablement then derives from the env fallback. The password is
 * never readable here; passwordHint is the last four characters in clear text so
 * the admin page can name the stored secret.
 */
#[ORM\Entity(repositoryClass: MailServerSettingsRepository::class)]
#[ORM\Table(name: 'mail_server_settings')]
class MailServerSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => 0])]
    private bool $enabled = false;

    #[ORM\Column(length: 255)]
    private string $host = '';

    #[ORM\Column]
    private int $port = MailConnection::DEFAULT_PORT;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(length: 16, enumType: MailEncryption::class)]
    private MailEncryption $encryption = MailEncryption::Starttls;

    #[ORM\Column(length: 255)]
    private string $fromAddress = '';

    #[ORM\Column(length: 255)]
    private string $fromName = '';

    #[ORM\Column(length: 1024)]
    private string $passwordCiphertext = '';

    #[ORM\Column(length: 64)]
    private string $passwordNonce = '';

    #[ORM\Column(length: 64)]
    private string $passwordSalt = '';

    #[ORM\Column(length: 8)]
    private string $passwordHint = '';

    #[ORM\Column(options: ['default' => 1])]
    private int $keyVersion = 1;

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getEncryption(): MailEncryption
    {
        return $this->encryption;
    }

    public function getFromAddress(): string
    {
        return $this->fromAddress;
    }

    public function getFromName(): string
    {
        return $this->fromName;
    }

    public function getPasswordHint(): string
    {
        return $this->passwordHint;
    }

    public function hasPassword(): bool
    {
        return '' !== $this->passwordCiphertext;
    }

    public function getSealedPassword(): SealedMailPassword
    {
        return new SealedMailPassword(
            $this->passwordCiphertext,
            $this->passwordNonce,
            $this->passwordSalt,
            $this->keyVersion,
        );
    }

    public function apply(MailConnection $connection, SealedMailPassword $sealed, string $passwordHint): void
    {
        $this->applyWithoutPassword($connection);
        $this->passwordCiphertext = $sealed->ciphertext;
        $this->passwordNonce = $sealed->nonce;
        $this->passwordSalt = $sealed->salt;
        $this->keyVersion = $sealed->version;
        $this->passwordHint = $passwordHint;
    }

    public function applyWithoutPassword(MailConnection $connection): void
    {
        $this->enabled = $connection->enabled;
        $this->host = $connection->host;
        $this->port = $connection->port;
        $this->username = $connection->username;
        $this->encryption = $connection->encryption;
        $this->fromAddress = $connection->fromAddress;
        $this->fromName = $connection->fromName;
    }
}
```

- [ ] **Step 2: Write the repository**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MailServerSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailServerSettings>
 */
final class MailServerSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailServerSettings::class);
    }

    public function findSingleton(): ?MailServerSettings
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
```

- [ ] **Step 3: Generate the migration timestamp and write the platform-aware DDL**

Copy `backend/migrations/Version20260822130000.php` and adapt: table `mail_server_settings`, description `Create mail_server_settings for admin-configured outgoing mail (#834)`, and the column set below (MySQL branch shown; SQLite branch uses `INTEGER PRIMARY KEY AUTOINCREMENT`, `BOOLEAN`, `INTEGER` as in the proxy migration). Use a fresh timestamp filename `Version20260904HHMMSS.php`.

```sql
CREATE TABLE mail_server_settings (
    id INT AUTO_INCREMENT NOT NULL,
    enabled TINYINT(1) DEFAULT 0 NOT NULL,
    host VARCHAR(255) NOT NULL,
    port INT NOT NULL,
    username VARCHAR(255) DEFAULT NULL,
    encryption VARCHAR(16) NOT NULL,
    from_address VARCHAR(255) NOT NULL,
    from_name VARCHAR(255) NOT NULL,
    password_ciphertext VARCHAR(1024) NOT NULL,
    password_nonce VARCHAR(64) NOT NULL,
    password_salt VARCHAR(64) NOT NULL,
    password_hint VARCHAR(8) NOT NULL,
    key_version INT DEFAULT 1 NOT NULL,
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
```

- [ ] **Step 4: Validate the mapping and migrate from empty on both drivers**

Run: `cd backend && bin/console doctrine:schema:validate --skip-sync` then, on the SQLite test DB, run the migration and re-validate:
`APP_ENV=test bin/console doctrine:migrations:migrate --no-interaction && APP_ENV=test bin/console doctrine:schema:validate`
Expected: "database schema is in sync with the mapping files" (the mail table validates; the migration applies cleanly). Then in Docker: `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Entity/MailServerSettings.php backend/src/Repository/MailServerSettingsRepository.php backend/migrations && git commit -m "feat(#834): persist admin mail settings in a singleton row"
```

---

### Task 5: Value objects + the env-fallback parser

**Files:**
- Create: `backend/src/Service/Mail/Settings/MailConnection.php`
- Create: `backend/src/Service/Mail/Settings/ResolvedMailTransport.php`
- Create: `backend/src/Service/Mail/Settings/MailIdentity.php`
- Create: `backend/src/Service/Mail/Settings/MailFallbackContext.php`
- Create: `backend/src/Service/Mail/Settings/MailFallback.php`
- Test: `backend/tests/Service/Mail/Settings/MailFallbackTest.php`

**Interfaces:**
- Consumes: env `MAILER_FALLBACK_DSN`, `MAIL_FROM`, `MAIL_FROM_NAME`; `MailEncryption`.
- Produces:
  - `MailConnection(bool $enabled, string $host, int $port, ?string $username, MailEncryption $encryption, string $fromAddress, string $fromName)` with `const int DEFAULT_PORT = 587`.
  - `ResolvedMailTransport(string $host, int $port, ?string $username, ?string $password, MailEncryption $encryption)` with `signature(): string`.
  - `MailIdentity(string $address, string $name)`.
  - `MailFallbackContext(bool $isReal, string $host, int $port, ?string $username, MailEncryption $encryption, string $fromAddress, string $fromName)`.
  - `MailFallback::context(): MailFallbackContext`, `::transportDsn(): string`, `::identity(): MailIdentity`.

- [ ] **Step 1: Write the failing test for the fallback parser**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Enum\MailEncryption;
use App\Service\Mail\Settings\MailFallback;
use PHPUnit\Framework\TestCase;

final class MailFallbackTest extends TestCase
{
    public function testNullTransportIsNotReal(): void
    {
        $context = (new MailFallback('null://null', 'noreply@example.com', 'Reader'))->context();

        self::assertFalse($context->isReal);
        self::assertSame('', $context->host);
    }

    public function testAnSmtpDsnFillsTheFormDefaults(): void
    {
        $context = (new MailFallback('smtp://alice%40relay:pw@smtp.relay.test:2525', 'noreply@example.com', 'Reader'))->context();

        self::assertTrue($context->isReal);
        self::assertSame('smtp.relay.test', $context->host);
        self::assertSame(2525, $context->port);
        self::assertSame('alice@relay', $context->username);
        self::assertSame(MailEncryption::Starttls, $context->encryption);
        self::assertSame('noreply@example.com', $context->fromAddress);
    }

    public function testAnSmtpsDsnResolvesToImplicitTls(): void
    {
        $context = (new MailFallback('smtps://smtp.relay.test', 'from@x.test', 'X'))->context();

        self::assertSame(MailEncryption::Tls, $context->encryption);
    }

    public function testASendmailDsnIsRealButNotSmtpParseable(): void
    {
        $context = (new MailFallback('sendmail://default?command=%2Fusr%2Fsbin%2Fsendmail%20-t%20-i', 'from@x.test', 'X'))->context();

        self::assertTrue($context->isReal);
        self::assertSame('', $context->host);
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Settings/MailFallbackTest.php`
Expected: FAIL (classes not found).

- [ ] **Step 3: Write the value objects**

`MailConnection.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Enum\MailEncryption;

/**
 * The non-secret mail connection fields, carried as one value so the entity
 * mutators and the service take a single argument. The sealed password travels
 * separately (it may be absent on an update).
 */
final readonly class MailConnection
{
    /** The SMTP submission port with STARTTLS. The one definition the entity
     *  default, the request DTO and the "not configured yet" payload read. */
    public const int DEFAULT_PORT = 587;

    public function __construct(
        public bool $enabled,
        public string $host,
        public int $port,
        public ?string $username,
        public MailEncryption $encryption,
        public string $fromAddress,
        public string $fromName,
    ) {
    }
}
```

`ResolvedMailTransport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Enum\MailEncryption;

/** A fully resolved SMTP transport, plaintext password included. Never serialised. */
final readonly class ResolvedMailTransport
{
    public function __construct(
        public string $host,
        public int $port,
        public ?string $username,
        public ?string $password,
        public MailEncryption $encryption,
    ) {
    }

    /** Stable across sends with the same settings, so the transport is built once. */
    public function signature(): string
    {
        return implode('|', [
            $this->host,
            (string) $this->port,
            $this->username ?? '',
            $this->encryption->value,
            null === $this->password ? 'no-pass' : 'has-pass',
        ]);
    }
}
```

`MailIdentity.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

final readonly class MailIdentity
{
    public function __construct(
        public string $address,
        public string $name,
    ) {
    }
}
```

`MailFallbackContext.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Enum\MailEncryption;

/** The env fallback expressed as form defaults. host is '' when the fallback is
 *  not an SMTP DSN (sendmail/null), so the SMTP form starts blank there. */
final readonly class MailFallbackContext
{
    public function __construct(
        public bool $isReal,
        public string $host,
        public int $port,
        public ?string $username,
        public MailEncryption $encryption,
        public string $fromAddress,
        public string $fromName,
    ) {
    }
}
```

- [ ] **Step 4: Write `MailFallback`**

Parse the DSN with `parse_url`. `null://` (or empty) → not real, blank host. `smtp`/`smtps` → real, parsed host/port/user, encryption `Starttls`/`Tls`. Any other scheme (e.g. `sendmail`) → real but blank host.

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Enum\MailEncryption;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The env transport DSN and MAIL_FROM(_NAME), read as the fallback used when no
 * DB row exists. Parses only SMTP DSNs into form defaults; a sendmail or null
 * transport is reported as real-but-blank so the SMTP form starts empty while
 * the env transport keeps sending until the admin saves a DB config.
 */
final readonly class MailFallback
{
    public function __construct(
        #[Autowire('%env(MAILER_FALLBACK_DSN)%')]
        private string $dsn,
        #[Autowire('%env(MAIL_FROM)%')]
        private string $fromAddress,
        #[Autowire('%env(MAIL_FROM_NAME)%')]
        private string $fromName,
    ) {
    }

    public function transportDsn(): string
    {
        return $this->dsn;
    }

    public function identity(): MailIdentity
    {
        return new MailIdentity($this->fromAddress, $this->fromName);
    }

    public function context(): MailFallbackContext
    {
        $parts = parse_url($this->dsn);
        $scheme = \is_array($parts) ? ($parts['scheme'] ?? '') : '';

        if ('' === trim($this->dsn) || 'null' === $scheme) {
            return $this->blank(false);
        }

        if ('smtp' !== $scheme && 'smtps' !== $scheme) {
            return $this->blank(true);
        }

        $encryption = 'smtps' === $scheme ? MailEncryption::Tls : MailEncryption::Starttls;

        return new MailFallbackContext(
            true,
            $parts['host'] ?? '',
            $parts['port'] ?? MailConnection::DEFAULT_PORT,
            isset($parts['user']) ? rawurldecode($parts['user']) : null,
            $encryption,
            $this->fromAddress,
            $this->fromName,
        );
    }

    private function blank(bool $isReal): MailFallbackContext
    {
        return new MailFallbackContext(
            $isReal,
            '',
            MailConnection::DEFAULT_PORT,
            null,
            MailEncryption::Starttls,
            $this->fromAddress,
            $this->fromName,
        );
    }
}
```

- [ ] **Step 5: Run the test, verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Settings/MailFallbackTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Mail/Settings backend/tests/Service/Mail/Settings/MailFallbackTest.php && git commit -m "feat(#834): resolve mail form defaults from the env fallback"
```

---

### Task 6: `MailSettings` service + JSON + request DTO

**Files:**
- Create: `backend/src/Service/Mail/Settings/MailSettings.php`
- Create: `backend/src/Http/Admin/MailSettingsJson.php`
- Create: `backend/src/Dto/Admin/MailSettingsRequest.php`
- Test: `backend/tests/Service/Mail/Settings/MailSettingsTest.php` (integration, uses the kernel + SQLite)

**Interfaces:**
- Consumes: `MailServerSettingsRepository`, `EntityManagerInterface`, `MailPasswordCipher`, `MailFallback`.
- Produces on `MailSettings`:
  - `view(): array` (shape below)
  - `update(MailSettingsRequest): void`
  - `configuredTransport(): ?ResolvedMailTransport` (the saved row, ignoring the toggle; null when no row or blank host — throws `MailPasswordUnreadableException` on a corrupt secret)
  - `activeTransportDsnFallback(): string` (the env DSN, for the dynamic transport)
  - `identity(): MailIdentity` (DB when a row's from-address is set, else env)
  - `isSendingEnabled(): bool` (row ? row.enabled : fallback.isReal)
  - JSON shape: `array{enabled:bool, host:string, port:int, username:string|null, encryption:string, fromAddress:string, fromName:string, hasPassword:bool, passwordHint:string}`.

- [ ] **Step 1: Write the failing integration test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Dto\Admin\MailSettingsRequest;
use App\Enum\MailEncryption;
use App\Service\Mail\Settings\MailSettings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MailSettingsTest extends KernelTestCase
{
    private function settings(): MailSettings
    {
        return self::getContainer()->get(MailSettings::class);
    }

    public function testNoRowReportsDerivedEnabledFromTheFallback(): void
    {
        // The test env fallback is null://null, so mail derives to disabled.
        self::assertFalse($this->settings()->isSendingEnabled());
        self::assertFalse($this->settings()->view()['hasPassword']);
    }

    public function testUpdateStoresTheConnectionAndSealsThePassword(): void
    {
        $this->settings()->update(new MailSettingsRequest(
            enabled: true,
            host: 'smtp.relay.test',
            port: 587,
            username: 'postbox',
            encryption: MailEncryption::Starttls->value,
            fromAddress: 'noreply@reader.test',
            fromName: 'Reader',
            password: 'top-secret',
        ));

        $view = $this->settings()->view();
        self::assertTrue($view['enabled']);
        self::assertSame('smtp.relay.test', $view['host']);
        self::assertTrue($view['hasPassword']);
        self::assertSame('cret', $view['passwordHint']);
        self::assertArrayNotHasKey('password', $view);

        $resolved = $this->settings()->configuredTransport();
        self::assertNotNull($resolved);
        self::assertSame('top-secret', $resolved->password);
    }

    public function testANullPasswordKeepsTheStoredSecret(): void
    {
        $this->settings()->update(new MailSettingsRequest(host: 'h', password: 'keep-me'));
        $this->settings()->update(new MailSettingsRequest(host: 'h2', password: null));

        self::assertSame('keep-me', $this->settings()->configuredTransport()?->password);
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Settings/MailSettingsTest.php`
Expected: FAIL (service/DTO not found).

- [ ] **Step 3: Write the request DTO**

```php
<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Enum\MailEncryption;
use App\Service\Mail\Settings\MailConnection;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Full-replace payload for the mail settings. `password` is the one exception:
 * null means "keep the stored secret", a non-null string replaces it. It is
 * inbound-only and is never echoed back.
 */
final readonly class MailSettingsRequest
{
    public function __construct(
        #[Assert\Type('bool')]
        public bool $enabled = false,
        #[Assert\Length(max: 255)]
        public string $host = '',
        #[Assert\Range(min: 1, max: 65535)]
        public int $port = MailConnection::DEFAULT_PORT,
        #[Assert\Length(max: 255)]
        public ?string $username = null,
        #[Assert\Choice(choices: [
            MailEncryption::None->value,
            MailEncryption::Starttls->value,
            MailEncryption::Tls->value,
        ])]
        public string $encryption = MailEncryption::Starttls->value,
        #[Assert\Length(max: 255)]
        public string $fromAddress = '',
        #[Assert\Length(max: 255)]
        public string $fromName = '',
        #[Assert\Length(max: 512)]
        public ?string $password = null,
    ) {
    }
}
```

- [ ] **Step 4: Write the JSON mapper**

When the row is absent, fill non-secret fields from the fallback context; the password is never prefilled (`hasPassword=false`, hint `''`).

```php
<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Entity\MailServerSettings;
use App\Service\Mail\Settings\MailFallbackContext;

/**
 * The admin mail payload. The password is absent by construction: only the
 * 4-char hint and a hasPassword flag cross the wire, never the secret. With no
 * row yet, the non-secret fields are seeded from the env fallback so the form
 * shows what is currently active; the password is never seeded from the env.
 */
final readonly class MailSettingsJson
{
    /**
     * @return array{
     *     enabled: bool, host: string, port: int, username: string|null,
     *     encryption: string, fromAddress: string, fromName: string,
     *     hasPassword: bool, passwordHint: string,
     * }
     */
    public static function from(?MailServerSettings $settings, MailFallbackContext $fallback): array
    {
        if (null === $settings) {
            return [
                'enabled' => $fallback->isReal,
                'host' => $fallback->host,
                'port' => $fallback->port,
                'username' => $fallback->username,
                'encryption' => $fallback->encryption->value,
                'fromAddress' => $fallback->fromAddress,
                'fromName' => $fallback->fromName,
                'hasPassword' => false,
                'passwordHint' => '',
            ];
        }

        return [
            'enabled' => $settings->isEnabled(),
            'host' => $settings->getHost(),
            'port' => $settings->getPort(),
            'username' => $settings->getUsername(),
            'encryption' => $settings->getEncryption()->value,
            'fromAddress' => $settings->getFromAddress(),
            'fromName' => $settings->getFromName(),
            'hasPassword' => $settings->hasPassword(),
            'passwordHint' => $settings->getPasswordHint(),
        ];
    }
}
```

- [ ] **Step 5: Write `MailSettings`**

Mirror `ProxySettings`. `identity()` returns the DB from-address/name when a row's from-address is non-empty, else the fallback identity.

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Dto\Admin\MailSettingsRequest;
use App\Entity\MailServerSettings;
use App\Enum\MailEncryption;
use App\Http\Admin\MailSettingsJson;
use App\Repository\MailServerSettingsRepository;
use App\Service\Mail\Settings\Crypto\MailPasswordCipher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and writes the instance-wide mail row, defaulting to "not configured"
 * when no row exists. The rest of the app depends on this, never on the entity
 * directly, so "no row yet", the sealing, and the DB-or-env resolution all live
 * in one place.
 */
readonly class MailSettings
{
    private const int HINT_LENGTH = 4;

    public function __construct(
        private MailServerSettingsRepository $repository,
        private EntityManagerInterface $em,
        private MailPasswordCipher $cipher,
        private MailFallback $fallback,
    ) {
    }

    /**
     * @return array{
     *     enabled: bool, host: string, port: int, username: string|null,
     *     encryption: string, fromAddress: string, fromName: string,
     *     hasPassword: bool, passwordHint: string,
     * }
     */
    public function view(): array
    {
        return MailSettingsJson::from($this->repository->findSingleton(), $this->fallback->context());
    }

    public function update(MailSettingsRequest $request): void
    {
        $settings = $this->repository->findSingleton();

        if (null === $settings) {
            $settings = new MailServerSettings();
            $this->em->persist($settings);
        }

        $connection = $this->connectionFrom($request);

        if (null === $request->password) {
            $settings->applyWithoutPassword($connection);
        } else {
            $settings->apply(
                $connection,
                $this->cipher->seal($request->password),
                mb_substr($request->password, -self::HINT_LENGTH),
            );
        }

        $this->em->flush();
    }

    /** The saved SMTP transport regardless of the enable switch — the tester and
     *  the dynamic transport resolve this. Null when nothing usable is saved. */
    public function configuredTransport(): ?ResolvedMailTransport
    {
        $settings = $this->repository->findSingleton();

        if (null === $settings || '' === $settings->getHost()) {
            return null;
        }

        return new ResolvedMailTransport(
            $settings->getHost(),
            $settings->getPort(),
            $settings->getUsername(),
            $settings->hasPassword() ? $this->cipher->open($settings->getSealedPassword()) : null,
            $settings->getEncryption(),
        );
    }

    public function activeTransportDsnFallback(): string
    {
        return $this->fallback->transportDsn();
    }

    public function identity(): MailIdentity
    {
        $settings = $this->repository->findSingleton();

        if (null !== $settings && '' !== $settings->getFromAddress()) {
            return new MailIdentity($settings->getFromAddress(), $settings->getFromName());
        }

        return $this->fallback->identity();
    }

    public function isSendingEnabled(): bool
    {
        $settings = $this->repository->findSingleton();

        return null !== $settings ? $settings->isEnabled() : $this->fallback->context()->isReal;
    }

    private function connectionFrom(MailSettingsRequest $request): MailConnection
    {
        return new MailConnection(
            $request->enabled,
            $request->host,
            $request->port,
            '' === $request->username ? null : $request->username,
            MailEncryption::from($request->encryption),
            $request->fromAddress,
            $request->fromName,
        );
    }
}
```

- [ ] **Step 6: Run the test, verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Settings/MailSettingsTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Mail/Settings/MailSettings.php backend/src/Http/Admin/MailSettingsJson.php backend/src/Dto/Admin/MailSettingsRequest.php backend/tests/Service/Mail/Settings/MailSettingsTest.php && git commit -m "feat(#834): read and write the admin mail settings"
```

---

### Task 7: The `dynamic://` transport + env swap

**Files:**
- Create: `backend/src/Service/Mail/Transport/DynamicMailTransport.php`
- Create: `backend/src/Service/Mail/Transport/DynamicMailTransportFactory.php`
- Modify: `backend/config/packages/mailer.yaml`
- Modify: `backend/.env` (rename `MAILER_DSN`→`MAILER_FALLBACK_DSN`; set `MAILER_DSN=dynamic://default`)
- Modify: `docker-compose.yml:50,121` (dev/test `MAILER_FALLBACK_DSN`; `MAILER_DSN=dynamic://default`)
- Test: `backend/tests/Service/Mail/Transport/DynamicMailTransportTest.php` + a functional send test.

**Interfaces:**
- Consumes: `MailSettings` (Task 6), the app `EventDispatcherInterface`, `HttpClientInterface`, `LoggerInterface`.
- Produces: a working `mailer.mailer` whose transport resolves per send. `DynamicMailTransportFactory::supports` matches scheme `dynamic`.

- [ ] **Step 1: Swap the env and mailer config**

`backend/config/packages/mailer.yaml` stays `dsn: '%env(MAILER_DSN)%'` — unchanged. In `backend/.env`:

```
MAILER_DSN=dynamic://default
MAILER_FALLBACK_DSN=null://null
```

In `docker-compose.yml` both service blocks (lines ~50 and ~121):

```yaml
      MAILER_DSN: "dynamic://default"
      MAILER_FALLBACK_DSN: "smtp://mailpit:1025"
```

- [ ] **Step 2: Write the failing test**

The transport, with no DB row and a `smtp://` fallback, must build an SMTP transport; with a DB row it must build from the row. Assert via the resolved transport's string form.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Transport;

use App\Dto\Admin\MailSettingsRequest;
use App\Service\Mail\Settings\MailSettings;
use App\Service\Mail\Transport\DynamicMailTransport;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

final class DynamicMailTransportTest extends KernelTestCase
{
    public function testWithoutARowItBuildsFromTheFallbackDsn(): void
    {
        $transport = self::getContainer()->get(DynamicMailTransport::class);
        // Test env fallback is null://null.
        self::assertSame('null://null', (string) $transport->resolveForTest());
    }

    public function testWithARowItBuildsAnSmtpTransport(): void
    {
        self::getContainer()->get(MailSettings::class)->update(
            new MailSettingsRequest(host: 'smtp.relay.test', port: 2525, password: 'p'),
        );
        $transport = self::getContainer()->get(DynamicMailTransport::class);

        self::assertInstanceOf(EsmtpTransport::class, $transport->resolveForTest());
    }
}
```

(Expose a `resolveForTest(): TransportInterface` that calls the private resolver, or make the resolver `public function activeTransport()`. Prefer a public `activeTransport()` used by `send()`.)

- [ ] **Step 3: Run it, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Transport/DynamicMailTransportTest.php`
Expected: FAIL (class not found).

- [ ] **Step 4: Write the transport**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Transport;

use App\Service\Mail\Settings\MailSettings;
use App\Service\Mail\Settings\ResolvedMailTransport;
use App\Enum\MailEncryption;
use Psr\Log\LoggerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The one mailer transport. It resolves the active transport at SEND time, never
 * at construction: the DB is not reachable during cache:warmup. A saved SMTP row
 * wins; otherwise the env fallback DSN is used. The built transport is memoised
 * per signature so a digest batch does not reconnect per message. The fallback is
 * built with the app's dispatcher/logger/client so the message-logger listener
 * still collects sent messages, and from the DEFAULT factory set — which does not
 * include `dynamic` — so there is no recursion.
 */
final class DynamicMailTransport implements TransportInterface
{
    private ?TransportInterface $cached = null;
    private ?string $cachedSignature = null;

    public function __construct(
        private readonly MailSettings $settings,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        return $this->activeTransport()->send($message, $envelope);
    }

    public function activeTransport(): TransportInterface
    {
        $resolved = $this->settings->configuredTransport();
        $signature = null !== $resolved
            ? 'db:' . $resolved->signature()
            : 'fallback:' . $this->settings->activeTransportDsnFallback();

        if ($signature === $this->cachedSignature && null !== $this->cached) {
            return $this->cached;
        }

        $this->cached = null !== $resolved ? $this->buildSmtp($resolved) : $this->buildFallback();
        $this->cachedSignature = $signature;

        return $this->cached;
    }

    private function buildSmtp(ResolvedMailTransport $resolved): TransportInterface
    {
        $implicitTls = MailEncryption::Tls === $resolved->encryption ?: null;
        $transport = new EsmtpTransport(
            $resolved->host,
            $resolved->port,
            $implicitTls,
            $this->dispatcher,
            $this->logger,
        );

        if (MailEncryption::None === $resolved->encryption) {
            $transport->setAutoTls(false);
        }
        if (null !== $resolved->username) {
            $transport->setUsername($resolved->username);
        }
        if (null !== $resolved->password) {
            $transport->setPassword($resolved->password);
        }

        return $transport;
    }

    private function buildFallback(): TransportInterface
    {
        $factories = Transport::getDefaultFactories($this->dispatcher, $this->httpClient, $this->logger);

        return (new Transport(iterator_to_array($factories)))->fromString($this->settings->activeTransportDsnFallback());
    }

    public function __toString(): string
    {
        return 'dynamic://default';
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Transport;

use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Registers the `dynamic://` scheme. FrameworkBundle autoconfigures any
 * TransportFactoryInterface with the mailer.transport_factory tag, so this joins
 * the transport chain and MAILER_DSN=dynamic://default resolves to it.
 */
final readonly class DynamicMailTransportFactory implements TransportFactoryInterface
{
    public function __construct(private DynamicMailTransport $transport)
    {
    }

    public function create(Dsn $dsn): TransportInterface
    {
        return $this->transport;
    }

    public function supports(Dsn $dsn): bool
    {
        return 'dynamic' === $dsn->getScheme();
    }
}
```

- [ ] **Step 6: Run the transport test + the mail suite (guards against broken event collection)**

Run: `cd backend && bin/console cache:warmup && php bin/phpunit tests/Service/Mail tests/Controller/Api/MeController* --testdox`
Expected: PASS. If Symfony's `assertEmail*` helpers used anywhere now fail, the fallback build lost the dispatcher — re-check `buildFallback`.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Mail/Transport backend/.env docker-compose.yml backend/tests/Service/Mail/Transport && git commit -m "feat(#834): resolve the mail transport from DB or env per send"
```

---

### Task 8: Make enablement DB-aware; retire the `MAIL_DISABLED` read

**Files:**
- Modify: `backend/src/Service/Mail/MailCapability.php`
- Test: `backend/tests/Service/Mail/MailCapabilityTest.php` (rewrite to the new contract), and confirm `MailGatedAccountMailerWiringTest` / gated digest tests still pass.

**Interfaces:**
- Consumes: `MailSettings::isSendingEnabled()`.
- Produces: `MailCapability::isEnabled(): bool` unchanged in shape; now DB-aware.

- [ ] **Step 1: Rewrite the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\MailCapability;
use App\Service\Mail\Settings\MailSettings;
use PHPUnit\Framework\TestCase;

final class MailCapabilityTest extends TestCase
{
    public function testItDelegatesToTheSettingsResolution(): void
    {
        $settings = $this->createMock(MailSettings::class);
        $settings->method('isSendingEnabled')->willReturn(true);

        self::assertTrue((new MailCapability($settings))->isEnabled());
    }

    public function testItIsDisabledWhenSettingsResolveDisabled(): void
    {
        $settings = $this->createMock(MailSettings::class);
        $settings->method('isSendingEnabled')->willReturn(false);

        self::assertFalse((new MailCapability($settings))->isEnabled());
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Mail/MailCapabilityTest.php`
Expected: FAIL (constructor mismatch).

- [ ] **Step 3: Rewrite `MailCapability`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Service\Mail\Settings\MailSettings;

/**
 * Whether this instance may send mail at all. A saved admin toggle governs;
 * with no row, enablement derives from the env fallback (a real transport = on).
 * MAIL_DISABLED is retired: "no mail" is now the natural state of "nothing
 * configured and no real fallback", plus the admin toggle.
 */
final readonly class MailCapability
{
    public function __construct(private MailSettings $settings)
    {
    }

    public function isEnabled(): bool
    {
        return $this->settings->isSendingEnabled();
    }
}
```

- [ ] **Step 4: Run the mail gate + registration-policy suites**

Run: `cd backend && php bin/phpunit tests/Service/Mail tests/Service/Auth --testdox`
Expected: PASS. If `RegistrationPolicy` tests set `MAIL_DISABLED` env to drive mail-off, update them to seed a `MailSettings` disabled state instead (a persisted row with `enabled=false`, or the null fallback).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Mail/MailCapability.php backend/tests/Service/Mail/MailCapabilityTest.php && git commit -m "refactor(#834): derive mail enablement from the admin settings"
```

---

### Task 9: Identity from `MailSettings`

**Files:**
- Modify: `backend/src/Service/Mail/AccountMailer.php`
- Test: `backend/tests/Service/Mail/AccountMailerTest.php` (adapt the existing one)

**Interfaces:**
- Consumes: `MailSettings::identity()`.
- Produces: `AccountMailer` no longer autowires `MAIL_FROM`/`MAIL_FROM_NAME`; it reads `MailSettings::identity()` at send time.

- [ ] **Step 1: Adapt the test**

Update the existing `AccountMailerTest` to construct `AccountMailer` with a `MailSettings` test double whose `identity()` returns `new MailIdentity('noreply@reader.test', 'Reader')`, and assert the `From` header on the sent mail equals that. Keep every other assertion.

- [ ] **Step 2: Run it, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Mail/AccountMailerTest.php`
Expected: FAIL (constructor mismatch).

- [ ] **Step 3: Change the constructor and the `send()` from-line**

Replace the two `#[Autowire]` string params with `private MailSettings $mailSettings`, and in `send()` build the address from the resolved identity:

```php
$identity = $this->mailSettings->identity();
// ...
->from(new Address($identity->address, $identity->name))
```

- [ ] **Step 4: Run it, verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Mail/AccountMailerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Mail/AccountMailer.php backend/tests/Service/Mail/AccountMailerTest.php && git commit -m "refactor(#834): resolve the mail from-identity from the admin settings"
```

---

### Task 10: Relax `InsecureProductionConfigGuard`

**Files:**
- Modify: `backend/src/EventListener/InsecureProductionConfigGuard.php`
- Test: `backend/tests/EventListener/InsecureProductionConfigGuardTest.php` (adapt)

**Interfaces:**
- Produces: the guard keeps the ALTCHA check; the mail branch, `NULL_MAILER_DSN`, the `$mailerDsn` and `MailCapability` deps are removed.

- [ ] **Step 1: Adapt the test**

Remove the two mail-branch cases (null DSN + mail enabled → problem; null DSN + mail disabled → no problem). Keep the ALTCHA placeholder case and the "prod-only" gate. Add one case: prod + a placeholder-free ALTCHA key + any mailer config → `problems()` is `[]`.

- [ ] **Step 2: Run it, verify it fails**

Run: `cd backend && php bin/phpunit tests/EventListener/InsecureProductionConfigGuardTest.php`
Expected: FAIL (still references removed constant/branch).

- [ ] **Step 3: Edit the guard**

Drop `use App\Service\Mail\MailCapability;`, the `NULL_MAILER_DSN` constant, the `$mailerDsn` and `$mail` constructor params, and the mail `if` block in `problems()`. Update the class docblock: remove the `MAILER_DSN=null://null` paragraph and the `null://null isn't always that mistake` paragraph; keep the ALTCHA rationale and the `WHY kernel.request` paragraph. The remaining check is ALTCHA-only.

- [ ] **Step 4: Run it, verify it passes; warm cache; boot**

Run: `cd backend && php bin/phpunit tests/EventListener/InsecureProductionConfigGuardTest.php && bin/console cache:warmup`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/EventListener/InsecureProductionConfigGuard.php backend/tests/EventListener/InsecureProductionConfigGuardTest.php && git commit -m "refactor(#834): accept an unconfigured mail transport as a valid state"
```

---

### Task 11: The test-send

**Files:**
- Create: `backend/src/Service/Mail/Settings/MailTestResult.php`
- Create: `backend/src/Service/Mail/Settings/MailConnectionTester.php`
- Test: `backend/tests/Service/Mail/Settings/MailConnectionTesterTest.php`

**Interfaces:**
- Consumes: `MailSettings::configuredTransport()`, `MailSettings::identity()`, `DynamicMailTransport::buildSmtp` logic (reuse by building an `EsmtpTransport` here from the resolved transport — extract a small builder if that avoids duplication, or construct directly), the acting user's email via `Symfony\Bundle\SecurityBundle\Security`.
- Produces: `MailConnectionTester::test(): MailTestResult`; `MailTestResult{ok:bool, reason:?string}` with `toArray()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Dto\Admin\MailSettingsRequest;
use App\Service\Mail\Settings\MailConnectionTester;
use App\Service\Mail\Settings\MailSettings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MailConnectionTesterTest extends KernelTestCase
{
    public function testItReportsNotConfiguredWithNoSavedRow(): void
    {
        // no row, acting admin resolved via a security double set in the container
        $result = self::getContainer()->get(MailConnectionTester::class)->test();

        self::assertFalse($result->ok);
        self::assertSame('not_configured', $result->reason);
    }

    public function testItReportsTheTransportErrorWhenTheServerIsUnreachable(): void
    {
        self::getContainer()->get(MailSettings::class)->update(
            new MailSettingsRequest(host: '127.0.0.1', port: 0, fromAddress: 'from@x.test', password: 'p'),
        );

        $result = self::getContainer()->get(MailConnectionTester::class)->test();

        self::assertFalse($result->ok);
        self::assertNotNull($result->reason);
    }
}
```

(Provide the acting admin: seed a user and log it in via `KernelBrowser`/`loginUser`, or inject a `Security` stub. Follow the pattern in the existing admin functional tests.)

- [ ] **Step 2: Run it, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Settings/MailConnectionTesterTest.php`
Expected: FAIL (classes not found).

- [ ] **Step 3: Write `MailTestResult`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

final readonly class MailTestResult
{
    private function __construct(
        public bool $ok,
        public ?string $reason,
    ) {
    }

    public static function ok(): self
    {
        return new self(true, null);
    }

    public static function failed(string $reason): self
    {
        return new self(false, $reason);
    }

    /** @return array{ok: bool, reason: string|null} */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'reason' => $this->reason];
    }
}
```

- [ ] **Step 4: Write `MailConnectionTester`**

Build the `EsmtpTransport` from the saved transport, send synchronously (a plain `MailerInterface` over that one transport, NOT `DeferredMailer`) to the acting admin's own email, from the resolved identity. Catch `MailPasswordUnreadableException` and `TransportExceptionInterface`.

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Enum\MailEncryption;
use App\Service\Mail\Settings\Crypto\Exception\MailPasswordUnreadableException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends a real message through the SAVED SMTP config (independent of the enable
 * switch), synchronously, so the admin can verify before turning mail on. The
 * recipient is the acting admin's own address: the instance cannot be driven to
 * mail an arbitrary target.
 */
final readonly class MailConnectionTester
{
    public function __construct(
        private MailSettings $settings,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    public function test(): MailTestResult
    {
        try {
            $resolved = $this->settings->configuredTransport();
        } catch (MailPasswordUnreadableException $e) {
            return MailTestResult::failed($e->getMessage());
        }

        $recipient = $this->actingAdminEmail();

        if (null === $resolved || null === $recipient) {
            return MailTestResult::failed('not_configured');
        }

        $identity = $this->settings->identity();

        try {
            $mailer = new Mailer($this->buildSmtp($resolved));
            $mailer->send(
                (new Email())
                    ->from(new Address($identity->address, $identity->name))
                    ->to($recipient)
                    ->subject('Simple Feed Reader test message')
                    ->text('This confirms the outgoing mail configuration works.'),
            );
        } catch (TransportExceptionInterface $e) {
            return MailTestResult::failed($e->getMessage());
        }

        return MailTestResult::ok();
    }

    private function actingAdminEmail(): ?string
    {
        $user = $this->security->getUser();

        return $user instanceof \App\Entity\User ? $user->getEmail() : null;
    }

    private function buildSmtp(ResolvedMailTransport $resolved): EsmtpTransport
    {
        $implicitTls = MailEncryption::Tls === $resolved->encryption ?: null;
        $transport = new EsmtpTransport($resolved->host, $resolved->port, $implicitTls, null, $this->logger);

        if (MailEncryption::None === $resolved->encryption) {
            $transport->setAutoTls(false);
        }
        if (null !== $resolved->username) {
            $transport->setUsername($resolved->username);
        }
        if (null !== $resolved->password) {
            $transport->setPassword($resolved->password);
        }

        return $transport;
    }
}
```

> **DRY note:** `buildSmtp` now appears in both `DynamicMailTransport` and here. On the third line of duplication that is a refactor — extract `EsmtpTransportBuilder::from(ResolvedMailTransport, ?EventDispatcherInterface, LoggerInterface): EsmtpTransport` and have both call it. Do the extraction in this task since this is the second occurrence and the shapes are identical.

- [ ] **Step 5: Extract the shared SMTP builder**

Create `backend/src/Service/Mail/Transport/EsmtpTransportBuilder.php` with a static `from(ResolvedMailTransport $resolved, ?EventDispatcherInterface $dispatcher, LoggerInterface $logger): EsmtpTransport`, and call it from both `DynamicMailTransport::buildSmtp` and `MailConnectionTester::buildSmtp`. Re-run the Task 7 and Task 11 tests.

- [ ] **Step 6: Run it, verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Settings/MailConnectionTesterTest.php tests/Service/Mail/Transport`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Mail && git commit -m "feat(#834): test-send through the saved mail config"
```

---

### Task 12: `AdminMailController`

**Files:**
- Create: `backend/src/Controller/Admin/AdminMailController.php`
- Test: `backend/tests/Controller/Admin/AdminMailControllerTest.php` (functional)

**Interfaces:**
- Consumes: `MailSettings`, `MailConnectionTester`.
- Produces: `GET /api/admin/mail`, `PUT /api/admin/mail`, `POST /api/admin/mail/test`. ROLE_ADMIN via the `^/api/admin/` rule.

- [ ] **Step 1: Write the failing functional test**

Cover: GET returns the JSON shape with `hasPassword=false` on a fresh DB; PUT persists and echoes back with `hasPassword=true` and the hint, and never returns `password`; a non-admin gets 403; POST `/test` returns `{ok:false, reason:'not_configured'}` when nothing is saved. Follow `AdminProxyControllerTest` for auth/login helpers.

- [ ] **Step 2: Run it, verify it fails**

Run: `cd backend && php bin/phpunit tests/Controller/Admin/AdminMailControllerTest.php`
Expected: FAIL (controller not found → 404).

- [ ] **Step 3: Write the controller (thin)**

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\MailSettingsRequest;
use App\Service\Mail\Settings\MailConnectionTester;
use App\Service\Mail\Settings\MailSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ROLE_ADMIN is enforced by the `^/api/admin/` prefix rule in security.yaml,
 * not by a per-action attribute here.
 */
#[Route('/api/admin/mail')]
final readonly class AdminMailController
{
    public function __construct(private MailSettings $settings)
    {
    }

    #[Route('', name: 'api_admin_mail_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse($this->settings->view());
    }

    #[Route('', name: 'api_admin_mail_update', methods: ['PUT'])]
    public function update(#[MapRequestPayload] MailSettingsRequest $request): JsonResponse
    {
        $this->settings->update($request);

        return new JsonResponse($this->settings->view());
    }

    #[Route('/test', name: 'api_admin_mail_test', methods: ['POST'])]
    public function test(MailConnectionTester $tester): JsonResponse
    {
        return new JsonResponse($tester->test()->toArray());
    }
}
```

- [ ] **Step 4: Run it, verify it passes**

Run: `cd backend && php bin/phpunit tests/Controller/Admin/AdminMailControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Backend gate**

Run: `cd backend && composer check && php bin/phpunit && composer md`
Expected: PASS. Fix any PHPMD finding in touched files by design, not by threshold.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Controller/Admin/AdminMailController.php backend/tests/Controller/Admin/AdminMailControllerTest.php && git commit -m "feat(#834): expose the admin mail settings API"
```

---

### Task 13: `MailSettingsService` (frontend)

**Files:**
- Create: `frontend/src/app/settings/admin/mail/mail-settings.service.ts`
- Test: `frontend/src/app/settings/admin/mail/mail-settings.service.spec.ts`

**Interfaces:**
- Consumes: `GET/PUT /api/admin/mail`, `POST /api/admin/mail/test`.
- Produces: `MailSettingsService` mirroring `ProxySettingsService`.

- [ ] **Step 1: Write the failing spec**

Mirror `proxy-settings.service.spec.ts`: `load()` GETs and commits; `save()` PUTs `state + draft`; `saveInstant()` PUTs `bodyFromState + partial`; `bodyFromState` sends `password: null` unless a typed edit sets it; `testConnection()` POSTs `{}` and maps `{ok:true}`→a success probe, `{ok:false,reason}`→error.

- [ ] **Step 2: Run it (Docker), verify it fails**

Run: `docker compose exec -T frontend npm test -- mail-settings.service`
Expected: FAIL.

- [ ] **Step 3: Write the service**

Adapt `proxy-settings.service.ts` with the mail shape. `MailTestResponse` is `{ok:boolean, reason:string|null}` (no `egressIp`); the `ok` probe carries no payload.

```ts
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';
import { Problem, parseProblem } from '../../../core/problem';

export type MailEncryption = 'none' | 'starttls' | 'tls';

export interface MailSettingsState {
  readonly enabled: boolean;
  readonly host: string;
  readonly port: number;
  readonly username: string | null;
  readonly encryption: MailEncryption;
  readonly fromAddress: string;
  readonly fromName: string;
  readonly hasPassword: boolean;
  readonly passwordHint: string;
}

export interface SaveMailSettings {
  readonly enabled: boolean;
  readonly host: string;
  readonly port: number;
  readonly username: string | null;
  readonly encryption: MailEncryption;
  readonly fromAddress: string;
  readonly fromName: string;
  /** null keeps the stored secret; a string replaces it. */
  readonly password: string | null;
}

/** The typed fields behind the explicit Save. The enable toggle and the
 *  encryption select save instantly and never enter the draft. */
export type TypedMailEdits = Partial<
  Omit<SaveMailSettings, 'enabled' | 'encryption'>
>;

export type MailProbe =
  | { readonly status: 'idle' }
  | { readonly status: 'loading' }
  | { readonly status: 'ok' }
  | { readonly status: 'error'; readonly message: string };

interface MailTestResponse {
  readonly ok: boolean;
  readonly reason: string | null;
}

@Injectable()
export class MailSettingsService {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  readonly state = signal<MailSettingsState | null>(null);
  readonly busy = signal(false);
  readonly failure = signal<Problem | null>(null);
  readonly saved = signal(false);
  readonly probe = signal<MailProbe>({ status: 'idle' });

  readonly draft = signal<TypedMailEdits>({});
  readonly dirty = computed(() => Object.keys(this.draft()).length > 0);

  load(): void {
    this.run(this.http.get<MailSettingsState>(`${this.base}/api/admin/mail`), (state) =>
      this.commit(state),
    );
  }

  saveInstant(partial: Partial<SaveMailSettings>): void {
    const current = this.state();
    if (!current) return;
    this.put({ ...this.bodyFromState(current), ...partial }, (state) => {
      this.state.set(state);
      this.saved.set(true);
    });
  }

  setTypedField<F extends keyof TypedMailEdits>(field: F, value: TypedMailEdits[F]): void {
    this.draft.update((draft) => ({ ...draft, [field]: value }));
  }

  save(): void {
    const current = this.state();
    if (!current) return;
    this.put({ ...this.bodyFromState(current), ...this.draft() }, (state) => {
      this.commit(state);
      this.saved.set(true);
    });
  }

  discardDraft(): void {
    this.draft.set({});
  }

  testConnection(): void {
    this.probe.set({ status: 'loading' });
    this.http.post<MailTestResponse>(`${this.base}/api/admin/mail/test`, {}).subscribe({
      next: (result) =>
        this.probe.set(
          result.ok
            ? { status: 'ok' }
            : { status: 'error', message: result.reason ?? 'failed' },
        ),
      error: (error: HttpErrorResponse) =>
        this.probe.set({ status: 'error', message: parseProblem(error).detail ?? 'failed' }),
    });
  }

  /** password defaults to null (keep stored) unless a typed edit sets it. */
  private bodyFromState(state: MailSettingsState): SaveMailSettings {
    return {
      enabled: state.enabled,
      host: state.host,
      port: state.port,
      username: state.username,
      encryption: state.encryption,
      fromAddress: state.fromAddress,
      fromName: state.fromName,
      password: null,
    };
  }

  private put(body: SaveMailSettings, onSuccess: (state: MailSettingsState) => void): void {
    this.run(this.http.put<MailSettingsState>(`${this.base}/api/admin/mail`, body), onSuccess);
  }

  private commit(state: MailSettingsState): void {
    this.state.set(state);
    this.draft.set({});
  }

  private run<T>(request: Observable<T>, onSuccess: (value: T) => void): void {
    this.busy.set(true);
    this.failure.set(null);
    this.saved.set(false);
    request.subscribe({
      next: (value) => {
        this.busy.set(false);
        onSuccess(value);
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.failure.set(parseProblem(error));
      },
    });
  }
}
```

- [ ] **Step 4: Run it, verify it passes**

Run: `docker compose exec -T frontend npm test -- mail-settings.service`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/settings/admin/mail/mail-settings.service.ts frontend/src/app/settings/admin/mail/mail-settings.service.spec.ts && git commit -m "feat(#834): admin mail settings client service"
```

---

### Task 14: `MailSectionComponent` (frontend)

**Files:**
- Create: `frontend/src/app/settings/admin/mail/mail-section.component.ts`
- Create: `frontend/src/app/settings/admin/mail/mail-section.component.html`
- Create: `frontend/src/app/settings/admin/mail/mail-section.component.scss`
- Test: `frontend/src/app/settings/admin/mail/mail-section.component.spec.ts`

**Interfaces:**
- Consumes: `MailSettingsService`.
- Produces: `MailSectionComponent` (selector `app-mail-section`).

- [ ] **Step 1: Copy `proxy-section.component.{ts,html,scss,spec.ts}` and adapt**

Concrete diff from the proxy section (not "similar to" — the exact transformation):
- Rename the class/selector/service to the mail ones; `providers: [MailSettingsService]`.
- Instant fields: `enabled` (toggle) and `encryption` (select over `['none','starttls','tls']`). Remove `directFallback`, `remoteDns`, `type`, `dnsIsChoosable`, `typeOptions`.
- Typed/draft fields: `host`, `port` (default `587` — define `const DEFAULT_PORT = 587;`, mirroring `MailConnection::DEFAULT_PORT`), `username`, `fromAddress`, `fromName`, and `password` (plain signal, never seeded).
- `configured = computed(() => (svc.state()?.host ?? '') !== '')`; `canTest = configured && !svc.dirty()`; `passwordHint = computed(() => svc.state()?.passwordHint ?? '')`.
- The Test result: on `probe.status === 'ok'` show a `settings.mail.testOk` line (no IP interpolation); on `'error'` show `settings.mail.testFailed` with `{reason}`.
- Toast key `settings.mail.saved`.
- HTML: reuse the shared `app-settings-group/-row/-stack`, `app-toggle`, `app-password-input` (placeholder `passwordHint()`), `app-button`, `app-error-banner`, `app-settings-save-bar`. Replace the proxy rows with: an Enabled toggle (disabled until `configured()`); an Encryption `<select>`; Host, Port, Username text/number inputs; a From-address and From-name input; the Password field; the Test button + status icon; the save bar.
- SCSS: copy `proxy-section.component.scss` verbatim (same shared layout tokens); rename only the top-level selector if it is class-scoped.

- [ ] **Step 2: Write the spec**

Mirror `proxy-section.component.spec.ts`: renders from a stubbed service state; the enable toggle disabled until a host is present; Test disabled while dirty; the password field maps blank→null on the service. Reuse the proxy spec's harness.

- [ ] **Step 3: Run it, verify it passes**

Run: `docker compose exec -T frontend npm test -- mail-section`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/app/settings/admin/mail/mail-section.component.* && git commit -m "feat(#834): admin mail settings section"
```

---

### Task 15: Route, navigation, and i18n

**Files:**
- Modify: `frontend/src/app/settings/settings.routes.ts`
- Modify: the admin section list/navigation that lists `admin/proxy` (find it: `grep -rn "admin/proxy" frontend/src/app/settings`)
- Modify: the Transloco locale files (find: `grep -rln "settings.proxy" frontend/src`)
- Test: existing settings routing/nav specs; add a smoke where one exists.

**Interfaces:**
- Produces: a reachable `settings/admin/mail` route behind `adminGuard`, a nav entry, and `settings.mail.*` keys in every locale.

- [ ] **Step 1: Add the route**

After the `admin/proxy` route block in `settings.routes.ts`:

```ts
      {
        path: 'admin/mail',
        title: sectionLabelKey('admin/mail'),
        canActivate: [adminGuard],
        loadComponent: () =>
          import('./admin/mail/mail-section.component').then((m) => m.MailSectionComponent),
      },
```

- [ ] **Step 2: Add the nav entry**

Wherever `admin/proxy` is listed for the admin settings menu, add an `admin/mail` entry with the same `adminGuard`/label convention.

- [ ] **Step 3: Add the i18n keys**

For every locale file that has `settings.proxy.*`, add a `settings.mail` block with at least: `saved`, `testOk`, `testFailed`, and labels for enabled, host, port, username, encryption (+ the three options), fromAddress, fromName, password, and the section title `sectionLabelKey('admin/mail')`. Match the existing translation style; keep the English source authoritative and copy placeholders for other locales if the project does that elsewhere.

- [ ] **Step 4: Frontend gate**

Run: `cd frontend && npm run check`
Expected: PASS (ESLint + Prettier + Stylelint + Jest, and the type-check the native runner skips).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/settings && git commit -m "feat(#834): route and surface the mail settings section"
```

---

### Task 16: Retire `MAIL_DISABLED`; finish env/compose/deploy

**Files:**
- Modify: `backend/.env` (remove `MAIL_DISABLED=`)
- Modify: `docker-compose.prod.yml` (remove the `MAIL_DISABLED` line and its comment; change `MAILER_DSN` to `dynamic://default` and add `MAILER_FALLBACK_DSN`)
- Modify: `.env.prod.example` (rewrite the mail section: `MAILER_FALLBACK_DSN`, drop `MAIL_DISABLED`, keep `MAIL_FROM(_NAME)`)
- Modify: `deploy/strato/.env.local.example` + `deploy/strato/README.md` (the `MAILER_DSN` line becomes `MAILER_FALLBACK_DSN`; the `dynamic://default` default is set by compose)
- Modify: any code still reading `MAIL_DISABLED` (should be none after Task 8 — verify)
- Test: full suite + migrate-from-empty legs.

**Interfaces:**
- Produces: a deploy contract with `MAILER_DSN=dynamic://default` fixed, `MAILER_FALLBACK_DSN` as the env transport, and no `MAIL_DISABLED`.

- [ ] **Step 1: Prove `MAIL_DISABLED` is dead in code**

Run: `grep -rn "MAIL_DISABLED" backend/src backend/config`
Expected: no hits. If any remain, fix them before editing env files.

- [ ] **Step 2: Edit the compose + env files**

`docker-compose.prod.yml`: set `MAILER_DSN: dynamic://default`; add `MAILER_FALLBACK_DSN: ${MAILER_FALLBACK_DSN:-null://null}`; delete the `MAIL_DISABLED` line and the comment block that references the null-DSN guard exception. Keep `MAIL_FROM`/`MAIL_FROM_NAME`. Rewrite `.env.prod.example` and the Strato examples to match: the operator sets `MAILER_FALLBACK_DSN` (or leaves it null and configures mail in the admin UI).

- [ ] **Step 3: Run the whole backend suite + both migrate-from-empty legs**

Run: `cd backend && composer check && php bin/phpunit`
Then MySQL: `docker compose exec php vendor/bin/phpunit` and the migrate-from-empty on both drivers (SQLite native + MySQL Docker) with `doctrine:schema:validate`.
Expected: PASS everywhere.

- [ ] **Step 4: Scan the dev log**

Run: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 50`
Expected: no new deprecations or swallowed mailer errors from the run.

- [ ] **Step 5: Commit**

```bash
git add backend/.env docker-compose.prod.yml .env.prod.example deploy/strato && git commit -m "chore(#834): retire MAIL_DISABLED and set the dynamic mail transport contract"
```

---

### Task 17: Reset to the env configuration (addendum, 2026-09-04)

A user request added mid-implementation: from the admin panel, revert to the
`.env` mail configuration. Semantics: delete the saved DB row so resolution
falls back to the env transport + identity (see the spec's "Reset to
environment" addendum). Backend lands here; the frontend button is folded into
Tasks 13–15.

**Files:**
- Modify: `backend/src/Service/Mail/Settings/MailSettings.php` (add
  `resetToEnvironment(): void`; add `hasSavedConfig`/`envFallbackConfigured` to
  `view()`).
- Modify: `backend/src/Http/Admin/MailSettingsJson.php` (add the two booleans to
  the shape: `hasSavedConfig = null !== $settings`,
  `envFallbackConfigured = $fallback->isReal`).
- Modify: `backend/src/Controller/Admin/AdminMailController.php` (add
  `POST /api/admin/mail/reset`).
- Test: extend `MailSettingsTest` (reset deletes the row → env fallback,
  `hasSavedConfig` false) and `AdminMailControllerTest` (row present → reset →
  200, env-seeded, `hasSavedConfig` false; non-admin 403).

**Interfaces:**
- `MailSettings::resetToEnvironment()` removes the singleton row and flushes;
  a no-op when no row exists.
- JSON shape gains `hasSavedConfig: bool`, `envFallbackConfigured: bool`.

- [ ] **Step 1: failing tests** for `resetToEnvironment()` and the endpoint.
- [ ] **Step 2:** implement the service method, the JSON fields, the endpoint.
- [ ] **Step 3:** `composer check` + the mail/controller suites green.
- [ ] **Step 4:** commit `feat(#834): reset mail settings back to the env config`.

Frontend addendum (Tasks 13–15): `MailSettingsService.reset()` POSTs the
endpoint and commits the response; `MailSettingsState` gains the two booleans;
`MailSectionComponent` shows a confirm-guarded "Reset to environment" button
when `hasSavedConfig && envFallbackConfigured`; i18n `settings.mail.resetToEnv`
(+ confirm text, toast) in every locale.

## Self-Review

**Spec coverage:**
- Storage/override singleton → Tasks 4, 6. ✓
- Enablement (derived when no row, DB overrides) → Tasks 6, 8; JSON derived-enabled → Task 6. ✓
- `dynamic://` transport + fallback rename → Task 7; shared SMTP builder → Task 11. ✓
- Secret sealing + hint + keep-on-null → Tasks 3, 6. ✓
- `INSTANCE_SECRET_KEY` rename → Task 1. ✓
- Guard relaxation → Task 10. ✓
- Identity resolution → Tasks 5, 9. ✓
- Test-send (saved row, admin's own email, humanised error) → Task 11. ✓
- API → Task 12; frontend section + env-prefill + SMTP-only → Tasks 13–15. ✓
- Retire `MAIL_DISABLED` → Tasks 8, 16. ✓

**Placeholder scan:** frontend HTML/SCSS in Task 14 is a named-file copy with an enumerated diff, not a vague "similar to"; i18n keys in Task 15 are enumerated. No TBDs.

**Type consistency:** `MailConnection::DEFAULT_PORT = 587` is referenced by the entity, DTO, fallback, and the frontend `DEFAULT_PORT` mirror. `ResolvedMailTransport.signature()`, `MailSettings::{configuredTransport,identity,isSendingEnabled,activeTransportDsnFallback}`, `MailTestResult::{ok,failed,toArray}`, and the JSON shape are used consistently across Tasks 6, 7, 11, 12, 13. The `EsmtpTransportBuilder::from(...)` extraction (Task 11 Step 5) is the single source for both SMTP builds.

**Risk flagged for the executor:** Task 7 is load-bearing — the fallback transport MUST be built with the app dispatcher or Symfony's mail test assertions break. Run the mail suite at Task 7 Step 6, not only at the end.
