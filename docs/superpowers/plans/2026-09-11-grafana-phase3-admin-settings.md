# Grafana Phase 3 — admin settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Let an admin configure the Loki shipping endpoint (push URL + optional username + secret token) and the Grafana viewing URL, with local-container values shown as effective defaults and only overrides stored; and make the Phase-2 push handler read these settings.

**Architecture:** Clone `ProxyServerSettings` (sealed secret, 3-state token) and the `InstanceSetting` + `InstanceSettingsJson` override/effective-default pattern. A `GrafanaSettings` service resolves override-or-env for each URL and exposes the effective Loki endpoint; a `SettingsLokiEndpoint` rebinds the Phase-2 `LokiEndpoint` seam onto it (retiring `EnvLokiEndpoint`). Frontend adds a `grafana-section` extending `DraftSettingsService`, a sibling lazy route to `proxy-section`.

**Tech Stack:** Symfony 7.4, PHP 8.4, Doctrine, libsodium sealed secrets, Angular 20 signals, Jest.

**Spec:** `docs/superpowers/specs/2026-09-11-grafana-observability-design.md`

**Depends on:** Phase 1 (logging), Phase 2 (`App\Service\Logging\Loki\LokiEndpoint` interface: `pushUrl()`, `username()`, `token()`).

## Global Constraints

- `declare(strict_types=1)`; `final readonly class` where stateless. Entities are mutable classes (not readonly), matching `ProxyServerSettings`.
- Secrets encrypted at rest; **write-only over the API** — only `hasToken` (+ a clear-text last-4 `tokenHint`) crosses the wire, never the token.
- Thin controllers (`ThinControllerRule`): the action reads the request, delegates, returns a response. No private methods doing real work.
- Override/effective: DB stores only overrides (nullable); null = fall back to the env default. The payload returns override AND effective value for each URL (mirror `publicBaseUrl` + `publicBaseUrlDefault`).
- Migrations get their own verification: after `doctrine:migrations:diff`, run `doctrine:migrations:migrate` then `doctrine:schema:validate` on SQLite; the migration must be dialect-safe for MySQL (no SQLite-only SQL).
- Gates: `composer check`, `composer md`, `php bin/phpunit`, `composer infection:diff`; frontend `npm run check` (run inside the Docker frontend container per CLAUDE.md: `docker compose exec -T frontend npm test`).
- Datetimes naive UTC (not relevant here — no datetime fields).

---

### Task 1: GrafanaSettings entity + repository + migration

**Files:**
- Create: `backend/src/Entity/GrafanaSettings.php`
- Create: `backend/src/Repository/GrafanaSettingsRepository.php`
- Create: `backend/src/Service/Grafana/GrafanaConnection.php` (value object for the non-secret fields)
- Create: `backend/migrations/VersionYYYYMMDDHHMMSS.php` (generated)
- Test: `backend/tests/Entity/GrafanaSettingsTest.php`

**Interfaces:**
- Produces: `GrafanaConnection` (`__construct(?string $lokiPushUrl, ?string $lokiUsername, ?string $grafanaUrl)`, public readonly props). `GrafanaSettings` entity with getters `getLokiPushUrlOverride(): ?string`, `getLokiUsername(): ?string`, `getGrafanaUrlOverride(): ?string`, `hasToken(): bool`, `getSealedToken(): SealedSecret`, `getTokenHint(): string`, and mutators `apply(GrafanaConnection, SealedSecret, string $tokenHint): void`, `applyWithoutToken(GrafanaConnection): void`, `clearStoredToken(): void`. `GrafanaSettingsRepository::findSingleton(): ?GrafanaSettings`.

- [ ] **Step 1: Write the failing test** (`backend/tests/Entity/GrafanaSettingsTest.php`)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GrafanaSettings;
use App\Service\Crypto\SealedSecret;
use App\Service\Grafana\GrafanaConnection;
use PHPUnit\Framework\TestCase;

final class GrafanaSettingsTest extends TestCase
{
    public function testFreshEntityIsUnconfigured(): void
    {
        $settings = new GrafanaSettings();

        self::assertNull($settings->getLokiPushUrlOverride());
        self::assertNull($settings->getLokiUsername());
        self::assertNull($settings->getGrafanaUrlOverride());
        self::assertFalse($settings->hasToken());
        self::assertSame('', $settings->getTokenHint());
    }

    public function testApplyStoresOverridesAndSealedToken(): void
    {
        $settings = new GrafanaSettings();

        $settings->apply(
            new GrafanaConnection('https://loki.example/loki/api/v1/push', 'tenant42', 'https://grafana.example'),
            new SealedSecret('cipher', 'nonce', 'salt', 3),
            'wxyz',
        );

        self::assertSame('https://loki.example/loki/api/v1/push', $settings->getLokiPushUrlOverride());
        self::assertSame('tenant42', $settings->getLokiUsername());
        self::assertSame('https://grafana.example', $settings->getGrafanaUrlOverride());
        self::assertTrue($settings->hasToken());
        self::assertSame('wxyz', $settings->getTokenHint());
        self::assertEquals(new SealedSecret('cipher', 'nonce', 'salt', 3), $settings->getSealedToken());
    }

