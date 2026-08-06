# Per-account AI provider (groundwork) — Implementation Plan (#305)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** an account can save an OpenAI-compatible endpoint and API key, discover the models that endpoint offers, choose one, and have the SPA report `aiReady` for later features to read.

**Architecture:** the API key is sealed with XChaCha20-Poly1305 under a key derived per row (HKDF over an env-only master secret, a random salt, the account id and the scheme version). A new `AiProviderSettings` entity holds the sealed material. Five endpoints under `/api/me/ai` verify against the provider before they write. The SPA gains one shared component (a searchable select), one settings section, and one availability signal.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM 3, `ext-sodium`, PHPUnit; Angular 20 standalone + signals, Transloco, Jest.

**Design spec:** [docs/superpowers/specs/2026-08-06-ai-provider-groundwork-design.md](../specs/2026-08-06-ai-provider-groundwork-design.md). Read it before Task 1.

## Global Constraints

- Every PHP file starts with `declare(strict_types=1);`.
- House style is `final readonly class` with constructor promotion. `final` unless designed for extension.
- Failures are typed exceptions in `Service/Ai/Exception/` or `Service/Ai/Crypto/Exception/`. Never `null`, never a magic value.
- Controllers hold **no** private methods that carry responsibility (`ThinControllerRule`, run by `composer stan`).
- Comments explain *why*, never *what*.
- Every `src` file touched must be PHPMD-clean before commit, not merely free of new findings.
- Backend gate: `composer check` (PSR-12 + PHPStan level max) and `composer md`, both from `backend/`.
- Frontend gate: `npm run check` from `frontend/` (ESLint + Prettier 100 columns + Stylelint + Jest).
- No hex colours and no raw `px` spacing in `.scss` outside `src/app/theme/`. Component styles live in a sibling `.scss` referenced by `styleUrl`, never inline in the `.ts`.
- Every user-facing string is a Transloco key present in **both** `frontend/public/i18n/en.json` and `de.json`.
- The API stays native-iOS viable: bearer token, stateless, JSON in, `application/problem+json` out, no browser-only input.
- Branch: `feature/305-ai-provider-groundwork`, already created off `develop`. Commit after every task.

## File Structure

**Backend — created**

| File | Responsibility |
|---|---|
| `src/Service/Ai/Crypto/SealedApiKey.php` | value object: ciphertext, nonce, salt, version (all base64 except version) |
| `src/Service/Ai/Crypto/ApiKeyCipher.php` | seals and opens an API key; owns HKDF and AEAD |
| `src/Service/Ai/Crypto/Exception/ApiKeyUnreadableException.php` | failed integrity check or unknown version |
| `src/Entity/AiProviderSettings.php` | the persisted row |
| `src/Repository/AiProviderSettingsRepository.php` | lookup by user |
| `migrations/Version20260806120000.php` | creates `user_ai_settings` |
| `src/Service/Ai/ProviderCredentials.php` | value object: base URL and plain key |
| `src/Service/Ai/ModelCatalog.php` | interface: `listModels()` |
| `src/Service/Ai/OpenAiCompatibleCatalog.php` | the `GET {baseUrl}/models` implementation |
| `src/Service/Ai/Exception/ProviderUnreachableException.php` | the endpoint did not answer, or answered unusably |
| `src/Service/Ai/Exception/CredentialsRejectedException.php` | the endpoint answered 401 or 403 |
| `src/Service/Ai/Exception/ModelNotOfferedException.php` | the chosen model is not in the list |
| `src/Service/Ai/AiProviderConfigurator.php` | verify, then persist. The only writer |
| `src/Http/AiSettingsJson.php` | the state and model-list response shapes, and the one definition of `ready` |
| `src/Exception/AiProviderApiException.php` | client-facing 422 |
| `src/Exception/AiNotConfiguredApiException.php` | client-facing 404 |
| `src/Dto/Ai/SaveConnectionRequest.php` | `baseUrl`, `apiKey` |
| `src/Dto/Ai/SaveModelRequest.php` | `model` |
| `src/Controller/Api/AiSettingsController.php` | the five routes |

**Backend — modified**

| File | Change |
|---|---|
| `.env` | `AI_KEY_SECRET` placeholder |
| `config/packages/rate_limiter.yaml` | `ai_provider` limiter |
| `src/Entity/User.php` | inverse side of the one-to-one |
| `src/Http/MeJson.php` | `ai` block |
| `scripts/lib.sh` | `ENV_PROD_REQUIRED`, `ensure_ai_key_secret` |
| `scripts/install.sh` | generate on install |
| `scripts/prod-start.sh` | call `ensure_ai_key_secret` |

**Frontend — created**

| File | Responsibility |
|---|---|
| `src/app/shared/searchable-select/searchable-select.component.{ts,html,scss,spec.ts}` | filterable listbox, reusable |
| `src/app/core/ai-availability.service.ts` | the `ready` signal |
| `src/app/settings/ai-section.component.{ts,html,scss,spec.ts}` | the settings section |
| `src/app/settings/ai-settings.service.ts` | the HTTP calls and section state |

**Frontend — modified**

| File | Change |
|---|---|
| `src/app/core/auth.service.ts` | `CurrentUser.ai`, adopt and reset |
| `src/app/settings/settings-sections.ts` | the `ai` entry |
| `src/app/settings/settings.routes.ts` | the `ai` route |
| `frontend/public/i18n/en.json`, `de.json` | new keys |

---

### Task 1: The cipher

Seals and opens an API key. No persistence, no HTTP — this task is pure crypto plus its environment wiring.

**Files:**
- Create: `backend/src/Service/Ai/Crypto/SealedApiKey.php`
- Create: `backend/src/Service/Ai/Crypto/ApiKeyCipher.php`
- Create: `backend/src/Service/Ai/Crypto/Exception/ApiKeyUnreadableException.php`
- Modify: `backend/.env` (after line 59, the `ALTCHA_HMAC_KEY` line)
- Modify: `scripts/lib.sh:345` and after `ensure_admin_setup_secret`
- Modify: `scripts/install.sh:92`
- Modify: `scripts/prod-start.sh:36`
- Test: `backend/tests/Service/Ai/Crypto/ApiKeyCipherTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `SealedApiKey` — `__construct(string $ciphertext, string $nonce, string $salt, int $version)`, all four public readonly.
  - `ApiKeyCipher::seal(int $userId, string $plainApiKey): SealedApiKey`
  - `ApiKeyCipher::open(int $userId, SealedApiKey $sealed): string`
  - `ApiKeyCipher::CURRENT_VERSION` — `int`, value `1`.
  - `ApiKeyUnreadableException extends \RuntimeException`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Ai/Crypto/ApiKeyCipherTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Crypto;

use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Crypto\Exception\ApiKeyUnreadableException;
use App\Service\Ai\Crypto\SealedApiKey;
use PHPUnit\Framework\TestCase;

/**
 * The properties that make a database dump useless on its own: the master
 * secret is not in the row, the account id is bound into the ciphertext, and
 * the scheme version is bound with it.
 */
final class ApiKeyCipherTest extends TestCase
{
    private const string SECRET = 'c0ffee1234567890c0ffee1234567890c0ffee1234567890c0ffee1234567890';

    private function cipher(): ApiKeyCipher
    {
        return new ApiKeyCipher(self::SECRET);
    }

    public function testASealedKeyOpensAgain(): void
    {
        $cipher = $this->cipher();

        $sealed = $cipher->seal(42, 'sk-test-abcdef');

        self::assertSame('sk-test-abcdef', $cipher->open(42, $sealed));
    }

    public function testTheCiphertextDoesNotContainThePlainKey(): void
    {
        $sealed = $this->cipher()->seal(42, 'sk-test-abcdef');

        self::assertStringNotContainsString('sk-test-abcdef', base64_decode($sealed->ciphertext, true) ?: '');
    }

    public function testTwoSealsOfOneKeyDifferFromEachOther(): void
    {
        $cipher = $this->cipher();

        $first = $cipher->seal(42, 'sk-test-abcdef');
        $second = $cipher->seal(42, 'sk-test-abcdef');

        self::assertNotSame($first->ciphertext, $second->ciphertext);
        self::assertNotSame($first->salt, $second->salt);
    }

    public function testAnotherAccountCannotOpenTheKey(): void
    {
        $cipher = $this->cipher();
        $sealed = $cipher->seal(42, 'sk-test-abcdef');

        $this->expectException(ApiKeyUnreadableException::class);
        $cipher->open(43, $sealed);
    }

    public function testAnAlteredVersionCannotOpenTheKey(): void
    {
        $cipher = $this->cipher();
        $sealed = $cipher->seal(42, 'sk-test-abcdef');

        $this->expectException(ApiKeyUnreadableException::class);
        $cipher->open(42, new SealedApiKey($sealed->ciphertext, $sealed->nonce, $sealed->salt, 2));
    }

    public function testAnotherMasterSecretCannotOpenTheKey(): void
    {
        $sealed = $this->cipher()->seal(42, 'sk-test-abcdef');
        $other = new ApiKeyCipher(str_repeat('a', 64));

        $this->expectException(ApiKeyUnreadableException::class);
        $other->open(42, $sealed);
    }

    public function testATamperedCiphertextCannotOpen(): void
    {
        $cipher = $this->cipher();
        $sealed = $cipher->seal(42, 'sk-test-abcdef');

        $raw = base64_decode($sealed->ciphertext, true);
        self::assertIsString($raw);
        $raw[0] = $raw[0] === "\x00" ? "\x01" : "\x00";

        $this->expectException(ApiKeyUnreadableException::class);
        $cipher->open(42, new SealedApiKey(base64_encode($raw), $sealed->nonce, $sealed->salt, $sealed->version));
    }

    public function testStoredMaterialThatIsNotBase64CannotOpen(): void
    {
        $cipher = $this->cipher();
        $sealed = $cipher->seal(42, 'sk-test-abcdef');

        $this->expectException(ApiKeyUnreadableException::class);
        $cipher->open(42, new SealedApiKey('not base64 !!', $sealed->nonce, $sealed->salt, $sealed->version));
    }

    public function testAShortMasterSecretIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ApiKeyCipher('too-short');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run from `backend/`:

```bash
php bin/phpunit tests/Service/Ai/Crypto/ApiKeyCipherTest.php
```

Expected: FAIL, `Class "App\Service\Ai\Crypto\ApiKeyCipher" not found`.

- [ ] **Step 3: Write the value object**

Create `backend/src/Service/Ai/Crypto/SealedApiKey.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai\Crypto;

/**
 * An API key at rest. Everything here is safe to store: without the master
 * secret from the environment, the ciphertext is noise.
 *
 * The three byte strings are base64 so one migration serves both MySQL and
 * SQLite — see the spec's persistence section.
 */
final readonly class SealedApiKey
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

- [ ] **Step 4: Write the exception**

Create `backend/src/Service/Ai/Crypto/Exception/ApiKeyUnreadableException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai\Crypto\Exception;

/**
 * The stored material did not open. Three causes, deliberately not
 * distinguished: a wrong master secret, a row edited in the database, and a
 * row moved to another account all mean the same thing to a caller — this key
 * is gone, ask the account to enter it again. Telling them apart would only
 * help someone probing the store.
 */
final class ApiKeyUnreadableException extends \RuntimeException
{
}
```

- [ ] **Step 5: Write the cipher**

