# Passkey login (#624) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a signed-in user enrol a passkey and afterwards sign in with it alone, and offer the enrolment once, on the first reader boot after this ships.

**Architecture:** `web-auth/webauthn-lib` 5.3 without its Symfony bundle. A `Service/Passkey/` namespace holds the options factories, the two verifiers, a cache-backed single-use challenge store and the credential repository wrapper. Login is a custom authenticator on its own stateless firewall, so the JWT, the status checks and the throttling come from the same code that serves password login. The relying party is admin-configured on the `instance_setting` singleton row, defaulting to the host of the public base URL.

**Tech Stack:** PHP 8.4, Symfony 7.4 LTS, Doctrine, `web-auth/webauthn-lib` ^5.3, Angular 20 signals, Transloco, Jest, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-08-29-624-passkey-login-design.md`

**Branch:** `feature/624-passkey-login` off `develop`.

## Global Constraints

- Commit message format is `type(#624): summary`. The issue number is the scope.
- Every PHP file starts `declare(strict_types=1);`. PSR-12. `final readonly class` with constructor promotion is the house style.
- Controllers hold no private method that carries responsibility. `ThinControllerRule` enforces it.
- Every `src` file you touch must be PHPMD-clean before commit, not merely free of new findings.
- No method takes more than three parameters. No boolean flag parameters on methods; a boolean field on a request DTO is fine.
- Errors are typed exceptions in `App\Service\Passkey\Exception\`. Never signal failure with `null`.
- Datetimes are stored as naive UTC. Normalise before persisting.
- Frontend: standalone components and signals, no NgModules. Component styles live in a sibling `.scss` via `styleUrl`, never inline. No hex colours and no raw `px` outside `src/app/theme/`. Prettier at 100 columns.
- Frontend tests run inside Docker: `docker compose exec -T frontend npm test`.
- Backend tests run natively on SQLite (`php bin/phpunit`) and in Docker on MySQL (`docker compose exec php vendor/bin/phpunit`). Run both legs before the PR.
- Anything running tests in parallel must set `TEST_TOKEN`.
- Every binary field crosses the wire base64url-encoded inside the JSON body, so a native iOS client can drive the same endpoints.
- `PASSKEY_RP_ID` and `PASSKEY_RP_NAME` are **not** added to `.env`. The relying party is admin-configured. See Task 3.

---

## File Structure

**Backend, created:**

| Path | Responsibility |
|---|---|
| `src/Entity/UserPasskey.php` | The credential row |
| `src/Repository/UserPasskeyRepository.php` | Lookups by credential id and by owner |
| `src/Service/Passkey/PasskeyCeremony.php` | Lazily builds the two `CeremonyStepManager`s, the serializer and the host |
| `src/Service/Passkey/PasskeyChallengeStore.php` | Opaque handle → challenge, single use, 5-minute TTL |
| `src/Service/Passkey/PasskeyChallenge.php` | The stored record (challenge, optional user id) |
| `src/Service/Passkey/RegistrationOptionsFactory.php` | Creation options |
| `src/Service/Passkey/AssertionOptionsFactory.php` | Request options |
| `src/Service/Passkey/AttestationVerifier.php` | Verify then persist |
| `src/Service/Passkey/AssertionVerifier.php` | Verify then resolve the user |
| `src/Service/Passkey/PasskeyCredentials.php` | Repository wrapper, user handles, `last_used_at` |
| `src/Service/Passkey/PasskeyRemovalPolicy.php` | Refuse the lock-out delete |
| `src/Service/Passkey/PasskeyOffer.php` | Stamp `passkey_offer_answered_at` |
| `src/Service/Passkey/Exception/*.php` | Typed failures |
| `src/Service/Settings/PasskeyRelyingParty.php` | Interface: `id()`, `name()` |
| `src/Service/Settings/ConfiguredPasskeyRelyingParty.php` | Implementation with the fallback |
| `src/Service/Settings/InstanceSettingsUpdate.php` | Value object replacing the widening parameter list |
| `src/Controller/Api/PasskeyController.php` | The five passkey routes |
| `src/Controller/Api/PasskeyOfferController.php` | `POST /api/me/passkey-offer/answer` |
| `src/Security/PasskeyAuthenticator.php` | The login authenticator |
| `src/Http/PasskeyJson.php` | Response shapes |
| `src/Dto/Passkey/*.php` | Request shapes |

**Backend, modified:** `src/Entity/Preferences.php`, `src/Entity/InstanceSetting.php`, `src/Service/Settings/InstanceSettings.php`, `src/Controller/Admin/AdminSettingsController.php`, `src/Dto/Admin/InstanceSettingsRequest.php`, `src/Http/Admin/InstanceSettingsJson.php`, `src/Http/MeJson.php`, `config/packages/security.yaml`, `config/packages/rate_limiter.yaml`, `composer.json`.

**Frontend, created:** `src/app/core/webauthn.ts`, `src/app/core/passkey.service.ts`, `src/app/settings/passkeys-group.component.{ts,html,scss}`, `src/app/reader/passkey-offer-dialog.component.{ts,html,scss}`.

**Frontend, modified:** `src/app/settings/account-section.component.html`, `src/app/auth/login/login.component.{ts,html}`, `src/app/settings/admin/admin-settings/admin-settings.component.{ts,html}`, `src/app/settings/admin/admin-settings/admin-settings-api.ts`, `src/app/core/auth.service.ts`, `src/app/reader/reader-shell.component.ts`, `public/i18n/{en,de}.json`.

---

## Task ordering and why

Task 1 installs the library and proves it loads. Task 2 lands the credential table, because four later tasks need it. Task 3 lands the relying party, because every ceremony reads it. Task 4 lands the offer flag, because Task 7 stamps it. Tasks 5–11 build the backend flows in dependency order. Tasks 12–18 build the frontend, which needs the endpoints to exist. Task 19 is the gate run.

---

### Task 1: Install the library

**Files:**
- Modify: `backend/composer.json`, `backend/composer.lock`
- Test: `backend/tests/Service/Passkey/LibraryIsInstalledTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: the `Webauthn\` namespace, autoloadable.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey;

use PHPUnit\Framework\TestCase;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

/**
 * Cheap, and it earns its place: the ceremony managers are built lazily at
 * runtime (see PasskeyCeremony), so a missing or moved library class would
 * otherwise first surface as a 500 during a real sign-in.
 */
final class LibraryIsInstalledTest extends TestCase
{
    public function testTheCeremonyStepManagerFactoryIsAutoloadable(): void
    {
        self::assertTrue(class_exists(CeremonyStepManagerFactory::class));
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd backend && php bin/phpunit tests/Service/Passkey/LibraryIsInstalledTest.php`
Expected: FAIL — `Webauthn\CeremonyStep\CeremonyStepManagerFactory` not found.

- [ ] **Step 3: Install**

```bash
cd backend && composer require web-auth/webauthn-lib:^5.3
```

The global gitignore hides `composer.lock`; this repo re-includes it with `!/composer.lock`. Keep it committed.

- [ ] **Step 4: Run it and watch it pass**

Run: `cd backend && php bin/phpunit tests/Service/Passkey/LibraryIsInstalledTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/composer.json backend/composer.lock backend/tests/Service/Passkey/LibraryIsInstalledTest.php
git commit -m "build(#624): add web-auth/webauthn-lib"
```

---

### Task 2: The `UserPasskey` entity, its migration and the backup declaration

**Files:**
- Create: `backend/src/Entity/UserPasskey.php`, `backend/src/Repository/UserPasskeyRepository.php`, `backend/migrations/VersionYYYYMMDDHHMMSS.php`
- Modify: `backend/tests/Service/Backup/BackupSchemaCoverageTest.php`
- Test: `backend/tests/Entity/UserPasskeyTest.php`

**Interfaces:**
- Consumes: `App\Entity\User`.
- Produces:
  - `UserPasskey::__construct(User $user, string $credentialId, string $userHandle, string $publicKey, int $signatureCounter, ?string $aaguid, array $transports, string $label, \DateTimeImmutable $createdAt)`
  - Getters for every field, plus `getUser(): User`, `getId(): ?int`, `getLastUsedAt(): ?\DateTimeImmutable`.
  - `recordUse(\DateTimeImmutable $at, int $signatureCounter): void`
  - `UserPasskeyRepository::findOneByCredentialId(string $credentialId): ?UserPasskey`
  - `UserPasskeyRepository::findForUser(User $user): list<UserPasskey>`
  - `UserPasskeyRepository::findOneForUser(User $user, int $id): ?UserPasskey` — the owner-scoped lookup Task 8's delete uses. One query, so there is no "fetch then compare owner" step to get wrong.
  - `UserPasskeyRepository::countForUser(User $user): int`
  - `UserPasskeyRepository::countAll(): int`
  - `UserPasskeyRepository::deleteAll(): void`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\UserPasskey;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;

final class UserPasskeyTest extends DbTestCase
{
    public function testACredentialRoundTripsThroughTheDatabase(): void
    {
        $user = (new UserFactory($this->em))->create('passkey-owner@example.test');
        $passkey = new UserPasskey(
            $user,
            'Y3JlZC1hYmM',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            '00000000-0000-0000-0000-000000000000',
            ['internal', 'hybrid'],
            'MacBook Touch ID',
            new \DateTimeImmutable('2026-08-29 10:00:00'),
        );
        $this->em->persist($passkey);
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->getRepository(UserPasskey::class)->findOneByCredentialId('Y3JlZC1hYmM');

        self::assertNotNull($found);
        self::assertSame(['internal', 'hybrid'], $found->getTransports());
        self::assertNull($found->getLastUsedAt());
    }

    /**
     * The whole reason credential_id is pinned to utf8mb4_bin. Without the
     * pin this passes on SQLite and fails on MySQL, which is exactly the
     * split that bit user_identity.provider_user_id.
     */
    public function testACredentialIdIsComparedCaseSensitively(): void
    {
        $user = (new UserFactory($this->em))->create('case-owner@example.test');
        $this->em->persist(new UserPasskey(
            $user, 'Sub-ABC', 'aGFuZGxl', 'cHVibGljLWtleQ', 0, null, [], 'Key', new \DateTimeImmutable(),
        ));
        $this->em->flush();
        $this->em->clear();

        $repository = $this->em->getRepository(UserPasskey::class);

        self::assertNotNull($repository->findOneByCredentialId('Sub-ABC'));
        self::assertNull($repository->findOneByCredentialId('sub-abc'));
    }
}
```

Check the real name and constructor of the user factory in `backend/tests/Support/` before writing this; `ApiTestCase::factory()` exposes it. Match what is there rather than the sketch above.

- [ ] **Step 2: Run it and watch it fail**

Run: `cd backend && php bin/phpunit tests/Entity/UserPasskeyTest.php`
Expected: FAIL — `App\Entity\UserPasskey` not found.

- [ ] **Step 3: Write the entity**

Follow `src/Entity/UserIdentity.php` for the shape and for the collation docblock. The columns are in spec §4.1. Two points the entity must carry as comments, because both were paid for once already:

- `credential_id` gets `options: ['collation' => 'utf8mb4_bin']` with a docblock explaining that a credential id is an opaque token where `a` and `A` are different identifiers. Cross-reference `UserIdentity::$providerUserId`.
- `user_handle` gets the same collation and a docblock pointing at spec §4.1.1: it is 32 random bytes, it is not the e-mail because the authenticator syncs it, and it is not the account id because that leaks the instance's account count.

Add `#[ORM\UniqueConstraint(name: 'uniq_passkey_credential_id', columns: ['credential_id'])]`.

`recordUse()` is the only mutator. It sets `lastUsedAt` and `signatureCounter` together, because a use that updated one without the other would be a half-written row.

- [ ] **Step 4: Write the repository**

Extends `ServiceEntityRepository<UserPasskey>`. `findForUser` orders by `createdAt ASC` so the list is stable. Give every method a PHPStan generic annotation; level max requires them.

- [ ] **Step 5: Run it and watch it pass**

Run: `cd backend && php bin/phpunit tests/Entity/UserPasskeyTest.php`
Expected: PASS.

- [ ] **Step 6: Generate and check the migration**

```bash
cd backend && php bin/console doctrine:migrations:diff --no-interaction
```

Open the generated file. Confirm the `up()` names the collation on both `credential_id` and `user_handle`, and that `down()` drops the table. A generated migration is a draft, not an answer.

- [ ] **Step 7: Prove the migration runs from empty on both engines**

```bash
cd backend && php bin/console doctrine:migrations:migrate --no-interaction && php bin/console doctrine:schema:validate
```

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec php bin/console doctrine:schema:validate
```

Expected: both clean. `tests/bootstrap.php` builds the schema from ORM metadata, so no test ever executes a migration — this manual check is the only thing standing between a broken migration and a green suite.

- [ ] **Step 8: Satisfy the backup drift guard**

Run: `cd backend && php bin/phpunit tests/Service/Backup/BackupSchemaCoverageTest.php`
Expected: FAIL, naming `UserPasskey` as undeclared.

Add it to `ACCOUNT_SCOPED_WHOLLY_DROPPED` — read the file to confirm that constant's exact name before editing — with this reason:

```php
UserPasskey::class => 'Passkeys are bound to a device and to a relying-party id, so a '
    . 'credential restored into another account or onto another device could never '
    . 'authenticate. Exporting credential ids and public keys would widen the blast '
    . 'radius of a leaked backup file for no gain.',
```

Then update the backup documentation row the guard cross-checks; the failure message names the file and section.

Re-run: PASS.

- [ ] **Step 9: Commit**

```bash
git add backend/src/Entity/UserPasskey.php backend/src/Repository/UserPasskeyRepository.php backend/migrations backend/tests
git commit -m "feat(#624): add the user_passkey credential table"
```

---

### Task 3: The admin-configured relying party

**Files:**
- Create: `backend/src/Service/Settings/PasskeyRelyingParty.php`, `backend/src/Service/Settings/ConfiguredPasskeyRelyingParty.php`, `backend/src/Service/Settings/InstanceSettingsUpdate.php`, `backend/migrations/VersionYYYYMMDDHHMMSS.php`
- Modify: `backend/src/Entity/InstanceSetting.php`, `backend/src/Service/Settings/InstanceSettings.php`, `backend/src/Dto/Admin/InstanceSettingsRequest.php`, `backend/src/Http/Admin/InstanceSettingsJson.php`, `backend/src/Controller/Admin/AdminSettingsController.php`
- Test: `backend/tests/Service/Settings/ConfiguredPasskeyRelyingPartyTest.php`, `backend/tests/Controller/Admin/AdminSettingsControllerTest.php`

**Interfaces:**
- Consumes: `PublicBaseUrl::get(): string`, `UserPasskeyRepository::countAll()`, `UserPasskeyRepository::deleteAll()`.
- Produces:
  - `interface PasskeyRelyingParty { public function id(): string; public function name(): string; }`
  - `final readonly class InstanceSettingsUpdate { public function __construct(public bool $requireEmailConfirmation, public bool $requireApproval, public ?string $publicBaseUrl, public ?string $passkeyRpId, public ?string $passkeyRpName) {} }`
  - `InstanceSettings::update(InstanceSettingsUpdate $update): void`
  - `InstanceSettings::getPasskeyRpId(): ?string`, `getPasskeyRpName(): ?string`
  - `/api/admin/settings` gains `passkeyRpId`, `passkeyRpName` and `passkeyRpIdEffective`. The third is the value the server would use right now, stored or derived. Task 16 renders it, so an admin who leaves the field empty can see what they are getting.

- [ ] **Step 1: Write the failing fallback test**

```php
public function testTheRelyingPartyIdDefaultsToThePublicBaseUrlHost(): void
{
    $relyingParty = new ConfiguredPasskeyRelyingParty(
        $this->settingsReturning(passkeyRpId: null, passkeyRpName: null),
        $this->publicBaseUrlOf('https://lars-pohlmann.de/reader'),
    );

    self::assertSame('lars-pohlmann.de', $relyingParty->id());
    self::assertSame('Simple Feed Reader', $relyingParty->name());
}

public function testAConfiguredRelyingPartyIdWins(): void
{
    $relyingParty = new ConfiguredPasskeyRelyingParty(
        $this->settingsReturning(passkeyRpId: 'example.test', passkeyRpName: 'My Reader'),
        $this->publicBaseUrlOf('https://lars-pohlmann.de/reader'),
    );

    self::assertSame('example.test', $relyingParty->id());
    self::assertSame('My Reader', $relyingParty->name());
}

/** The /reader subpath is irrelevant to a relying-party id — spec §3.6. */
public function testTheSubpathIsStrippedFromTheDerivedId(): void
{
    $relyingParty = new ConfiguredPasskeyRelyingParty(
        $this->settingsReturning(passkeyRpId: null, passkeyRpName: null),
        $this->publicBaseUrlOf('http://localhost:4200/reader'),
    );

    self::assertSame('localhost', $relyingParty->id());
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd backend && php bin/phpunit tests/Service/Settings/ConfiguredPasskeyRelyingPartyTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Add the two columns and the service**

Add `passkey_rp_id` (string 255, nullable) and `passkey_rp_name` (string 100, nullable) to `InstanceSetting`, with a docblock modelled on the `publicBaseUrl` one: null means "no override", and the override exists because an RP id is baked into every credential.

`ConfiguredPasskeyRelyingParty` implements `PasskeyRelyingParty`. `id()` returns the stored value, or `parse_url(PublicBaseUrl::get(), PHP_URL_HOST)`. `name()` returns the stored value or the literal `Simple Feed Reader`.

Wire the interface to the implementation in `config/services.yaml`, following how `PublicBaseUrl` is bound.

- [ ] **Step 4: Run it and watch it pass**

Run: `cd backend && php bin/phpunit tests/Service/Settings/ConfiguredPasskeyRelyingPartyTest.php`
Expected: PASS.

- [ ] **Step 5: Do the parameter-list refactor before widening anything**

`InstanceSettings::update` currently takes three scalars and would take five. CLAUDE.md puts the line at three. Introduce `InstanceSettingsUpdate` and change the signature to take it. Update `AdminSettingsController::update` and every existing caller and test.

Run the existing admin settings tests and keep them green: `cd backend && php bin/phpunit tests/Controller/Admin/AdminSettingsControllerTest.php`

- [ ] **Step 6: Write the failing validation and guard tests**

```php
public function testAnRelyingPartyIdThatIsNotASuffixOfTheHostIsRefused(): void
{
    $client = $this->adminClient();
    $client->jsonRequest('PUT', '/api/admin/settings', [
        'requireEmailConfirmation' => true,
        'requireApproval' => true,
        'publicBaseUrl' => 'https://lars-pohlmann.de',
        'passkeyRpId' => 'evil.test',
        'passkeyRpName' => 'Reader',
    ]);

    self::assertResponseStatusCodeSame(422);
}

public function testChangingTheRelyingPartyIdIsRefusedWhileCredentialsExist(): void
{
    $this->givenAnEnrolledPasskey();
    $client = $this->adminClient();

    $client->jsonRequest('PUT', '/api/admin/settings', [
        'requireEmailConfirmation' => true,
        'requireApproval' => true,
        'publicBaseUrl' => 'https://lars-pohlmann.de',
        'passkeyRpId' => 'lars-pohlmann.de',
        'passkeyRpName' => 'Reader',
    ]);

    self::assertResponseStatusCodeSame(409);
    self::assertSame(1, json_decode(
        $client->getResponse()->getContent(), true,
    )['invalidatedPasskeyCount']);
}

public function testAConfirmedChangeDeletesEveryCredential(): void
{
    $this->givenAnEnrolledPasskey();
    $client = $this->adminClient();

    $client->jsonRequest('PUT', '/api/admin/settings', [
        'requireEmailConfirmation' => true,
        'requireApproval' => true,
        'publicBaseUrl' => 'https://lars-pohlmann.de',
        'passkeyRpId' => 'lars-pohlmann.de',
        'passkeyRpName' => 'Reader',
        'invalidateExistingPasskeys' => true,
    ]);

    self::assertResponseIsSuccessful();
    self::assertSame(0, $this->passkeys()->countAll());
}
```

Note the third test asserts through a fresh repository read, not through an entity handle. After a bulk DELETE, `find()` serves the stale identity map.

Only a *change* is guarded. A PUT that resends the same RP id must pass with credentials present, or the admin could never edit the other three fields. Add a test for that too.

- [ ] **Step 7: Run them and watch them fail**

Run: `cd backend && php bin/phpunit tests/Controller/Admin/AdminSettingsControllerTest.php`
Expected: FAIL — 200 where 422 and 409 are wanted.

- [ ] **Step 8: Implement the guard and the validation**

Add `passkeyRpId`, `passkeyRpName` and `invalidateExistingPasskeys` (defaulting to `false`) to `InstanceSettingsRequest`. Keep the class docblock's full-replace warning accurate; extend it to name the new fields.

The suffix check and the 409 belong in a service, not in the controller — `ThinControllerRule` will say so. Put both in `App\Service\Settings\RelyingPartyChange`, which the controller calls before `InstanceSettings::update`. It throws a typed 422 exception and a typed 409 exception, and it performs the delete when the change is confirmed.

Add `passkeyRpId`, `passkeyRpName` and `passkeyRpIdEffective` to `InstanceSettingsJson::from`. The third comes from `PasskeyRelyingParty::id()`, so the payload always carries the value the server would actually use. Add a test that it reports the derived host when the stored value is null, and the stored value when it is set.

- [ ] **Step 9: Run them and watch them pass, then migrate**

Run: `cd backend && php bin/phpunit tests/Controller/Admin tests/Service/Settings`
Expected: PASS.

```bash
cd backend && php bin/console doctrine:migrations:diff --no-interaction
```

Review it, then run the two-engine check from Task 2 Step 7.

`InstanceSetting` is already in the guard's `INSTANCE_SCOPED` list, so the backup drift guard stays quiet here. Confirm by running `tests/Service/Backup/BackupSchemaCoverageTest.php` and seeing it pass unchanged.

- [ ] **Step 10: Commit**

```bash
git add backend/src backend/migrations backend/tests backend/config
git commit -m "feat(#624): make the passkey relying party admin-configured"
```

---

### Task 4: The offer flag

**Files:**
- Create: `backend/src/Service/Passkey/PasskeyOffer.php`, `backend/src/Controller/Api/PasskeyOfferController.php`, `backend/migrations/VersionYYYYMMDDHHMMSS.php`
- Modify: `backend/src/Entity/Preferences.php`, `backend/src/Http/MeJson.php`, `backend/tests/Support/BackupFieldDeclarations.php`
- Test: `backend/tests/Controller/Api/PasskeyOfferControllerTest.php`

**Interfaces:**
- Consumes: `App\Entity\User`.
- Produces:
  - `Preferences::getPasskeyOfferAnsweredAt(): ?\DateTimeImmutable`, `Preferences::markPasskeyOfferAnswered(\DateTimeImmutable $at): void`
  - `PasskeyOffer::markAnswered(User $user): void` — idempotent; it does not move an existing timestamp.
  - `/api/me` gains `preferences.passkeyOfferAnswered: bool`.

- [ ] **Step 1: Write the failing test**

```php
public function testTheProfileReportsTheOfferUnanswered(): void
{
    $client = static::createClient();
    $this->factory()->create('offer-reader@example.test');
    $this->authenticate($client, 'offer-reader@example.test');

    $client->request('GET', '/api/me');

    self::assertFalse(
        json_decode($client->getResponse()->getContent(), true)['preferences']['passkeyOfferAnswered'],
    );
}

public function testAnsweringTheOfferIsRecordedAndIdempotent(): void
{
    $client = static::createClient();
    $this->factory()->create('offer-reader@example.test');
    $this->authenticate($client, 'offer-reader@example.test');

    $client->request('POST', '/api/me/passkey-offer/answer');
    self::assertResponseStatusCodeSame(204);

    $client->request('POST', '/api/me/passkey-offer/answer');
    self::assertResponseStatusCodeSame(204);

    $client->request('GET', '/api/me');
    self::assertTrue(
        json_decode($client->getResponse()->getContent(), true)['preferences']['passkeyOfferAnswered'],
    );
}

public function testAnAnonymousCallerCannotAnswerTheOffer(): void
{
    static::createClient()->request('POST', '/api/me/passkey-offer/answer');

    self::assertResponseStatusCodeSame(401);
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `cd backend && php bin/phpunit tests/Controller/Api/PasskeyOfferControllerTest.php`
Expected: FAIL — 404 on the POST, missing key on the GET.

- [ ] **Step 3: Implement**

Add the nullable `passkey_offer_answered_at` column to `Preferences`. Add `passkeyOfferAnswered => null !== $preferences->getPasskeyOfferAnsweredAt()` inside the `preferences` block of `MeJson::profile`.

`PasskeyOffer::markAnswered` returns early when the timestamp is already set, so a second call does not move it. Inject `ClockInterface`, not `new \DateTimeImmutable()`, so the test can pin the clock.

`PasskeyOfferController` is a `final readonly class` with one action. It does not go on `MeController`, whose constructor already carries eight dependencies.

**Do not** add the flag to `UpdatePreferencesRequest`. That DTO gives its field no default on purpose — read its docblock. Spec §5.1 records the reasoning.

- [ ] **Step 4: Run them and watch them pass**

Run: `cd backend && php bin/phpunit tests/Controller/Api/PasskeyOfferControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Satisfy the backup drift guard**

Run: `cd backend && php bin/phpunit tests/Service/Backup/BackupSchemaCoverageTest.php`
Expected: FAIL — `Preferences::$passkeyOfferAnsweredAt` undeclared.

Add it to `NOT_BACKED_UP` with this reason:

```php
'passkeyOfferAnsweredAt' => 'Interface state, not account configuration: it records that the '
    . 'one-time enrolment offer was shown and answered. A restore into a fresh account should '
    . 'let that account see the offer.',
```

Add the matching documentation row the guard cross-checks. Re-run: PASS.

- [ ] **Step 6: Migrate and verify on both engines**

Generate the migration, review it, then run the two-engine check from Task 2 Step 7.

- [ ] **Step 7: Commit**

```bash
git add backend/src backend/migrations backend/tests
git commit -m "feat(#624): record whether the passkey offer was answered"
```

---

### Task 5: `PasskeyCeremony` and `PasskeyChallengeStore`

**Files:**
- Create: `backend/src/Service/Passkey/PasskeyCeremony.php`, `backend/src/Service/Passkey/PasskeyChallengeStore.php`, `backend/src/Service/Passkey/PasskeyChallenge.php`, `backend/src/Service/Passkey/Exception/UnknownChallengeException.php`
- Test: `backend/tests/Service/Passkey/PasskeyChallengeStoreTest.php`, `backend/tests/Service/Passkey/PasskeyCeremonyTest.php`

**Interfaces:**
- Consumes: `PasskeyRelyingParty`, `PublicBaseUrl`, `CacheItemPoolInterface`, `ClockInterface`.
- Produces:
  - `PasskeyChallenge` — `final readonly class` with `public string $challenge` and `public ?int $userId`.
  - `PasskeyChallengeStore::issue(string $challenge, ?int $userId): string` returns the handle.
  - `PasskeyChallengeStore::consume(string $handle): PasskeyChallenge` throws `UnknownChallengeException`.
  - `PasskeyCeremony::creation(): CeremonyStepManager`
  - `PasskeyCeremony::request(): CeremonyStepManager`
  - `PasskeyCeremony::serializer(): SerializerInterface`
  - `PasskeyCeremony::host(): string`

- [ ] **Step 1: Write the failing challenge-store tests**

```php
public function testAHandleIsRedeemedExactlyOnce(): void
{
    $store = $this->store();
    $handle = $store->issue('a-challenge', userId: 7);

    $record = $store->consume($handle);
    self::assertSame('a-challenge', $record->challenge);
    self::assertSame(7, $record->userId);

    $this->expectException(UnknownChallengeException::class);
    $store->consume($handle);
}

public function testAnExpiredHandleIsRefused(): void
{
    $clock = new MockClock('2026-08-29 10:00:00');
    $store = $this->store($clock);
    $handle = $store->issue('a-challenge', userId: null);

    $clock->modify('+6 minutes');

    $this->expectException(UnknownChallengeException::class);
    $store->consume($handle);
}

public function testAnUnknownHandleIsRefused(): void
{
    $this->expectException(UnknownChallengeException::class);
    $this->store()->consume('never-issued');
}

/**
 * For the five minutes a handle is live it is a bearer credential, so a
 * readable cache directory must not be a list of usable ones. Same reasoning
 * as OAuthStateStore.
 */
public function testTheHandleItselfIsNotTheCacheKey(): void
{
    $pool = new ArrayAdapter();
    $handle = $this->store(pool: $pool)->issue('a-challenge', userId: null);

    self::assertFalse($pool->hasItem($handle));
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `cd backend && php bin/phpunit tests/Service/Passkey/PasskeyChallengeStoreTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement the store**

Model it on `src/Service/OAuth/OAuthStateStore.php`. The handle is 32 random bytes, base64url-encoded. The cache key is `'passkey_challenge_' . hash('sha256', $handle)`. TTL is 300 seconds. `consume()` deletes before validating, so an expired entry is burned rather than left to retry.

Copy the single-use caveat from `OAuthStateStore`'s docblock, in your own words: PSR-6 has no compare-and-swap, so two simultaneous callbacks can both see the hit. The delete-first ordering narrows the window; it does not close it.

- [ ] **Step 4: Run them and watch them pass**

Run: `cd backend && php bin/phpunit tests/Service/Passkey/PasskeyChallengeStoreTest.php`
Expected: PASS.

- [ ] **Step 5: Write the failing ceremony test**

```php
/**
 * The origin comes from PublicBaseUrl, which reads the database, so the
 * managers cannot be built when the container is compiled. This test is what
 * stops somebody "simplifying" them into constructor-time state.
 */
public function testTheCeremonyManagersAreBuiltFromTheRuntimeOrigin(): void
{
    $ceremony = new PasskeyCeremony(
        $this->relyingPartyOf('lars-pohlmann.de'),
        $this->publicBaseUrlOf('https://lars-pohlmann.de/reader'),
    );

    self::assertSame('lars-pohlmann.de', $ceremony->host());
    self::assertInstanceOf(CeremonyStepManager::class, $ceremony->creation());
    self::assertInstanceOf(CeremonyStepManager::class, $ceremony->request());
}

public function testTheManagersAreBuiltOnceAndReused(): void
{
    $ceremony = new PasskeyCeremony(
        $this->relyingPartyOf('localhost'),
        $this->publicBaseUrlOf('http://localhost:4200'),
    );

    self::assertSame($ceremony->creation(), $ceremony->creation());
}
```

- [ ] **Step 6: Run it and watch it fail, then implement**

`PasskeyCeremony` is `final class`, not `final readonly` — it memoises. Build with:

```php
$factory = new CeremonyStepManagerFactory();
$factory->setAllowedOrigins([$this->publicBaseUrl->get()]);
$this->creation = $factory->creationCeremony();
$this->request = $factory->requestCeremony();
```

The serializer comes from `new WebauthnSerializerFactory(AttestationStatementSupportManager::create())` — confirm that static exists in the installed version; the vendor source is the authority, not this plan.

`host()` returns `parse_url($this->publicBaseUrl->get(), PHP_URL_HOST)`.

Add a docblock recording that `CheckCounter` (wired to `ThrowExceptionIfInvalid`) and `CheckUserVerification` come from the library, so nobody re-implements the counter rule a second time.

- [ ] **Step 7: Run both test files and watch them pass, then commit**

```bash
git add backend/src/Service/Passkey backend/tests/Service/Passkey
git commit -m "feat(#624): add the passkey ceremony seam and challenge store"
```

---

### Task 6: Registration options

**Files:**
- Create: `backend/src/Service/Passkey/RegistrationOptionsFactory.php`, `backend/src/Service/Passkey/PasskeyCredentials.php`, `backend/src/Controller/Api/PasskeyController.php`, `backend/src/Http/PasskeyJson.php`
- Modify: `backend/config/packages/security.yaml`
- Test: `backend/tests/Controller/Api/PasskeyRegistrationTest.php`

**Interfaces:**
- Consumes: `PasskeyCeremony`, `PasskeyChallengeStore`, `PasskeyRelyingParty`, `UserPasskeyRepository`.
- Produces:
  - `PasskeyCredentials::userHandleFor(User $user): string`
  - `PasskeyCredentials::excludeListFor(User $user): list<PublicKeyCredentialDescriptor>`
  - `RegistrationOptionsFactory::create(User $user): array{options: array<string,mixed>, handle: string}`

- [ ] **Step 1: Write the failing tests**

```php
public function testTheOptionsCarryTheRelyingPartyAndRequireUserVerification(): void
{
    $client = static::createClient();
    $this->factory()->create('enroller@example.test');
    $this->authenticate($client, 'enroller@example.test');

    $client->request('POST', '/api/auth/passkey/register/options');

    self::assertResponseIsSuccessful();
    $body = json_decode($client->getResponse()->getContent(), true);
    self::assertSame('localhost', $body['options']['rp']['id']);
    self::assertSame('required', $body['options']['authenticatorSelection']['userVerification']);
    self::assertSame('required', $body['options']['authenticatorSelection']['residentKey']);
    self::assertNotEmpty($body['handle']);
}

/**
 * The three enrolment paths sit under ^/api/auth/, which access_control
 * already makes PUBLIC_ACCESS, and the first match wins. Without an explicit
 * rule above that line these endpoints are open. The rules are prefix
 * matches, which is exactly the kind of thing that is right in review and
 * wrong in production.
 */
public function testEveryEnrolmentPathRejectsAnAnonymousCaller(): void
{
    foreach ([
        ['POST', '/api/auth/passkey/register/options'],
        ['POST', '/api/auth/passkey/register'],
        ['GET', '/api/auth/passkeys'],
        ['DELETE', '/api/auth/passkeys/1'],
    ] as [$method, $path]) {
        static::createClient()->request($method, $path);
        self::assertResponseStatusCodeSame(401, sprintf('%s %s must require a bearer token', $method, $path));
    }
}

public function testTheExcludeListNamesTheCallersExistingCredentials(): void
{
    $client = static::createClient();
    $user = $this->factory()->create('enroller@example.test');
    $this->givenAPasskeyFor($user, credentialId: 'Y3JlZC1hYmM');
    $this->authenticate($client, 'enroller@example.test');

    $client->request('POST', '/api/auth/passkey/register/options');

    $body = json_decode($client->getResponse()->getContent(), true);
    self::assertSame(['Y3JlZC1hYmM'], array_column($body['options']['excludeCredentials'], 'id'));
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `cd backend && php bin/phpunit tests/Controller/Api/PasskeyRegistrationTest.php`
Expected: FAIL — 404 on the route, and the anonymous test fails with 404 rather than 401.

- [ ] **Step 3: Add the access_control rules**

In `config/packages/security.yaml`, **above** the `^/api/auth/` line:

```yaml
        # Both sit under ^/api/auth/, which the next rule makes public, and the
        # first match wins — so without these two the enrolment endpoints are
        # open to anyone. The first also covers /register/options; the second
        # also covers /passkeys/{id}. Neither matches /passkey/login, which is
        # public on purpose (the flow is discoverable-credential only).
        - { path: ^/api/auth/passkey/register, roles: IS_AUTHENTICATED_FULLY }
        - { path: ^/api/auth/passkeys, roles: IS_AUTHENTICATED_FULLY }
```

- [ ] **Step 4: Implement the options factory and the controller action**

`PasskeyCredentials::userHandleFor` returns the handle from any existing credential of that user, or mints 32 random bytes base64url-encoded when there is none. This is the single place a handle is created — spec §4.1.1.

`RegistrationOptionsFactory` builds `PublicKeyCredentialCreationOptions` with `PublicKeyCredentialRpEntity` from `PasskeyRelyingParty`, a `PublicKeyCredentialUserEntity` whose `id` is the user handle and whose `name`/`displayName` are the e-mail, `AuthenticatorSelectionCriteria` with `residentKey: required` and `userVerification: required`, attestation `none`, and the exclude list. It serialises through `PasskeyCeremony::serializer()`, issues the challenge to the store, and returns both.

The controller action reads the user, delegates, and returns a `JsonResponse`. Nothing else. `ThinControllerRule` runs in `composer stan`.

- [ ] **Step 5: Run them and watch them pass**

Run: `cd backend && php bin/phpunit tests/Controller/Api/PasskeyRegistrationTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/src backend/config backend/tests
git commit -m "feat(#624): issue passkey registration options"
```

---

### Task 7: Registration verification

**Files:**
- Create: `backend/src/Service/Passkey/AttestationVerifier.php`, `backend/src/Dto/Passkey/RegisterPasskeyRequest.php`, `backend/src/Service/Passkey/Exception/AttestationRejectedException.php`
- Modify: `backend/src/Controller/Api/PasskeyController.php`
- Test: `backend/tests/Controller/Api/PasskeyRegistrationTest.php`, `backend/tests/Service/Passkey/AttestationVerifierTest.php`

**Interfaces:**
- Consumes: `PasskeyCeremony`, `PasskeyChallengeStore`, `PasskeyCredentials`, `PasskeyOffer`.
- Produces: `AttestationVerifier::verifyAndStore(User $user, RegisterPasskeyRequest $request): UserPasskey`

- [ ] **Step 1: Build the fixture helper first**

Create `backend/tests/Support/PasskeyFixtures.php`.

**This is the one step of this plan that cannot be handed to you as literal content.** A valid attestation is a real signature over a real challenge; it cannot be written by hand and it cannot be invented. You have to capture one. Do that first, before writing any test in this task, because every case below is built on it.

Capture recipe, using the Chrome DevTools Protocol virtual authenticator:

```js
// In a Playwright script against the running stack.
const cdp = await context.newCDPSession(page)
await cdp.send('WebAuthn.enable')
const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
  options: {
    protocol: 'ctap2',
    transport: 'internal',
    hasResidentKey: true,
    hasUserVerification: true,
    isUserVerified: true,
    automaticPresenceSimulation: true,
  },
})
```

Then drive a real enrolment against a fixed, known challenge and log what the browser hands `navigator.credentials.create`. Save the base64url `clientDataJSON`, `attestationObject` and `rawId`, plus the exact challenge they were signed against and the origin and RP id in force at the time. Repeat for `navigator.credentials.get` to get the assertion pair.

Record in the file's docblock: where the fixture came from, which origin and RP id it is bound to, and that changing either invalidates it. A future reader will otherwise spend an afternoon on a fixture that fails for a reason the file could have named.

The helper exposes the raw payload and the exact challenge it was signed against, so a test can seed the challenge store with that challenge and then post the payload.

The origin-mismatch and RP-id-mismatch cases below reuse the same fixture and change the configured relying party instead of the payload. That is cheaper than capturing a second fixture and it tests the same boundary.

- [ ] **Step 2: Write the failing tests**

Cover, each as its own named test:

- A valid attestation stores a credential, and the response lists it.
- A valid attestation stamps `passkey_offer_answered_at`, so a user who enrols from Settings is never offered — spec §5.2.
- A replayed handle is rejected (consume it once, then post again) → 400.
- An expired handle is rejected → 400.
- A handle issued for a different user is rejected → 403. This is the check that keeps a registration challenge bound to its owner.
- A tampered `clientDataJSON` is rejected → 400.
- An origin mismatch is rejected → 400.
- An RP-id mismatch is rejected → 400.
- A blank `label` is rejected → 422.

Assert on status codes and on `application/problem+json`, never on exception classes: the point is the wiring.

- [ ] **Step 3: Run them and watch them fail**

Run: `cd backend && php bin/phpunit tests/Controller/Api/PasskeyRegistrationTest.php`
Expected: FAIL — 404 on the route.

- [ ] **Step 4: Implement**

`RegisterPasskeyRequest` carries `handle`, `credential` (the raw client JSON) and `label` (not blank, max 100).

`AttestationVerifier::verifyAndStore`:

1. `consume()` the handle; a mismatch between the record's `userId` and the caller is a typed 403.
2. Deserialise the credential through `PasskeyCeremony::serializer()`.
3. `AuthenticatorAttestationResponseValidator::create($ceremony->creation())->check($response, $options, $ceremony->host())`. Rebuild `$options` from the stored challenge, not from the client.
4. Map the returned `CredentialRecord` onto a `UserPasskey` and persist.
5. Call `PasskeyOffer::markAnswered($user)`.

Wrap the library's exceptions in `AttestationRejectedException`. Never let a library exception reach the kernel; the problem+json contract is ours.

- [ ] **Step 5: Run them and watch them pass, then commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#624): verify and store a passkey attestation"
```

---

### Task 8: List and delete, with the lock-out guard

**Files:**
- Create: `backend/src/Service/Passkey/PasskeyRemovalPolicy.php`, `backend/src/Service/Passkey/Exception/LastSignInMethodException.php`
- Modify: `backend/src/Controller/Api/PasskeyController.php`, `backend/src/Http/PasskeyJson.php`
- Test: `backend/tests/Service/Passkey/PasskeyRemovalPolicyTest.php`, `backend/tests/Controller/Api/PasskeyListTest.php`

**Interfaces:**
- Consumes: `UserPasskeyRepository`, `UserIdentityRepository`.
- Produces: `PasskeyRemovalPolicy::guardRemoval(User $user, UserPasskey $passkey): void` throws `LastSignInMethodException`.

- [ ] **Step 1: Write the failing policy tests**

The truth table, one test each:

| Passkeys | Password | OAuth identity | Delete allowed |
|---|---|---|---|
| 2 | no | no | yes — another passkey remains |
| 1 | yes | no | yes |
| 1 | no | yes | yes |
| 1 | no | no | **no** |

The last row is the acceptance criterion. `User::getPasswordHash()` is nullable, so "has a password" is not the same question as "has an account" — write the test for an OAuth-only account explicitly, with a `null` password hash.

- [ ] **Step 2: Run them and watch them fail, then implement the policy**

`guardRemoval` returns early when `countForUser($user) > 1`. Otherwise it throws unless the user has a password hash or at least one `UserIdentity`.

- [ ] **Step 3: Write the failing endpoint tests**

- `GET /api/auth/passkeys` lists only the caller's credentials, with `id`, `label`, `createdAt`, `lastUsedAt`, and never the public key.
- A user cannot see another user's credential in that list.
- `DELETE /api/auth/passkeys/{id}` removes one and returns 204.
- Deleting a credential owned by somebody else returns **404, not 403** — a 403 would confirm the id exists.
- Deleting the last credential on a password-less, identity-less account returns 409.

- [ ] **Step 4: Implement, run, and commit**

The delete action looks the credential up by `(id, user)` in one query. There is no "fetch then compare owner" step; that shape is how a 403 leaks.

```bash
git add backend/src backend/tests
git commit -m "feat(#624): list and remove passkeys without locking the account out"
```

---

### Task 9: Login options and its rate limit

**Files:**
- Create: `backend/src/Service/Passkey/AssertionOptionsFactory.php`
- Modify: `backend/config/packages/rate_limiter.yaml`, `backend/src/Controller/Api/PasskeyController.php`, `backend/src/Service/RateLimit/` (add the limiter to the existing registry — read that directory for the pattern)
- Test: `backend/tests/Controller/Api/PasskeyLoginOptionsTest.php`

**Interfaces:**
- Produces: `AssertionOptionsFactory::create(): array{options: array<string,mixed>, handle: string}` — no parameters, because the flow takes no e-mail.

- [ ] **Step 1: Write the failing tests**

```php
public function testTheOptionsAreIssuedToAnAnonymousCaller(): void
{
    $client = static::createClient();

    $client->request('POST', '/api/auth/passkey/login/options');

    self::assertResponseIsSuccessful();
    $body = json_decode($client->getResponse()->getContent(), true);
    self::assertSame('required', $body['options']['userVerification']);
    self::assertSame([], $body['options']['allowCredentials']);
    self::assertNotEmpty($body['handle']);
}

/**
 * No enumeration: the endpoint takes no e-mail, and the response shape is
 * identical whether or not any account exists.
 */
public function testTheResponseShapeDoesNotDependOnWhetherAccountsExist(): void
{
    $empty = $this->optionsBodyShape();
    $this->factory()->create('somebody@example.test');
    $populated = $this->optionsBodyShape();

    self::assertSame($empty, $populated);
}

/**
 * Conditional mediation calls this on every login-page view, from every
 * anonymous visitor, and each call writes a cache entry. Without its own
 * budget that is an unbounded write surface a stranger controls.
 */
public function testTheChallengeEndpointIsRateLimited(): void
{
    $client = static::createClient();

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $client->request('POST', '/api/auth/passkey/login/options');
        self::assertResponseIsSuccessful();
    }

    $client->request('POST', '/api/auth/passkey/login/options');
    self::assertResponseStatusCodeSame(429);
}
```

`optionsBodyShape()` returns the sorted key structure with the random values stripped, so the assertion is about shape rather than bytes.

- [ ] **Step 2: Run them and watch them fail, then implement**

Add to `config/packages/rate_limiter.yaml`, with a comment giving the reasoning above:

```yaml
        passkey_challenge:
            policy: 'sliding_window'
            limit: 30
            interval: '15 minutes'
            cache_pool: cache.rate_limiter
```

It is a separate budget from the login limiter on purpose. A shared budget would let loading the login page consume the sign-in allowance.

Apply it through the existing `RateLimitGuard`, keyed by client IP, following how the registration limiter is applied.

- [ ] **Step 3: Run them and watch them pass, then commit**

```bash
git add backend/src backend/config backend/tests
git commit -m "feat(#624): issue rate-limited passkey login options"
```

---

### Task 10: The login authenticator

**Files:**
- Create: `backend/src/Security/PasskeyAuthenticator.php`, `backend/src/Service/Passkey/AssertionVerifier.php`, `backend/src/Service/Passkey/Exception/AssertionRejectedException.php`
- Modify: `backend/config/packages/security.yaml`
- Test: `backend/tests/Controller/Api/PasskeyLoginTest.php`, `backend/tests/Service/Passkey/AssertionVerifierTest.php`

**Interfaces:**
- Consumes: `PasskeyCeremony`, `PasskeyChallengeStore`, `PasskeyCredentials`.
- Produces: `AssertionVerifier::verify(string $handle, array $credential): UserPasskey` — returns the credential row, from which the authenticator takes the user.

- [ ] **Step 1: Add the firewall**

In `config/packages/security.yaml`, **between** `login` and `api`:

```yaml
        passkey_login:
            pattern: ^/api/auth/passkey/login$
            stateless: true
            provider: app_users
            # The same checker json_login uses: status is tested only after the
            # credential is verified.
            user_checker: App\Security\LoginUserChecker
            custom_authenticator: App\Security\PasskeyAuthenticator
            # The same handler json_login uses, so the JWT this flow returns is
            # the JWT password login returns — structurally, not by copying.
            success_handler: lexik_jwt_authentication.handler.authentication_success
            failure_handler: App\Security\LoginFailureHandler
            # The request carries no e-mail, so DefaultLoginRateLimiter finds an
            # empty identifier and this becomes five attempts per quarter hour
            # per client IP. That is the right budget for a flow with no
            # identifier to key on.
            login_throttling:
                max_attempts: 5
                interval: '15 minutes'
```

**Order matters.** Firewalls match in order and `api` matches `^/api`, so this block placed after `api` would never be reached.

- [ ] **Step 2: Write the failing tests**

- A valid assertion returns 200 with a `token`, and that token authenticates a following `GET /api/me`.
- The token's payload matches the one password login returns for the same user. Assert on the decoded claims, not on the string.
- A replayed handle → 401.
- An expired handle → 401.
- A credential id that is not enrolled → 401.
- **A signature counter that goes backwards → 401, and a `warning` line naming the credential id and the user id.** Assert the log through a test handler.
- A tampered `clientDataJSON` → 401.
- An origin mismatch → 401.
- An RP-id mismatch → 401.
- A suspended account → 403, proving `LoginUserChecker` runs.
- Six failures from one IP → the seventh is 429.
- A successful assertion stamps `last_used_at` and stores the new counter.

- [ ] **Step 3: Run them and watch them fail, then implement**

`PasskeyAuthenticator extends AbstractAuthenticator`. `supports()` matches the path and POST. `authenticate()` reads `handle` and `credential` from the JSON body, calls `AssertionVerifier::verify`, and returns `new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), fn () => $user))`.

`AssertionVerifier::verify`:

1. `consume()` the handle.
2. Deserialise the credential.
3. Resolve the `UserPasskey` by credential id. Not found is a typed rejection.
4. `AuthenticatorAssertionResponseValidator::create($ceremony->request())->check($record, $response, $options, $ceremony->host(), $userHandle)`, where `$userHandle` is the **stored** handle. Never the client-supplied one — spec §4.5.
5. Catch the library's counter exception, log at `warning` with the credential id and user id, and rethrow as `AssertionRejectedException`.
6. `recordUse()` with the new counter and the clock's now.

Do not re-implement the counter comparison. `CheckCounter` already does it — spec §4.4.1. Your code adds the logging only.

Note in the class docblock that `LoginFailureHandler` reuse means `LoginTimingEqualizer` runs with an empty identifier, giving a constant delay on failure. That leaks nothing here, because this flow has no address to enumerate, and it costs the success path nothing.

- [ ] **Step 4: Run them and watch them pass, then commit**

```bash
git add backend/src backend/config backend/tests
git commit -m "feat(#624): sign in with a passkey through its own firewall"
```

---

### Task 11: Backend gates

- [ ] **Step 1: Warm the cache and run the gates**

```bash
cd backend && php bin/console cache:warmup && composer check && composer md
```

`composer stan` needs a warm dev cache. `composer check` is cs + stan + tramp.

- [ ] **Step 2: Fix PHPMD in every `src` file you touched**

Standing rule: every touched `src` file must be PHPMD-clean, not merely free of new findings. Fix the design the metric points at. Do not tune the threshold.

- [ ] **Step 3: Check the tramp gate**

CI runs the tip of phptramp's `develop`, not the commit in `composer.lock`. If the tramp gate is red, run `composer show larspohlmann/phptramp` before hunting for the cause in application code.

If a chain does trip: the fix is a context object or a per-pass collaborator holding the value as a field, not a longer signature.

- [ ] **Step 4: Run both test legs**

```bash
cd backend && php bin/phpunit
```

```bash
docker compose exec php vendor/bin/phpunit
```

Both must be green. If the MySQL leg shows a rate-limiter failure that passes in isolation, that is the known order-dependent flake in issue #651, not your regression — confirm by running that test alone.

- [ ] **Step 5: Scan the dev log**

```bash
ls -t backend/var/log/dev-*.log | head -1
```

Read that file. Deprecations and swallowed errors surface there and nowhere else. The library pulls a serializer stack, so a deprecation here is plausible.

- [ ] **Step 6: Run mutation testing over the changed files**

```bash
cd backend && composer infection:diff
```

This is what CI gates. Escaped mutants arrive as PR annotations. A full sweep scores lower than the gate; that is expected.

- [ ] **Step 7: Commit any fixes**

```bash
git commit -am "chore(#624): satisfy the backend quality gates"
```

---

### Task 12: `core/webauthn.ts`

**Files:**
- Create: `frontend/src/app/core/webauthn.ts`, `frontend/src/app/core/webauthn.spec.ts`

**Interfaces:**
- Produces:
  - `base64UrlToBytes(value: string): ArrayBuffer`
  - `bytesToBase64Url(value: ArrayBuffer): string`
  - `isPasskeySupported(): boolean`
  - `isConditionalMediationSupported(): Promise<boolean>`

- [ ] **Step 1: Write the failing tests**

Cover: a round trip through both helpers; decoding a value with no padding; decoding a value using `-` and `_`; `isPasskeySupported()` false when `window.PublicKeyCredential` is absent; `isConditionalMediationSupported()` false when the method is absent, and false when it rejects.

jsdom has neither `PublicKeyCredential` nor `navigator.credentials`, so the absent case is the default and needs no stub. The present case needs one — assign to `window` in the test and delete it in `afterEach`.

- [ ] **Step 2: Run them and watch them fail**

Run: `docker compose exec -T frontend npx jest src/app/core/webauthn.spec.ts`

If the path contains a `+`, use `./node_modules/.bin/jest` instead of `npx jest`.

- [ ] **Step 3: Implement, run, and commit**

Keep this file free of Angular imports. It is pure so it can be tested without a TestBed.

```bash
git add frontend/src/app/core/webauthn.ts frontend/src/app/core/webauthn.spec.ts
git commit -m "feat(#624): add base64url and WebAuthn capability helpers"
```

---

### Task 13: `core/passkey.service.ts`

**Files:**
- Create: `frontend/src/app/core/passkey.service.ts`, `frontend/src/app/core/passkey.service.spec.ts`

**Interfaces:**
- Consumes: `webauthn.ts`, `TokenStore`, `API_BASE_URL`, `core/problem.ts`.
- Produces:
  - `enrol(label: string): Promise<void>`
  - `list(): Observable<PasskeySummary[]>`
  - `remove(id: number): Observable<void>`
  - `signIn(): Promise<string>` — resolves to the JWT.
  - `signInConditionally(signal: AbortSignal): Promise<string>`
  - `interface PasskeySummary { id: number; label: string; createdAt: string; lastUsedAt: string | null }`

- [ ] **Step 1: Write the failing tests**

Use `HttpTestingController`. Stub `navigator.credentials.create` and `.get` with jest mocks returning a fixture credential; assert the service base64url-encodes what it posts. Cover: enrol posts to both endpoints in order; a rejected ceremony surfaces as a problem, not an unhandled rejection; `signIn` returns the token from the second call; `signInConditionally` passes `mediation: 'conditional'` and the abort signal through.

- [ ] **Step 2: Run, implement, run, commit**

Errors go through `core/problem.ts` like every other API failure.

```bash
git add frontend/src/app/core/passkey.service.ts frontend/src/app/core/passkey.service.spec.ts
git commit -m "feat(#624): add the passkey client service"
```

---

### Task 14: The Settings → Account passkeys group

**Files:**
- Create: `frontend/src/app/settings/passkeys-group.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/settings/account-section.component.html`, `frontend/public/i18n/{en,de}.json`

- [ ] **Step 1: Write the failing spec**

Cover: the list renders one row per credential with its label and creation date; a credential with no `lastUsedAt` shows the never-used copy rather than a blank; *Add a passkey* calls `enrol`; the per-row remove calls `remove` and refreshes; a 409 on remove renders the lock-out message from the problem body; the whole group is absent when `isPasskeySupported()` is false.

- [ ] **Step 2: Run it and watch it fail, then build**

Build from the Grouped primitives in `src/app/shared/settings/` — `app-settings-group`, `app-settings-row`, `app-settings-stack`. Read `docs/design-language.md` before adding the surface.

It is a sibling component used inside `account-section`, not more markup in `account-section` itself, which keeps that file small.

Dates go through `formatDateOr`, never `DatePipe`: runtime Transloco switching leaves `DatePipe` on `en-US`.

Styles in the sibling `.scss`. No hex, no raw `px`.

- [ ] **Step 3: Add both locales**

Add every key to `public/i18n/en.json` **and** `public/i18n/de.json`. A key present in one and missing from the other renders the raw key.

- [ ] **Step 4: Run, then commit**

```bash
git add frontend/src/app/settings frontend/public/i18n
git commit -m "feat(#624): manage passkeys from Settings → Account"
```

---

### Task 15: The login page

**Files:**
- Modify: `frontend/src/app/auth/login/login.component.{ts,html}`, `frontend/src/app/auth/login/login.component.spec.ts`, `frontend/public/i18n/{en,de}.json`

- [ ] **Step 1: Write the failing spec**

Cover: the button is hidden when `isPasskeySupported()` is false; clicking it signs in and navigates exactly where password login navigates; a failure renders through `app-form-error`; conditional mediation is requested only when `isConditionalMediationSupported()` resolves true; **the conditional request is aborted when the password form submits**; the component aborts it on destroy.

- [ ] **Step 2: Run it and watch it fail, then implement**

Three traps, all from spec §6.1:

- The e-mail input's `autocomplete` must become `username webauthn`. It is `email` today. Conditional mediation does nothing without that token.
- Hold the `AbortController` in a field and abort it in the submit path and in `ngOnDestroy`. Two live ceremonies compete and the browser rejects one.
- Reuse `TokenStore` and the existing post-login navigation. No new session concept, no cookie.

- [ ] **Step 3: Add both locales, run, and commit**

```bash
git add frontend/src/app/auth frontend/public/i18n
git commit -m "feat(#624): sign in with a passkey from the login page"
```

---

### Task 16: The admin relying-party fields

**Files:**
- Modify: `frontend/src/app/settings/admin/admin-settings/admin-settings.component.{ts,html}`, `admin-settings-api.ts`, `admin-settings.component.spec.ts`, `frontend/public/i18n/{en,de}.json`

- [ ] **Step 1: Write the failing spec**

Cover: both fields render and round-trip; an empty field is sent as `null`, not `''`, so the fallback is restored; a 422 renders the validation message; **a 409 opens a confirm dialog quoting `invalidatedPasskeyCount`, and only a confirmation re-sends the PUT with `invalidateExistingPasskeys: true`**; dismissing the dialog sends nothing.

Plus three for the documentation:

- The RP id field's placeholder is `passkeyRpIdEffective` from the payload, not a hard-coded string.
- The RP id row's description interpolates that same value, so it reads "Leave empty to use lars-pohlmann.de" with the real host.
- The help disclosure is closed on first render.

- [ ] **Step 2: Run it and watch it fail, then implement the fields**

Follow the `publicBaseUrl` row for the input shape, the `[description]` hint and the null-on-empty handling. Reuse `shared/confirm-dialog` for the invalidation confirmation.

- [ ] **Step 3: Implement the documentation**

An admin gets a relying-party id wrong once and then cannot debug it, because the failure happens in the browser and writes nothing to the server log. The form has to explain itself.

**The live value does most of the work.** Bind the RP id placeholder and its description to `passkeyRpIdEffective`. An admin who reads nothing still sees the right answer.

Below both rows add a closed `app-disclosure` with `appearance="row"`:

```html
<app-disclosure [label]="'settings.instance.passkeyHelp.label' | transloco">
  <h4>{{ 'settings.instance.passkeyHelp.idTitle' | transloco }}</h4>
  <p>{{ 'settings.instance.passkeyHelp.idIntro' | transloco }}</p>

  <table class="examples">
    <thead>
      <tr>
        <th>{{ 'settings.instance.passkeyHelp.tableServing' | transloco }}</th>
        <th>{{ 'settings.instance.passkeyHelp.tableUse' | transloco }}</th>
      </tr>
    </thead>
    <tbody>
      <tr><td>https://lars-pohlmann.de/reader</td><td>lars-pohlmann.de</td></tr>
      <tr><td>https://reader.example.com</td><td>reader.example.com — or example.com</td></tr>
      <tr><td>http://localhost:4200</td><td>localhost</td></tr>
    </tbody>
  </table>

  <ul>
    <li>{{ 'settings.instance.passkeyHelp.rule1' | transloco }}</li>
    <li>{{ 'settings.instance.passkeyHelp.rule2' | transloco }}</li>
    <li>{{ 'settings.instance.passkeyHelp.rule3' | transloco }}</li>
    <li>{{ 'settings.instance.passkeyHelp.rule4' | transloco }}</li>
  </ul>

  <p class="warning">{{ 'settings.instance.passkeyHelp.idWarning' | transloco }}</p>

  <h4>{{ 'settings.instance.passkeyHelp.nameTitle' | transloco }}</h4>
  <p>{{ 'settings.instance.passkeyHelp.nameBody' | transloco }}</p>
</app-disclosure>
```

The example domains are literals in the template, not translation keys. A domain is not translatable, and putting one in a locale file invites somebody to "localise" it.

The table must sit in an `overflow-x: auto` container so it scrolls inside itself on a narrow screen instead of widening the page. Styles go in the sibling `.scss`. No hex, no raw `px`.

- [ ] **Step 4: Add the English copy**

Into `public/i18n/en.json` under `settings.instance`:

```json
"passkeyRpId": "Passkey domain",
"passkeyRpIdHint": "The domain your passkeys belong to. Leave empty to use {{host}}.",
"passkeyRpName": "Passkey display name",
"passkeyRpNameHint": "The name a device shows when it asks for a passkey. Leave empty to use Simple Feed Reader.",
"passkeyHelp": {
  "label": "How do I find these values?",
  "idTitle": "Passkey domain",
  "idIntro": "A browser uses a passkey only on the domain it was made for. Write the domain on its own: no https://, no port, no path.",
  "tableServing": "You serve the reader at",
  "tableUse": "Use",
  "rule1": "It must be the host you serve from, or a parent of it. example.com works for reader.example.com. It does not work for other.com.",
  "rule2": "A public suffix is refused. You cannot own com or co.uk.",
  "rule3": "An IP address is refused. Use a domain name. localhost is the one exception.",
  "rule4": "Passkeys need a secure page. Use HTTPS, or localhost.",
  "idWarning": "Changing the domain signs out every passkey, permanently. The domain is written into each passkey when it is made, so an existing one stops matching and cannot be repaired. The reader refuses the change until you confirm it, then deletes the passkeys it made unusable.",
  "nameTitle": "Passkey display name",
  "nameBody": "This is the name people see when their device asks for a passkey, and the label their password manager saves. Keep it short. Changing it is safe and every passkey keeps working. A device that already saved one keeps showing the old name."
}
```

- [ ] **Step 5: Add the German copy**

Into `public/i18n/de.json` under the same path:

```json
"passkeyRpId": "Passkey-Domain",
"passkeyRpIdHint": "Die Domain, zu der Ihre Passkeys gehören. Leer lassen, um {{host}} zu verwenden.",
"passkeyRpName": "Passkey-Anzeigename",
"passkeyRpNameHint": "Der Name, den ein Gerät bei der Passkey-Abfrage zeigt. Leer lassen für Simple Feed Reader.",
"passkeyHelp": {
  "label": "Wie finde ich diese Werte?",
  "idTitle": "Passkey-Domain",
  "idIntro": "Ein Browser verwendet einen Passkey nur auf der Domain, für die er erstellt wurde. Tragen Sie nur die Domain ein: kein https://, kein Port, kein Pfad.",
  "tableServing": "Sie betreiben den Reader unter",
  "tableUse": "Eintragen",
  "rule1": "Es muss der Host sein, unter dem Sie den Reader betreiben, oder eine übergeordnete Domain. example.com funktioniert für reader.example.com. Für other.com funktioniert es nicht.",
  "rule2": "Eine öffentliche Endung wird abgelehnt. com oder co.uk können Sie nicht besitzen.",
  "rule3": "Eine IP-Adresse wird abgelehnt. Verwenden Sie einen Domainnamen. localhost ist die einzige Ausnahme.",
  "rule4": "Passkeys brauchen eine sichere Seite. Verwenden Sie HTTPS oder localhost.",
  "idWarning": "Eine Änderung der Domain meldet jeden Passkey dauerhaft ab. Die Domain wird bei der Erstellung in jeden Passkey geschrieben. Ein vorhandener Passkey passt dann nicht mehr und lässt sich nicht reparieren. Der Reader lehnt die Änderung ab, bis Sie sie bestätigen, und löscht danach die unbrauchbar gewordenen Passkeys.",
  "nameTitle": "Passkey-Anzeigename",
  "nameBody": "Das ist der Name, den Nutzer sehen, wenn ihr Gerät nach einem Passkey fragt, und die Bezeichnung, die ihr Passwortmanager speichert. Halten Sie ihn kurz. Eine Änderung ist unbedenklich, alle Passkeys funktionieren weiter. Ein Gerät, das bereits einen gespeichert hat, zeigt weiterhin den alten Namen."
}
```

A key present in one locale and missing from the other renders the raw key. Add both, in the same commit.

- [ ] **Step 6: Run, then commit**

```bash
git add frontend/src/app/settings/admin frontend/public/i18n
git commit -m "feat(#624): configure the passkey relying party from the admin settings"
```

---

### Task 17: The first-login offer

**Files:**
- Create: `frontend/src/app/reader/passkey-offer-dialog.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/reader/reader-shell.component.{ts,spec.ts}`, `frontend/src/app/core/auth.service.ts`, `frontend/public/i18n/{en,de}.json`

**Interfaces:**
- Consumes: `PasskeyService`, `AuthService.user()`, `isPasskeySupported()`.
- Produces: `CurrentUser.preferences.passkeyOfferAnswered: boolean` on the existing interface.

- [ ] **Step 1: Write the failing dialog spec**

Cover: state one offers both actions; *Set up a passkey* calls `enrol` and, on success, closes; **a cancelled authenticator sheet keeps the dialog open, shows the error, and does not mark the offer answered**; *Not now* swaps to state two; **state two marks the offer answered when it opens, not when OK is pressed**; state two names the Settings path.

- [ ] **Step 2: Write the failing shell spec**

The four conditions from spec §5.3, one test each, plus their negatives:

- Shown when the flag is false, WebAuthn is available, the shell has settled, and onboarding is not running.
- Not shown when `passkeyOfferAnswered` is true.
- Not shown when `window.PublicKeyCredential` is absent.
- **Not shown while the subscription onboarding is running.** A new account is redirected to `/discover`, and a modal on top of that is wrong.
- Shown at most once per boot, even if the shell re-renders.
- Any close marks the offer answered — the button, Escape, and the backdrop, each its own test.

Watch the known trap here: an unstubbed reader-boot GET returns 401, which logs out and redirects to login, and that race only trips on slow CI. Stub every boot request these specs touch.

- [ ] **Step 3: Run them and watch them fail, then implement**

Add `passkeyOfferAnswered` to the `UserPreferences` interface in `auth.service.ts`.

Use the CDK Dialog. This one **is** modal, so the default block-scroll strategy is correct — do not copy the `noop()` scroll strategy the toast needs.

Any close path calls `POST /api/me/passkey-offer/answer` and updates the local `AuthService.user()` signal, so a re-render inside the same boot cannot re-open it.

If the flag write fails, the offer returns on the next boot. That is accepted — spec §5.4.

- [ ] **Step 4: Add both locales, run, and commit**

```bash
git add frontend/src/app/reader frontend/src/app/core frontend/public/i18n
git commit -m "feat(#624): offer a passkey once, on the first reader boot"
```

---

### Task 18: Frontend gates

- [ ] **Step 1: Run the CI gate**

```bash
cd frontend && npm run check
```

ESLint + Prettier + Stylelint + Jest. Prettier is 100 columns. Stylelint bans hex colours and raw `px` outside `src/app/theme/`.

- [ ] **Step 2: Confirm no inline styles crept in**

Every new component must use `styleUrl` with a sibling `.scss`. Stylelint has no TS syntax installed, so an inline style is silently unlinted and will pass this gate while being wrong.

- [ ] **Step 3: Commit any fixes**

```bash
git commit -am "chore(#624): satisfy the frontend quality gates"
```

---

### Task 19: Documentation, the real run, and the PR

- [ ] **Step 1: Document the relying party for an operator**

Keep this short, and do **not** restate the guidance the admin page now carries. Two copies of the same rules drift apart, and the one in the product is the one an admin actually reads.

Add a few lines to `docs/local-docker.md` — or wherever instance settings are documented; check first — saying only: the relying party defaults to the host of the public base URL, an admin can override it in Settings → Admin, that page explains the value in full, and changing it invalidates every enrolled passkey.

- [ ] **Step 2: Run the §6 checklist in `docs/architecture.md`**

Run it against all six new client-facing endpoints. Record the result in the PR body. The standing constraint is that a native Swift iOS client stays viable: bearer auth, stateless, JSON in and `application/problem+json` out, no CSRF token, no browser-only inputs, no `text/html` fallback.

- [ ] **Step 3: Drive the real thing**

Gates green is not the deliverable. In the Docker stack:

1. Enrol a passkey from Settings → Account.
2. Sign out. Sign in with the passkey. Reach the reader.
3. Confirm the offer dialog does **not** appear, because enrolling answered it.
4. On a second account, decline the offer and confirm it never returns across a reload.
5. Delete the last passkey on an OAuth-only account and see the refusal.
6. Open Settings → Admin. Confirm the passkey domain field shows the derived host as its placeholder, and that the help disclosure reads correctly in both English and German. Switch the language rather than trusting the JSON.

Restart the containers first if you changed backend code — the worker daemon holds code from boot, and the dev container can serve a stale chunk.

- [ ] **Step 4: Open the PR**

```bash
git push -u origin feature/624-passkey-login
```

Base it on `develop`, never `main`. The body says `Closes #624`, which auto-closes the issue on merge because `develop` is the default branch.

Record two decisions in the body, both of which the issue asks for explicitly:

- Passkeys are **not** exported in the account backup, and the drift guard was updated to say so.
- The relying party is admin-configured instead of the `PASSKEY_RP_ID` and `PASSKEY_RP_NAME` environment variables the issue proposed. Give the reason: the admin form can validate the value against the public base URL it already holds, and a wrong value otherwise fails silently in the browser.

Do not pass `--auto`. `develop` has no required checks, so `--auto` merges immediately rather than waiting for CI.

- [ ] **Step 5: Verify CI, then confirm the issue closed after the merge**

---

## Deferred, and deliberately so

Each is its own ticket if we want it. None blocks this work.

- Passkey-only registration for a new visitor.
- A passkey as a second factor on top of a password.
- Renaming an enrolled passkey after creation.
- Enterprise attestation policies. We accept `none`.
- A Playwright smoke driving a CDP virtual authenticator. It would work, and it stays outside the CI gate like the other smokes. If you add it, the spec must own the data it asserts on.
- The `apple-app-site-association` file with a `webcredentials` entry, needed by a native iOS client.