    public function testClearStoredTokenLeavesOverridesButDropsSecret(): void
    {
        $settings = new GrafanaSettings();
        $settings->apply(new GrafanaConnection('u', null, null), new SealedSecret('c', 'n', 's', 1), 'abcd');

        $settings->clearStoredToken();

        self::assertFalse($settings->hasToken());
        self::assertSame('', $settings->getTokenHint());
        self::assertSame('u', $settings->getLokiPushUrlOverride());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && php bin/phpunit tests/Entity/GrafanaSettingsTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement `GrafanaConnection`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Grafana;

final readonly class GrafanaConnection
{
    public function __construct(
        public ?string $lokiPushUrl,
        public ?string $lokiUsername,
        public ?string $grafanaUrl,
    ) {
    }
}
```

- [ ] **Step 4: Implement the entity** (`backend/src/Entity/GrafanaSettings.php`)

Model after `ProxyServerSettings`. Nullable override columns; sealed token columns mirroring the proxy password (ciphertext 1024, nonce 64, salt 64, keyVersion default 1) plus a `tokenHint` length 8.

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GrafanaSettingsRepository;
use App\Service\Crypto\SealedSecret;
use App\Service\Grafana\GrafanaConnection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Instance-wide Grafana wiring, held in a single row (see InstanceSetting for the
 * singleton rationale). Absence of the row means "use the env defaults / not
 * configured". The URLs are nullable overrides: null falls back to the env
 * default the installer writes for the local container. The token is never
 * readable here; only whether one is stored, and its last four characters,
 * cross to the admin page.
 */
#[ORM\Entity(repositoryClass: GrafanaSettingsRepository::class)]
#[ORM\Table(name: 'grafana_settings')]
class GrafanaSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'loki_push_url', length: 255, nullable: true)]
    private ?string $lokiPushUrl = null;

    #[ORM\Column(name: 'loki_username', length: 255, nullable: true)]
    private ?string $lokiUsername = null;

    #[ORM\Column(name: 'grafana_url', length: 255, nullable: true)]
    private ?string $grafanaUrl = null;

    #[ORM\Column(length: 1024)]
    private string $tokenCiphertext = '';

    #[ORM\Column(length: 64)]
    private string $tokenNonce = '';

    #[ORM\Column(length: 64)]
    private string $tokenSalt = '';

    #[ORM\Column(length: 8)]
    private string $tokenHint = '';

    #[ORM\Column(options: ['default' => 1])]
    private int $keyVersion = 1;

    public function getLokiPushUrlOverride(): ?string
    {
        return $this->lokiPushUrl;
    }

    public function getLokiUsername(): ?string
    {
        return $this->lokiUsername;
    }

    public function getGrafanaUrlOverride(): ?string
    {
        return $this->grafanaUrl;
    }

    public function hasToken(): bool
    {
        return '' !== $this->tokenCiphertext;
    }

    public function getTokenHint(): string
    {
        return $this->tokenHint;
    }

    public function getSealedToken(): SealedSecret
    {
        return new SealedSecret($this->tokenCiphertext, $this->tokenNonce, $this->tokenSalt, $this->keyVersion);
    }

    public function apply(GrafanaConnection $connection, SealedSecret $sealed, string $tokenHint): void
    {
        $this->applyWithoutToken($connection);
        $this->tokenCiphertext = $sealed->ciphertext;
        $this->tokenNonce = $sealed->nonce;
        $this->tokenSalt = $sealed->salt;
        $this->keyVersion = $sealed->version;
        $this->tokenHint = $tokenHint;
    }

    public function applyWithoutToken(GrafanaConnection $connection): void
    {
        $this->lokiPushUrl = $connection->lokiPushUrl;
        $this->lokiUsername = $connection->lokiUsername;
        $this->grafanaUrl = $connection->grafanaUrl;
    }

    public function clearStoredToken(): void
    {
        $this->tokenCiphertext = '';
        $this->tokenNonce = '';
        $this->tokenSalt = '';
        $this->tokenHint = '';
        $this->keyVersion = 1;
    }
}
```

- [ ] **Step 5: Implement the repository**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GrafanaSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GrafanaSettings>
 */
final class GrafanaSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GrafanaSettings::class);
    }

    public function findSingleton(): ?GrafanaSettings
    {
        return $this->findOneBy([]);
    }
}
```

Check `ProxyServerSettingsRepository` for the exact `findSingleton` idiom and copy it verbatim if it differs (e.g. an `->findOneBy([], ...)` ordering).

- [ ] **Step 6: Run the entity test**

Run: `cd backend && php bin/phpunit tests/Entity/GrafanaSettingsTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Generate + verify the migration**