Create `backend/src/Service/Ai/Crypto/ApiKeyCipher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai\Crypto;

use App\Service\Ai\Crypto\Exception\ApiKeyUnreadableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Seals an account's API key so a database dump alone reveals nothing.
 *
 * The master secret lives only in the environment. Every row carries its own
 * random salt, and the account id and the scheme version are bound into both
 * the key derivation and the AEAD's additional data. So a row cannot be moved
 * to another account, and its version cannot be lowered to reach an older
 * scheme once a second one exists.
 *
 * What this cannot do: protect a key from someone holding both the dump and
 * the environment file. The server has to use the key while the account holder
 * is absent, so the secret has to be reachable by the server.
 */
final readonly class ApiKeyCipher
{
    public const int CURRENT_VERSION = 1;

    private const int SALT_BYTES = 16;
    private const int MINIMUM_SECRET_LENGTH = 32;

    public function __construct(
        #[Autowire('%env(AI_KEY_SECRET)%')]
        private string $masterSecret,
    ) {
        // A short or empty secret would still derive a key and still encrypt,
        // so nothing downstream could notice. Fail at construction instead.
        if (\strlen($masterSecret) < self::MINIMUM_SECRET_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'AI_KEY_SECRET must be at least %d characters; got %d.',
                self::MINIMUM_SECRET_LENGTH,
                \strlen($masterSecret),
            ));
        }
    }

    public function seal(int $userId, string $plainApiKey): SealedApiKey
    {
        $salt = random_bytes(self::SALT_BYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $rowKey = $this->deriveRowKey($userId, self::CURRENT_VERSION, $salt);

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plainApiKey,
            $this->binding($userId, self::CURRENT_VERSION),
            $nonce,
            $rowKey,
        );

        sodium_memzero($rowKey);

        return new SealedApiKey(
            base64_encode($ciphertext),
            base64_encode($nonce),
            base64_encode($salt),
            self::CURRENT_VERSION,
        );
    }

    public function open(int $userId, SealedApiKey $sealed): string
    {
        if (self::CURRENT_VERSION !== $sealed->version) {
            throw new ApiKeyUnreadableException(sprintf('Unknown key scheme version %d.', $sealed->version));
        }

        $rowKey = $this->deriveRowKey($userId, $sealed->version, $this->decode($sealed->salt));

        $plainApiKey = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $this->decode($sealed->ciphertext),
            $this->binding($userId, $sealed->version),
            $this->decode($sealed->nonce),
            $rowKey,
        );

        sodium_memzero($rowKey);

        if (false === $plainApiKey) {
            throw new ApiKeyUnreadableException('The stored API key failed its integrity check.');
        }

        return $plainApiKey;
    }

    private function deriveRowKey(int $userId, int $version, string $salt): string
    {
        return hash_hkdf(
            'sha256',
            $this->masterSecret,
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $this->binding($userId, $version),
            $salt,
        );
    }

    /**
     * One string feeds both the HKDF info and the AEAD's additional data, so
     * the two can never drift apart.
     */
    private function binding(int $userId, int $version): string
    {
        return sprintf('ai-api-key|v%d|user:%d', $version, $userId);
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode($value, true);

        if (false === $decoded) {
            throw new ApiKeyUnreadableException('Stored key material is not valid base64.');
        }

        return $decoded;
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
php bin/phpunit tests/Service/Ai/Crypto/ApiKeyCipherTest.php
```

Expected: PASS, 9 tests.

- [ ] **Step 7: Add the environment variable**

In `backend/.env`, directly below the `ALTCHA_HMAC_KEY` line:

```
# Master secret for sealing per-account AI provider API keys (#305). The
# committed value is a placeholder; scripts/prod-start.sh generates a real one
# on the server, and it never enters git or the database. Changing it makes
# every stored API key unreadable, and each account must enter its key again.
AI_KEY_SECRET=test-ai-key-secret-not-for-production-0123456789
```

- [ ] **Step 8: Wire the deploy scripts**

Leave `ENV_PROD_REQUIRED` (`scripts/lib.sh:345`) **unchanged**. An earlier
draft of this plan appended `AI_KEY_SECRET` to it; that is wrong. `prod-start.sh`
runs `env_prod_missing` and dies on any empty required value *before* it calls
`ensure_ai_key_secret`, so an instance upgrading from before #305 would abort at
that check and never reach the generator — the very outage the generator exists
to prevent. `ADMIN_SETUP_SECRET` is the precedent: machine-generated values stay
out of that list, because there is nothing for an operator to fill in.

In `scripts/lib.sh`, directly after the `ensure_admin_setup_secret` function:

```sh
# Generate AI_KEY_SECRET when it is still empty. An instance installed before
# #305 has no such variable, and %env(AI_KEY_SECRET)% that cannot resolve fails
# the container build -- every route, not just the AI ones. Generating here
# keeps the upgrade uneventful. Never regenerate a value that exists: that
# would silently make every stored API key unreadable.
ensure_ai_key_secret() {
  if [ -z "$(env_prod_get AI_KEY_SECRET)" ]; then
    env_prod_set AI_KEY_SECRET "$(generate_secret)"
  fi
}
```

In `scripts/install.sh`, after line 92 (`ALTCHA_HMAC_KEY`):

```sh
env_prod_set AI_KEY_SECRET "$(generate_secret)"
```

In `scripts/prod-start.sh`, directly after the `ensure_admin_setup_secret` call on line 36:

```sh
ensure_ai_key_secret
```

- [ ] **Step 9: Verify shellcheck and the backend gate**

```bash
shellcheck scripts/*.sh
```

Expected: no output. CI fails on **any** finding, info level included.

```bash
cd backend && composer check && composer md
```

Expected: both clean.

- [ ] **Step 10: Commit**

```bash
git add backend/src/Service/Ai backend/tests/Service/Ai backend/.env scripts/lib.sh scripts/install.sh scripts/prod-start.sh
git commit -m "feat(#305): seal AI provider API keys with a per-row derived key"
```

---

### Task 2: The stored row

The entity, its repository, and the migration.

**Files:**
- Create: `backend/src/Entity/AiProviderSettings.php`
- Create: `backend/src/Repository/AiProviderSettingsRepository.php`
- Create: `backend/migrations/Version20260806120000.php`
- Modify: `backend/src/Entity/User.php`
- Test: `backend/tests/Entity/AiProviderSettingsTest.php`

**Interfaces:**
- Consumes: `SealedApiKey`, `ApiKeyCipher::CURRENT_VERSION` from Task 1.
- Produces:
  - `AiProviderSettings::__construct(User $user, string $baseUrl, SealedApiKey $sealed, string $apiKeyHint)`
  - `->getUser(): User`, `->getBaseUrl(): string`, `->getSealedApiKey(): SealedApiKey`, `->getApiKeyHint(): string`, `->getModel(): ?string`, `->getVerifiedAt(): ?\DateTimeImmutable`
  - `->replaceConnection(string $baseUrl, SealedApiKey $sealed, string $apiKeyHint, \DateTimeImmutable $verifiedAt): void` — also clears the model, because a model chosen at the old endpoint means nothing at a new one.
  - `->chooseModel(string $model, \DateTimeImmutable $verifiedAt): void`
  - `->hasModel(): bool`
  - `AiProviderSettingsRepository::findForUser(User $user): ?AiProviderSettings`
  - `User::getAiProviderSettings(): ?AiProviderSettings`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Entity/AiProviderSettingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\Crypto\SealedApiKey;
use PHPUnit\Framework\TestCase;

final class AiProviderSettingsTest extends TestCase
{
    private function sealed(string $ciphertext = 'Y2lwaGVy'): SealedApiKey
    {
        return new SealedApiKey($ciphertext, 'bm9uY2U=', 'c2FsdA==', 1);
    }

    private function settings(): AiProviderSettings
    {
        return new AiProviderSettings(
            new User('reader@example.test', new \DateTimeImmutable('2026-08-06 09:00:00')),
            'https://api.example.test/v1',
            $this->sealed(),
            'cdef',
        );
    }

    public function testANewRowCarriesNoModelYet(): void
    {
        $settings = $this->settings();

        self::assertFalse($settings->hasModel());
        self::assertNull($settings->getModel());
    }

    public function testChoosingAModelStampsTheVerificationTime(): void
    {
        $settings = $this->settings();
        $verifiedAt = new \DateTimeImmutable('2026-08-06 10:00:00');

        $settings->chooseModel('gpt-4o-mini', $verifiedAt);

        self::assertTrue($settings->hasModel());
        self::assertSame('gpt-4o-mini', $settings->getModel());
        self::assertEquals($verifiedAt, $settings->getVerifiedAt());
    }

    public function testReplacingTheConnectionDropsTheChosenModel(): void
    {
        $settings = $this->settings();
        $settings->chooseModel('gpt-4o-mini', new \DateTimeImmutable('2026-08-06 10:00:00'));

        $settings->replaceConnection(
            'https://other.example.test/v1',
            $this->sealed('b3RoZXI='),
            'wxyz',
            new \DateTimeImmutable('2026-08-06 11:00:00'),
        );

        self::assertFalse($settings->hasModel());
        self::assertSame('https://other.example.test/v1', $settings->getBaseUrl());
        self::assertSame('wxyz', $settings->getApiKeyHint());
        self::assertSame('b3RoZXI=', $settings->getSealedApiKey()->ciphertext);
    }
}
```

`User::__construct(string $email, \DateTimeImmutable $createdAt)` — both arguments are required, and the constructor also builds the account's `Preferences` row.

- [ ] **Step 2: Run the test to verify it fails**

```bash
php bin/phpunit tests/Entity/AiProviderSettingsTest.php
```

Expected: FAIL, `Class "App\Entity\AiProviderSettings" not found`.

- [ ] **Step 3: Write the entity**

Create `backend/src/Entity/AiProviderSettings.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AiProviderSettingsRepository;
use App\Service\Ai\Crypto\SealedApiKey;
use Doctrine\ORM\Mapping as ORM;

/**
 * One account's AI provider. Unlike Preferences, this row is NOT created with
 * the account: most accounts never configure a provider, and "no row" says
 * "not configured" without a nullable flag.
 *
 * The row holds no readable secret. `apiKeyHint` is the last four characters
 * in clear text, on purpose, so the settings page can say which key is stored.
 */
#[ORM\Entity(repositoryClass: AiProviderSettingsRepository::class)]
#[ORM\Table(name: 'user_ai_settings')]
#[ORM\UniqueConstraint(name: 'uniq_ai_settings_user', columns: ['user_id'])]
class AiProviderSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'aiProviderSettings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 512)]
    private string $baseUrl;

    #[ORM\Column(length: 1024)]
    private string $apiKeyCiphertext;

    #[ORM\Column(length: 64)]
    private string $apiKeyNonce;

    #[ORM\Column(length: 64)]
    private string $apiKeySalt;

    #[ORM\Column(length: 8)]
    private string $apiKeyHint;

    #[ORM\Column(options: ['default' => 1])]
    private int $keyVersion;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    public function __construct(User $user, string $baseUrl, SealedApiKey $sealed, string $apiKeyHint)
    {
        $this->user = $user;
        $this->baseUrl = $baseUrl;
        $this->apiKeyHint = $apiKeyHint;
        $this->applySealedKey($sealed);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getApiKeyHint(): string
    {
        return $this->apiKeyHint;
    }

    public function getSealedApiKey(): SealedApiKey
    {
        return new SealedApiKey(
            $this->apiKeyCiphertext,
            $this->apiKeyNonce,
            $this->apiKeySalt,
            $this->keyVersion,
        );
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function hasModel(): bool
    {
        return null !== $this->model;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    /**
     * A new endpoint or a new key invalidates the chosen model: the identifier
     * that existed at the old provider carries no promise at the new one, and
     * keeping it would let `ready` claim a model the provider never offered.
     */
    public function replaceConnection(
        string $baseUrl,
        SealedApiKey $sealed,
        string $apiKeyHint,
        \DateTimeImmutable $verifiedAt,
    ): void {
        $this->baseUrl = $baseUrl;
        $this->apiKeyHint = $apiKeyHint;
        $this->applySealedKey($sealed);
        $this->model = null;
        $this->verifiedAt = $verifiedAt;
    }

    public function chooseModel(string $model, \DateTimeImmutable $verifiedAt): void
    {
        $this->model = $model;
        $this->verifiedAt = $verifiedAt;
    }

    private function applySealedKey(SealedApiKey $sealed): void
    {
        $this->apiKeyCiphertext = $sealed->ciphertext;
        $this->apiKeyNonce = $sealed->nonce;
        $this->apiKeySalt = $sealed->salt;
        $this->keyVersion = $sealed->version;
    }
}
```

- [ ] **Step 4: Write the repository**

Create `backend/src/Repository/AiProviderSettingsRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiProviderSettings>
 */
final class AiProviderSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiProviderSettings::class);
    }

    public function findForUser(User $user): ?AiProviderSettings
    {
        return $this->findOneBy(['user' => $user]);
    }
}
```

- [ ] **Step 5: Add the inverse side to User**

In `backend/src/Entity/User.php`, beside the existing `preferences` association, add the property and getter. Match the file's own formatting:

```php
    #[ORM\OneToOne(mappedBy: 'user', targetEntity: AiProviderSettings::class, cascade: ['remove'])]
    private ?AiProviderSettings $aiProviderSettings = null;
```

```php
    /** Null until the account configures a provider — see AiProviderSettings. */
    public function getAiProviderSettings(): ?AiProviderSettings
    {
        return $this->aiProviderSettings;
    }
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
php bin/phpunit tests/Entity/AiProviderSettingsTest.php
```

Expected: PASS, 3 tests.

- [ ] **Step 7: Write the migration**

Create `backend/migrations/Version20260806120000.php`. `user_ai_settings` is a fresh table, so one `CREATE TABLE` parses on both platforms — no platform branch is needed here, unlike the ALTER-based migrations in this directory.

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The per-account AI provider row (#305). A new table, so the same DDL parses
 * on MySQL and on SQLite; the ALTER-based migrations in this directory need a
 * platform branch, this one does not.
 *
 * The three key columns hold base64, not raw bytes, so no BLOB semantics
 * differ between the two platforms.
 */
final class Version20260806120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_ai_settings for the per-account AI provider (#305)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE user_ai_settings (
                id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                base_url VARCHAR(512) NOT NULL,
                api_key_ciphertext VARCHAR(1024) NOT NULL,
                api_key_nonce VARCHAR(64) NOT NULL,
                api_key_salt VARCHAR(64) NOT NULL,
                api_key_hint VARCHAR(8) NOT NULL,
                key_version INTEGER DEFAULT 1 NOT NULL,
                model VARCHAR(255) DEFAULT NULL,
                verified_at DATETIME DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_ai_settings_user ON user_ai_settings (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_ai_settings');
    }
}
```

