# Admin Mail Settings Refinements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refine the admin mail settings page — hide the saved password, show per-field errors, present env-managed config read-only, test the transport that actually sends, and let mail route through the egress proxy — without touching Symfony Mailer internals.

**Architecture:** Backend contract changes land first (persistence, API payload, transport), then the Angular section consumes them. Proxy-routed SMTP is implemented as a `CurlSmtpTransport` extending the **public** `AbstractTransport` and driving ext-curl (which tunnels SMTP over SOCKS5/HTTP natively), so nothing depends on Mailer `@internal` classes. The non-proxied path keeps the existing `EsmtpTransport` untouched.

**Tech Stack:** Symfony 7.4 / PHP 8.4 (backend), Angular 20 signals (frontend), Doctrine migrations (SQLite + MySQL), ext-curl (already required), PHPUnit, Jest.

**Spec:** GitHub issue [#845](https://github.com/larspohlmann/simple-feed-reader/issues/845). Design decisions were settled in a grilling session; the key ones are captured in Global Constraints below.

## Global Constraints

- **Clean Code is mandatory** (see `CLAUDE.md`): intention-revealing names, small single-purpose functions, no boolean flag *method* parameters, guard clauses, immutability by default (`final readonly` where possible), depend on interfaces, typed namespaced exceptions, delete redundant comments/docblocks. Every `src` file touched must be PHPMD-clean, not merely free of *new* findings.
- **Thin controllers**: `AdminMailController` stays thin — no new private methods that carry responsibility.
- **`declare(strict_types=1)`** in every PHP file; PSR-12; PHPStan level max over `src` and `tests` (no new baselines; any `@phpstan-ignore` needs a why-comment).
- **Migrations are dialect-portable and verified by CI's migrate-from-empty leg** (SQLite + MySQL), because `tests/bootstrap.php` builds the schema from ORM metadata and never runs a migration. Use platform-aware DDL exactly like `migrations/Version20260904120000.php` / `Version20260830181347.php`.
- **No `@internal` Symfony Mailer dependencies.** The proxy transport extends `Symfony\Component\Mailer\Transport\AbstractTransport` (public) and implements `doSend(SentMessage): void`. Do NOT subclass `AbstractStream`/`SocketStream` or duck-type `EsmtpTransport`.
- **Frontend**: standalone components + signals, no NgModules. Component styles in a sibling `.scss` (never inline). No hex colours / ad-hoc `px` / media-query literals in `.scss` outside `src/app/theme/`. Every user-facing string is a Transloco key added to BOTH `frontend/public/i18n/en.json` and `de.json`.
- **Password wire model is a three-state intent**: `password: null` = keep; `password: "<value>"` = replace; `removePassword: true` = remove. `removePassword` is a wire data field; the service maps it to intent (it is not a behaviour flag on a domain method).
- **Mail routing through the proxy is independent of feed egress**: it uses `ProxySettings::configuredProxy()` (the saved connection regardless of the feed-egress enable switch).
- **Frontend tests run in the Docker frontend container** (`docker compose exec -T frontend npm test`), never native `npx jest` — the worktree/`node_modules` gotchas and the type-check only run there. **Backend runs both legs**: `php bin/phpunit` (SQLite) and `docker compose exec php vendor/bin/phpunit` (MySQL).
- **Branch:** `fix/845-admin-mail-settings-refinements` (already created, off `develop`). PR body says `Closes #845`.

---

## Phase 1 — Backend persistence & API contract

### Task 1: Stop storing the password last-4 hint

**Files:**
- Modify: `backend/src/Entity/MailServerSettings.php:59-60` (drop `$passwordHint`), `:100-103` (drop `getPasswordHint`), `:133-141` (`apply()` signature)
- Modify: `backend/src/Service/Mail/Settings/MailSettings.php:26` (drop `HINT_LENGTH`), `:64-72` (password branch)
- Modify: `backend/src/Http/Admin/MailSettingsJson.php:16-21` (phpstan type), `:39` (drop `passwordHint`)
- Create: `backend/migrations/Version20260904130000.php`
- Test: `backend/tests/Entity/…` (via) `backend/tests/Service/Mail/Settings/MailSettingsTest.php`, `backend/tests/Http/Admin/MailSettingsJsonTest.php`, `backend/tests/Controller/Admin/AdminMailControllerTest.php`

**Interfaces:**
- Produces: `MailServerSettings::apply(MailConnection $connection, SealedSecret $sealed): void` (hint param removed). Payload no longer has a `passwordHint` key; `hasPassword: bool` stays. `MailSettingsPayload` phpstan type drops `passwordHint: string`.

- [ ] **Step 1: Update `MailSettingsJsonTest` to assert no `passwordHint` key**

In `backend/tests/Http/Admin/MailSettingsJsonTest.php`, in the test that builds a payload from a row with a sealed password, replace any `passwordHint` assertion with:

```php
self::assertArrayNotHasKey('passwordHint', $payload);
self::assertTrue($payload['hasPassword']);
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && php bin/phpunit --filter MailSettingsJsonTest`
Expected: FAIL — `passwordHint` key still present.

- [ ] **Step 3: Drop `passwordHint` from the payload**

In `backend/src/Http/Admin/MailSettingsJson.php` remove the `passwordHint` line from the returned array and from the `@phpstan-type MailSettingsPayload` docblock (delete `hasPassword: bool, passwordHint: string,` → `hasPassword: bool,`).

- [ ] **Step 4: Drop the hint from the entity and service**

In `backend/src/Entity/MailServerSettings.php`: delete the `$passwordHint` property (`:59-60`), `getPasswordHint()` (`:100-103`), the `$passwordHint` parameter and its assignment in `apply()` (`:133`, `:140`). Add:

```php
public function clearStoredPassword(): void
{
    $this->passwordCiphertext = '';
    $this->passwordNonce = '';
    $this->passwordSalt = '';
    $this->keyVersion = 1;
}
```

In `backend/src/Service/Mail/Settings/MailSettings.php`: delete `private const int HINT_LENGTH = 4;` and change the password-set branch:

```php
if (null === $request->password) {
    $settings->applyWithoutPassword($connection);
} else {
    $settings->apply($connection, $this->cipher->seal($request->password));
}
```

- [ ] **Step 5: Remove the multibyte-hint test**

In `backend/tests/Service/Mail/Settings/MailSettingsTest.php`, delete `testTheHintIsTheLastFourCharactersEvenWhenTheyAreMultibyte` (its subject no longer exists). In `backend/tests/Controller/Admin/AdminMailControllerTest.php`, remove the `passwordHint === 'fish'` assertion; keep the assertion that the response has no `password` key and that `hasPassword` is `true` after saving a secret.

- [ ] **Step 6: Write the migration (platform-aware, drop column)**

Create `backend/migrations/Version20260904130000.php`, mirroring `Version20260830181347.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Drop the clear-text password_hint from mail_server_settings (#845): the admin
 *  page now shows only that a password is saved, so storing the last four
 *  characters in clear text buys nothing. */
final class Version20260904130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop mail_server_settings.password_hint (#845)';
    }

    public function up(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $this->addSql('ALTER TABLE mail_server_settings DROP password_hint');
    }

    public function down(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $this->addSql("ALTER TABLE mail_server_settings ADD password_hint VARCHAR(8) DEFAULT '' NOT NULL");
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

- [ ] **Step 7: Verify the suite, then the migration on a fresh SQLite DB and schema:validate**

Run: `cd backend && composer cs:fix && php bin/phpunit --filter 'MailSettingsJsonTest|MailSettingsTest|AdminMailControllerTest'`
Expected: PASS.

Then prove the migration matches the metadata (this is the CI leg's check):

```bash
cd backend && rm -f var/migrate-check.db && \
  DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" php bin/console doctrine:migrations:migrate --no-interaction && \
  DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" php bin/console doctrine:schema:validate --skip-sync
```
Expected: migrations run clean; schema validate reports the mapping is in sync.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Entity/MailServerSettings.php backend/src/Service/Mail/Settings/MailSettings.php backend/src/Http/Admin/MailSettingsJson.php backend/migrations/Version20260904130000.php backend/tests
git commit -m "refactor(#845): stop storing the mail password last-4 hint"
```

---

### Task 2: Explicit "remove password" (three-state intent)

**Files:**
- Modify: `backend/src/Dto/Admin/MailSettingsRequest.php` (add `removePassword`)
- Modify: `backend/src/Service/Mail/Settings/MailSettings.php` (`update()` + guard)
- Test: `backend/tests/Service/Mail/Settings/MailSettingsTest.php`, `backend/tests/Controller/Admin/AdminMailControllerTest.php`, `backend/tests/Dto/Admin/MailSettingsRequestTest.php`

**Interfaces:**
- Consumes: `MailServerSettings::clearStoredPassword()` (Task 1).
- Produces: request field `public bool $removePassword = false`. `update()` honours Keep / Replace / Remove.

- [ ] **Step 1: Failing test — removePassword clears the stored secret**

In `backend/tests/Service/Mail/Settings/MailSettingsTest.php` add:

```php
public function testRemovePasswordClearsTheStoredSecret(): void
{
    $this->settings->update($this->requestWith(host: 'smtp.example.test', username: null, password: 'topsecret'));
    self::assertTrue($this->repository->findSingleton()?->hasPassword());

    $this->settings->update($this->requestWith(host: 'smtp.example.test', username: null, removePassword: true));

    self::assertFalse($this->repository->findSingleton()?->hasPassword());
}
```

(Extend the test's existing `requestWith(...)` helper with `bool $removePassword = false`, or construct `MailSettingsRequest` inline with named args. Match the file's existing helper style.)

- [ ] **Step 2: Failing test — removing the password of an enabled authenticated row is rejected**

```php
public function testRemovingThePasswordOfAnEnabledAuthenticatedRowIsRejected(): void
{
    $this->settings->update($this->requestWith(enabled: true, host: 'smtp.example.test', username: 'alice', password: 'topsecret'));

    $this->expectException(IncompleteMailConfigurationException::class);
    $this->settings->update($this->requestWith(enabled: true, host: 'smtp.example.test', username: 'alice', removePassword: true));
}
```

- [ ] **Step 3: Run them to confirm they fail**

Run: `cd backend && php bin/phpunit --filter MailSettingsTest`
Expected: FAIL — `removePassword` is not a known argument / secret not cleared.

- [ ] **Step 4: Add the wire field**

In `backend/src/Dto/Admin/MailSettingsRequest.php` add, after `password`:

```php
        #[Assert\Type('bool')]
        public bool $removePassword = false,
```

- [ ] **Step 5: Implement the three-state intent in `update()`**

In `backend/src/Service/Mail/Settings/MailSettings.php`, replace the password branch (from Task 1) with:

```php
if ($request->removePassword) {
    $settings->applyWithoutPassword($connection);
    $settings->clearStoredPassword();
} elseif (null === $request->password) {
    $settings->applyWithoutPassword($connection);
} else {
    $settings->apply($connection, $this->cipher->seal($request->password));
}
```

And update the guard so a removal counts as "no password":

```php
private function guardAgainstIncompleteAuthenticatedRow(
    MailSettingsRequest $request,
    MailConnection $connection,
    ?MailServerSettings $existing,
): void {
    $willHavePassword = !$request->removePassword
        && (null !== $request->password || ($existing?->hasPassword() ?? false));
    $isAuthenticatedTransport = $connection->enabled
        && '' !== $connection->host
        && null !== $connection->username;

    if ($isAuthenticatedTransport && !$willHavePassword) {
        throw IncompleteMailConfigurationException::passwordMissing();
    }
}
```

- [ ] **Step 6: Controller-level round-trip test**

In `backend/tests/Controller/Admin/AdminMailControllerTest.php` add a test that PUTs a config with a password, then PUTs `removePassword: true`, and asserts the GET payload has `hasPassword === false`. Mirror the existing "keeping stored password" test's request-building.

- [ ] **Step 7: Run all three suites**

Run: `cd backend && composer cs:fix && php bin/phpunit --filter 'MailSettingsTest|AdminMailControllerTest|MailSettingsRequestTest'`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Dto/Admin/MailSettingsRequest.php backend/src/Service/Mail/Settings/MailSettings.php backend/tests
git commit -m "feat(#845): support explicit removal of the saved mail password"
```

---

### Task 3: Persist `useProxy` and expose proxy availability in the payload

**Files:**
- Modify: `backend/src/Service/Mail/Settings/MailConnection.php` (add `useProxy`)
- Modify: `backend/src/Entity/MailServerSettings.php` (field + `usesProxy()`, `connection()`, `applyWithoutPassword()`)
- Modify: `backend/src/Dto/Admin/MailSettingsRequest.php` (add `useProxy`)
- Modify: `backend/src/Service/Mail/Settings/MailFallback.php` (pass `useProxy: false` in both `MailConnection`s)
- Modify: `backend/src/Service/Mail/Settings/MailSettings.php` (inject `ProxySettings`; guard; pass proxy to `view()`)
- Modify: `backend/src/Http/Admin/MailSettingsJson.php` (add `useProxy`, `proxyConfigured`, `proxyLabel`)
- Modify: `backend/src/Service/Mail/Settings/Exception/IncompleteMailConfigurationException.php` (add `proxyMissing()`)
- Create: `backend/migrations/Version20260904140000.php`
- Test: `MailSettingsTest`, `MailSettingsJsonTest`, `MailFallbackTest`, `AdminMailControllerTest`

**Interfaces:**
- Produces:
  - `MailConnection` gains `public bool $useProxy` (LAST constructor param, default `false`).
  - `MailServerSettings::usesProxy(): bool`.
  - Payload gains `useProxy: bool`, `proxyConfigured: bool`, `proxyLabel: string` (e.g. `"SOCKS5 · proxy.example:1080"`, `''` when none).
  - `IncompleteMailConfigurationException::proxyMissing(): self`.
  - `MailSettings` constructor gains `private ProxySettings $proxySettings`.

- [ ] **Step 1: Failing test — useProxy round-trips and is rejected without a configured proxy**

In `MailSettingsTest`, add (the test's setup already has a `ProxySettings` available via the container in the integration test base; if the test is a pure unit test with mocks, give it a `ProxySettings` test double whose `configuredProxy()` returns a `ProxyConfig` or `null` per case):

```php
public function testUseProxyIsRejectedWhenNoEgressProxyIsConfigured(): void
{
    // proxySettings->configuredProxy() returns null in this fixture
    $this->expectException(IncompleteMailConfigurationException::class);
    $this->settings->update($this->requestWith(host: 'smtp.gmail.com', useProxy: true));
}

public function testUseProxyIsPersistedWhenAProxyIsConfigured(): void
{
    // proxySettings->configuredProxy() returns a ProxyConfig in this fixture
    $this->settings->update($this->requestWith(host: 'smtp.gmail.com', useProxy: true, password: 'app-pw'));
    self::assertTrue($this->repository->findSingleton()?->usesProxy());
}
```

- [ ] **Step 2: Failing test — payload carries useProxy + proxy availability**

In `MailSettingsJsonTest`, add a case building the payload with a configured `ProxyConfig` and assert:

```php
self::assertTrue($payload['proxyConfigured']);
self::assertSame('SOCKS5 · proxy.example:1080', $payload['proxyLabel']);
self::assertFalse($payload['useProxy']); // env/no-row default
```

`MailSettingsJson::from` gains a third parameter `?ProxyConfig $proxy` — update its existing call sites in the test accordingly.

- [ ] **Step 3: Run to confirm failure**

Run: `cd backend && php bin/phpunit --filter 'MailSettingsTest|MailSettingsJsonTest'`
Expected: FAIL — unknown `useProxy` arg / missing payload keys.

- [ ] **Step 4: Thread `useProxy` through `MailConnection` and the entity**

`MailConnection.php`: add `public bool $useProxy = false,` as the LAST constructor parameter.

`MailServerSettings.php`: add
```php
#[ORM\Column(options: ['default' => 0])]
private bool $useProxy = false;
```
add `public function usesProxy(): bool { return $this->useProxy; }`; include `$this->useProxy` as the last argument in `connection()`; and in `applyWithoutPassword()` add `$this->useProxy = $connection->useProxy;`.

`MailFallback.php`: both `new MailConnection(...)` calls take `useProxy: false` (or positionally `false` as the last arg) — the env fallback never proxies.

`MailSettings.php`: in `connectionFrom()` pass `$request->useProxy` as the last `MailConnection` arg.

- [ ] **Step 5: Add the wire field, the guard, and the exception**

`MailSettingsRequest.php`: add
```php
        #[Assert\Type('bool')]
        public bool $useProxy = false,
```

`IncompleteMailConfigurationException.php`: add
```php
public static function proxyMissing(): self
{
    return new self('Mail is set to use the egress proxy, but no proxy is configured.');
}
```
(match the existing factory-method style / parent constructor in that file.)

`MailSettings.php`: inject `ProxySettings` in the constructor and add a guard called from `update()`:
```php
private function guardAgainstProxyRoutingWithoutAProxy(MailSettingsRequest $request): void
{
    if ($request->useProxy && null === $this->proxySettings->configuredProxy()) {
        throw IncompleteMailConfigurationException::proxyMissing();
    }
}
```
Call it in `update()` alongside the other guards.

- [ ] **Step 6: Expose proxy info in the payload**

`MailSettings.php` `view()`:
```php
public function view(): array
{
    return MailSettingsJson::from(
        $this->repository->findSingleton(),
        $this->fallback->connection(),
        $this->proxySettings->configuredProxy(),
    );
}
```

`MailSettingsJson.php`: add the `?ProxyConfig $proxy` parameter, extend the phpstan type with `useProxy: bool, proxyConfigured: bool, proxyLabel: string,`, and return:
```php
'useProxy' => $settings?->usesProxy() ?? false,
'proxyConfigured' => null !== $proxy,
'proxyLabel' => null !== $proxy ? \sprintf('%s · %s:%d', $proxy->type->value, $proxy->host, $proxy->port) : '',
```

- [ ] **Step 7: Migration — add `use_proxy` (platform-aware boolean)**

Create `backend/migrations/Version20260904140000.php`. `up()` is platform-aware (MySQL `TINYINT(1)` vs SQLite `BOOLEAN`), mirroring the create-table migration's two branches:

```php
public function up(Schema $schema): void
{
    $platform = $this->connection->getDatabasePlatform();
    if ($platform instanceof AbstractMySQLPlatform) {
        $this->addSql('ALTER TABLE mail_server_settings ADD use_proxy TINYINT(1) DEFAULT 0 NOT NULL');

        return;
    }
    if ($platform instanceof SQLitePlatform) {
        $this->addSql('ALTER TABLE mail_server_settings ADD use_proxy BOOLEAN DEFAULT 0 NOT NULL');

        return;
    }
    throw new \RuntimeException('Unsupported database platform for mail_server_settings migration.');
}

public function down(Schema $schema): void
{
    $this->addSql('ALTER TABLE mail_server_settings DROP use_proxy');
}
```
Description: `Add mail_server_settings.use_proxy (#845)`.

- [ ] **Step 8: Update `MailFallbackTest` call sites and run everything**

`MailFallbackTest` asserts `MailConnection` shape — the new `useProxy` defaults to `false`, so add `self::assertFalse($connection->useProxy);` in one representative case. Then:

Run: `cd backend && composer cs:fix && php bin/phpunit --filter 'MailSettingsTest|MailSettingsJsonTest|MailFallbackTest|AdminMailControllerTest'`
Expected: PASS. Re-run the migrate-from-empty + `schema:validate` check from Task 1 Step 7.

- [ ] **Step 9: Commit**

```bash
git add backend/src backend/migrations/Version20260904140000.php backend/tests
git commit -m "feat(#845): persist mail use-proxy flag and expose proxy availability"
```

---

## Phase 2 — Backend transport (curl proxy)

### Task 4: `ResolvedMailTransport` carries `useProxy`

**Files:**
- Modify: `backend/src/Service/Mail/Settings/ResolvedMailTransport.php`
- Modify: `backend/src/Service/Mail/Settings/MailSettings.php` (`configuredTransport()`)
- Test: `backend/tests/Service/Mail/Settings/ResolvedMailTransportTest.php`, `MailSettingsTest`

**Interfaces:**
- Produces: `ResolvedMailTransport` gains `public bool $useProxy` (LAST constructor param); `signature()` includes it.

- [ ] **Step 1: Failing test — signature distinguishes useProxy**

In `ResolvedMailTransportTest`:
```php
public function testSignatureDiffersWhenProxyRoutingDiffers(): void
{
    $direct = new ResolvedMailTransport('h', 587, 'u', 'p', MailEncryption::Starttls, false);
    $proxied = new ResolvedMailTransport('h', 587, 'u', 'p', MailEncryption::Starttls, true);
    self::assertNotSame($direct->signature(), $proxied->signature());
}
```

- [ ] **Step 2: Run to confirm failure**

Run: `cd backend && php bin/phpunit --filter ResolvedMailTransportTest`
Expected: FAIL — constructor has no 6th parameter.

- [ ] **Step 3: Add the field and extend the signature**

`ResolvedMailTransport.php`: add `public bool $useProxy = false,` as the last constructor param; append `$this->useProxy ? 'proxy' : 'direct'` to the `implode('|', [...])` in `signature()`.

`MailSettings.php` `configuredTransport()`: pass `$settings->usesProxy()` as the last `ResolvedMailTransport` argument.

- [ ] **Step 4: Assert `configuredTransport()` reflects the flag**

In `MailSettingsTest`, extend an existing `configuredTransport()` test: after saving a row with `useProxy: true` (+ a configured proxy fixture), `self::assertTrue($this->settings->configuredTransport()?->useProxy);`.

- [ ] **Step 5: Run + commit**

Run: `cd backend && composer cs:fix && php bin/phpunit --filter 'ResolvedMailTransportTest|MailSettingsTest'` → PASS
```bash
git add backend/src/Service/Mail/Settings/ResolvedMailTransport.php backend/src/Service/Mail/Settings/MailSettings.php backend/tests
git commit -m "feat(#845): carry the use-proxy flag on the resolved mail transport"
```

---

### Task 5: `CurlSmtpOptions` — pure curl-option assembly (fully unit-tested)

**Files:**
- Create: `backend/src/Service/Mail/Transport/CurlSmtpOptions.php`
- Test: `backend/tests/Service/Mail/Transport/CurlSmtpOptionsTest.php`

**Interfaces:**
- Produces: `CurlSmtpOptions::for(ResolvedMailTransport $resolved, ProxyConfig $proxy, Envelope $envelope): array` → an integer-keyed `CURLOPT_*` map with URL, `CURLOPT_PROXY`, `CURLOPT_MAIL_FROM`, `CURLOPT_MAIL_RCPT`, `CURLOPT_USE_SSL`, and (when set) `CURLOPT_USERNAME`/`CURLOPT_PASSWORD`. It does NOT include the upload stream, size, or timeouts — the transport adds those.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Mail/Transport/CurlSmtpOptionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Transport;

use App\Enum\MailEncryption;
use App\Enum\ProxyType;
use App\Service\Fetch\ProxyConfig;
use App\Service\Mail\Settings\ResolvedMailTransport;
use App\Service\Mail\Transport\CurlSmtpOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;

final class CurlSmtpOptionsTest extends TestCase
{
    private function envelope(): Envelope
    {
        return new Envelope(new Address('from@example.test'), [new Address('to@example.test')]);
    }

    private function proxy(): ProxyConfig
    {
        return new ProxyConfig(ProxyType::Socks5, 'proxy.example', 1080, null, null, true, true);
    }

    public function testImplicitTlsUsesSmtpsScheme(): void
    {
        $resolved = new ResolvedMailTransport('smtp.gmail.com', 465, 'u', 'p', MailEncryption::Tls, true);
        $options = CurlSmtpOptions::for($resolved, $this->proxy(), $this->envelope());
        self::assertSame('smtps://smtp.gmail.com:465', $options[\CURLOPT_URL]);
    }

    public function testStarttlsRequiresTlsUpgradeOverPlainScheme(): void
    {
        $resolved = new ResolvedMailTransport('smtp.gmail.com', 587, 'u', 'p', MailEncryption::Starttls, true);
        $options = CurlSmtpOptions::for($resolved, $this->proxy(), $this->envelope());
        self::assertSame('smtp://smtp.gmail.com:587', $options[\CURLOPT_URL]);
        self::assertSame(\CURLUSESSL_ALL, $options[\CURLOPT_USE_SSL]);
    }

    public function testEnvelopeAddressesAreBracketed(): void
    {
        $resolved = new ResolvedMailTransport('h', 587, null, null, MailEncryption::Starttls, true);
        $options = CurlSmtpOptions::for($resolved, $this->proxy(), $this->envelope());
        self::assertSame('<from@example.test>', $options[\CURLOPT_MAIL_FROM]);
        self::assertSame(['<to@example.test>'], $options[\CURLOPT_MAIL_RCPT]);
    }

    public function testProxyDsnIsPassedThrough(): void
    {
        $resolved = new ResolvedMailTransport('h', 587, null, null, MailEncryption::Starttls, true);
        $options = CurlSmtpOptions::for($resolved, $this->proxy(), $this->envelope());
        self::assertSame('socks5h://proxy.example:1080', $options[\CURLOPT_PROXY]);
    }

    public function testCredentialsAreOmittedWhenAbsent(): void
    {
        $resolved = new ResolvedMailTransport('h', 587, null, null, MailEncryption::Starttls, true);
        $options = CurlSmtpOptions::for($resolved, $this->proxy(), $this->envelope());
        self::assertArrayNotHasKey(\CURLOPT_USERNAME, $options);
    }
}
```

- [ ] **Step 2: Run to confirm failure**

Run: `cd backend && php bin/phpunit --filter CurlSmtpOptionsTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `CurlSmtpOptions`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Transport;

use App\Enum\MailEncryption;
use App\Service\Fetch\ProxyConfig;
use App\Service\Mail\Settings\ResolvedMailTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;

/** Builds the curl option map for one proxied SMTP send. Kept pure and free of
 *  the upload stream so the scheme/TLS/proxy/envelope decisions are unit-tested
 *  without a socket. */
final class CurlSmtpOptions
{
    /** @return array<int, mixed> */
    public static function for(ResolvedMailTransport $resolved, ProxyConfig $proxy, Envelope $envelope): array
    {
        $options = [
            \CURLOPT_URL => self::url($resolved),
            \CURLOPT_PROXY => $proxy->dsn(),
            \CURLOPT_MAIL_FROM => \sprintf('<%s>', $envelope->getSender()->getAddress()),
            \CURLOPT_MAIL_RCPT => array_map(
                static fn (Address $recipient): string => \sprintf('<%s>', $recipient->getAddress()),
                $envelope->getRecipients(),
            ),
            \CURLOPT_USE_SSL => MailEncryption::None === $resolved->encryption ? \CURLUSESSL_NONE : \CURLUSESSL_ALL,
        ];

        if (null !== $resolved->username) {
            $options[\CURLOPT_USERNAME] = $resolved->username;
        }
        if (null !== $resolved->password) {
            $options[\CURLOPT_PASSWORD] = $resolved->password;
        }

        return $options;
    }

    private static function url(ResolvedMailTransport $resolved): string
    {
        $scheme = MailEncryption::Tls === $resolved->encryption ? 'smtps' : 'smtp';

        return \sprintf('%s://%s:%d', $scheme, $resolved->host, $resolved->port);
    }
}
```

(Note: with `smtps://`, curl does implicit TLS; `CURLOPT_USE_SSL` is harmless there. For `None`, `CURLUSESSL_NONE` disables the upgrade.)

- [ ] **Step 4: Run to green + commit**

Run: `cd backend && composer cs:fix && php bin/phpunit --filter CurlSmtpOptionsTest` → PASS
```bash
git add backend/src/Service/Mail/Transport/CurlSmtpOptions.php backend/tests/Service/Mail/Transport/CurlSmtpOptionsTest.php
git commit -m "feat(#845): assemble curl SMTP options from resolved transport + proxy"
```

---

### Task 6: `CurlSmtpTransport` (extends the public `AbstractTransport`)

**Files:**
- Create: `backend/src/Service/Mail/Transport/CurlSmtpTransport.php`
- Test: `backend/tests/Service/Mail/Transport/CurlSmtpTransportTest.php`

**Interfaces:**
- Consumes: `CurlSmtpOptions::for(...)` (Task 5).
- Produces: `final class CurlSmtpTransport extends AbstractTransport` with constructor `(ResolvedMailTransport $resolved, ProxyConfig $proxy, ?EventDispatcherInterface $dispatcher = null, ?LoggerInterface $logger = null)`; `doSend(SentMessage): void`; `__toString(): string`.

- [ ] **Step 1: Failing tests — contract + error mapping**

`backend/tests/Service/Mail/Transport/CurlSmtpTransportTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Transport;

use App\Enum\MailEncryption;
use App\Enum\ProxyType;
use App\Service\Fetch\ProxyConfig;
use App\Service\Mail\Settings\ResolvedMailTransport;
use App\Service\Mail\Transport\CurlSmtpTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

final class CurlSmtpTransportTest extends TestCase
{
    private function transport(): CurlSmtpTransport
    {
        // Port 1 is reserved and refuses instantly, so the send fails fast and
        // deterministically -- no external dependency, real curl path exercised.
        $resolved = new ResolvedMailTransport('smtp.invalid.test', 587, 'u', 'p', MailEncryption::Starttls, true);
        $proxy = new ProxyConfig(ProxyType::Socks5, '127.0.0.1', 1, null, null, true, true);

        return new CurlSmtpTransport($resolved, $proxy);
    }

    public function testItIsATransport(): void
    {
        self::assertInstanceOf(TransportInterface::class, $this->transport());
    }

    public function testAnUnreachableProxyRaisesATransportException(): void
    {
        $email = (new Email())->from('from@example.test')->to('to@example.test')->subject('x')->text('y');

        $this->expectException(TransportExceptionInterface::class);
        $this->transport()->send($email);
    }
}
```

- [ ] **Step 2: Run to confirm failure**

Run: `cd backend && php bin/phpunit --filter CurlSmtpTransportTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the transport**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Transport;

use App\Service\Fetch\ProxyConfig;
use App\Service\Mail\Settings\ResolvedMailTransport;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Sends one message over SMTP through the configured egress proxy, using ext-curl
 * (which tunnels SMTP over SOCKS5/HTTP natively). Extends the public
 * AbstractTransport, so it keeps Mailer's event/logging integration and depends
 * on no @internal Mailer class -- a Mailer upgrade cannot silently break it.
 */
final class CurlSmtpTransport extends AbstractTransport
{
    private const int TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly ResolvedMailTransport $resolved,
        private readonly ProxyConfig $proxy,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);
    }

    protected function doSend(SentMessage $message): void
    {
        $body = $message->toString();
        $stream = fopen('php://temp', 'r+');
        if (false === $stream) {
            throw new TransportException('Unable to buffer the message for the proxied SMTP send.');
        }
        fwrite($stream, $body);
        rewind($stream);

        $handle = curl_init();
        curl_setopt_array($handle, CurlSmtpOptions::for($this->resolved, $this->proxy, $message->getEnvelope()) + [
            \CURLOPT_UPLOAD => true,
            \CURLOPT_INFILE => $stream,
            \CURLOPT_INFILESIZE => \strlen($body),
            \CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            \CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            \CURLOPT_RETURNTRANSFER => true,
        ]);

        $ok = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);
        fclose($stream);

        if (false === $ok) {
            throw new TransportException(\sprintf('Proxied SMTP send failed: %s', $error));
        }
    }

    public function __toString(): string
    {
        return \sprintf('smtp+proxy://%s:%d', $this->resolved->host, $this->resolved->port);
    }
}
```

- [ ] **Step 4: Run to green**

Run: `cd backend && composer cs:fix && php bin/phpunit --filter CurlSmtpTransportTest` → PASS (the send throws a `TransportException` because the proxy at `127.0.0.1:1` refuses).

- [ ] **Step 5: PHPStan + commit**

Run: `cd backend && bin/console cache:warmup && composer stan`
Expected: no errors (add a `@return array<int, mixed>` where needed; `curl_*` are known to PHPStan).
```bash
git add backend/src/Service/Mail/Transport/CurlSmtpTransport.php backend/tests/Service/Mail/Transport/CurlSmtpTransportTest.php
git commit -m "feat(#845): curl-backed SMTP transport for proxied sending"
```

---

### Task 7: `ActiveMailTransportFactory` — one place to choose the transport

**Files:**
- Create: `backend/src/Service/Mail/Transport/ActiveMailTransportFactory.php`
- Test: `backend/tests/Service/Mail/Transport/ActiveMailTransportFactoryTest.php`

**Interfaces:**
- Consumes: `EsmtpTransportBuilder::from(...)`, `CurlSmtpTransport`, `ProxySettings::configuredProxy()`, `IncompleteMailConfigurationException::proxyMissing()`.
- Produces: `ActiveMailTransportFactory::forResolved(ResolvedMailTransport $resolved, ?EventDispatcherInterface $dispatcher, LoggerInterface $logger): TransportInterface`.

- [ ] **Step 1: Failing tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Transport;

use App\Enum\MailEncryption;
use App\Enum\ProxyType;
use App\Service\Fetch\ProxyConfig;
use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Mail\Settings\ResolvedMailTransport;
use App\Service\Mail\Transport\ActiveMailTransportFactory;
use App\Service\Mail\Transport\CurlSmtpTransport;
use App\Service\Proxy\ProxySettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

final class ActiveMailTransportFactoryTest extends TestCase
{
    public function testDirectResolvedGivesAnEsmtpTransport(): void
    {
        $proxySettings = $this->createMock(ProxySettings::class);
        $factory = new ActiveMailTransportFactory($proxySettings);
        $resolved = new ResolvedMailTransport('h', 587, 'u', 'p', MailEncryption::Starttls, false);

        self::assertInstanceOf(EsmtpTransport::class, $factory->forResolved($resolved, null, new NullLogger()));
    }

    public function testProxiedResolvedGivesACurlTransport(): void
    {
        $proxySettings = $this->createMock(ProxySettings::class);
        $proxySettings->method('configuredProxy')->willReturn(
            new ProxyConfig(ProxyType::Socks5, 'proxy.example', 1080, null, null, true, true),
        );
        $factory = new ActiveMailTransportFactory($proxySettings);
        $resolved = new ResolvedMailTransport('smtp.gmail.com', 587, 'u', 'p', MailEncryption::Starttls, true);

        self::assertInstanceOf(CurlSmtpTransport::class, $factory->forResolved($resolved, null, new NullLogger()));
    }

    public function testProxiedResolvedWithNoProxyThrows(): void
    {
        $proxySettings = $this->createMock(ProxySettings::class);
        $proxySettings->method('configuredProxy')->willReturn(null);
        $factory = new ActiveMailTransportFactory($proxySettings);
        $resolved = new ResolvedMailTransport('smtp.gmail.com', 587, 'u', 'p', MailEncryption::Starttls, true);

        $this->expectException(IncompleteMailConfigurationException::class);
        $factory->forResolved($resolved, null, new NullLogger());
    }
}
```

- [ ] **Step 2: Run to confirm failure**

Run: `cd backend && php bin/phpunit --filter ActiveMailTransportFactoryTest` → FAIL (class not found).

- [ ] **Step 3: Implement the factory**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Transport;

use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Mail\Settings\ResolvedMailTransport;
use App\Service\Proxy\ProxySettings;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/** Builds the transport a resolved mail row asks for: the plain EsmtpTransport,
 *  or the curl proxy transport when the row routes through the egress proxy.
 *  The single place that decision lives, shared by real sends and the tester. */
final readonly class ActiveMailTransportFactory
{
    public function __construct(private ProxySettings $proxySettings)
    {
    }

    public function forResolved(
        ResolvedMailTransport $resolved,
        ?EventDispatcherInterface $dispatcher,
        LoggerInterface $logger,
    ): TransportInterface {
        if (!$resolved->useProxy) {
            return EsmtpTransportBuilder::from($resolved, $dispatcher, $logger);
        }

        $proxy = $this->proxySettings->configuredProxy();
        if (null === $proxy) {
            throw IncompleteMailConfigurationException::proxyMissing();
        }

        return new CurlSmtpTransport($resolved, $proxy, $dispatcher, $logger);
    }
}
```

- [ ] **Step 4: Run + PHPStan + commit**

Run: `cd backend && composer cs:fix && php bin/phpunit --filter ActiveMailTransportFactoryTest && composer stan` → PASS
```bash
git add backend/src/Service/Mail/Transport/ActiveMailTransportFactory.php backend/tests/Service/Mail/Transport/ActiveMailTransportFactoryTest.php
git commit -m "feat(#845): factory selecting direct vs proxied mail transport"
```

---

### Task 8a: `DynamicMailTransport` builds via the factory

**Files:**
- Modify: `backend/src/Service/Mail/Transport/DynamicMailTransport.php`
- Test: `backend/tests/Service/Mail/Transport/DynamicMailTransportTest.php` (create if absent)

**Interfaces:**
- Consumes: `ActiveMailTransportFactory::forResolved(...)`.
- Produces: `DynamicMailTransport::__construct(MailSettings, ActiveMailTransportFactory, EventDispatcherInterface, HttpClientInterface, LoggerInterface)` — factory added; the memoisation signature already accounts for `useProxy` via `ResolvedMailTransport::signature()` (Task 4).

- [ ] **Step 1: Failing test — a proxied row yields the curl transport**

`DynamicMailTransportTest` (integration test extending `KernelTestCase`, so the real DB + `ProxySettings` are wired). Seed a configured proxy row and a mail row with `useProxy=true` + a password, then:

```php
public function testActiveTransportUsesTheCurlTransportForAProxiedRow(): void
{
    // arrange: persist a ProxyServerSettings row and a MailServerSettings row with useProxy=true
    self::assertInstanceOf(CurlSmtpTransport::class, $this->dynamicMailTransport->activeTransport());
}

public function testActiveTransportUsesEsmtpForADirectRow(): void
{
    // arrange: a MailServerSettings row with useProxy=false
    self::assertInstanceOf(EsmtpTransport::class, $this->dynamicMailTransport->activeTransport());
}
```

- [ ] **Step 2: Run to confirm failure**

Run: `cd backend && php bin/phpunit --filter DynamicMailTransportTest` → FAIL.

- [ ] **Step 3: Inject the factory and use it**

In `DynamicMailTransport.php`: add `private readonly ActiveMailTransportFactory $transportFactory` to the constructor. Replace the DB branch in `activeTransport()`:
```php
$this->cached = null !== $resolved
    ? $this->transportFactory->forResolved($resolved, $this->dispatcher, $this->logger)
    : $this->buildFallback();
```
Keep `buildFallback()` and the memoisation exactly as-is (the signature already changes with `useProxy`). Remove the now-unused `EsmtpTransportBuilder` import if nothing else uses it.

- [ ] **Step 4: Run both DB legs + commit**

Run: `cd backend && composer cs:fix && php bin/phpunit --filter 'DynamicMailTransportTest'`
Then MySQL: `docker compose exec -T php vendor/bin/phpunit --filter DynamicMailTransportTest`
Expected: PASS on both.
```bash
git add backend/src/Service/Mail/Transport/DynamicMailTransport.php backend/tests/Service/Mail/Transport/DynamicMailTransportTest.php
git commit -m "feat(#845): route real mail sends through the transport factory"
```

---

### Task 8b: `MailConnectionTester` tests the effective transport (proxy + env fallback)

**Files:**
- Modify: `backend/src/Service/Mail/Settings/MailConnectionTester.php`
- Test: `backend/tests/Service/Mail/Settings/MailConnectionTesterTest.php`

**Interfaces:**
- Consumes: `ActiveMailTransportFactory::forResolved(...)`, `MailSettings::activeTransportDsnFallback()`, `HttpClientInterface`.
- Produces: tester builds the SAVED row's transport via the factory (so proxied configs are tested through the proxy); when no row exists but the env fallback is configured, it builds and tests the fallback transport instead of returning `not_configured`.

- [ ] **Step 1: Failing test — env-only fallback is actually tested**

In `MailConnectionTesterTest`, add a case with NO saved row and a non-routable SMTP fallback DSN (set `MAILER_FALLBACK_DSN` for the test, e.g. `smtp://smtp.invalid.test:2525`), an authenticated admin, and `MAIL_FROM` set:

```php
public function testItTestsTheEnvFallbackWhenNoRowIsSaved(): void
{
    // no MailServerSettings row; MAILER_FALLBACK_DSN points at an unreachable SMTP host
    $result = $this->tester->test();
    self::assertFalse($result->ok);
    self::assertNotSame('not_configured', $result->reason); // it TRIED the fallback transport
}
```

- [ ] **Step 2: Failing test — a proxied saved row is tested through the factory**

Add a case with a configured proxy + a saved mail row `useProxy=true` pointing at an unreachable proxy, asserting the reason is a transport error (not `not_configured`) — proving the tester built the curl transport.

- [ ] **Step 3: Run to confirm failure**

Run: `cd backend && php bin/phpunit --filter MailConnectionTesterTest` → FAIL (currently returns `not_configured` for env-only).

- [ ] **Step 4: Implement**

In `MailConnectionTester.php`: inject `ActiveMailTransportFactory $transportFactory` and `HttpClientInterface $httpClient`. Replace the body of `test()` so it:
- resolves `configuredTransport()`;
- if a resolved row exists → `$mailer = new Mailer($this->transportFactory->forResolved($resolved, null, $this->logger));`
- else if `envFallbackConfigured` (the fallback connection is enabled) → `$mailer = new Mailer(Transport::fromDsn($this->settings->activeTransportDsnFallback(), null, $this->httpClient, $this->logger));`
- else → `MailTestResult::failed('not_configured')`.

Keep the `no_from_address` guard and the `SecretUnreadableException` / `TransportExceptionInterface|RfcComplianceException` handling. Extract the "which transport" decision into a small private helper returning `?TransportInterface` so `test()` stays a readable sentence (guard clauses, no nesting). Determine `envFallbackConfigured` from `$this->settings` (add a tiny accessor `hasEnvFallback(): bool` on `MailSettings` returning `$this->fallback->connection()->enabled`, or reuse an existing one if present).

- [ ] **Step 5: Run both DB legs + PHPStan + commit**

Run: `cd backend && composer cs:fix && php bin/phpunit --filter MailConnectionTesterTest && composer stan` → PASS
```bash
git add backend/src/Service/Mail/Settings/MailConnectionTester.php backend/src/Service/Mail/Settings/MailSettings.php backend/tests
git commit -m "feat(#845): test the transport that would actually send"
```

---

## Phase 3 — Frontend

### Task 9: Field-error slot on `app-settings-row` + the shared invalid-input style

**Files:**
- Modify: `frontend/src/app/shared/settings/settings-row/settings-row.component.ts` (add `error` input)
- Modify: `frontend/src/app/shared/settings/settings-row/settings-row.component.html` (render error)
- Modify: `frontend/src/app/shared/settings/settings-row/settings-row.component.scss` (error style) OR `frontend/src/styles/_controls.scss` for the `aria-invalid` input style
- Test: `frontend/src/app/shared/settings/settings-row/settings-row.component.spec.ts` (create if absent)

**Interfaces:**
- Produces: `app-settings-row` accepts `[error]="string | null"`; when set it renders `<p class="error" role="alert">{{error}}</p>` after the row. Projected inputs carrying `aria-invalid="true"` get the shared invalid style. This mirrors `app-field` (`frontend/src/app/shared/field/field.component.html:22-24`) for the settings-row layout that the mail/proxy forms use.

- [ ] **Step 1: Failing spec — error renders with role=alert**

```ts
it('renders a field error with role=alert when [error] is set', () => {
  // render <app-settings-row [title]="'t'" [error]="'Bad value'">…</app-settings-row>
  const alert = fixture.nativeElement.querySelector('p.error[role="alert"]');
  expect(alert?.textContent).toContain('Bad value');
});

it('renders no error element when [error] is null', () => {
  expect(fixture.nativeElement.querySelector('p.error')).toBeNull();
});
```

- [ ] **Step 2: Run to confirm failure**

Run: `docker compose exec -T frontend npx jest settings-row`
Expected: FAIL (no `error` input).

- [ ] **Step 3: Implement**

`settings-row.component.ts`: add `readonly error = input<string | null>(null);`.
`settings-row.component.html`: after the closing `</div>` of `.row`, add:
```html
@if (error(); as text) {
  <p class="error" role="alert">{{ text }}</p>
}
```
`settings-row.component.scss`: `.error { color: var(--danger); margin: var(--space-1) 0 0; }` (use tokens only). In `frontend/src/styles/_controls.scss` (which already styles projected controls) add the invalid state for text/number inputs, e.g. `input[aria-invalid='true'] { border-color: var(--danger); }` and a matching `:focus` — tokens only, no hex.

- [ ] **Step 4: Run to green + lint + commit**

Run: `docker compose exec -T frontend npx jest settings-row && docker compose exec -T frontend npm run check`
```bash
git add frontend/src/app/shared/settings/settings-row frontend/src/styles/_controls.scss
git commit -m "feat(#845): field-error slot and invalid-input style on settings rows"
```

---

### Task 10: Mail form — per-field errors (client + server)

**Files:**
- Modify: `frontend/src/app/settings/admin/mail/mail-section.component.ts`
- Modify: `frontend/src/app/settings/admin/mail/mail-section.component.html`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/settings/admin/mail/mail-section.component.spec.ts`

**Interfaces:**
- Consumes: `app-settings-row [error]` (Task 9), `svc.failure()?.errors` (the 422 `errors` map keyed by DTO property — `fromAddress`, `port`, `host`, `username`, `password`), `core/problem.ts`.
- Produces: `fieldError(field)`, `isFieldInvalid(field)`, `clearFieldError(field)`, `validateBeforeSave(): boolean`. Field keys match the backend DTO property names exactly.

- [ ] **Step 1: Failing specs**

Add to `mail-section.component.spec.ts`:
```ts
it('marks fromAddress invalid and shows a message for a bad email on save', () => {
  // set host to something, fromAddress to 'not-an-email', click save
  // expect fieldError('fromAddress') non-null and the input aria-invalid=true
});

it('maps a 422 errors map onto the offending field', () => {
  // stub PUT to fail with problem+json { errors: { port: ['This value should be between 1 and 65535.'] } }
  // expect fieldError('port') to contain that message
});

it('clears a field error when the user edits that field', () => {
  // after a server error on 'host', typing in host clears fieldError('host')
});
```

- [ ] **Step 2: Run to confirm failure**

Run: `docker compose exec -T frontend npx jest mail-section`
Expected: FAIL.

- [ ] **Step 3: Implement the error model**

In `mail-section.component.ts`, add (mirroring `recommendation-settings-card.component.ts:283-347`):
```ts
type MailField = 'host' | 'port' | 'username' | 'fromAddress' | 'fromName' | 'password';

readonly clientErrors = signal<Partial<Record<MailField, string>>>({});
readonly dismissedServerErrors = signal<Partial<Record<MailField, true>>>({});

fieldError(field: MailField): string | null {
  const client = this.clientErrors()[field];
  if (client) return client;
  if (this.dismissedServerErrors()[field]) return null;
  return this.svc.failure()?.errors?.[field]?.join(' ') ?? null;
}
isFieldInvalid(field: MailField): boolean {
  return this.fieldError(field) !== null;
}
private clearFieldError(field: MailField): void {
  this.clientErrors.update((e) => ({ ...e, [field]: undefined }));
  this.dismissedServerErrors.update((e) => ({ ...e, [field]: true }));
}
```
Call `clearFieldError(field)` inside `onTyped`, `onPort`, `onPassword`. Add client validation invoked from `onSave`:
```ts
private validateBeforeSave(): boolean {
  const errors: Partial<Record<MailField, string>> = {};
  if (this.enabled() && this.host() === '') {
    errors.host = this.i18n.translate('settings.mail.errors.hostRequired');
  }
  if (this.fromAddress() !== '' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(this.fromAddress())) {
    errors.fromAddress = this.i18n.translate('settings.mail.errors.emailInvalid');
  }
  if (this.port() < 1 || this.port() > 65535) {
    errors.port = this.i18n.translate('settings.mail.errors.portRange');
  }
  this.clientErrors.set(errors);
  return Object.keys(errors).length === 0;
}
```
Guard `onSave()`: `if (!this.validateBeforeSave()) return;` before calling `this.svc.save(...)`.

- [ ] **Step 4: Wire the template**

In `mail-section.component.html`, add `[error]="fieldError('host')"` (etc.) to each typed `app-settings-row`, and `[attr.aria-invalid]="isFieldInvalid('host')"` to each input. Add the i18n keys under `settings.mail.errors`: `hostRequired`, `emailInvalid`, `portRange` in `en.json` and `de.json`.

- [ ] **Step 5: Run to green + lint + commit**

Run: `docker compose exec -T frontend npx jest mail-section && docker compose exec -T frontend npm run check`
```bash
git add frontend/src/app/settings/admin/mail frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#845): per-field validation errors on the mail form"
```

---

### Task 11: Mail form — "A password is saved" + Remove control

**Files:**
- Modify: `frontend/src/app/settings/admin/mail/mail-settings.service.ts` (state/body + `removePassword()`)
- Modify: `frontend/src/app/settings/admin/mail/mail-section.component.ts` / `.html` / `.scss`
- Modify: `frontend/public/i18n/en.json`, `de.json`
- Test: `mail-section.component.spec.ts`, `mail-settings.service.spec.ts`

**Interfaces:**
- Produces:
  - `MailSettingsState`: **remove** `passwordHint`; keep `hasPassword`. **Add** `useProxy: boolean`, `proxyConfigured: boolean`, `proxyLabel: string` (consumed here + Task 12).
  - `SaveMailSettings`: add `removePassword: boolean` and `useProxy: boolean`. `bodyFromState` sends `password: null, removePassword: false, useProxy: state.useProxy`.
  - `MailSettingsService.removePassword(): void` — PUTs `removePassword: true` against the saved row and commits.

- [ ] **Step 1: Failing specs**

`mail-settings.service.spec.ts`: `removePassword()` issues a PUT whose body has `removePassword: true` and commits the returned state. `mail-section.component.spec.ts`: shows the "password saved" text when `hasPassword`; the Remove button calls `svc.removePassword()`; Remove is disabled while dirty; the password placeholder no longer reads `passwordHint` (it's gone).

- [ ] **Step 2: Run to confirm failure**

Run: `docker compose exec -T frontend npx jest "mail-settings.service|mail-section"` → FAIL.

- [ ] **Step 3: Update the service contract**

`mail-settings.service.ts`: in `MailSettingsState` delete `passwordHint`, add `useProxy: boolean; proxyConfigured: boolean; proxyLabel: string;`. In `SaveMailSettings` add `removePassword: boolean;` and `useProxy: boolean;`. Update `bodyFromState`:
```ts
return { enabled: state.enabled, host: state.host, port: state.port, username: state.username,
  encryption: state.encryption, fromAddress: state.fromAddress, fromName: state.fromName,
  password: null, removePassword: false, useProxy: state.useProxy };
```
Add:
```ts
removePassword(): void {
  const current = this.state();
  if (!current) return;
  this.put({ ...this.bodyFromState(current), removePassword: true }, (state) => {
    this.commit(state);
    this.saved.set(true);
  });
}
```

- [ ] **Step 4: Update the component + template**

`mail-section.component.ts`: delete `passwordHint`; add `readonly passwordSaved = computed(() => this.svc.state()?.hasPassword ?? false);` and `removePassword() { this.svc.removePassword(); }`. `mail-section.component.html`: replace the password row's `[placeholder]="passwordHint()"` with a static hint (`settings.mail.passwordKeepHint`), and add, when `passwordSaved()`, a "A password is saved" line (`settings.mail.passwordSaved`) plus a Remove button (`settings.mail.removePassword`) that is `[disabled]="dirty()"` and calls `removePassword()`. Add i18n keys to `en.json` + `de.json`.

- [ ] **Step 5: Fix existing specs that referenced `passwordHint`**

Update the spec asserting the placeholder was the hint (mail-section.component.spec.ts around the password test): assert the input value is never the secret and that `passwordSaved` drives the saved-text instead.

- [ ] **Step 6: Run to green + lint + commit**

Run: `docker compose exec -T frontend npx jest "mail-settings.service|mail-section" && docker compose exec -T frontend npm run check`
```bash
git add frontend/src/app/settings/admin/mail frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#845): show only that a mail password is saved, with a remove control"
```

---

### Task 12: Mail form — read-only env panel + proxy checkbox

**Files:**
- Modify: `frontend/src/app/settings/admin/mail/mail-section.component.ts` / `.html` / `.scss`
- Modify: `frontend/public/i18n/en.json`, `de.json`
- Test: `mail-section.component.spec.ts`

**Interfaces:**
- Consumes: `MailSettingsState.useProxy/proxyConfigured/proxyLabel/hasSavedConfig/envFallbackConfigured` (Task 11 + Task 3 backend).
- Produces: `envManaged` view state; `overriding` signal; `useProxy` staged/instant field; `canTest` enabled in env state.

- [ ] **Step 1: Failing specs**

- With no saved row, `envFallbackConfigured=true`, `host=''`: renders the read-only "Configured by the environment" panel (a status line, from-address, from-name, a "System mail" summary), NO enable toggle, and the Test button enabled; "Override with in-app settings" reveals the editable form.
- With no saved row + SMTP env (`host='smtp.x'`): the summary shows `smtp.x:port`.
- Proxy toggle reflects `useProxy`; disabled with a hint linking to `/settings/admin/proxy` when `!proxyConfigured`; the label shows `proxyLabel` when configured; `onSave` carries `useProxy`.

- [ ] **Step 2: Run to confirm failure**

Run: `docker compose exec -T frontend npx jest mail-section` → FAIL.

- [ ] **Step 3: Implement the view state**

`mail-section.component.ts`:
```ts
readonly overriding = signal(false);
readonly envManaged = computed(() => {
  const s = this.svc.state();
  return !!s && !s.hasSavedConfig && s.envFallbackConfigured && !this.overriding();
});
readonly envTransportSummary = computed(() => {
  const s = this.svc.state();
  if (!s) return '';
  return s.host === ''
    ? this.i18n.translate('settings.mail.env.systemMail')
    : `${s.host}:${s.port} (${s.encryption})`;
});
readonly useProxy = linkedSignal<boolean>(() => this.svc.state()?.useProxy ?? false);
readonly proxyConfigured = computed(() => this.svc.state()?.proxyConfigured ?? false);
readonly proxyLabel = computed(() => this.svc.state()?.proxyLabel ?? '');
startOverride(): void { this.overriding.set(true); }
onUseProxy(value: boolean): void {
  this.useProxy.set(value);
  if (this.svc.state()?.hasSavedConfig) this.svc.saveInstant({ useProxy: value });
}
```
Change `canTest`:
```ts
readonly canTest = computed(() =>
  (this.configured() || (this.svc.state()?.envFallbackConfigured ?? false)) && !this.dirty(),
);
```
`onSave()` overrides gain `useProxy: this.useProxy()`.

- [ ] **Step 4: Implement the template**

Wrap the current editable body in `@if (envManaged()) { <read-only panel + Test row + Override button> } @else { <editable form> }`. In the editable form add a proxy toggle row (`app-toggle`, `[checked]="useProxy()"`, `[disabled]="!proxyConfigured()"`, `(toggled)="onUseProxy($event)"`), its description = `proxyConfigured() ? proxyLabel() : ('settings.mail.proxy.noneHint' | transloco)`, and when `!proxyConfigured()` a `routerLink="/settings/admin/proxy"` link (import `RouterLink`). Add i18n keys under `settings.mail.env.*` and `settings.mail.proxy.*` to `en.json` + `de.json`.

- [ ] **Step 5: Run to green + lint + commit**

Run: `docker compose exec -T frontend npx jest mail-section && docker compose exec -T frontend npm run check`
```bash
git add frontend/src/app/settings/admin/mail frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#845): read-only env mail view and proxy-routing toggle"
```

---

### Task 13: Proxy page — clarify the toggle scope and name consumers

**Files:**
- Modify: `frontend/public/i18n/en.json`, `de.json` (`settings.proxy.enabled` text + a new consumers note)
- Modify: `frontend/src/app/settings/admin/proxy/proxy-section.component.html`
- Test: `frontend/src/app/settings/admin/proxy/proxy-section.component.spec.ts`

**Interfaces:**
- Produces: the enable toggle reads "Use the proxy for feed fetching"; a note names outgoing mail as the other consumer and links to Mail settings.

- [ ] **Step 1: Failing spec**

`proxy-section.component.spec.ts`: the enable row's label text is the feed-fetching wording; a consumers note with a `routerLink="/settings/admin/mail"` is present.

- [ ] **Step 2: Run to confirm failure**

Run: `docker compose exec -T frontend npx jest proxy-section` → FAIL.

- [ ] **Step 3: Implement**

In `en.json`/`de.json`, change `settings.proxy.enabled` to the feed-fetching wording and add `settings.proxy.usedByMailNote` (e.g. "Outgoing mail can also use this proxy — turn that on in Mail settings."). In `proxy-section.component.html`, after the enable row add a note paragraph with a `routerLink="/settings/admin/mail"` (import `RouterLink` into the component). Keep it tokens-only in the sibling `.scss`.

- [ ] **Step 4: Run to green + lint + commit**

Run: `docker compose exec -T frontend npx jest proxy-section && docker compose exec -T frontend npm run check`
```bash
git add frontend/src/app/settings/admin/proxy frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#845): scope the proxy toggle to feeds and name mail as a consumer"
```

---

## Phase 4 — Docs & final verification

### Task 14: Document the field-error convention

**Files:**
- Modify: `docs/design-language.md`

- [ ] **Step 1: Add the convention**

Add a short subsection near the settings/form guidance: settings forms surface validation per field via `app-settings-row [error]` (matching `app-field`'s error slot), the input carries `[attr.aria-invalid]`, client checks and the backend 422 `errors` map (keyed by DTO property) both feed `fieldError()`, and the error clears when the user edits the field. Point to `mail-section.component.ts` as the reference. Keep it to a few sentences (one instruction per line).

- [ ] **Step 2: Commit**

```bash
git add docs/design-language.md
git commit -m "docs(#845): record the settings field-error convention"
```

---

### Task 15: Full verification before PR

- [ ] **Step 1: Backend gates (both DB legs)**

```bash
cd backend && bin/console cache:warmup && composer check && composer md && php bin/phpunit
docker compose exec -T php vendor/bin/phpunit
```
Expected: cs + stan + tramp clean; PHPMD clean on every touched `src` file; both suites green.

- [ ] **Step 2: Migration leg**

Re-run the migrate-from-empty + `doctrine:schema:validate` on SQLite (Task 1 Step 7) and, via the Docker stack, on MySQL:
```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```
Expected: both dialects migrate clean and validate in sync.

- [ ] **Step 3: Mutation gate on touched files**

```bash
cd backend && composer infection:diff
```
Expected: MSI at or above `infection.json5`'s `minMsi`. Address escaped mutants on changed lines (add assertions; do not lower the threshold). For `CurlSmtpTransport::doSend`, if a mutant escapes because the send path isn't unit-covered, strengthen `CurlSmtpOptionsTest` (the pure logic) rather than chasing the network path.

- [ ] **Step 4: Frontend gate (Docker)**

```bash
docker compose exec -T frontend npm run check
```
Expected: ESLint + Prettier + Stylelint + Jest green (this is the leg that type-checks; native `npx jest` does not).

- [ ] **Step 5: Scan the dev log**

```bash
ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 50
```
Expected: no new deprecations or swallowed errors from the mail/transport work.

- [ ] **Step 6: Self-review against the spec, then open the PR**

Re-read issue #845; confirm each of the six items maps to landed tasks. Then:
```bash
git push -u origin fix/845-admin-mail-settings-refinements
gh pr create --base develop --title "Admin mail settings refinements (#845)" --body "Closes #845" 
```
After merge, verify #845 auto-closed.

---

## Self-Review (author's pass)

**Spec coverage:** (1) hide password + drop last-4 → Tasks 1, 11; (2) explicit remove → Task 2, 11; (3) per-field errors + convention → Tasks 9, 10, 14; (4) env read-only view → Task 12; (5) test effective transport → Task 8b; (6) SMTP via egress proxy (curl) → Tasks 3–8a, 12; proxy page touch → Task 13. All six issue items are covered.

**Type consistency:** `ResolvedMailTransport(host, port, username, password, encryption, useProxy)` used identically in Tasks 4–8. `MailConnection` `useProxy` is the last param everywhere (entity, fallback, request mapping). Payload keys `useProxy/proxyConfigured/proxyLabel` defined in Task 3 (backend) and consumed in Tasks 11–12 (frontend `MailSettingsState`). `removePassword` wire field defined in Task 2, sent by the frontend in Task 11. `CurlSmtpOptions::for(...)` signature identical in Tasks 5 and 6.

**No placeholders:** every code step carries real code or an exact existing-test reference to mirror. Migration DDL is spelled out for both platforms.

**Open risk flagged for the executor:** the `phpstorm` MCP server was down at planning time — run PhpStorm inspections on changed PHP if it is back up (block on ERROR/WARNING); otherwise note it was unavailable in the PR.