```bash
cd backend && php bin/console doctrine:migrations:diff --no-interaction
```
Open the generated `migrations/VersionYYYYMMDDHHMMSS.php`; confirm it only creates `grafana_settings` (a `CREATE TABLE`), remove any unrelated drift it may have picked up, and keep the `getDescription()` meaningful. Then:

```bash
cd backend && php bin/console doctrine:migrations:migrate --no-interaction \
  && php bin/console doctrine:schema:validate
```
Expected: migrates clean; schema validates ("mapping" and "database" both in sync). If `schema:validate` reports the DB out of sync, the migration is wrong — fix the migration, not the entity.

Note (CLAUDE.md gotcha): `tests/bootstrap.php` builds the schema from ORM metadata, so the suite never runs this migration — the `schema:validate` step above is the only local proof it is correct. Keep it dialect-neutral (Doctrine's generated `CREATE TABLE` is; do not hand-edit in SQLite-specific syntax).

- [ ] **Step 8: Commit**

```bash
git add backend/src/Entity/GrafanaSettings.php backend/src/Repository/GrafanaSettingsRepository.php backend/src/Service/Grafana/GrafanaConnection.php backend/migrations/Version*.php backend/tests/Entity/GrafanaSettingsTest.php
git commit -m "feat(#983): add GrafanaSettings entity, repository and migration"
```

---

### Task 2: GrafanaApiKeyCipher

**Files:**
- Create: `backend/src/Service/Grafana/Crypto/GrafanaApiKeyCipher.php`
- Test: `backend/tests/Service/Grafana/Crypto/GrafanaApiKeyCipherTest.php`

**Interfaces:**
- Produces: `final readonly class GrafanaApiKeyCipher` with `seal(string $token): SealedSecret` and `open(SealedSecret $sealed): string`, purpose `'grafana-api-key'`. Exact clone of `ProxyPasswordCipher`.

- [ ] **Step 1: Write the failing test** (round-trip through the real `InstanceSecretCipher`)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Grafana\Crypto;

use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use PHPUnit\Framework\TestCase;

final class GrafanaApiKeyCipherTest extends TestCase
{
    public function testSealsAndOpensRoundTrip(): void
    {
        $cipher = new GrafanaApiKeyCipher(new InstanceSecretCipher(str_repeat('k', 32)));

        $sealed = $cipher->seal('glc_secrettoken');

        self::assertNotSame('glc_secrettoken', $sealed->ciphertext);
        self::assertSame('glc_secrettoken', $cipher->open($sealed));
    }
}
```

Confirm `InstanceSecretCipher`'s constructor signature first (it takes the master key string; the test above assumes `new InstanceSecretCipher(string $key)`). If it is wired from an env `#[Autowire]`, construct it in the test with a literal 32-char key as shown. Adjust to the real constructor.

- [ ] **Step 2: Run to verify it fails; Step 3: Implement** (clone `ProxyPasswordCipher`)

```php
<?php

declare(strict_types=1);

namespace App\Service\Grafana\Crypto;

use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Crypto\SealedSecret;
use App\Service\Crypto\SecretBinding;

/** The instance-wide Grafana push token; its own binding keeps it apart from every other sealed secret. */
final readonly class GrafanaApiKeyCipher
{
    private const string PURPOSE = 'grafana-api-key';

    public function __construct(private InstanceSecretCipher $cipher)
    {
    }

    public function seal(string $plainToken): SealedSecret
    {
        return $this->cipher->seal(SecretBinding::forInstance(self::PURPOSE), $plainToken);
    }

    public function open(SealedSecret $sealed): string
    {
        return $this->cipher->open(SecretBinding::forInstance(self::PURPOSE), $sealed);
    }
}
```

- [ ] **Step 4: Run test to verify it passes; Step 5: Commit**

```bash
git add backend/src/Service/Grafana/Crypto/GrafanaApiKeyCipher.php backend/tests/Service/Grafana/Crypto/GrafanaApiKeyCipherTest.php
git commit -m "feat(#983): add GrafanaApiKeyCipher for the sealed push token"
```

---

### Task 3: Request DTO + JSON mapper

**Files:**
- Create: `backend/src/Dto/Admin/GrafanaSettingsRequest.php`
- Create: `backend/src/Http/Admin/GrafanaSettingsJson.php`
- Test: `backend/tests/Http/Admin/GrafanaSettingsJsonTest.php`

**Interfaces:**
- Produces: `GrafanaSettingsRequest` (readonly, `#[MapRequestPayload]`-shaped): `?string $lokiPushUrl = null`, `?string $lokiUsername = null`, `?string $grafanaUrl = null`, `?string $token = null` (null=keep, string=replace), `bool $removeToken = false`. `GrafanaSettingsJson::from(?GrafanaSettings $settings, string $lokiPushUrlDefault, string $grafanaUrlDefault): array` emitting the override, the default, the effective value for each URL, plus `hasToken`, `tokenHint`, `lokiUsername`, `containerPresent`.

- [ ] **Step 1: Write the failing test** (`GrafanaSettingsJsonTest.php`)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http\Admin;

use App\Entity\GrafanaSettings;
use App\Http\Admin\GrafanaSettingsJson;
use App\Service\Crypto\SealedSecret;
use App\Service\Grafana\GrafanaConnection;
use PHPUnit\Framework\TestCase;

final class GrafanaSettingsJsonTest extends TestCase
{
    public function testNoRowFallsBackToDefaultsAndReportsContainerPresent(): void
    {
        $payload = GrafanaSettingsJson::from(null, 'http://loki:3100/loki/api/v1/push', 'http://localhost:3000');

        self::assertNull($payload['lokiPushUrl']);
        self::assertSame('http://loki:3100/loki/api/v1/push', $payload['lokiPushUrlDefault']);
        self::assertSame('http://loki:3100/loki/api/v1/push', $payload['lokiPushUrlEffective']);
        self::assertNull($payload['grafanaUrl']);
        self::assertSame('http://localhost:3000', $payload['grafanaUrlEffective']);
        self::assertFalse($payload['hasToken']);
        self::assertSame('', $payload['tokenHint']);
        self::assertNull($payload['lokiUsername']);
        self::assertTrue($payload['containerPresent']);
    }

    public function testOverrideWinsOverDefaultAndSecretNeverLeaks(): void
    {
        $settings = new GrafanaSettings();
        $settings->apply(new GrafanaConnection('https://cloud/loki/push', 'tenant42', 'https://cloud/grafana'), new SealedSecret('c', 'n', 's', 1), 'wxyz');

        $payload = GrafanaSettingsJson::from($settings, 'http://loki:3100/loki/api/v1/push', 'http://localhost:3000');

        self::assertSame('https://cloud/loki/push', $payload['lokiPushUrl']);
        self::assertSame('https://cloud/loki/push', $payload['lokiPushUrlEffective']);
        self::assertSame('tenant42', $payload['lokiUsername']);
        self::assertTrue($payload['hasToken']);
        self::assertSame('wxyz', $payload['tokenHint']);
        self::assertArrayNotHasKey('token', $payload);
    }

    public function testNoContainerWhenDefaultEmpty(): void
    {
        $payload = GrafanaSettingsJson::from(null, '', '');

        self::assertFalse($payload['containerPresent']);
        self::assertNull($payload['lokiPushUrlEffective']);
    }
}
```

- [ ] **Step 2: Run to verify it fails; Step 3: Implement**

`GrafanaSettingsRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Full-replace payload for the Grafana wiring. A null URL clears the override
 * and falls back to the env default; the token is a three-state intent: null
 * keeps the stored secret, a string replaces it, removeToken clears it. The
 * token is inbound-only, never echoed back.
 */
final readonly class GrafanaSettingsRequest
{
    public function __construct(
        #[Assert\Length(max: 255)]
        #[Assert\Url]
        public ?string $lokiPushUrl = null,
        #[Assert\Length(max: 255)]
        public ?string $lokiUsername = null,
        #[Assert\Length(max: 255)]
        #[Assert\Url]
        public ?string $grafanaUrl = null,
        #[Assert\Length(max: 512)]
        public ?string $token = null,
        #[Assert\Type('bool')]
        public bool $removeToken = false,
    ) {
    }
}
```

Note: `#[Assert\Url]` on a nullable field only validates when non-null (Symfony skips null). If an empty string `''` should mean "clear the override", have the service map `''`→null before validation is a concern; simplest is to treat `''` and null identically in the service (Task 4).

`GrafanaSettingsJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Entity\GrafanaSettings;

/**
 * The admin Grafana payload. The token is absent by construction: only hasToken
 * and the last-four tokenHint cross the wire, never the secret. Each URL carries
 * its stored override, the env default, and the effective value the server uses
 * now, so an admin who leaves a field empty still sees the local-container value.
 */
final readonly class GrafanaSettingsJson
{
    /**
     * @return array{
     *     lokiPushUrl: string|null,
     *     lokiPushUrlDefault: string,
     *     lokiPushUrlEffective: string|null,
     *     lokiUsername: string|null,
     *     grafanaUrl: string|null,
     *     grafanaUrlDefault: string,
     *     grafanaUrlEffective: string|null,
     *     hasToken: bool,
     *     tokenHint: string,
     *     containerPresent: bool,
     * }
     */
    public static function from(?GrafanaSettings $settings, string $lokiPushUrlDefault, string $grafanaUrlDefault): array
    {
        $settings ??= new GrafanaSettings();
        $lokiOverride = $settings->getLokiPushUrlOverride();
        $grafanaOverride = $settings->getGrafanaUrlOverride();

        return [
            'lokiPushUrl' => $lokiOverride,
            'lokiPushUrlDefault' => $lokiPushUrlDefault,
            'lokiPushUrlEffective' => self::effective($lokiOverride, $lokiPushUrlDefault),
            'lokiUsername' => $settings->getLokiUsername(),
            'grafanaUrl' => $grafanaOverride,
            'grafanaUrlDefault' => $grafanaUrlDefault,
            'grafanaUrlEffective' => self::effective($grafanaOverride, $grafanaUrlDefault),
            'hasToken' => $settings->hasToken(),
            'tokenHint' => $settings->getTokenHint(),
            'containerPresent' => '' !== $lokiPushUrlDefault,
        ];
    }

    private static function effective(?string $override, string $default): ?string
    {
        if (null !== $override) {
            return $override;
        }

        return '' === $default ? null : $default;
    }
}
```

- [ ] **Step 4: Run test to verify it passes; Step 5: Commit**

```bash
git add backend/src/Dto/Admin/GrafanaSettingsRequest.php backend/src/Http/Admin/GrafanaSettingsJson.php backend/tests/Http/Admin/GrafanaSettingsJsonTest.php
git commit -m "feat(#983): add Grafana settings request DTO and write-only JSON mapper"
```

---

### Task 4: GrafanaSettings service + AdminGrafanaController

**Files:**
- Create: `backend/src/Service/Grafana/GrafanaSettings.php`
- Create: `backend/src/Controller/Admin/AdminGrafanaController.php`
- Test: `backend/tests/Functional/Admin/AdminGrafanaControllerTest.php` (or match the existing functional-test namespace for admin endpoints — check where `AdminProxyController` is tested and mirror it)

**Interfaces:**
- Consumes: `GrafanaSettingsRepository`, `EntityManagerInterface`, `GrafanaApiKeyCipher`, and the two env defaults via `#[Autowire('%env(default::GRAFANA_LOKI_PUSH_URL)%')]` / `#[Autowire('%env(default::GRAFANA_URL)%')]`.
- Produces: `GrafanaSettings` service with `view(): array`, `update(GrafanaSettingsRequest): void`, and the endpoint resolvers `effectiveLokiPushUrl(): ?string`, `lokiUsername(): ?string`, `lokiToken(): ?string`. `AdminGrafanaController` at `/api/admin/grafana` (GET, PUT).

- [ ] **Step 1: Write the failing functional test**

Mirror the `AdminProxyController` functional test (find it first — likely `backend/tests/Functional/...` or `backend/tests/Controller/Admin/...`; copy its base class, its admin-authentication helper, and its request style verbatim). The test must:
1. GET `/api/admin/grafana` as an admin → 200, body has `lokiPushUrl: null`, `hasToken: false`, and the `*Default`/`*Effective` keys.
2. PUT a `grafanaUrl` override + a `token` → 200; a follow-up GET shows `grafanaUrl` set, `hasToken: true`, `tokenHint` = last 4 chars, and NO `token` key.
3. PUT `removeToken: true` → GET shows `hasToken: false`.
4. GET without admin auth → 401/403 (the `^/api/admin/` firewall).

Use the exact admin-login/token helper the proxy test uses. Do not invent one.

- [ ] **Step 2: Run to verify it fails; Step 3: Implement the service**

```php
<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Dto\Admin\GrafanaSettingsRequest;
use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Http\Admin\GrafanaSettingsJson;
use App\Repository\GrafanaSettingsRepository;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads and writes the instance-wide Grafana row, defaulting to the env values
 * the installer writes for the local container when no row exists. The push
 * handler resolves its endpoint through here, so "no row", the env fallback and
 * the token sealing all live in one place.
 */
readonly class GrafanaSettings
{
    public function __construct(
        private GrafanaSettingsRepository $repository,
        private EntityManagerInterface $em,
        private GrafanaApiKeyCipher $cipher,
        #[Autowire('%env(default::GRAFANA_LOKI_PUSH_URL)%')]
        private string $lokiPushUrlDefault,
        #[Autowire('%env(default::GRAFANA_URL)%')]
        private string $grafanaUrlDefault,
    ) {
    }

    /** @return array<string, mixed> */
    public function view(): array
    {
        return GrafanaSettingsJson::from($this->repository->findSingleton(), $this->lokiPushUrlDefault, $this->grafanaUrlDefault);
    }

    public function update(GrafanaSettingsRequest $request): void
    {
        $settings = $this->repository->findSingleton();
        if (null === $settings) {
            $settings = new GrafanaSettingsEntity();
            $this->em->persist($settings);
        }

        $connection = $this->connectionFrom($request);

        if ($request->removeToken) {
            $settings->applyWithoutToken($connection);
            $settings->clearStoredToken();
        } elseif (null === $request->token || '' === $request->token) {
            $settings->applyWithoutToken($connection);
        } else {
            $settings->apply($connection, $this->cipher->seal($request->token), $this->hint($request->token));
        }

        $this->em->flush();
    }

    public function effectiveLokiPushUrl(): ?string
    {
        $settings = $this->repository->findSingleton();
        $override = $settings?->getLokiPushUrlOverride();

        return $override ?? ('' === $this->lokiPushUrlDefault ? null : $this->lokiPushUrlDefault);
    }

    public function lokiUsername(): ?string
    {
        return $this->repository->findSingleton()?->getLokiUsername();
    }

    public function lokiToken(): ?string
    {
        $settings = $this->repository->findSingleton();

        return null !== $settings && $settings->hasToken() ? $this->cipher->open($settings->getSealedToken()) : null;
    }

    private function connectionFrom(GrafanaSettingsRequest $request): GrafanaConnection
    {
        return new GrafanaConnection(
            $this->blankToNull($request->lokiPushUrl),
            $this->blankToNull($request->lokiUsername),
            $this->blankToNull($request->grafanaUrl),
        );
    }

    private function blankToNull(?string $value): ?string
    {
        return null === $value || '' === $value ? null : $value;
    }

    private function hint(string $token): string
    {
        return substr($token, -4);
    }
}
```

Note: `GrafanaSettings` (service) and `GrafanaSettings` (entity) share a short name; the service aliases the entity as `GrafanaSettingsEntity` to avoid the clash. If the reviewer prefers distinct names, renaming the entity is out of scope — the alias is the house-acceptable resolution.

- [ ] **Step 4: Implement the controller** (thin — mirror `AdminProxyController`)

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\GrafanaSettingsRequest;
use App\Service\Grafana\GrafanaSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ROLE_ADMIN is enforced by the `^/api/admin/` prefix rule in security.yaml,
 * not by a per-action attribute here.
 */
#[Route('/api/admin/grafana')]
final readonly class AdminGrafanaController
{
    public function __construct(private GrafanaSettings $settings)
    {
    }

    #[Route('', name: 'api_admin_grafana_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse($this->settings->view());
    }

    #[Route('', name: 'api_admin_grafana_update', methods: ['PUT'])]
    public function update(#[MapRequestPayload] GrafanaSettingsRequest $request): JsonResponse
    {
        $this->settings->update($request);

        return new JsonResponse($this->settings->view());
    }
}
```

- [ ] **Step 5: Run the functional test + thin-controller rule**

Run: `cd backend && php bin/phpunit tests/Functional/Admin/AdminGrafanaControllerTest.php && composer stan`
Expected: pass; `ThinControllerRule` clean (the controller has no private logic).

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Grafana/GrafanaSettings.php backend/src/Controller/Admin/AdminGrafanaController.php backend/tests/Functional/Admin/AdminGrafanaControllerTest.php
git commit -m "feat(#983): add Grafana settings service and admin endpoint"
```

---

### Task 5: Rebind the LokiEndpoint onto the settings

**Files:**
- Create: `backend/src/Service/Grafana/SettingsLokiEndpoint.php`
- Delete: `backend/src/Service/Logging/Loki/EnvLokiEndpoint.php` and `backend/tests/Service/Logging/Loki/EnvLokiEndpointTest.php` (superseded — no dead code at PR time)
- Modify: `backend/config/services.yaml` (rebind `LokiEndpoint`, drop the `EnvLokiEndpoint` binding)
- Test: `backend/tests/Service/Grafana/SettingsLokiEndpointTest.php`

**Interfaces:**
- Consumes: `GrafanaSettings` service (Task 4), implements `App\Service\Logging\Loki\LokiEndpoint` (Phase 2).
- Produces: `SettingsLokiEndpoint` delegating `pushUrl()`→`effectiveLokiPushUrl()`, `username()`→`lokiUsername()`, `token()`→`lokiToken()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Grafana;

use App\Service\Grafana\GrafanaSettings;
use App\Service\Grafana\SettingsLokiEndpoint;
use PHPUnit\Framework\TestCase;

final class SettingsLokiEndpointTest extends TestCase
{
    public function testDelegatesToTheSettingsService(): void
    {
        $settings = $this->createMock(GrafanaSettings::class);
        $settings->method('effectiveLokiPushUrl')->willReturn('http://loki:3100/loki/api/v1/push');
        $settings->method('lokiUsername')->willReturn('tenant42');
        $settings->method('lokiToken')->willReturn('secret');
        $endpoint = new SettingsLokiEndpoint($settings);

        self::assertSame('http://loki:3100/loki/api/v1/push', $endpoint->pushUrl());
        self::assertSame('tenant42', $endpoint->username());
        self::assertSame('secret', $endpoint->token());
    }
}
```

(`GrafanaSettings` is a non-final readonly service, so it is mockable. If PHPStan/PHPUnit objects to mocking a readonly class, make the test use a hand-written stub subclass instead, as the Phase-2 tests did for `LokiEndpoint`.)

- [ ] **Step 2: Run to verify it fails; Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Service\Logging\Loki\LokiEndpoint;

final readonly class SettingsLokiEndpoint implements LokiEndpoint
{
    public function __construct(private GrafanaSettings $settings)
    {
    }

    public function pushUrl(): ?string
    {
        return $this->settings->effectiveLokiPushUrl();
    }

    public function username(): ?string
    {
        return $this->settings->lokiUsername();
    }

    public function token(): ?string
    {
        return $this->settings->lokiToken();
    }
}
```

- [ ] **Step 4: Rebind + delete the env endpoint**

In `backend/config/services.yaml`: remove the `App\Service\Logging\Loki\EnvLokiEndpoint` block and change the interface binding to:

```yaml
    App\Service\Logging\Loki\LokiEndpoint: '@App\Service\Grafana\SettingsLokiEndpoint'
```

Delete `backend/src/Service/Logging/Loki/EnvLokiEndpoint.php` and its test. Keep the `GRAFANA_LOKI_PUSH_URL` / `GRAFANA_URL` / `GRAFANA_LOKI_USERNAME` / `GRAFANA_LOKI_TOKEN` env defaults in `backend/.env` — the settings service reads the first two, and the token/username are now DB-only overrides (drop `GRAFANA_LOKI_USERNAME`/`GRAFANA_LOKI_TOKEN` from `.env` if nothing reads them; check first with a grep).

- [ ] **Step 5: Verify + Commit**

Run:
```bash
cd backend && php bin/phpunit tests/Service/Grafana/SettingsLokiEndpointTest.php \
  && php bin/console lint:container --env=dev && php bin/console lint:container --env=prod \
  && composer stan
```
Expected: pass; no reference to the deleted `EnvLokiEndpoint` remains (stan/lint would fail otherwise).

```bash
git add -A backend/src/Service/Grafana/SettingsLokiEndpoint.php backend/config/services.yaml backend/.env backend/tests/Service/Grafana backend/src/Service/Logging/Loki
git commit -m "feat(#983): drive the Loki push handler from the admin settings"
```

---

### Task 6: Frontend GrafanaSettingsService

**Files:**
- Create: `frontend/src/app/settings/admin/grafana/grafana-settings.service.ts`
- Test: `frontend/src/app/settings/admin/grafana/grafana-settings.service.spec.ts`

**Interfaces:**
- Produces: `GrafanaSettingsService extends DraftSettingsService<GrafanaSettingsState, SaveGrafanaSettings, TypedGrafanaEdits>` with `endpoint = ${this.base}/api/admin/grafana`, a `removeToken()` method (mirror proxy's `removePassword()`), and `bodyFromState()` defaulting `token: null, removeToken: false`.

- [ ] **Step 1: Write the failing spec** (mirror `proxy-settings.service.spec.ts` — read it first for the exact `HttpTestingController` setup)

State/body interfaces:

```ts
export interface GrafanaSettingsState {
  readonly lokiPushUrl: string | null;
  readonly lokiPushUrlDefault: string;
  readonly lokiPushUrlEffective: string | null;
  readonly lokiUsername: string | null;
  readonly grafanaUrl: string | null;
  readonly grafanaUrlDefault: string;
  readonly grafanaUrlEffective: string | null;
  readonly hasToken: boolean;
  readonly tokenHint: string;
  readonly containerPresent: boolean;
}

export interface SaveGrafanaSettings {
  readonly lokiPushUrl: string | null;
  readonly lokiUsername: string | null;
  readonly grafanaUrl: string | null;
  /** null keeps the stored token; a string replaces it. */
  readonly token: string | null;
  readonly removeToken: boolean;
}

export type TypedGrafanaEdits = Partial<Omit<SaveGrafanaSettings, never>>;
```

Spec cases: `load()` GETs and exposes state; `save()` PUTs the body with `token: null` when untouched; `setTypedField('token', 'x')` then `save()` sends `token: 'x'`; `removeToken()` PUTs `removeToken: true`. Assert against `HttpTestingController` exactly as the proxy spec does.

- [ ] **Step 2–4: Run fail, implement, run pass** (implementation mirrors `ProxySettingsService`)

```ts
import { HttpErrorResponse } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { DraftSettingsService } from '../../../shared/settings/draft-settings.service';
// ...state/body/edits interfaces above...

@Injectable()
export class GrafanaSettingsService extends DraftSettingsService<
  GrafanaSettingsState,
  SaveGrafanaSettings,
  TypedGrafanaEdits
> {
  protected readonly endpoint = `${this.base}/api/admin/grafana`;

  removeToken(): void {
    const current = this.state();
    if (!current) return;
    this.put({ ...this.bodyFromState(current), removeToken: true }, (state) => {
      this.commit(state);
      this.saved.set(true);
    });
  }

  protected bodyFromState(state: GrafanaSettingsState): SaveGrafanaSettings {
    return {
      lokiPushUrl: state.lokiPushUrl,
      lokiUsername: state.lokiUsername,
      grafanaUrl: state.grafanaUrl,
      token: null,
      removeToken: false,
    };
  }
}
```

Run: `docker compose exec -T frontend npx jest src/app/settings/admin/grafana/grafana-settings.service.spec.ts` (Jest runs inside the Docker frontend container per CLAUDE.md).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/settings/admin/grafana/grafana-settings.service.ts frontend/src/app/settings/admin/grafana/grafana-settings.service.spec.ts
git commit -m "feat(#983): add GrafanaSettingsService draft/save/remove-token client"
```

---

### Task 7: Frontend grafana-section component + route

**Files:**
- Create: `frontend/src/app/settings/admin/grafana/grafana-section.component.ts`
- Create: `frontend/src/app/settings/admin/grafana/grafana-section.component.html`
- Create: `frontend/src/app/settings/admin/grafana/grafana-section.component.scss`
- Create: `frontend/src/app/settings/admin/grafana/grafana-section.component.spec.ts`
- Modify: `frontend/src/app/settings/settings.routes.ts` (add the lazy route next to proxy at `settings.routes.ts:95`)

**Interfaces:**
- Consumes: `GrafanaSettingsService` (Task 6), shared `SettingsGroupComponent`/`SettingsRowComponent`/`SaveBarComponent`/`StackComponent`, `PasswordInputComponent`.

- [ ] **Step 1: Read `proxy-section.component.{ts,html,scss,spec.ts}` and mirror the structure.** Two `SettingsGroupComponent` groups: "Log shipping" (Loki push URL, username, token via `PasswordInputComponent` with a "Remove" affordance calling `removeToken()`, plus a read-only hint line showing `lokiPushUrlEffective`/`containerPresent` — "Local container: <effective>" when `containerPresent`) and "Viewing" (Grafana URL, with the `grafanaUrlEffective` hint and a link-out affordance). URL fields are typed (draft + explicit Save); there are no instant toggles here, so no `saveInstant` usage. Show `tokenHint` beside the token input ("stored key ends …wxyz") when `hasToken`.

- [ ] **Step 2: Write the component spec** mirroring `proxy-section.component.spec.ts`: renders, shows the effective/local-container value, edits a URL and saves, removes the token. Run it in the Docker frontend container.

- [ ] **Step 3: Add the route** in `frontend/src/app/settings/settings.routes.ts` next to the proxy entry:

```ts
{
  path: 'grafana',
  loadComponent: () =>
    import('./admin/grafana/grafana-section.component').then((m) => m.GrafanaSectionComponent),
},
```

Match the surrounding route object shape exactly (title, guards, data) as the proxy route has them.

- [ ] **Step 4: Add the section to the admin settings navigation** wherever the proxy section is linked in the settings UI (find the nav/menu that lists "Proxy" and add "Grafana" beside it, following that file's pattern).

- [ ] **Step 5: Full frontend gate**

Run: `docker compose exec -T frontend npm run check`
Expected: ESLint + Prettier + Stylelint + Jest all green. Fix any hex-colour / raw-px Stylelint violations by using tokens (CLAUDE.md).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/settings/admin/grafana frontend/src/app/settings/settings.routes.ts frontend/src/app/settings
git commit -m "feat(#983): add Grafana admin settings section and route"
```

---

## Phase-3 exit checks

```bash
cd backend && composer check && composer md && php bin/phpunit && composer infection:diff
docker compose exec -T frontend npm run check
```

All green. The migration was validated in Task 1 Step 7; re-confirm `doctrine:schema:validate` is clean after the whole phase.

## Self-review notes

- Spec coverage: implements the "Phase 3" section — entity/cipher/repo/service/DTO/JSON/controller with sealed write-only token, override+effective for both URLs, `containerPresent`, and the frontend section; and rebinds the Phase-2 `LokiEndpoint` onto the settings (retiring `EnvLokiEndpoint`).
- Not covered here: the containers and the installer that writes `GRAFANA_LOKI_PUSH_URL`/`GRAFANA_URL` (Phase 4), tracing (Phase 5).
- Type consistency: entity `getLokiPushUrlOverride()/getLokiUsername()/getGrafanaUrlOverride()/hasToken()/getSealedToken()/getTokenHint()/apply()/applyWithoutToken()/clearStoredToken()`; service `view()/update()/effectiveLokiPushUrl()/lokiUsername()/lokiToken()`; JSON keys match between `GrafanaSettingsJson`, the request DTO, and the frontend `GrafanaSettingsState`/`SaveGrafanaSettings`.