**Then correct it against the real diff rather than trusting the hand-written SQL.** Run:

```bash
cd backend && php bin/console doctrine:migrations:diff --no-interaction
```

Copy the generated `up()` and `down()` bodies into `Version20260806120000.php`, keeping the class docblock above, then delete the generated file. This is the only way to get the auto-increment, foreign-key and identity syntax right for both platforms.

- [ ] **Step 8: Verify the migration on an empty database**

The test suite builds its schema from ORM metadata, so no test executes this migration. Prove it by hand, on both platforms:

```bash
cd backend && rm -f var/data_migrate.db && DATABASE_URL="sqlite:///%kernel.project_dir%/var/data_migrate.db" php bin/console doctrine:migrations:migrate --no-interaction && DATABASE_URL="sqlite:///%kernel.project_dir%/var/data_migrate.db" php bin/console doctrine:schema:validate
```

Expected: the chain applies and the mapping validates.

```bash
docker compose up -d && docker compose exec php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec php bin/console doctrine:schema:validate
```

Expected: the same. If the MySQL database already carries the schema, the new version is the only one that applies.

- [ ] **Step 9: Run the gate**

```bash
cd backend && php bin/phpunit && composer check && composer md
```

Expected: green.

- [ ] **Step 10: Commit**

```bash
git add backend/src/Entity backend/src/Repository/AiProviderSettingsRepository.php backend/migrations backend/tests/Entity/AiProviderSettingsTest.php
git commit -m "feat(#305): store the per-account AI provider row"
```

---

### Task 3: Listing a provider's models

The outbound call. No persistence — this task turns credentials into a list of model identifiers, or into a typed failure.

**Files:**
- Create: `backend/src/Service/Ai/ProviderCredentials.php`
- Create: `backend/src/Service/Ai/ModelCatalog.php`
- Create: `backend/src/Service/Ai/OpenAiCompatibleCatalog.php`
- Create: `backend/src/Service/Ai/Exception/ProviderUnreachableException.php`
- Create: `backend/src/Service/Ai/Exception/CredentialsRejectedException.php`
- Create: `backend/src/Service/Ai/Exception/ModelNotOfferedException.php`
- Modify: `backend/config/services.yaml`
- Test: `backend/tests/Service/Ai/OpenAiCompatibleCatalogTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `ProviderCredentials::__construct(string $baseUrl, string $apiKey)`, both public readonly; `ProviderCredentials::normalizeBaseUrl(string $baseUrl): string` — static, trims whitespace and trailing slashes, throws `ProviderUnreachableException` when the URL is malformed, carries credentials, or is not `http`/`https`.
  - `ModelCatalog::listModels(ProviderCredentials $credentials): array` — returns `list<string>`, sorted, never empty.
  - `OpenAiCompatibleCatalog implements ModelCatalog`.
  - `ProviderUnreachableException`, `CredentialsRejectedException`, `ModelNotOfferedException`, all `extends \RuntimeException`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Ai/OpenAiCompatibleCatalogTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\OpenAiCompatibleCatalog;
use App\Service\Ai\ProviderCredentials;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiCompatibleCatalogTest extends TestCase
{
    private function credentials(): ProviderCredentials
    {
        return new ProviderCredentials('https://api.example.test/v1', 'sk-test');
    }

    private function catalogAnswering(MockResponse $response): OpenAiCompatibleCatalog
    {
        return new OpenAiCompatibleCatalog(new MockHttpClient($response), 'SimpleFeedReader/1.0');
    }

    public function testItReturnsTheOfferedModelsSorted(): void
    {
        $catalog = $this->catalogAnswering(new MockResponse(
            '{"data":[{"id":"gpt-4o-mini"},{"id":"claude-sonnet"},{"id":"gpt-4o"}]}',
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        self::assertSame(
            ['claude-sonnet', 'gpt-4o', 'gpt-4o-mini'],
            $catalog->listModels($this->credentials()),
        );
    }

    public function testItSendsTheKeyAsABearerToken(): void
    {
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? []];

            return new MockResponse('{"data":[{"id":"gpt-4o"}]}');
        });

        (new OpenAiCompatibleCatalog($client, 'SimpleFeedReader/1.0'))->listModels($this->credentials());

        self::assertSame('GET', $seen['method']);
        self::assertSame('https://api.example.test/v1/models', $seen['url']);
        self::assertContains('Authorization: Bearer sk-test', $seen['headers']);
    }

    public function testARejectedKeyIsDistinguishedFromAnUnreachableProvider(): void
    {
        $catalog = $this->catalogAnswering(new MockResponse('{"error":"nope"}', ['http_code' => 401]));

        $this->expectException(CredentialsRejectedException::class);
        $catalog->listModels($this->credentials());
    }

    public function testAForbiddenAnswerIsAlsoARejectedKey(): void
    {
        $catalog = $this->catalogAnswering(new MockResponse('{"error":"nope"}', ['http_code' => 403]));

        $this->expectException(CredentialsRejectedException::class);
        $catalog->listModels($this->credentials());
    }

    public function testAServerErrorIsUnreachable(): void
    {
        $catalog = $this->catalogAnswering(new MockResponse('', ['http_code' => 500]));

        $this->expectException(ProviderUnreachableException::class);
        $catalog->listModels($this->credentials());
    }

    public function testATransportFailureIsUnreachable(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });

        $this->expectException(ProviderUnreachableException::class);
        (new OpenAiCompatibleCatalog($client, 'SimpleFeedReader/1.0'))->listModels($this->credentials());
    }

    public function testMalformedJsonIsUnreachable(): void
    {
        $catalog = $this->catalogAnswering(new MockResponse('<html>nope</html>'));

        $this->expectException(ProviderUnreachableException::class);
        $catalog->listModels($this->credentials());
    }

    public function testAnEmptyModelListIsUnreachable(): void
    {
        $catalog = $this->catalogAnswering(new MockResponse('{"data":[]}'));

        $this->expectException(ProviderUnreachableException::class);
        $catalog->listModels($this->credentials());
    }

    public function testEntriesWithoutAnIdAreIgnored(): void
    {
        $catalog = $this->catalogAnswering(new MockResponse('{"data":[{"id":"gpt-4o"},{"object":"model"}]}'));

        self::assertSame(['gpt-4o'], $catalog->listModels($this->credentials()));
    }

    public function testTheBaseUrlLosesItsTrailingSlash(): void
    {
        self::assertSame(
            'https://api.example.test/v1',
            ProviderCredentials::normalizeBaseUrl('  https://api.example.test/v1//  '),
        );
    }

    public function testALocalProviderIsAccepted(): void
    {
        self::assertSame(
            'http://localhost:11434/v1',
            ProviderCredentials::normalizeBaseUrl('http://localhost:11434/v1'),
        );
    }

    public function testANonHttpSchemeIsRefused(): void
    {
        $this->expectException(ProviderUnreachableException::class);
        ProviderCredentials::normalizeBaseUrl('file:///etc/passwd');
    }

    public function testCredentialsInTheUrlAreRefused(): void
    {
        $this->expectException(ProviderUnreachableException::class);
        ProviderCredentials::normalizeBaseUrl('https://user:pass@api.example.test/v1');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php bin/phpunit tests/Service/Ai/OpenAiCompatibleCatalogTest.php
```

Expected: FAIL, `Class "App\Service\Ai\OpenAiCompatibleCatalog" not found`.

- [ ] **Step 3: Write the three exceptions**

Create `backend/src/Service/Ai/Exception/ProviderUnreachableException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/**
 * The endpoint did not answer, or answered something that is not a model list.
 * Separate from CredentialsRejectedException because the two need different
 * advice: check the address, versus check the key.
 */
final class ProviderUnreachableException extends \RuntimeException
{
}
```

Create `backend/src/Service/Ai/Exception/CredentialsRejectedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/** The endpoint answered, and refused the key. */
final class CredentialsRejectedException extends \RuntimeException
{
}
```

Create `backend/src/Service/Ai/Exception/ModelNotOfferedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/**
 * The chosen model is not in the list the provider offers. Raised on the model
 * write, so `ready` can never claim a model the provider does not have.
 */
final class ModelNotOfferedException extends \RuntimeException
{
}
```

- [ ] **Step 4: Write the credentials value object**

Create `backend/src/Service/Ai/ProviderCredentials.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Service\Ai\Exception\ProviderUnreachableException;

/**
 * An endpoint and the key that opens it, ready to use. The base URL is the
 * full OpenAI-compatible root the account entered, including any `/v1` — the
 * catalog appends `/models` and nothing else, because the path prefix differs
 * between providers and guessing it would break the ones that do not use it.
 *
 * NOTE ON SSRF: this URL deliberately does NOT pass through UrlGuard, so a
 * local provider works. That is a recorded exception to the standing boundary,
 * decided for #305; the reasoning and the accepted risk are in the design
 * spec. Do not copy this class as a template for any other outbound call.
 */
final readonly class ProviderCredentials
{
    public function __construct(
        public string $baseUrl,
        public string $apiKey,
    ) {
    }

    /**
     * Trims the value and removes trailing slashes, so `…/v1` and `…/v1/`
     * produce one stored form and one request URL.
     */
    public static function normalizeBaseUrl(string $baseUrl): string
    {
        $trimmed = rtrim(trim($baseUrl), '/');
        $parts = parse_url($trimmed);

        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            throw new ProviderUnreachableException('That is not a complete address.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ProviderUnreachableException('Remove the username and password from the address; the API key is sent separately.');
        }

        if (!\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new ProviderUnreachableException('The address must start with http:// or https://.');
        }

        return $trimmed;
    }
}
```

- [ ] **Step 5: Write the interface**

Create `backend/src/Service/Ai/ModelCatalog.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;

interface ModelCatalog
{
    /**
     * @return list<string> the model identifiers the provider offers, sorted, never empty
     *
     * @throws CredentialsRejectedException  the provider refused the key
     * @throws ProviderUnreachableException  the provider did not answer usably
     */
    public function listModels(ProviderCredentials $credentials): array;
}
```

- [ ] **Step 6: Write the implementation**

Create `backend/src/Service/Ai/OpenAiCompatibleCatalog.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads `GET {baseUrl}/models`, the one call every OpenAI-compatible provider
 * answers the same way.
 *
 * The caps are not an SSRF boundary — see ProviderCredentials for why there is
 * none — they keep one hostile or broken endpoint from holding a request open
 * or filling memory.
 */
final readonly class OpenAiCompatibleCatalog implements ModelCatalog
{
    private const float TIMEOUT_SECONDS = 10.0;
    private const int MAXIMUM_RESPONSE_BYTES = 1_048_576;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $userAgent,
    ) {
    }

    public function listModels(ProviderCredentials $credentials): array
    {
        $body = $this->readBody($credentials);
        $decoded = json_decode($body, true);

        if (!\is_array($decoded) || !isset($decoded['data']) || !\is_array($decoded['data'])) {
            throw new ProviderUnreachableException('That address answered, but not with a model list.');
        }

        $models = $this->identifiers($decoded['data']);

        if ([] === $models) {
            throw new ProviderUnreachableException('That provider offers no models.');
        }

        sort($models);

        return array_values($models);
    }

    private function readBody(ProviderCredentials $credentials): string
    {
        try {
            $response = $this->httpClient->request('GET', $credentials->baseUrl . '/models', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $credentials->apiKey,
                    'Accept' => 'application/json',
                    'User-Agent' => $this->userAgent,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);

            $status = $response->getStatusCode();

            if (401 === $status || 403 === $status) {
                throw new CredentialsRejectedException('That provider refused the API key.');
            }

            if ($status >= 300) {
                throw new ProviderUnreachableException(sprintf('That provider answered with status %d.', $status));
            }

            return $this->boundedContent($response->toStream());
        } catch (ExceptionInterface $e) {
            throw new ProviderUnreachableException('That address did not answer.', 0, $e);
        }
    }

    /**
     * Reads at most the cap. Streaming rather than toArray(): a provider that
     * answers with gigabytes must cost one megabyte of memory, not all of it.
     *
     * @param resource $stream
     */
    private function boundedContent($stream): string
    {
        $content = stream_get_contents($stream, self::MAXIMUM_RESPONSE_BYTES);

        return false === $content ? '' : $content;
    }

    /**
     * @param array<mixed> $entries
     *
     * @return list<string>
     */
    private function identifiers(array $entries): array
    {
        $models = [];

        foreach ($entries as $entry) {
            if (\is_array($entry) && isset($entry['id']) && \is_string($entry['id']) && '' !== $entry['id']) {
                $models[] = $entry['id'];
            }
        }

        return $models;
    }
}
```

- [ ] **Step 7: Alias the interface**

In `backend/config/services.yaml`, in the block of explicit interface aliases (below `App\Service\Version\ReleaseVersionReader`), add:

```yaml
    App\Service\Ai\ModelCatalog: '@App\Service\Ai\OpenAiCompatibleCatalog'
```

The `string $userAgent` constructor argument is already satisfied by the `_defaults` bind — that bind exists precisely so every outbound call sends one agent string.

- [ ] **Step 8: Run the test to verify it passes**

```bash
php bin/phpunit tests/Service/Ai/OpenAiCompatibleCatalogTest.php
```

Expected: PASS, 13 tests. If `testItSendsTheKeyAsABearerToken` fails on the header assertion, print `$seen['headers']` — `MockHttpClient` normalises them to `Name: value` strings, and the assertion above expects that form.

- [ ] **Step 9: Run the gate**

```bash
composer check && composer md
```

- [ ] **Step 10: Commit**

```bash
git add backend/src/Service/Ai backend/tests/Service/Ai backend/config/services.yaml
git commit -m "feat(#305): list the models an OpenAI-compatible endpoint offers"
```

---

### Task 4: Verify, persist, and report the state

The service layer that joins Tasks 1–3: it verifies credentials before writing them. It also adds the one mapper that decides what "ready" means, which both this feature's endpoints and `/api/me` read.

**Files:**
- Create: `backend/src/Service/Ai/AiProviderConfigurator.php`
- Create: `backend/src/Service/Ai/Exception/AiNotConfiguredException.php`
- Create: `backend/src/Http/AiSettingsJson.php`
- Modify: `backend/src/Http/MeJson.php`
- Test: `backend/tests/Service/Ai/AiProviderConfiguratorTest.php`
- Test: `backend/tests/Http/AiSettingsJsonTest.php`

**Interfaces:**
- Consumes: `ApiKeyCipher`, `SealedApiKey` (Task 1); `AiProviderSettings`, `AiProviderSettingsRepository` (Task 2); `ModelCatalog`, `ProviderCredentials`, all three `Service\Ai\Exception\*` (Task 3).
- Produces:
  - `AiProviderConfigurator::saveConnection(User $user, string $baseUrl, string $apiKey): array` — returns `list<string>`, the models the provider offers. Throws the Task 3 exceptions. Writes nothing when verification fails.
  - `AiProviderConfigurator::listModels(User $user): array` — `list<string>`, using the stored credentials. Throws `AiNotConfiguredException` when no row exists.
  - `AiProviderConfigurator::chooseModel(User $user, string $model): void` — throws `ModelNotOfferedException` when the provider does not offer it.
  - `AiProviderConfigurator::forget(User $user): void`
  - `AiProviderConfigurator::settingsFor(User $user): ?AiProviderSettings`
  - `AiSettingsJson::state(?AiProviderSettings $settings): array`, `::stateWithModels(?AiProviderSettings $settings, array $models): array`, `::models(array $models): array` — all static.
  - `App\Service\Ai\Exception\AiNotConfiguredException`

**One definition of "ready".** There is no `AiReadiness` service. `AiSettingsJson::state()` decides it, `MeJson` calls that mapper, and the controller in Task 5 calls it too — so the rule exists once, in the class that puts it on the wire, and no second implementation can drift from it.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Ai/AiProviderConfiguratorTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\User;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ModelNotOfferedException;
use App\Service\Ai\ModelCatalog;
use App\Service\Ai\ProviderCredentials;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Against the real repository and entity manager, not mocks: the account id is
 * bound into the sealed key, so a User that was never flushed has no id to
 * seal for and the interesting cases could not run at all.
 *
 * Only the catalog is replaced — nothing here calls a provider.
 */
final class AiProviderConfiguratorTest extends DbTestCase
{
    private function user(string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    /** @param list<string>|\Throwable $models */
    private function configurator(array|\Throwable $models): AiProviderConfigurator
    {
        self::getContainer()->set(ModelCatalog::class, new class($models) implements ModelCatalog {
            /** @param list<string>|\Throwable $models */
            public function __construct(private readonly array|\Throwable $models)
            {
            }

            public function listModels(ProviderCredentials $credentials): array
            {
                if ($this->models instanceof \Throwable) {
                    throw $this->models;
                }

                return $this->models;
            }
        });

        /** @var AiProviderConfigurator $configurator */
        $configurator = self::getContainer()->get(AiProviderConfigurator::class);

        return $configurator;
    }

    public function testSavingAConnectionReturnsTheOfferedModels(): void
    {
        $configurator = $this->configurator(['gpt-4o', 'gpt-4o-mini']);
        $user = $this->user('cfg-save@example.test');

        $models = $configurator->saveConnection($user, 'https://api.example.test/v1/', 'sk-abcdef1234');

        self::assertSame(['gpt-4o', 'gpt-4o-mini'], $models);
        $stored = $configurator->settingsFor($user);
        self::assertNotNull($stored);
        self::assertSame('https://api.example.test/v1', $stored->getBaseUrl());
        self::assertSame('1234', $stored->getApiKeyHint());
    }

    public function testTheStoredRowDoesNotContainThePlainKey(): void
    {
        $configurator = $this->configurator(['gpt-4o']);
        $user = $this->user('cfg-secret@example.test');

        $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');

        $stored = $configurator->settingsFor($user);
        self::assertNotNull($stored);
        $ciphertext = base64_decode($stored->getSealedApiKey()->ciphertext, true);
        self::assertIsString($ciphertext);
        self::assertStringNotContainsString('sk-abcdef1234', $ciphertext);
    }

    public function testARejectedKeyWritesNothing(): void
    {
        $configurator = $this->configurator(new CredentialsRejectedException('refused'));
        $user = $this->user('cfg-refused@example.test');

        try {
            $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');
            self::fail('Expected CredentialsRejectedException.');
        } catch (CredentialsRejectedException) {
            $this->em->clear();
            self::assertNull($configurator->settingsFor($this->users()->findOneByEmail('cfg-refused@example.test')));
        }
    }

    public function testChoosingAnOfferedModelStoresIt(): void
    {
        $configurator = $this->configurator(['gpt-4o', 'gpt-4o-mini']);
        $user = $this->user('cfg-model@example.test');
        $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');

        $configurator->chooseModel($user, 'gpt-4o-mini');

        self::assertSame('gpt-4o-mini', $configurator->settingsFor($user)?->getModel());
    }

    public function testChoosingAModelTheProviderDoesNotOfferIsRefused(): void
    {
        $configurator = $this->configurator(['gpt-4o']);
        $user = $this->user('cfg-badmodel@example.test');
        $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');

        $this->expectException(ModelNotOfferedException::class);
        $configurator->chooseModel($user, 'gpt-4o-mini');
    }

    public function testASecondConnectionSaveDropsTheChosenModel(): void
    {
        $configurator = $this->configurator(['gpt-4o']);
        $user = $this->user('cfg-replace@example.test');
        $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');
        $configurator->chooseModel($user, 'gpt-4o');

        $configurator->saveConnection($user, 'https://other.example.test/v1', 'sk-zyxwvu9876');

        $stored = $configurator->settingsFor($user);
        self::assertNotNull($stored);
        self::assertNull($stored->getModel());
        self::assertSame('9876', $stored->getApiKeyHint());
    }

    public function testForgettingRemovesTheRow(): void
    {
        $configurator = $this->configurator(['gpt-4o']);
        $user = $this->user('cfg-forget@example.test');
        $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');

        $configurator->forget($user);

        // clear() first: after a remove, find() would otherwise serve the stale
        // identity map and the assertion would pass whatever the database did.
        $this->em->clear();
        self::assertNull($configurator->settingsFor($this->users()->findOneByEmail('cfg-forget@example.test')));
    }
}
```

`DbTestCase` (`backend/tests/DbTestCase.php`) boots the kernel and exposes `$this->em`. It has no `users()` helper — add a small private one to this class that fetches `UserRepository` from the container, following `ApiTestCase::users()`.

`self::getContainer()->set(ModelCatalog::class, …)` is the suite's established way to replace a collaborator; see `tests/Controller/Api/SubscriptionControllerTest.php:62`.

- [ ] **Step 2: Run the test to verify it fails**

```bash
php bin/phpunit tests/Service/Ai/AiProviderConfiguratorTest.php
```

Expected: FAIL, `Class "App\Service\Ai\AiProviderConfigurator" not found`.

- [ ] **Step 3: Write the not-configured exception**

Create `backend/src/Service/Ai/Exception/AiNotConfiguredException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/** The account has no provider row: nothing to list, choose from, or forget. */
final class AiNotConfiguredException extends \RuntimeException
{
}
```

- [ ] **Step 4: Write the configurator**

Create `backend/src/Service/Ai/AiProviderConfigurator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Repository\AiProviderSettingsRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\ModelNotOfferedException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The only writer of AiProviderSettings.
 *
 * Every write is preceded by a live call to the provider, so a stored
 * configuration is one that worked. A failed verification throws before
 * anything is persisted, which is why the existing configuration survives a
 * mistyped key.
 */
final readonly class AiProviderConfigurator
{
    private const int HINT_LENGTH = 4;

    public function __construct(
        private ModelCatalog $catalog,
        private ApiKeyCipher $cipher,
        private AiProviderSettingsRepository $repository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function settingsFor(User $user): ?AiProviderSettings
    {
        return $this->repository->findForUser($user);
    }

    /**
     * @return list<string> the models the provider offers
     */
    public function saveConnection(User $user, string $baseUrl, string $apiKey): array
    {
        $credentials = new ProviderCredentials(ProviderCredentials::normalizeBaseUrl($baseUrl), trim($apiKey));
        $models = $this->catalog->listModels($credentials);

        $sealed = $this->cipher->seal($this->identify($user), $credentials->apiKey);
        $hint = substr($credentials->apiKey, -self::HINT_LENGTH);
        $settings = $this->repository->findForUser($user);

        if (null === $settings) {
            $this->entityManager->persist(
                new AiProviderSettings($user, $credentials->baseUrl, $sealed, $hint),
            );
        } else {
            $settings->replaceConnection($credentials->baseUrl, $sealed, $hint, $this->clock->now());
        }

        $this->entityManager->flush();

        return $models;
    }

    /**
     * @return list<string>
     */
    public function listModels(User $user): array
    {
        return $this->catalog->listModels($this->storedCredentials($user));
    }

    public function chooseModel(User $user, string $model): void
    {
        $settings = $this->require($user);

        if (!\in_array($model, $this->catalog->listModels($this->storedCredentials($user)), true)) {
            throw new ModelNotOfferedException(sprintf('That provider does not offer "%s".', $model));
        }

        $settings->chooseModel($model, $this->clock->now());
        $this->entityManager->flush();
    }

    public function forget(User $user): void
    {
        $settings = $this->repository->findForUser($user);

        if (null === $settings) {
            return;
        }

        $this->entityManager->remove($settings);
        $this->entityManager->flush();
    }

    private function storedCredentials(User $user): ProviderCredentials
    {
        $settings = $this->require($user);

        return new ProviderCredentials(
            $settings->getBaseUrl(),
            $this->cipher->open($this->identify($user), $settings->getSealedApiKey()),
        );
    }

    private function require(User $user): AiProviderSettings
    {
        return $this->repository->findForUser($user)
            ?? throw new AiNotConfiguredException('This account has no AI provider configured.');
    }

    /**
     * The account id is bound into the sealed key, so an unsaved User cannot be
     * sealed for: the id it would get on flush is not the one used here.
     */
    private function identify(User $user): int
    {
        return $user->getId() ?? throw new \LogicException('Cannot seal a key for an unsaved account.');
    }
}
```

Note: `AiProviderConfiguratorTest` builds a `User` that has never been flushed, so `getId()` is null. Give the test user an id the way the rest of the suite does — check `backend/tests/Support/UserFactory.php` and `tests/Entity/` for the established approach (usually a reflection helper). If none exists, set the id through reflection in the test's `setUp()`:

```php
$id = new \ReflectionProperty(User::class, 'id');
$id->setValue($this->user, 7);
```

- [ ] **Step 5: Write the response mapper**

Create `backend/src/Http/AiSettingsJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\AiProviderSettings;

/**
 * The client's view of its AI provider, and the ONE definition of "ready".
 *
 * Hand-built for the same reason MeJson is: the entity holds sealed key
 * material, and a serialiser that learned to walk it would put that on the
 * wire. The API key is absent by construction; `apiKeyHint` is the last four
 * characters, which is what lets the settings page say which key is stored.
 *
 * `ready` reports what the last successful save proved — an endpoint, a key
 * and a model the provider accepted together. It is not a live health check:
 * a key revoked since then still reads as ready, and the feature that uses it
 * carries that failure. Polling the provider on every /api/me would be the
 * alternative, and it is not worth a round trip per profile read.
 */
final class AiSettingsJson
{
    /**
     * @return array<string, mixed>
     */
    public static function state(?AiProviderSettings $settings): array
    {
        return [
            'configured' => null !== $settings,
            'baseUrl' => $settings?->getBaseUrl(),
            'apiKeyHint' => $settings?->getApiKeyHint(),
            'model' => $settings?->getModel(),
            'ready' => null !== $settings && $settings->hasModel() && null !== $settings->getVerifiedAt(),
        ];
    }

    /**
     * @param list<string> $models
     *
     * @return array<string, mixed>
     */
    public static function stateWithModels(?AiProviderSettings $settings, array $models): array
    {
        return self::state($settings) + ['models' => $models];
    }

    /**
     * @param list<string> $models
     *
     * @return array<string, mixed>
     */
    public static function models(array $models): array
    {
        return ['models' => $models];
    }
}
```

- [ ] **Step 6: Extend the profile payload**

In `backend/src/Http/MeJson.php`, add the `ai` block after `preferences`, delegating so the rule stays in one class:

```php
            'ai' => [
                'ready' => AiSettingsJson::state($user->getAiProviderSettings())['ready'],
                'model' => $user->getAiProviderSettings()?->getModel(),
            ],
```

`AiSettingsJson` lives in `App\Http`, the same namespace as `MeJson`, so no `use` statement is needed.

- [ ] **Step 7: Test the mapper**

Create `backend/tests/Http/AiSettingsJsonTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Http\AiSettingsJson;
use App\Service\Ai\Crypto\SealedApiKey;
use PHPUnit\Framework\TestCase;

final class AiSettingsJsonTest extends TestCase
{
    private function settings(?string $model): AiProviderSettings
    {
        $settings = new AiProviderSettings(
            new User('mapper@example.test', new \DateTimeImmutable('2026-08-06 09:00:00')),
            'https://api.example.test/v1',
            new SealedApiKey('Y2lwaGVy', 'bm9uY2U=', 'c2FsdA==', 1),
            'abcd',
        );

        if (null !== $model) {
            $settings->chooseModel($model, new \DateTimeImmutable('2026-08-06 10:00:00'));
        }

        return $settings;
    }

    public function testNoRowIsNeitherConfiguredNorReady(): void
    {
        $state = AiSettingsJson::state(null);

        self::assertFalse($state['configured']);
        self::assertFalse($state['ready']);
        self::assertNull($state['baseUrl']);
        self::assertNull($state['apiKeyHint']);
    }

    public function testAProviderWithoutAModelIsConfiguredButNotReady(): void
    {
        $state = AiSettingsJson::state($this->settings(null));

        self::assertTrue($state['configured']);
        self::assertFalse($state['ready']);
        self::assertSame('abcd', $state['apiKeyHint']);
    }

    public function testAProviderWithAModelIsReady(): void
    {
        $state = AiSettingsJson::state($this->settings('gpt-4o'));

        self::assertTrue($state['ready']);
        self::assertSame('gpt-4o', $state['model']);
    }

    public function testTheStateNeverCarriesKeyMaterial(): void
    {
        $encoded = json_encode(AiSettingsJson::state($this->settings('gpt-4o')));

        self::assertIsString($encoded);
        self::assertStringNotContainsString('Y2lwaGVy', $encoded);
        self::assertStringNotContainsString('c2FsdA==', $encoded);
    }

    public function testTheModelListRidesAlongsideTheState(): void
    {
        $state = AiSettingsJson::stateWithModels($this->settings(null), ['gpt-4o', 'gpt-4o-mini']);

        self::assertSame(['gpt-4o', 'gpt-4o-mini'], $state['models']);
        self::assertTrue($state['configured']);
    }
}
```

- [ ] **Step 8: Run the tests to verify they pass**

```bash
php bin/phpunit tests/Service/Ai tests/Http
```

Expected: PASS.

- [ ] **Step 9: Run the gate**

```bash
composer check && composer md
```

- [ ] **Step 10: Commit**

```bash
git add backend/src/Service/Ai backend/src/Http backend/tests
git commit -m "feat(#305): verify an AI provider before storing it, and report readiness"
```

---

### Task 5: The HTTP surface

Five routes, their DTOs, the problem-document mapping, the rate limiter, and functional tests.

**Files:**
- Create: `backend/src/Dto/Ai/SaveConnectionRequest.php`
- Create: `backend/src/Dto/Ai/SaveModelRequest.php`
- Create: `backend/src/Exception/AiProviderApiException.php`
- Create: `backend/src/Exception/AiNotConfiguredApiException.php`
- Create: `backend/src/Controller/Api/AiSettingsController.php`
- Modify: `backend/config/packages/rate_limiter.yaml`
- Test: `backend/tests/Controller/Api/AiSettingsControllerTest.php`

**Interfaces:**
- Consumes: `AiProviderConfigurator` and `AiSettingsJson` (Task 4); the four `Service\Ai\Exception\*` classes (Tasks 3–4).
- Produces: the routes below. No later task consumes PHP from this one; Task 7 consumes the JSON.

| Route | Name | Method |
|---|---|---|
| `/api/me/ai` | `api_me_ai_show` | GET |
| `/api/me/ai/connection` | `api_me_ai_save_connection` | PUT |
| `/api/me/ai/models` | `api_me_ai_models` | GET |
| `/api/me/ai/model` | `api_me_ai_save_model` | PUT |
| `/api/me/ai` | `api_me_ai_forget` | DELETE |

State body: `{"configured":bool,"baseUrl":string|null,"apiKeyHint":string|null,"model":string|null,"ready":bool}`.
Model list body: `{"models":["…"]}`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Controller/Api/AiSettingsControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Service\Ai\ModelCatalog;
use App\Service\Ai\ProviderCredentials;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The provider is never really called: the catalog is replaced in the
 * container, so these cases prove the endpoints' own behaviour without a
 * network. Tokens are minted from the JWT manager so the login throttler's
 * filesystem pool stays out of it.
 */
final class AiSettingsControllerTest extends ApiTestCase
{
    /** @param list<string>|\Throwable $models */
    private function catalogAnswering(array|\Throwable $models): void
    {
        self::getContainer()->set(ModelCatalog::class, new class($models) implements ModelCatalog {
            /** @param list<string>|\Throwable $models */
            public function __construct(private readonly array|\Throwable $models)
            {
            }

            public function listModels(ProviderCredentials $credentials): array
            {
                if ($this->models instanceof \Throwable) {
                    throw $this->models;
                }

                return $this->models;
            }
        });
    }

    private function authenticate(KernelBrowser $client, string $email): void
    {
        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $manager->create($user));
    }

    private function putJson(KernelBrowser $client, string $uri, string $json): void
    {
        $client->request('PUT', $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $json);
    }

    public function testAnUnconfiguredAccountReportsNothingConfigured(): void
    {
        $client = static::createClient();
        $this->factory()->create('ai-empty@example.test');
        $this->authenticate($client, 'ai-empty@example.test');

        $client->request('GET', '/api/me/ai');

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertFalse($payload['configured']);
        self::assertFalse($payload['ready']);
        self::assertNull($payload['baseUrl']);
    }

    public function testTheEndpointRefusesAnAnonymousCaller(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/me/ai');

        self::assertResponseStatusCodeSame(401);
    }

    public function testSavingAConnectionReturnsTheModels(): void
    {
        $client = static::createClient();
        $this->catalogAnswering(['gpt-4o', 'gpt-4o-mini']);
        $this->factory()->create('ai-save@example.test');
        $this->authenticate($client, 'ai-save@example.test');

        $this->putJson(
            $client,
            '/api/me/ai/connection',
            '{"baseUrl":"https://api.example.test/v1","apiKey":"sk-abcdef1234"}',
        );

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertTrue($payload['configured']);
        self::assertFalse($payload['ready']);
        self::assertSame('1234', $payload['apiKeyHint']);
        self::assertSame(['gpt-4o', 'gpt-4o-mini'], $payload['models']);
    }

    public function testTheResponseNeverCarriesTheApiKey(): void
    {
        $client = static::createClient();
        $this->catalogAnswering(['gpt-4o']);
        $this->factory()->create('ai-secret@example.test');
        $this->authenticate($client, 'ai-secret@example.test');

        $this->putJson(
            $client,
            '/api/me/ai/connection',
            '{"baseUrl":"https://api.example.test/v1","apiKey":"sk-abcdef1234"}',
        );

        self::assertStringNotContainsString('sk-abcdef1234', (string) $client->getResponse()->getContent());
    }

    public function testARejectedKeyIsReportedAsUnprocessable(): void
    {
        $client = static::createClient();
        $this->catalogAnswering(new \App\Service\Ai\Exception\CredentialsRejectedException('refused'));
        $this->factory()->create('ai-refused@example.test');
        $this->authenticate($client, 'ai-refused@example.test');

        $this->putJson(
            $client,
            '/api/me/ai/connection',
            '{"baseUrl":"https://api.example.test/v1","apiKey":"sk-wrong"}',
        );

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testChoosingAModelMakesTheAccountReady(): void
    {
        $client = static::createClient();
        $this->catalogAnswering(['gpt-4o', 'gpt-4o-mini']);
        $this->factory()->create('ai-ready@example.test');
        $this->authenticate($client, 'ai-ready@example.test');

        $this->putJson(
            $client,
            '/api/me/ai/connection',
            '{"baseUrl":"https://api.example.test/v1","apiKey":"sk-abcdef1234"}',
        );
        $this->putJson($client, '/api/me/ai/model', '{"model":"gpt-4o-mini"}');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload($client)['ready']);

        $client->request('GET', '/api/me');
        self::assertTrue($this->payload($client)['ai']['ready']);
        self::assertSame('gpt-4o-mini', $this->payload($client)['ai']['model']);
    }

    public function testAModelTheProviderDoesNotOfferIsRefused(): void
    {
        $client = static::createClient();
        $this->catalogAnswering(['gpt-4o']);
        $this->factory()->create('ai-badmodel@example.test');
        $this->authenticate($client, 'ai-badmodel@example.test');

        $this->putJson(
            $client,
            '/api/me/ai/connection',
            '{"baseUrl":"https://api.example.test/v1","apiKey":"sk-abcdef1234"}',
        );
        $this->putJson($client, '/api/me/ai/model', '{"model":"gpt-9"}');

        self::assertResponseStatusCodeSame(422);
    }

    public function testTheModelListCanBeReReadWithTheStoredKey(): void
    {
        $client = static::createClient();
        $this->catalogAnswering(['gpt-4o', 'gpt-4o-mini']);
        $this->factory()->create('ai-relist@example.test');
        $this->authenticate($client, 'ai-relist@example.test');

        $this->putJson(
            $client,
            '/api/me/ai/connection',
            '{"baseUrl":"https://api.example.test/v1","apiKey":"sk-abcdef1234"}',
        );
        $client->request('GET', '/api/me/ai/models');

        self::assertResponseIsSuccessful();
        self::assertSame(['gpt-4o', 'gpt-4o-mini'], $this->payload($client)['models']);
    }

    public function testListingModelsWithoutAConfigurationIsNotFound(): void
    {
        $client = static::createClient();
        $this->factory()->create('ai-nolist@example.test');
        $this->authenticate($client, 'ai-nolist@example.test');

        $client->request('GET', '/api/me/ai/models');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeletingTheConfigurationClearsIt(): void
    {
        $client = static::createClient();
        $this->catalogAnswering(['gpt-4o']);
        $this->factory()->create('ai-forget@example.test');
        $this->authenticate($client, 'ai-forget@example.test');

        $this->putJson(
            $client,
            '/api/me/ai/connection',
            '{"baseUrl":"https://api.example.test/v1","apiKey":"sk-abcdef1234"}',
        );
        $client->request('DELETE', '/api/me/ai');

        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/me/ai');
        self::assertFalse($this->payload($client)['configured']);
    }

    public function testOneAccountCannotSeeAnothersConfiguration(): void
    {
        $client = static::createClient();
        $this->catalogAnswering(['gpt-4o']);
        $this->factory()->create('ai-owner@example.test');
        $this->factory()->create('ai-stranger@example.test');

        $this->authenticate($client, 'ai-owner@example.test');
        $this->putJson(
            $client,
            '/api/me/ai/connection',
            '{"baseUrl":"https://api.example.test/v1","apiKey":"sk-abcdef1234"}',
        );

        $this->authenticate($client, 'ai-stranger@example.test');
        $client->request('GET', '/api/me/ai');

        self::assertFalse($this->payload($client)['configured']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php bin/phpunit tests/Controller/Api/AiSettingsControllerTest.php
```

Expected: FAIL — 404 on every route.

- [ ] **Step 3: Write the DTOs**

Create `backend/src/Dto/Ai/SaveConnectionRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The address is validated for shape here and normalised in
 * ProviderCredentials; the two are not redundant. This rejects an empty body
 * with a 422 before any outbound call, the other decides the stored form.
 */
final readonly class SaveConnectionRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 512)]
        public string $baseUrl,
        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 512)]
        public string $apiKey,
    ) {
    }
}
```

Create `backend/src/Dto/Ai/SaveModelRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SaveModelRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $model,
    ) {
    }
}
```

- [ ] **Step 4: Write the client-facing exceptions**

Create `backend/src/Exception/AiProviderApiException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * The client-facing form of the Service\Ai refusals. One type for all of them,
 * because the client's move is the same in every case — show the message and
 * let the account correct the form — while `detail` carries which of "check
 * the address", "check the key" or "pick another model" applies.
 */
final class AiProviderApiException extends ApiException
{
    public function __construct(string $detail, ?\Throwable $previous = null)
    {
        parent::__construct(
            'ai_provider_rejected',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'The AI provider could not be used',
            $detail,
            [],
            $previous,
        );
    }
}
```

Create `backend/src/Exception/AiNotConfiguredApiException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class AiNotConfiguredApiException extends ApiException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'ai_not_configured',
            Response::HTTP_NOT_FOUND,
            'No AI provider is configured',
            'Save an endpoint and an API key first.',
            [],
            $previous,
        );
    }
}
```

- [ ] **Step 5: Add the rate limiter**

In `backend/config/packages/rate_limiter.yaml`, append inside `framework.rate_limiter`:

```yaml
        # Both AI writes call the account's own provider before they store
        # anything, so an uncapped pair is an outbound request generator keyed
        # to whatever address the account last entered. The budget is per user
        # id, not per IP: the endpoints are authenticated, so the trustworthy
        # key is available.
        #
        # 30 in 15 minutes is generous for the flow it serves -- save, look at
        # the list, pick, maybe correct a typo -- and low enough that a script
        # cannot make the server a useful probe.
        ai_provider:
            policy: 'sliding_window'
            limit: 30
            interval: '15 minutes'
            cache_pool: cache.rate_limiter
```

- [ ] **Step 6: Write the controller**

Create `backend/src/Controller/Api/AiSettingsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Ai\SaveConnectionRequest;
use App\Dto\Ai\SaveModelRequest;
use App\Entity\User;
use App\Exception\AiNotConfiguredApiException;
use App\Exception\AiProviderApiException;
use App\Http\AiSettingsJson;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ModelNotOfferedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The account's AI provider. Every write verifies against the provider first,
 * so a stored configuration is one that worked — see AiProviderConfigurator.
 */
#[Route('/api/me/ai')]
final readonly class AiSettingsController
{
    public function __construct(
        private AiProviderConfigurator $configurator,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $aiProviderLimiter,
    ) {
    }

    #[Route('', name: 'api_me_ai_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(AiSettingsJson::state($this->configurator->settingsFor($user)));
    }

    #[Route('/connection', name: 'api_me_ai_save_connection', methods: ['PUT'])]
    public function saveConnection(
        #[CurrentUser] User $user,
        #[MapRequestPayload] SaveConnectionRequest $request,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);

        try {
            $models = $this->configurator->saveConnection($user, $request->baseUrl, $request->apiKey);
        } catch (ProviderUnreachableException|CredentialsRejectedException $e) {
            throw new AiProviderApiException($e->getMessage(), $e);
        }

        return new JsonResponse(
            AiSettingsJson::stateWithModels($this->configurator->settingsFor($user), $models),
        );
    }

    #[Route('/models', name: 'api_me_ai_models', methods: ['GET'])]
    public function models(#[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);

        try {
            $models = $this->configurator->listModels($user);
        } catch (AiNotConfiguredException $e) {
            throw new AiNotConfiguredApiException($e);
        } catch (ProviderUnreachableException|CredentialsRejectedException $e) {
            throw new AiProviderApiException($e->getMessage(), $e);
        }

        return new JsonResponse(AiSettingsJson::models($models));
    }

    #[Route('/model', name: 'api_me_ai_save_model', methods: ['PUT'])]
    public function saveModel(
        #[CurrentUser] User $user,
        #[MapRequestPayload] SaveModelRequest $request,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);

        try {
            $this->configurator->chooseModel($user, $request->model);
        } catch (AiNotConfiguredException $e) {
            throw new AiNotConfiguredApiException($e);
        } catch (ModelNotOfferedException|ProviderUnreachableException|CredentialsRejectedException $e) {
            throw new AiProviderApiException($e->getMessage(), $e);
        }

        return new JsonResponse(AiSettingsJson::state($this->configurator->settingsFor($user)));
    }

    #[Route('', name: 'api_me_ai_forget', methods: ['DELETE'])]
    public function forget(#[CurrentUser] User $user): JsonResponse
    {
        $this->configurator->forget($user);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

```bash
php bin/phpunit tests/Controller/Api/AiSettingsControllerTest.php
```

Expected: PASS, 11 tests.

If `self::getContainer()->set(ModelCatalog::class, …)` fails because the service is not public in the test container, check how `catalog.rot_check.http_client` is swapped in `backend/tests/Command/` and follow that approach.

- [ ] **Step 8: Run the whole backend gate**

```bash
php bin/phpunit && composer check && composer md
```

Then read the log for anything the suite swallowed:

```bash
tail -n 60 backend/var/log/dev.log
```

- [ ] **Step 9: Commit**

```bash
git add backend/src/Controller backend/src/Dto/Ai backend/src/Exception backend/config/packages/rate_limiter.yaml backend/tests/Controller
git commit -m "feat(#305): expose the AI provider configuration over the API"
```

---

### Task 6: The searchable select

A shared component. It has no AI knowledge — it filters a list of options and emits the chosen value.

**Files:**
- Create: `frontend/src/app/shared/searchable-select/searchable-select.component.ts`
- Create: `frontend/src/app/shared/searchable-select/searchable-select.component.html`
- Create: `frontend/src/app/shared/searchable-select/searchable-select.component.scss`
- Test: `frontend/src/app/shared/searchable-select/searchable-select.component.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `DismissOnOutsideDirective` from `src/app/shared/dismiss-on-outside.directive.ts`, `IconComponent` from `src/app/shared/icon/icon.component.ts`.
- Produces: `SearchableSelectComponent`, selector `app-searchable-select`.
  - `options = input.required<readonly SelectOption[]>()` where `SelectOption = { value: string; label: string }`, exported from the same file.
  - `value = model<string | null>(null)` — two-way bound.
  - `placeholder = input<string>('')`, `disabled = input(false, { transform: booleanAttribute })`, `inputId = input.required<string>()`.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/app/shared/searchable-select/searchable-select.component.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { SearchableSelectComponent } from './searchable-select.component';

describe('SearchableSelectComponent', () => {
  function mount(options = [
    { value: 'gpt-4o', label: 'gpt-4o' },
    { value: 'gpt-4o-mini', label: 'gpt-4o-mini' },
    { value: 'claude-sonnet', label: 'claude-sonnet' },
  ]) {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });
    const fixture = TestBed.createComponent(SearchableSelectComponent);
    fixture.componentRef.setInput('options', options);
    fixture.componentRef.setInput('inputId', 'model-select');
    fixture.detectChanges();
    return fixture;
  }

  function open(fixture: ReturnType<typeof mount>) {
    const trigger = fixture.nativeElement.querySelector('.trigger') as HTMLButtonElement;
    trigger.click();
    fixture.detectChanges();
  }

  function type(fixture: ReturnType<typeof mount>, text: string) {
    const search = fixture.nativeElement.querySelector('.search') as HTMLInputElement;
    search.value = text;
    search.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  function optionLabels(fixture: ReturnType<typeof mount>): string[] {
    return Array.from(fixture.nativeElement.querySelectorAll('[role="option"]')).map((el) =>
      (el as HTMLElement).textContent!.trim(),
    );
  }

  it('shows no list until it is opened', () => {
    const fixture = mount();
    expect(fixture.nativeElement.querySelector('[role="listbox"]')).toBeNull();
  });

  it('lists every option when opened', () => {
    const fixture = mount();
    open(fixture);
    expect(optionLabels(fixture)).toEqual(['gpt-4o', 'gpt-4o-mini', 'claude-sonnet']);
  });

  it('filters the list on the typed text, ignoring case', () => {
    const fixture = mount();
    open(fixture);
    type(fixture, 'MINI');
    expect(optionLabels(fixture)).toEqual(['gpt-4o-mini']);
  });

  it('reports when the filter matches nothing', () => {
    const fixture = mount();
    open(fixture);
    type(fixture, 'llama');
    expect(optionLabels(fixture)).toEqual([]);
    expect(fixture.nativeElement.querySelector('.empty')).not.toBeNull();
  });

  it('emits the value of a clicked option and closes', () => {
    const fixture = mount();
    open(fixture);
    (fixture.nativeElement.querySelectorAll('[role="option"]')[1] as HTMLElement).click();
    fixture.detectChanges();

    expect(fixture.componentInstance.value()).toBe('gpt-4o-mini');
    expect(fixture.nativeElement.querySelector('[role="listbox"]')).toBeNull();
  });

  it('moves the active option with the arrow keys and takes it on Enter', () => {
    const fixture = mount();
    open(fixture);
    const search = fixture.nativeElement.querySelector('.search') as HTMLInputElement;

    search.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown' }));
    fixture.detectChanges();
    search.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    fixture.detectChanges();

    expect(fixture.componentInstance.value()).toBe('gpt-4o-mini');
  });

  it('keeps the active option inside the filtered list', () => {
    const fixture = mount();
    open(fixture);
    const search = fixture.nativeElement.querySelector('.search') as HTMLInputElement;

    search.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown' }));
    search.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown' }));
    fixture.detectChanges();
    type(fixture, 'claude');
    search.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    fixture.detectChanges();

    expect(fixture.componentInstance.value()).toBe('claude-sonnet');
  });

  it('closes on Escape without choosing', () => {
    const fixture = mount();
    open(fixture);
    const search = fixture.nativeElement.querySelector('.search') as HTMLInputElement;

    search.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('[role="listbox"]')).toBeNull();
    expect(fixture.componentInstance.value()).toBeNull();
  });

  it('does not open while disabled', () => {
    const fixture = mount();
    fixture.componentRef.setInput('disabled', true);
    fixture.detectChanges();
    open(fixture);
    expect(fixture.nativeElement.querySelector('[role="listbox"]')).toBeNull();
  });
});
```

Check the real path of `provideTranslocoTesting` first — `frontend/src/app/settings/preferences-section.component.spec.ts` imports it as `../../testing/transloco-testing`, so from `shared/searchable-select/` it is one level deeper. Fix the import to match.

- [ ] **Step 2: Run the test to verify it fails**

From `frontend/`:

```bash
npx jest src/app/shared/searchable-select
```

Expected: FAIL, cannot resolve `./searchable-select.component`.

- [ ] **Step 3: Write the component class**

Create `frontend/src/app/shared/searchable-select/searchable-select.component.ts`:

```ts
// src/app/shared/searchable-select/searchable-select.component.ts
import {
  booleanAttribute,
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
  model,
  signal,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { DismissOnOutsideDirective } from '../dismiss-on-outside.directive';
import { IconComponent } from '../icon/icon.component';

export interface SelectOption {
  readonly value: string;
  readonly label: string;
}

/**
 * A select for lists too long to scan: a filter box above the options.
 *
 * Not a native `<select>` — a provider can offer hundreds of models, and a
 * native list has no filter. Not a `ControlValueAccessor` either, following
 * `app-icon-picker`: the consumers here bind a signal, and the forms API would
 * be machinery nobody uses.
 *
 * The active index is kept inside the FILTERED list, not the full one, so
 * narrowing the filter can never leave the highlight pointing at an option the
 * user can no longer see.
 */
@Component({
  selector: 'app-searchable-select',
  imports: [DismissOnOutsideDirective, IconComponent, TranslocoPipe],
  templateUrl: './searchable-select.component.html',
  styleUrl: './searchable-select.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SearchableSelectComponent {
  readonly options = input.required<readonly SelectOption[]>();
  readonly value = model<string | null>(null);
  readonly placeholder = input('');
  readonly inputId = input.required<string>();
  readonly disabled = input(false, { transform: booleanAttribute });

  readonly open = signal(false);
  readonly filter = signal('');
  readonly activeIndex = signal(0);

  readonly matches = computed(() => {
    const needle = this.filter().trim().toLowerCase();
    const all = this.options();
    if (!needle) return all;
    return all.filter((option) => option.label.toLowerCase().includes(needle));
  });

  readonly selectedLabel = computed(
    () => this.options().find((option) => option.value === this.value())?.label ?? '',
  );

  toggle(): void {
    if (this.disabled()) return;
    this.open.update((wasOpen) => !wasOpen);
    this.filter.set('');
    this.activeIndex.set(0);
  }

  close(): void {
    this.open.set(false);
  }

  applyFilter(text: string): void {
    this.filter.set(text);
    this.activeIndex.set(0);
  }

  choose(option: SelectOption): void {
    this.value.set(option.value);
    this.close();
  }

  onKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') return this.move(1, event);
    if (event.key === 'ArrowUp') return this.move(-1, event);
    if (event.key === 'Enter') return this.takeActive(event);
    if (event.key === 'Escape') this.close();
  }

  private move(step: number, event: KeyboardEvent): void {
    event.preventDefault();
    const count = this.matches().length;
    if (count === 0) return;
    this.activeIndex.update((index) => (index + step + count) % count);
  }

  private takeActive(event: KeyboardEvent): void {
    event.preventDefault();
    const option = this.matches()[this.activeIndex()];
    if (option) this.choose(option);
  }
}
```

- [ ] **Step 4: Write the template**

Create `frontend/src/app/shared/searchable-select/searchable-select.component.html`:

```html
<div class="wrap" [appDismissOnOutside]="open()" (dismiss)="close()">
  <button
    type="button"
    class="trigger"
    [id]="inputId()"
    [class.open]="open()"
    [disabled]="disabled()"
    aria-haspopup="listbox"
    [attr.aria-expanded]="open()"
    (click)="toggle()"
  >
    <span class="current" [class.empty-value]="!selectedLabel()">
      {{ selectedLabel() || placeholder() }}
    </span>
    <app-icon class="caret" name="expand_more" size="sm" />
  </button>

  @if (open()) {
    <div class="panel">
      <input
        class="search"
        type="text"
        autocomplete="off"
        [attr.aria-label]="'searchableSelect.filter' | transloco"
        [placeholder]="'searchableSelect.filter' | transloco"
        [value]="filter()"
        (input)="applyFilter($any($event.target).value)"
        (keydown)="onKeydown($event)"
      />

      @if (matches().length) {
        <ul class="list" role="listbox">
          @for (option of matches(); track option.value; let i = $index) {
            <li
              class="option"
              role="option"
              [class.active]="i === activeIndex()"
              [attr.aria-selected]="option.value === value()"
              (click)="choose(option)"
            >
              {{ option.label }}
            </li>
          }
        </ul>
      } @else {
        <p class="empty">{{ 'searchableSelect.noMatch' | transloco }}</p>
      }
    </div>
  }
</div>
```

- [ ] **Step 5: Write the styles**

Create `frontend/src/app/shared/searchable-select/searchable-select.component.scss`. Read `frontend/src/app/shared/icon-picker/icon-picker.component.scss` first and reuse its trigger and popover treatment — tokens only, no hex, no raw `px`:

```scss
.wrap {
  position: relative;
  width: 100%;
}

.trigger {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  width: 100%;
  padding: var(--space-2) var(--space-3);
  border: var(--border-width) solid var(--border);
  border-radius: var(--radius-md);
  background: var(--surface);
  color: var(--text);
  cursor: pointer;

  &:disabled {
    cursor: not-allowed;
    opacity: var(--opacity-disabled);
  }
}

.current.empty-value {
  color: var(--text-muted);
}

.panel {
  position: absolute;
  z-index: var(--z-overlay);
  inset-inline: 0;
  margin-block-start: var(--space-1);
  border: var(--border-width) solid var(--border);
  border-radius: var(--radius-md);
  background: var(--surface-raised);
  box-shadow: var(--shadow-md);
}

.search {
  width: 100%;
  padding: var(--space-2) var(--space-3);
  border: 0;
  border-block-end: var(--border-width) solid var(--border);
  background: transparent;
  color: var(--text);
}

.list {
  max-block-size: var(--overlay-max-height);
  margin: 0;
  padding: 0;
  overflow-y: auto;
  list-style: none;
}

.option {
  padding: var(--space-2) var(--space-3);
  cursor: pointer;

  &.active,
  &:hover {
    background: var(--surface-hover);
  }
}

.empty {
  margin: 0;
  padding: var(--space-3);
  color: var(--text-muted);
}
```

**Every custom property above must exist.** Check `frontend/src/app/theme/` and `docs/design-language.md`, and replace any name that is not defined there with the real token. `--overlay-max-height` and `--opacity-disabled` in particular are guesses; if the theme has no equivalent, use the value pattern the icon-picker popover already uses.

- [ ] **Step 6: Add the translations**

In `frontend/public/i18n/en.json`, add a top-level block beside `iconPicker`:

```json
  "searchableSelect": {
    "filter": "Type to filter",
    "noMatch": "Nothing matches"
  },
```

In `frontend/public/i18n/de.json`, at the same place:

```json
  "searchableSelect": {
    "filter": "Zum Filtern tippen",
    "noMatch": "Keine Treffer"
  },
```

- [ ] **Step 7: Run the test to verify it passes**

```bash
npx jest src/app/shared/searchable-select
```

Expected: PASS, 9 tests.

- [ ] **Step 8: Run the frontend gate**

```bash
npm run check
```

Expected: clean. Prettier's 100-column rule bites the long test chains — run `npx prettier --write src/app/shared/searchable-select` if it complains.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/app/shared/searchable-select frontend/public/i18n
git commit -m "feat(#305): add a searchable select to the shared catalog"
```

---

### Task 7: The settings section and the availability signal

**Files:**
- Create: `frontend/src/app/settings/ai-settings.service.ts`
- Create: `frontend/src/app/core/ai-availability.service.ts`
- Create: `frontend/src/app/settings/ai-section.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/core/auth.service.ts`
- Modify: `frontend/src/app/settings/settings-sections.ts`
- Modify: `frontend/src/app/settings/settings.routes.ts`
- Modify: `frontend/public/i18n/en.json`, `de.json`
- Test: `frontend/src/app/core/ai-availability.service.spec.ts`

**Interfaces:**
- Consumes: the endpoints from Task 5; `SearchableSelectComponent` and `SelectOption` from Task 6; `SettingsCardComponent`, `FieldComponent`, `ButtonComponent`, `ErrorBannerComponent`, `SpinnerComponent` from `src/app/shared/`.
- Produces:
  - `AiAvailabilityService` — `ready: Signal<boolean>`, `model: Signal<string | null>`, `adopt(user: CurrentUser): void`, `apply(state: AiState): void`, `reset(): void`.
  - `AiSettingsService` — `state`, `models`, `busy`, `error` signals; `load()`, `saveConnection(baseUrl, apiKey)`, `refreshModels()`, `saveModel(model)`, `forget()`.
  - `AiState = { configured: boolean; baseUrl: string | null; apiKeyHint: string | null; model: string | null; ready: boolean }`, exported from `ai-availability.service.ts` so both consumers share one type.

- [ ] **Step 1: Write the failing test for the availability signal**

Create `frontend/src/app/core/ai-availability.service.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { AiAvailabilityService, AiState } from './ai-availability.service';
import { CurrentUser } from './auth.service';

describe('AiAvailabilityService', () => {
  function service(): AiAvailabilityService {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({});
    return TestBed.inject(AiAvailabilityService);
  }

  const user = (ready: boolean, model: string | null): CurrentUser =>
    ({ ai: { ready, model } }) as CurrentUser;

  const state = (over: Partial<AiState>): AiState => ({
    configured: true,
    baseUrl: 'https://api.example.test/v1',
    apiKeyHint: '1234',
    model: null,
    ready: false,
    ...over,
  });

  it('is not ready before an account is adopted', () => {
    expect(service().ready()).toBe(false);
  });

  it('adopts the account profile', () => {
    const ai = service();
    ai.adopt(user(true, 'gpt-4o'));
    expect(ai.ready()).toBe(true);
    expect(ai.model()).toBe('gpt-4o');
  });

  it('applies a saved settings state without another profile fetch', () => {
    const ai = service();
    ai.apply(state({ model: 'gpt-4o-mini', ready: true }));
    expect(ai.ready()).toBe(true);
    expect(ai.model()).toBe('gpt-4o-mini');
  });

  it('drops the signed-out account state', () => {
    const ai = service();
    ai.adopt(user(true, 'gpt-4o'));
    ai.reset();
    expect(ai.ready()).toBe(false);
    expect(ai.model()).toBeNull();
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
npx jest src/app/core/ai-availability
```

Expected: FAIL, cannot resolve `./ai-availability.service`.

- [ ] **Step 3: Write the availability service**

Create `frontend/src/app/core/ai-availability.service.ts`:

```ts
// src/app/core/ai-availability.service.ts
import { Injectable, signal } from '@angular/core';
import { CurrentUser } from './auth.service';

/** The account's AI provider, as the API reports it. */
export interface AiState {
  readonly configured: boolean;
  readonly baseUrl: string | null;
  readonly apiKeyHint: string | null;
  readonly model: string | null;
  readonly ready: boolean;
}

/**
 * Whether AI features may run for the signed-in account.
 *
 * One signal for the whole app, seeded from `/api/me` and updated by the
 * settings section, so a later feature reads it without a request of its own.
 * `false` is the safe default while the profile is in flight: an AI feature
 * that stays hidden a moment longer is right, one that appears and then fails
 * is not.
 */
@Injectable({ providedIn: 'root' })
export class AiAvailabilityService {
  private readonly readySignal = signal(false);
  private readonly modelSignal = signal<string | null>(null);

  readonly ready = this.readySignal.asReadonly();
  readonly model = this.modelSignal.asReadonly();

  /** Take the account's values, right after `AuthService.loadMe()`. */
  adopt(user: CurrentUser): void {
    this.readySignal.set(user.ai.ready);
    this.modelSignal.set(user.ai.model);
  }

  /** Take a settings write's own answer, so the section needs no profile refetch. */
  apply(state: AiState): void {
    this.readySignal.set(state.ready);
    this.modelSignal.set(state.model);
  }

  /**
   * Per-account, like PreferencesService: leaving it set would let the next
   * signed-in account see AI offered until its own profile arrives, or forever
   * if that request fails.
   */
  reset(): void {
    this.readySignal.set(false);
    this.modelSignal.set(null);
  }
}
```

- [ ] **Step 4: Wire it into the session**

In `frontend/src/app/core/auth.service.ts`:

Add to the `CurrentUser` interface, after `preferences`:

```ts
  ai: { ready: boolean; model: string | null };
```

Inject the service beside `preferences`:

```ts
  private readonly ai = inject(AiAvailabilityService);
```

In `loadMe()`'s `tap`, after `this.preferences.adopt(u);`:

```ts
        this.ai.adopt(u);
```

In `logout()`, after `this.preferences.reset();`:

```ts
    this.ai.reset();
```

- [ ] **Step 5: Run the availability test to verify it passes**

```bash
npx jest src/app/core/ai-availability
```

Expected: PASS, 4 tests.

- [ ] **Step 6: Write the settings service**

Create `frontend/src/app/settings/ai-settings.service.ts`:

```ts
// src/app/settings/ai-settings.service.ts
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { catchError, of, tap } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { AiAvailabilityService, AiState } from '../core/ai-availability.service';
import { problemDetail } from '../core/problem';

const EMPTY: AiState = {
  configured: false,
  baseUrl: null,
  apiKeyHint: null,
  model: null,
  ready: false,
};

/**
 * The AI section's own state and writes.
 *
 * Every write answers with the new state, so nothing here re-reads the profile
 * to find out what happened, and `AiAvailabilityService` is fed from the same
 * answer.
 */
@Injectable()
export class AiSettingsService {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly availability = inject(AiAvailabilityService);

  readonly state = signal<AiState>(EMPTY);
  readonly models = signal<readonly string[]>([]);
  readonly busy = signal(false);
  readonly error = signal<string | null>(null);

  load(): void {
    this.run(this.http.get<AiState>(`${this.base}/api/me/ai`), (state) => this.take(state));
  }

  saveConnection(baseUrl: string, apiKey: string): void {
    this.run(
      this.http.put<AiState & { models: string[] }>(`${this.base}/api/me/ai/connection`, {
        baseUrl,
        apiKey,
      }),
      (answer) => {
        this.take(answer);
        this.models.set(answer.models);
      },
    );
  }

  refreshModels(): void {
    this.run(this.http.get<{ models: string[] }>(`${this.base}/api/me/ai/models`), (answer) =>
      this.models.set(answer.models),
    );
  }

  saveModel(model: string): void {
    this.run(this.http.put<AiState>(`${this.base}/api/me/ai/model`, { model }), (state) =>
      this.take(state),
    );
  }

  forget(): void {
    this.run(this.http.delete<void>(`${this.base}/api/me/ai`), () => {
      this.take(EMPTY);
      this.models.set([]);
    });
  }

  private take(state: AiState): void {
    this.state.set(state);
    this.availability.apply(state);
  }

  private run<T>(request: import('rxjs').Observable<T>, onSuccess: (value: T) => void): void {
    this.busy.set(true);
    this.error.set(null);

    request
      .pipe(
        tap((value) => onSuccess(value)),
        catchError((failure: HttpErrorResponse) => {
          this.error.set(problemDetail(failure));
          return of(null);
        }),
      )
      .subscribe(() => this.busy.set(false));
  }
}
```

Check `frontend/src/app/core/problem.ts` for the real helper name and signature before writing `problemDetail(failure)`; use whatever that file exports. If it exposes no single-message helper, add the extraction inline here rather than changing `problem.ts`.

- [ ] **Step 7: Write the section component**

Create `frontend/src/app/settings/ai-section.component.ts`:

```ts
// src/app/settings/ai-section.component.ts
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { TranslocoPipe } from '@jsverse/transloco';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { FieldComponent } from '../shared/field/field.component';
import { SearchableSelectComponent, SelectOption } from '../shared/searchable-select/searchable-select.component';
import { SettingsCardComponent } from '../shared/settings-card/settings-card.component';
import { AiSettingsService } from './ai-settings.service';

/**
 * The AI provider form. Two writes, in the order the flow needs them: the
 * connection first, because the model list cannot be fetched without a key,
 * then the model.
 */
@Component({
  selector: 'app-ai-section',
  imports: [
    ErrorBannerComponent,
    FieldComponent,
    FormsModule,
    SearchableSelectComponent,
    SettingsCardComponent,
    TranslocoPipe,
  ],
  providers: [AiSettingsService],
  templateUrl: './ai-section.component.html',
  styleUrl: './ai-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AiSectionComponent {
  readonly ai = inject(AiSettingsService);

  readonly baseUrl = signal('');
  readonly apiKey = signal('');
  readonly chosenModel = signal<string | null>(null);

  readonly options = computed<SelectOption[]>(() =>
    this.ai.models().map((model) => ({ value: model, label: model })),
  );

  readonly canSaveConnection = computed(
    () => this.baseUrl().trim().length > 0 && this.apiKey().trim().length > 0 && !this.ai.busy(),
  );

  constructor() {
    this.ai.load();
  }

  saveConnection(): void {
    this.ai.saveConnection(this.baseUrl().trim(), this.apiKey().trim());
    this.apiKey.set('');
  }

  saveModel(): void {
    const model = this.chosenModel();
    if (model) this.ai.saveModel(model);
  }
}
```

Create `frontend/src/app/settings/ai-section.component.html`:

```html
<app-settings-card [heading]="'settings.ai.title' | transloco">
  <p class="lead">{{ 'settings.ai.lead' | transloco }}</p>

  @if (ai.state().configured) {
    <p class="stored">
      {{ 'settings.ai.storedKey' | transloco: { hint: ai.state().apiKeyHint } }}
    </p>
  }

  <app-field [label]="'settings.ai.baseUrl' | transloco" [hint]="'settings.ai.baseUrlHint' | transloco">
    <input
      type="url"
      autocomplete="off"
      [placeholder]="ai.state().baseUrl ?? 'https://api.openai.com/v1'"
      [ngModel]="baseUrl()"
      (ngModelChange)="baseUrl.set($event)"
    />
  </app-field>

  <app-field [label]="'settings.ai.apiKey' | transloco">
    <input
      type="password"
      autocomplete="off"
      [ngModel]="apiKey()"
      (ngModelChange)="apiKey.set($event)"
    />
  </app-field>

  <button type="button" class="btn primary" [disabled]="!canSaveConnection()" (click)="saveConnection()">
    {{ 'settings.ai.connect' | transloco }}
  </button>

  @if (ai.models().length) {
    <app-field [label]="'settings.ai.model' | transloco">
      <app-searchable-select
        inputId="ai-model-select"
        [options]="options()"
        [placeholder]="'settings.ai.modelPlaceholder' | transloco"
        [value]="chosenModel()"
        (valueChange)="chosenModel.set($event)"
      />
    </app-field>

    <button type="button" class="btn primary" [disabled]="!chosenModel() || ai.busy()" (click)="saveModel()">
      {{ 'settings.ai.saveModel' | transloco }}
    </button>
  }

  @if (ai.state().configured) {
    <button type="button" class="btn danger" [disabled]="ai.busy()" (click)="ai.forget()">
      {{ 'settings.ai.remove' | transloco }}
    </button>
  }

  @if (ai.state().ready) {
    <p class="ready">{{ 'settings.ai.ready' | transloco: { model: ai.state().model } }}</p>
  }

  @if (ai.error(); as message) {
    <app-error-banner [message]="message" />
  }
</app-settings-card>
```

Check `frontend/src/app/shared/button/` for the project's real button component and use it instead of the `class="btn"` markup above if one exists — three raw buttons where a shared component exists is exactly the duplication the design language forbids. Follow `opml-section.component.html`, which has the same shape of form plus actions.

Create `frontend/src/app/settings/ai-section.component.scss`:

```scss
:host {
  display: block;
}

.lead,
.stored,
.ready {
  margin-block: 0 var(--space-3);
  color: var(--text-muted);
}

.ready {
  color: var(--success);
}

app-field {
  display: block;
  margin-block-end: var(--space-3);
}

app-searchable-select {
  display: block;
}
```

Every custom property must exist — check `frontend/src/app/theme/` and `docs/design-language.md`, and replace `--success` with the real token if that name is not defined. Read `opml-section.component.scss` first and follow its spacing rhythm rather than inventing one.

- [ ] **Step 8: Write the section test**

Create `frontend/src/app/settings/ai-section.component.spec.ts`:

```ts
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideHttpClient } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../core/api';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { AiSectionComponent } from './ai-section.component';

describe('AiSectionComponent', () => {
  let http: HttpTestingController;

  function mount() {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: '' },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(AiSectionComponent);
    fixture.detectChanges();
    http.expectOne('/api/me/ai').flush({
      configured: false,
      baseUrl: null,
      apiKeyHint: null,
      model: null,
      ready: false,
    });
    fixture.detectChanges();
    return fixture;
  }

  afterEach(() => http.verify());

  it('offers no model select before a connection is saved', () => {
    const fixture = mount();
    expect(fixture.nativeElement.querySelector('app-searchable-select')).toBeNull();
  });

  it('shows the model select once the connection save returns models', () => {
    const fixture = mount();
    fixture.componentInstance.baseUrl.set('https://api.example.test/v1');
    fixture.componentInstance.apiKey.set('sk-abcdef1234');
    fixture.componentInstance.saveConnection();

    http.expectOne('/api/me/ai/connection').flush({
      configured: true,
      baseUrl: 'https://api.example.test/v1',
      apiKeyHint: '1234',
      model: null,
      ready: false,
      models: ['gpt-4o', 'gpt-4o-mini'],
    });
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('app-searchable-select')).not.toBeNull();
  });

  it('clears the typed key after the connection is saved', () => {
    const fixture = mount();
    fixture.componentInstance.baseUrl.set('https://api.example.test/v1');
    fixture.componentInstance.apiKey.set('sk-abcdef1234');
    fixture.componentInstance.saveConnection();
    http.expectOne('/api/me/ai/connection').flush({
      configured: true,
      baseUrl: 'https://api.example.test/v1',
      apiKeyHint: '1234',
      model: null,
      ready: false,
      models: ['gpt-4o'],
    });

    expect(fixture.componentInstance.apiKey()).toBe('');
  });

  it('surfaces the provider refusal', () => {
    const fixture = mount();
    fixture.componentInstance.baseUrl.set('https://api.example.test/v1');
    fixture.componentInstance.apiKey.set('sk-wrong');
    fixture.componentInstance.saveConnection();

    http.expectOne('/api/me/ai/connection').flush(
      { type: 'ai_provider_rejected', detail: 'That provider refused the API key.' },
      { status: 422, statusText: 'Unprocessable Content' },
    );
    fixture.detectChanges();

    const banner = fixture.nativeElement.querySelector('app-error-banner');
    expect(banner).not.toBeNull();
    expect(banner.textContent).toContain('refused the API key');
  });
});
```

- [ ] **Step 9: Register the section**

In `frontend/src/app/settings/settings-sections.ts`, add before the `about` entry:

```ts
  { path: 'ai', icon: 'smart_toy', labelKey: 'settings.ai.title', group: 'general' },
```

In `frontend/src/app/settings/settings.routes.ts`, add beside the other children:

```ts
      {
        path: 'ai',
        loadComponent: () => import('./ai-section.component').then((m) => m.AiSectionComponent),
      },
```

- [ ] **Step 10: Add the translations**

In `frontend/public/i18n/en.json`, inside `settings`:

```json
    "ai": {
      "title": "AI",
      "lead": "Connect an OpenAI-compatible endpoint. The key is stored encrypted and is never shown again.",
      "baseUrl": "Endpoint",
      "baseUrlHint": "The full API root, including any version path — for example https://api.openai.com/v1",
      "apiKey": "API key",
      "storedKey": "A key ending in {{hint}} is stored.",
      "connect": "Save and find models",
      "model": "Model",
      "modelPlaceholder": "Select a model",
      "saveModel": "Save model",
      "remove": "Remove this provider",
      "ready": "AI features are available and use {{model}}."
    },
```

In `frontend/public/i18n/de.json`, at the same place:

```json
    "ai": {
      "title": "KI",
      "lead": "Verbinde einen OpenAI-kompatiblen Endpunkt. Der Schlüssel wird verschlüsselt gespeichert und nie wieder angezeigt.",
      "baseUrl": "Endpunkt",
      "baseUrlHint": "Die vollständige API-Basis, inklusive Versionspfad — zum Beispiel https://api.openai.com/v1",
      "apiKey": "API-Schlüssel",
      "storedKey": "Ein Schlüssel mit der Endung {{hint}} ist gespeichert.",
      "connect": "Speichern und Modelle suchen",
      "model": "Modell",
      "modelPlaceholder": "Modell auswählen",
      "saveModel": "Modell speichern",
      "remove": "Diesen Anbieter entfernen",
      "ready": "KI-Funktionen sind verfügbar und nutzen {{model}}."
    },
```

- [ ] **Step 11: Run the frontend gate**

```bash
npm run check
```

Expected: clean, including the existing `settings-sections.spec.ts` and `auth.service.spec.ts` — both may need the new `ai` field in their fixtures. Update those fixtures; do not change the interface to make them pass.

- [ ] **Step 12: Run the whole backend gate once more**

```bash
cd backend && php bin/phpunit && composer check && composer md && composer infection:diff
```

`composer infection:diff` is what CI gates. Escaped mutants arrive as PR annotations; kill them with tests rather than by lowering `minMsi`.

- [ ] **Step 13: Commit**

```bash
git add frontend/src frontend/public/i18n
git commit -m "feat(#305): add the AI settings section and the availability signal"
```

---

## Verification before the pull request

- [ ] `cd backend && php bin/phpunit` — the SQLite leg.
- [ ] `docker compose up -d && docker compose exec php vendor/bin/phpunit` — the MySQL leg. Note the known order-dependent rate-limiter flake in this leg; confirm any failure passes in isolation before treating it as yours.
- [ ] `cd backend && composer check && composer md && composer infection:diff`.
- [ ] `cd frontend && npm run check`.
- [ ] `shellcheck scripts/*.sh`.
- [ ] Migrate from empty on both SQLite and MySQL, then `doctrine:schema:validate` on both.
- [ ] `tail -n 100 backend/var/log/dev.log` — no new deprecations or swallowed errors.
- [ ] PhpStorm inspections (`mcp__phpstorm__lint_files`) on every changed PHP file: block on ERROR and WARNING.
- [ ] Open the settings page in the running stack and configure a real provider end to end.
- [ ] PR into `develop` with `Closes #305` in the body, and the SSRF exception called out for the reviewer.
