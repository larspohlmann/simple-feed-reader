# Multiple AI Provider Configurations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an account save several named AI provider configurations and switch which one is active, without re-typing keys or re-selecting models.

**Architecture:** Reuse `AiProviderSettings` as the per-configuration row (`ManyToOne` to `User`, plus a `name`). `User` gains a nullable `activeAiProviderSettings` pointer that is the single source of truth for "which one is active". `AiProviderConfigurator::settingsFor()`/`requireConfiguration()` resolve that pointer, so `RecommendationRunAdvancer`, `MeJson`, and the frontend availability path keep working unchanged. New configurator methods add, activate (re-verify first), rename, and delete configurations.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Doctrine ORM + migrations, PHPUnit; Angular 20 signals + Jest; MySQL (prod/Docker), SQLite (native tests).

## Global Constraints

- `declare(strict_types=1);` in every PHP file. PSR-12 (`composer cs`), PHPStan level max (`composer stan`), PHPMD codesize clean on every touched `src` file (`composer md`), PhpStorm inspections clean (ERROR/WARNING) on changed PHP.
- Clean Code is mandatory: intention-revealing names, functions do one thing, guard clauses, **no boolean flag parameters** (split the method), `final readonly` with constructor promotion, depend on injected interfaces, typed namespaced exceptions (never null/magic), DRY.
- **Thin controllers:** an action reads the request, delegates, returns a response. No private controller method that carries responsibility (querying, response assembly, ownership decisions). Enforced by `ThinControllerRule`.
- **Native iOS client viable:** bearer JWT, stateless JSON in / `application/problem+json` out, no browser-only inputs, no CSRF. Config ids are ownership-scoped — another account's id reads as 404.
- **Datetimes are naive UTC** — use the injected `Psr\Clock\ClockInterface`, never `new \DateTimeImmutable()` in domain code.
- Frontend: standalone signals components, styles in a sibling `.scss` (`styleUrl`, never inline), no hex or raw px/media literals outside `src/app/theme/`, tokens and shared components from the catalog. `npm run check` is the gate.
- **Migrations get their own verification:** the suite builds schema from metadata, so a migration is only proven by the CI migrate-from-empty leg (SQLite + MySQL) plus `doctrine:schema:validate`. After merge, apply to the live Docker DB and restart the worker.
- Commit style: `feat(#334): …` / `test(#334): …` / `refactor(#334): …`, one logical change per commit.
- Cap: at most **20** configurations per account.

---

## Execution structure (supersedes the task numbering below)

A Doctrine relation change is one atomic compile unit: the moment
`AiProviderSettings`'s constructor and relation change, `AiProviderConfigurator`,
`MeJson`, the existing controller, and `AiSettingsJson` all stop compiling. The
plan's Tasks 1–8 below therefore cannot each be green. They are executed as six
green slices; the detailed steps and code in Tasks 1–12 remain the reference the
briefs draw from.

- **Exec Task 1 — backend data model + service core (additive, green).** Plan
  Tasks 1–5 minus the HTTP surface: entity (`ManyToOne` + `name`), `User`
  (collection + active pointer), repository (`findOwnedById` / `findAllForUser` /
  `countForUser`, drop `findForUser`), `TooManyConfigurationsException` /
  `ModelRequiredForActivationException`, `AddedConfiguration`, the full
  configurator (active-pointer resolution; `addConfiguration`, `chooseModel`
  auto-activate, `rename`, `activate`, `deleteConfiguration`, `listConfigurations`,
  cap), and `MeJson`. **Keep the existing `saveConnection`/`forget` working**
  (reimplemented against the active pointer) so the untouched controller and
  `AiSettingsJson` still compile and pass — the app stays green end-to-end as a
  single-active-config setup. Unit tests only.
- **Exec Task 2 — backend HTTP surface (green).** Plan Tasks 6, 7, 8:
  `AiConfigurationForUser` resolver, request DTOs, API exception mappers,
  `AiSettingsJson` new shapes (replacing `state`/`stateWithModels`/`models`),
  the controller rewrite to the new routes, and **removal of the transitional
  `saveConnection`/`forget`**. Functional tests incl. cross-user 404 and the cap.
- **Exec Task 3 — migration** (plan Task 9).
- **Exec Task 4 — frontend service** (plan Task 10).
- **Exec Task 5 — frontend section** (plan Task 11).
- **Exec Task 6 — full verification + mutation + PR** (plan Task 12).

---

## File Structure

**Backend — create:**
- `src/Service/Ai/AddedConfiguration.php` — read model: the new row + its offered model ids.
- `src/Service/Ai/AiConfigurationForUser.php` — ownership resolver: id + user → owned `AiProviderSettings` or throw.
- `src/Service/Ai/Exception/ConfigurationNotFoundException.php` — unknown/foreign id.
- `src/Service/Ai/Exception/TooManyConfigurationsException.php` — cap reached.
- `src/Service/Ai/Exception/ModelRequiredForActivationException.php` — activate a model-less config.
- `src/Exception/AiConfigurationNotFoundApiException.php` — 404 mapper.
- `src/Exception/TooManyAiConfigurationsApiException.php` — 409 mapper.
- `src/Dto/Ai/AddConfigurationRequest.php` — name?, baseUrl, apiKey.
- `src/Dto/Ai/RenameConfigurationRequest.php` — name?.
- `migrations/VersionYYYYMMDDHHMMSS.php` — schema + backfill.

**Backend — modify:**
- `src/Entity/AiProviderSettings.php` — `ManyToOne` user, add `name`, rename accessor.
- `src/Entity/User.php` — `OneToMany` collection + `activeAiProviderSettings` pointer + accessors.
- `src/Repository/AiProviderSettingsRepository.php` — `findOwnedById`, `findAllForUser`, `countForUser`.
- `src/Service/Ai/AiProviderConfigurator.php` — resolve active; add/rename/activate/delete/list; auto-activate in `chooseModel`.
- `src/Http/AiSettingsJson.php` — `configuration`, `list`, `added` shapes.
- `src/Http/MeJson.php` — read `getActiveAiProviderSettings()`.
- `src/Controller/Api/AiSettingsController.php` — new routes.

**Frontend — modify/create:**
- `src/app/core/ai-availability.service.ts` — `AiState` split (list vs availability) or keep availability shape.
- `src/app/settings/ai-settings.service.ts` — configs list, activeId, per-config writes.
- `src/app/settings/ai-section.component.ts` / `.html` / `.scss` — list + add form + row actions.
- `src/assets/i18n/*.json` (transloco) — new copy keys.
- Spec files alongside each.

---

## Task 1: `AiProviderSettings` becomes a per-configuration row

**Files:**
- Modify: `backend/src/Entity/AiProviderSettings.php`
- Test: `backend/tests/Entity/AiProviderSettingsTest.php`

**Interfaces:**
- Produces: `AiProviderSettings::getName(): ?string`, `rename(?string $name): void`; constructor gains `?string $name` after `$user`; `#[ORM\ManyToOne]` on `$user`; the class-level `UniqueConstraint` is removed.

- [ ] **Step 1: Write the failing test** — append to `AiProviderSettingsTest.php`:

```php
public function testCarriesAnOptionalName(): void
{
    $settings = new AiProviderSettings(
        $this->user(),
        'LM Studio local',
        'https://localhost:1234/v1',
        $this->sealed(),
        'ab12',
        new \DateTimeImmutable('2026-08-09T10:00:00Z'),
    );

    self::assertSame('LM Studio local', $settings->getName());

    $settings->rename(null);
    self::assertNull($settings->getName());
}
```

(Reuse the file's existing `user()` / `sealed()` helpers; if the constructor signature there differs, update every `new AiProviderSettings(...)` in this test file to pass the name as the second argument.)

- [ ] **Step 2: Run it, expect failure**

Run: `cd backend && php bin/phpunit tests/Entity/AiProviderSettingsTest.php`
Expected: FAIL (constructor arity / `getName` undefined).

- [ ] **Step 3: Change the entity.** In `AiProviderSettings.php`:
  - Delete the `#[ORM\UniqueConstraint(name: 'uniq_ai_settings_user', columns: ['user_id'])]` attribute.
  - Change the relation to many-to-one:

```php
#[ORM\ManyToOne(inversedBy: 'aiProviderSettings')]
#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
private User $user;
```

  - Add the column and accessor:

```php
#[ORM\Column(length: 120, nullable: true)]
private ?string $name = null;
```

  - Add `$name` to the constructor (second parameter, after `User $user`) and assign it before `replaceConnection(...)`:

```php
public function __construct(
    User $user,
    ?string $name,
    string $baseUrl,
    SealedApiKey $sealed,
    string $apiKeyHint,
    \DateTimeImmutable $verifiedAt,
) {
    $this->user = $user;
    $this->name = $name;
    $this->replaceConnection($baseUrl, $sealed, $apiKeyHint, $verifiedAt);
}
```

  - Add:

```php
public function getName(): ?string
{
    return $this->name;
}

public function rename(?string $name): void
{
    $this->name = $name;
}
```

- [ ] **Step 4: Run it, expect pass**

Run: `cd backend && php bin/phpunit tests/Entity/AiProviderSettingsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Entity/AiProviderSettings.php backend/tests/Entity/AiProviderSettingsTest.php
git commit -m "feat(#334): give an AI configuration an optional name and a ManyToOne owner"
```

---

## Task 2: `User` holds many configurations and one active pointer

**Files:**
- Modify: `backend/src/Entity/User.php`
- Test: `backend/tests/Entity/UserAiConfigurationsTest.php` (create)

**Interfaces:**
- Produces on `User`: `getAiProviderSettings(): Collection<int, AiProviderSettings>` (the collection), `addAiProviderSettings(AiProviderSettings): void`, `removeAiProviderSettings(AiProviderSettings): void`, `getActiveAiProviderSettings(): ?AiProviderSettings`, `setActiveAiProviderSettings(?AiProviderSettings): void`.
- Note: the old single-value `getAiProviderSettings()`/`setAiProviderSettings()` are removed; call sites are fixed in Tasks 4 and 5. This task leaves the tree not-yet-green for `MeJson`/configurator until those tasks land, so keep Tasks 2–5 in one working session.

- [ ] **Step 1: Write the failing test** — `backend/tests/Entity/UserAiConfigurationsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\Crypto\SealedApiKey;
use PHPUnit\Framework\TestCase;

final class UserAiConfigurationsTest extends TestCase
{
    public function testHoldsManyConfigurationsAndOneActivePointer(): void
    {
        $user = new User('owner@example.com', new \DateTimeImmutable('2026-08-09T09:00:00Z'));

        $first = $this->configuration($user, 'first');
        $second = $this->configuration($user, 'second');
        $user->addAiProviderSettings($first);
        $user->addAiProviderSettings($second);

        self::assertCount(2, $user->getAiProviderSettings());
        self::assertNull($user->getActiveAiProviderSettings());

        $user->setActiveAiProviderSettings($second);
        self::assertSame($second, $user->getActiveAiProviderSettings());
    }

    private function configuration(User $user, string $name): AiProviderSettings
    {
        return new AiProviderSettings(
            $user,
            $name,
            'https://api.example.com/v1',
            new SealedApiKey('cipher', 'nonce', 'salt', 1),
            'ab12',
            new \DateTimeImmutable('2026-08-09T09:00:00Z'),
        );
    }
}
```

- [ ] **Step 2: Run it, expect failure**

Run: `cd backend && php bin/phpunit tests/Entity/UserAiConfigurationsTest.php`
Expected: FAIL (methods undefined).

- [ ] **Step 3: Change `User.php`.** Replace the single `aiProviderSettings` property block (currently `#[ORM\OneToOne(mappedBy: 'user', targetEntity: AiProviderSettings::class, cascade: ['remove'])] private ?AiProviderSettings $aiProviderSettings = null;`) with a collection and an active pointer:

```php
/** @var Collection<int, AiProviderSettings> */
#[ORM\OneToMany(mappedBy: 'user', targetEntity: AiProviderSettings::class, cascade: ['remove'])]
private Collection $aiProviderSettings;

/**
 * The one configuration AI features use. A pointer, not a per-row flag, so
 * the model cannot say two configurations are active at once. ON DELETE SET
 * NULL is the database floor; AiProviderConfigurator clears it explicitly
 * before it removes the active row.
 */
#[ORM\ManyToOne(targetEntity: AiProviderSettings::class)]
#[ORM\JoinColumn(name: 'active_ai_config_id', nullable: true, onDelete: 'SET NULL')]
private ?AiProviderSettings $activeAiProviderSettings = null;
```

Ensure `use Doctrine\Common\Collections\ArrayCollection;` and `use Doctrine\Common\Collections\Collection;` are imported. In the constructor body add `$this->aiProviderSettings = new ArrayCollection();`. Replace the old getter/setter with:

```php
/** @return Collection<int, AiProviderSettings> */
public function getAiProviderSettings(): Collection
{
    return $this->aiProviderSettings;
}

public function addAiProviderSettings(AiProviderSettings $settings): void
{
    if (!$this->aiProviderSettings->contains($settings)) {
        $this->aiProviderSettings->add($settings);
    }
}

public function removeAiProviderSettings(AiProviderSettings $settings): void
{
    $this->aiProviderSettings->removeElement($settings);
}

public function getActiveAiProviderSettings(): ?AiProviderSettings
{
    return $this->activeAiProviderSettings;
}

public function setActiveAiProviderSettings(?AiProviderSettings $settings): void
{
    $this->activeAiProviderSettings = $settings;
}
```

- [ ] **Step 4: Run it, expect pass**

Run: `cd backend && php bin/phpunit tests/Entity/UserAiConfigurationsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Entity/User.php backend/tests/Entity/UserAiConfigurationsTest.php
git commit -m "feat(#334): let an account hold many AI configurations with one active pointer"
```

---

## Task 3: Repository queries for the owned set

**Files:**
- Modify: `backend/src/Repository/AiProviderSettingsRepository.php`
- Test: `backend/tests/Repository/AiProviderSettingsRepositoryTest.php` (create; use the kernel/database test base the repo already uses — mirror an existing repository test's bootstrapping).

**Interfaces:**
- Produces: `findOwnedById(User $user, int $id): ?AiProviderSettings`, `findAllForUser(User $user): array` (`list<AiProviderSettings>`, ordered by id asc), `countForUser(User $user): int`. `findForUser` is deleted (replaced by the active pointer on `User`).

- [ ] **Step 1: Write the failing test.** Model it on an existing DB-backed repository test (find one under `tests/Repository` or `tests/Service` that persists a `User`). Persist a `User` with two configurations and a second `User` with one, then assert:

```php
self::assertCount(2, $this->repository->findAllForUser($owner));
self::assertSame($ownedId, $this->repository->findOwnedById($owner, $ownedId)?->getId());
self::assertNull($this->repository->findOwnedById($owner, $strangerConfigId));
self::assertSame(2, $this->repository->countForUser($owner));
```

- [ ] **Step 2: Run it, expect failure**

Run: `cd backend && php bin/phpunit tests/Repository/AiProviderSettingsRepositoryTest.php`
Expected: FAIL (methods undefined).

- [ ] **Step 3: Implement.** Replace `findForUser` with:

```php
public function findOwnedById(User $user, int $id): ?AiProviderSettings
{
    return $this->findOneBy(['id' => $id, 'user' => $user]);
}

/** @return list<AiProviderSettings> */
public function findAllForUser(User $user): array
{
    return array_values($this->findBy(['user' => $user], ['id' => 'ASC']));
}

public function countForUser(User $user): int
{
    return $this->count(['user' => $user]);
}
```

- [ ] **Step 4: Run it, expect pass** — same command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repository/AiProviderSettingsRepository.php backend/tests/Repository/AiProviderSettingsRepositoryTest.php
git commit -m "feat(#334): query an account's AI configurations and one owned by id"
```

---

## Task 4: Configurator resolves the active configuration and lists the set

**Files:**
- Modify: `backend/src/Service/Ai/AiProviderConfigurator.php`
- Modify: `backend/src/Http/MeJson.php`
- Test: `backend/tests/Service/Ai/AiProviderConfiguratorTest.php`

**Interfaces:**
- Produces: `settingsFor(User): ?AiProviderSettings` returns `$user->getActiveAiProviderSettings()`; `requireConfiguration(User): AiProviderSettings` returns it or throws `AiNotConfiguredException`; `listConfigurations(User): list<AiProviderSettings>`.
- Consumes: `User::getActiveAiProviderSettings()` (Task 2), `AiProviderSettingsRepository::findAllForUser()` (Task 3).

- [ ] **Step 1: Fix and extend the test.** In `AiProviderConfiguratorTest.php`, the existing assertions at lines ~222/230/234 use the removed single-value `getAiProviderSettings()`. Change them to `getActiveAiProviderSettings()`. Add:

```php
public function testSettingsForReturnsTheActiveConfiguration(): void
{
    $user = $this->userWithId(1);
    self::assertNull($this->configurator()->settingsFor($user));

    $active = $this->configurationFor($user);
    $user->addAiProviderSettings($active);
    $user->setActiveAiProviderSettings($active);

    self::assertSame($active, $this->configurator()->settingsFor($user));
}
```

(Use the test's existing construction helpers; add small ones only if none fit.)

- [ ] **Step 2: Run it, expect failure**

Run: `cd backend && php bin/phpunit tests/Service/Ai/AiProviderConfiguratorTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement the read path.** In `AiProviderConfigurator.php`:

```php
public function settingsFor(User $user): ?AiProviderSettings
{
    return $user->getActiveAiProviderSettings();
}

/** @return list<AiProviderSettings> */
public function listConfigurations(User $user): array
{
    return $this->repository->findAllForUser($user);
}

private function requireSettings(User $user): AiProviderSettings
{
    return $user->getActiveAiProviderSettings()
        ?? throw new AiNotConfiguredException('This account has no active AI configuration.');
}
```

In `MeJson.php` change `$user->getAiProviderSettings()` to `$user->getActiveAiProviderSettings()`.

- [ ] **Step 4: Run it, expect pass**

Run: `cd backend && php bin/phpunit tests/Service/Ai/AiProviderConfiguratorTest.php tests/Http`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Ai/AiProviderConfigurator.php backend/src/Http/MeJson.php backend/tests/Service/Ai/AiProviderConfiguratorTest.php
git commit -m "feat(#334): resolve the active AI configuration through the pointer"
```

---

## Task 5: Add, choose-model (auto-activate), rename, activate, delete

**Files:**
- Create: `backend/src/Service/Ai/AddedConfiguration.php`
- Create: `backend/src/Service/Ai/Exception/TooManyConfigurationsException.php`, `ModelRequiredForActivationException.php`
- Modify: `backend/src/Service/Ai/AiProviderConfigurator.php`
- Test: `backend/tests/Service/Ai/AiProviderConfiguratorTest.php`

**Interfaces:**
- Produces:
  - `addConfiguration(User $user, ?string $name, string $baseUrl, string $apiKey): AddedConfiguration` — enforces the cap, verifies via `catalog->listModels`, persists a new row (model null), does **not** activate; returns row + model ids.
  - `chooseModel(AiProviderSettings $settings, string $model): void` — unchanged verification + stamp, then auto-activate: if the owner has no active configuration, point it at this row.
  - `rename(AiProviderSettings $settings, ?string $name): void`.
  - `activate(AiProviderSettings $settings): void` — throws `ModelRequiredForActivationException` when `!hasModel()`; re-verifies the stored model against the provider (`offeredDescriptor` over a fresh `listModels`), then sets the owner's active pointer. Verification failure throws before the pointer moves.
  - `deleteConfiguration(AiProviderSettings $settings): void` — if it is the owner's active one, clear the pointer first, then remove.
- `AddedConfiguration`: `public function __construct(public AiProviderSettings $configuration, /** @var list<string> */ public array $modelIds)`.
- The cap constant: `private const int MAX_CONFIGURATIONS = 20;`.

- [ ] **Step 1: Write failing tests** — add cases to `AiProviderConfiguratorTest.php`:

```php
public function testChooseModelAutoActivatesWhenNoConfigurationIsActive(): void
{
    // fake catalog offers 'm1'; user has one config, no active pointer
    $this->configurator()->chooseModel($config, 'm1');
    self::assertSame($config, $user->getActiveAiProviderSettings());
}

public function testChooseModelLeavesTheActivePointerWhenOneIsAlreadyActive(): void
{
    $user->setActiveAiProviderSettings($alreadyActive);
    $this->configurator()->chooseModel($second, 'm1');
    self::assertSame($alreadyActive, $user->getActiveAiProviderSettings());
}

public function testActivateRefusesAConfigurationWithoutAModel(): void
{
    $this->expectException(ModelRequiredForActivationException::class);
    $this->configurator()->activate($modelless);
}

public function testActivateSetsThePointerAfterASuccessfulReverify(): void
{
    // catalog offers the stored model id
    $this->configurator()->activate($config);
    self::assertSame($config, $user->getActiveAiProviderSettings());
}

public function testActivateLeavesTheCurrentActiveWhenReverifyFails(): void
{
    $user->setActiveAiProviderSettings($current);
    $this->expectException(ModelNotOfferedException::class); // or ProviderUnreachableException from the fake
    try {
        $this->configurator()->activate($other);
    } finally {
        self::assertSame($current, $user->getActiveAiProviderSettings());
    }
}

public function testDeleteClearsThePointerWhenRemovingTheActiveConfiguration(): void
{
    $user->setActiveAiProviderSettings($config);
    $this->configurator()->deleteConfiguration($config);
    self::assertNull($user->getActiveAiProviderSettings());
}

public function testAddRefusesBeyondTheCap(): void
{
    // repository->countForUser returns 20
    $this->expectException(TooManyConfigurationsException::class);
    $this->configurator()->addConfiguration($user, 'x', 'https://api.example.com/v1', 'key-1234');
}
```

(Match the file's existing fake `ModelCatalog`/repository doubles; the file already fakes the catalog for `saveConnection`/`chooseModel` — extend those doubles rather than inventing new ones.)

- [ ] **Step 2: Run, expect failure**

Run: `cd backend && php bin/phpunit tests/Service/Ai/AiProviderConfiguratorTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement.** Create `AddedConfiguration.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiProviderSettings;

final readonly class AddedConfiguration
{
    /** @param list<string> $modelIds */
    public function __construct(
        public AiProviderSettings $configuration,
        public array $modelIds,
    ) {
    }
}
```

Create the two exceptions (each `final class … extends \RuntimeException` under `App\Service\Ai\Exception`, with a `declare(strict_types=1);`), mirroring `ModelNotOfferedException`.

In `AiProviderConfigurator.php`, replace `saveConnection` with `addConfiguration` and add the rest:

```php
public function addConfiguration(User $user, ?string $name, string $baseUrl, string $apiKey): AddedConfiguration
{
    if ($this->repository->countForUser($user) >= self::MAX_CONFIGURATIONS) {
        throw new TooManyConfigurationsException('This account already holds the maximum number of AI configurations.');
    }

    $credentials = ProviderCredentials::fromAccountInput($baseUrl, $apiKey);
    $descriptors = $this->catalog->listModels($credentials);

    $sealed = $this->cipher->seal($this->identify($user), $credentials->apiKey);
    $hint = substr($credentials->apiKey, -self::HINT_LENGTH);

    $configuration = new AiProviderSettings($user, $name, $credentials->baseUrl, $sealed, $hint, $this->clock->now());
    $this->entityManager->persist($configuration);
    $user->addAiProviderSettings($configuration);
    $this->entityManager->flush();

    return new AddedConfiguration($configuration, $this->ids($descriptors));
}

public function chooseModel(AiProviderSettings $settings, string $model): void
{
    $offered = $this->catalog->listModels($this->credentials($settings));
    $descriptor = $this->offeredDescriptor($offered, $model);

    $settings->chooseModel($model, $this->clock->now(), $descriptor->contextWindow);
    $this->activateWhenNoneActive($settings);
    $this->entityManager->flush();
}

public function rename(AiProviderSettings $settings, ?string $name): void
{
    $settings->rename($name);
    $this->entityManager->flush();
}

public function activate(AiProviderSettings $settings): void
{
    if (!$settings->hasModel()) {
        throw new ModelRequiredForActivationException('Choose a model before activating this configuration.');
    }

    $offered = $this->catalog->listModels($this->credentials($settings));
    $this->offeredDescriptor($offered, (string) $settings->getModel());

    $settings->getUser()->setActiveAiProviderSettings($settings);
    $this->entityManager->flush();
}

public function deleteConfiguration(AiProviderSettings $settings): void
{
    $user = $settings->getUser();
    if ($settings === $user->getActiveAiProviderSettings()) {
        $user->setActiveAiProviderSettings(null);
    }

    $user->removeAiProviderSettings($settings);
    $this->entityManager->remove($settings);
    $this->entityManager->flush();
}

private function activateWhenNoneActive(AiProviderSettings $settings): void
{
    $user = $settings->getUser();
    if (null === $user->getActiveAiProviderSettings()) {
        $user->setActiveAiProviderSettings($settings);
    }
}
```

Delete the old `forget()` and `setAiProviderSettings` usage. Add `private const int MAX_CONFIGURATIONS = 20;` and the new `use` imports.

- [ ] **Step 4: Run, expect pass**

Run: `cd backend && php bin/phpunit tests/Service/Ai`
Expected: PASS.

- [ ] **Step 5: PHPMD/PHPStan on the touched service**

Run: `cd backend && composer stan && composer md`
Expected: clean. If `AiProviderConfigurator` trips class-length, extract the verify-and-list step (`offeredDescriptor` over a fresh `listModels`) into a small private `assertModelStillOffered(AiProviderSettings): void` used by both `chooseModel` and `activate`.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Ai backend/tests/Service/Ai
git commit -m "feat(#334): add, rename, activate and delete AI configurations"
```

---

## Task 6: Ownership resolver + request DTOs

**Files:**
- Create: `backend/src/Service/Ai/AiConfigurationForUser.php`
- Create: `backend/src/Service/Ai/Exception/ConfigurationNotFoundException.php`
- Create: `backend/src/Dto/Ai/AddConfigurationRequest.php`, `backend/src/Dto/Ai/RenameConfigurationRequest.php`
- Test: `backend/tests/Service/Ai/AiConfigurationForUserTest.php`

**Interfaces:**
- Produces: `AiConfigurationForUser::require(User $user, int $id): AiProviderSettings` — returns the owned row or throws `ConfigurationNotFoundException`. Depends on `AiProviderSettingsRepository::findOwnedById`.
- `AddConfigurationRequest`: `?string $name` (`Assert\Length(max: 120)`), `string $baseUrl` (`NotBlank`, `Length(max: 512)`), `string $apiKey` (`NotBlank`, `Length(min: 8, max: 512)`).
- `RenameConfigurationRequest`: `?string $name` (`Assert\Length(max: 120)`).

- [ ] **Step 1: Write the failing test** — persist two users, assert `require` returns the owned row and throws `ConfigurationNotFoundException` for a foreign or unknown id.

- [ ] **Step 2: Run, expect failure.** Run: `cd backend && php bin/phpunit tests/Service/Ai/AiConfigurationForUserTest.php` — FAIL.

- [ ] **Step 3: Implement.**

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Repository\AiProviderSettingsRepository;
use App\Service\Ai\Exception\ConfigurationNotFoundException;

final readonly class AiConfigurationForUser
{
    public function __construct(private AiProviderSettingsRepository $repository)
    {
    }

    public function require(User $user, int $id): AiProviderSettings
    {
        return $this->repository->findOwnedById($user, $id)
            ?? throw new ConfigurationNotFoundException(sprintf('No AI configuration %d for this account.', $id));
    }
}
```

Create `ConfigurationNotFoundException` (extends `\RuntimeException`). Create the two DTOs mirroring `SaveConnectionRequest`/`SaveModelRequest`.

- [ ] **Step 4: Run, expect pass.** Same command — PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Ai/AiConfigurationForUser.php backend/src/Service/Ai/Exception/ConfigurationNotFoundException.php backend/src/Dto/Ai backend/tests/Service/Ai/AiConfigurationForUserTest.php
git commit -m "feat(#334): resolve an owned AI configuration by id and add its request DTOs"
```

---

## Task 7: Response shapes (`AiSettingsJson`)

**Files:**
- Modify: `backend/src/Http/AiSettingsJson.php`
- Test: `backend/tests/Http/AiSettingsJsonTest.php`

**Interfaces:**
- Produces:
  - `configuration(AiProviderSettings $settings, bool $active): array` → `['id', 'name', 'baseUrl', 'apiKeyHint', 'model', 'ready', 'active']`.
  - `list(list<AiProviderSettings> $configurations, ?int $activeId): array` → `['configs' => [...], 'activeId' => $activeId]`.
  - `added(AiProviderSettings $settings, list<string> $models): array` → `configuration(..., false) + ['models' => $models]`.
  - `isReady(?AiProviderSettings): bool` unchanged.
- No boolean flag smell note: `configuration(...)`'s `bool $active` is data the caller already knows (id equality), not a mode switch — acceptable, but if PHPMD/review objects, pass `?int $activeId` instead and compare inside.

- [ ] **Step 1: Write failing tests** in `AiSettingsJsonTest.php` for `configuration` (active true/false), `list` (activeId echoed; empty list ⇒ `configs: []`, `activeId: null`), and `added`.

- [ ] **Step 2: Run, expect failure.** `cd backend && php bin/phpunit tests/Http/AiSettingsJsonTest.php` — FAIL.

- [ ] **Step 3: Implement** the three methods; keep `isReady`. Prefer the `?int $activeId` form to avoid the flag parameter:

```php
/** @return array<string, mixed> */
public static function configuration(AiProviderSettings $settings, ?int $activeId): array
{
    return [
        'id' => $settings->getId(),
        'name' => $settings->getName(),
        'baseUrl' => $settings->getBaseUrl(),
        'apiKeyHint' => $settings->getApiKeyHint(),
        'model' => $settings->getModel(),
        'ready' => self::isReady($settings),
        'active' => $settings->getId() === $activeId,
    ];
}

/**
 * @param list<AiProviderSettings> $configurations
 *
 * @return array<string, mixed>
 */
public static function list(array $configurations, ?int $activeId): array
{
    return [
        'configs' => array_map(
            static fn (AiProviderSettings $each): array => self::configuration($each, $activeId),
            $configurations,
        ),
        'activeId' => $activeId,
    ];
}

/**
 * @param list<string> $models
 *
 * @return array<string, mixed>
 */
public static function added(AiProviderSettings $settings, array $models): array
{
    return self::configuration($settings, null) + ['models' => $models];
}
```

Remove the now-unused `state`/`stateWithModels`/`models` if nothing else references them (grep first; `MeJson` uses only `isReady`).

- [ ] **Step 4: Run, expect pass.** Same command — PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Http/AiSettingsJson.php backend/tests/Http/AiSettingsJsonTest.php
git commit -m "feat(#334): render an AI configuration list with the active id"
```

---

## Task 8: HTTP API + error mappers (functional)

**Files:**
- Create: `backend/src/Exception/AiConfigurationNotFoundApiException.php` (404), `backend/src/Exception/TooManyAiConfigurationsApiException.php` (409)
- Modify: `backend/src/Controller/Api/AiSettingsController.php`
- Test: `backend/tests/Controller/Api/AiSettingsControllerTest.php`

**Interfaces:**
- Consumes: `AiProviderConfigurator` (Task 5), `AiConfigurationForUser::require` (Task 6), `AiSettingsJson` (Task 7), the DTOs (Task 6).
- Routes (all under `#[Route('/api/me/ai')]`):
  - `GET ''` → `AiSettingsJson::list($configurator->listConfigurations($user), activeId)`.
  - `POST '/configs'` → rate-limit; `addConfiguration`; `201` with `AiSettingsJson::added(...)`.
  - `GET '/configs/{id}/models'` → resolve owned; rate-limit; `listModels`.
  - `PUT '/configs/{id}/model'` → resolve owned; rate-limit; `chooseModel`; return `configuration`.
  - `PUT '/configs/{id}/name'` → resolve owned; `rename`; return `configuration`.
  - `PUT '/configs/{id}/active'` → resolve owned; rate-limit; `activate`; return `configuration`.
  - `DELETE '/configs/{id}'` → resolve owned; `deleteConfiguration`; `204`.
- `{id}` is `#[Route(..., requirements: ['id' => '\d+'])]`; map to `int $id`.
- `activeId` for responses: `$configurator->settingsFor($user)?->getId()`.

- [ ] **Step 1: Write failing functional tests.** Extend `AiSettingsControllerTest.php` (it already boots the kernel + authenticates). Cover: add → choose model → `GET` shows one active config; add a second, `PUT .../active`, confirm `GET /api/me` and `GET /api/me/ai` follow; rename; delete non-active; delete active ⇒ `activeId` null; **cross-user 404** on each `{id}` route (create a second user's config, call as the first); cap ⇒ 409; model-less activate ⇒ 422 (provider exception) — actually a domain refusal ⇒ map `ModelRequiredForActivationException` to `AiProviderApiException` (422). Assert the rate limiter fires on `POST /configs` (loop past the budget as the existing test does, if it does).

- [ ] **Step 2: Run, expect failure.** `cd backend && php bin/phpunit tests/Controller/Api/AiSettingsControllerTest.php` — FAIL.

- [ ] **Step 3: Implement mappers + controller.** `AiConfigurationNotFoundApiException` mirrors `AiNotConfiguredApiException` but `HTTP_NOT_FOUND` with code `ai_configuration_not_found`. `TooManyAiConfigurationsApiException` → `HTTP_CONFLICT`, code `ai_configuration_limit`. Rewrite the controller — each action stays thin:

```php
#[Route('', name: 'api_me_ai_list', methods: ['GET'])]
public function list(#[CurrentUser] User $user): JsonResponse
{
    return new JsonResponse(AiSettingsJson::list(
        $this->configurator->listConfigurations($user),
        $this->configurator->settingsFor($user)?->getId(),
    ));
}

#[Route('/configs', name: 'api_me_ai_add', methods: ['POST'])]
public function add(#[CurrentUser] User $user, #[MapRequestPayload] AddConfigurationRequest $request): JsonResponse
{
    $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);

    try {
        $added = $this->configurator->addConfiguration($user, $request->name, $request->baseUrl, $request->apiKey);
    } catch (TooManyConfigurationsException $e) {
        throw new TooManyAiConfigurationsApiException($e);
    } catch (ProviderUnreachableException | CredentialsRejectedException $e) {
        throw new AiProviderApiException($e->getMessage(), $e);
    }

    return new JsonResponse(AiSettingsJson::added($added->configuration, $added->modelIds), Response::HTTP_CREATED);
}
```

Model the id-scoped actions on this shape — resolve first, then act:

```php
#[Route('/configs/{id}/active', name: 'api_me_ai_activate', methods: ['PUT'], requirements: ['id' => '\d+'])]
public function activate(#[CurrentUser] User $user, int $id): JsonResponse
{
    try {
        $configuration = $this->configuration->require($user, $id);
        $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);
        $this->configurator->activate($configuration);
    } catch (ConfigurationNotFoundException $e) {
        throw new AiConfigurationNotFoundApiException($e);
    } catch (ModelRequiredForActivationException | ModelNotOfferedException | ProviderUnreachableException | CredentialsRejectedException $e) {
        throw new AiProviderApiException($e->getMessage(), $e);
    } catch (ApiKeyUnreadableException $e) {
        throw new AiKeyUnreadableApiException($e);
    }

    return new JsonResponse(AiSettingsJson::configuration($configuration, $configuration->getId()));
}
```

Inject `AiConfigurationForUser $configuration` in the constructor. Rename/model/models/delete follow the same resolve-then-act shape; rename and delete skip the rate limiter.

- [ ] **Step 4: Run, expect pass.** Same command — PASS. Then the full suite: `cd backend && php bin/phpunit`.

- [ ] **Step 5: Full gate**

Run: `cd backend && bin/console cache:warmup && composer check && composer md`
Then PhpStorm inspections (`mcp__phpstorm__lint_files`) on every changed PHP file; block on ERROR/WARNING.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Controller/Api/AiSettingsController.php backend/src/Exception backend/tests/Controller/Api/AiSettingsControllerTest.php
git commit -m "feat(#334): expose the AI configuration list, add, activate, rename and delete routes"
```

---

## Task 9: Migration (schema + backfill)

**Files:**
- Create: `backend/migrations/VersionYYYYMMDDHHMMSS.php`

**Interfaces:** none (schema only). Produces the live shape Tasks 1–2 assume.

- [ ] **Step 1: Generate the diff**

Run: `cd backend && bin/console doctrine:migrations:diff`
Inspect the generated file: it should drop `uniq_ai_settings_user`, add `user_ai_settings.name`, add `user.active_ai_config_id` with the FK (`ON DELETE SET NULL`), and adjust the `user_id` index (unique → plain). Remove any unrelated churn.

- [ ] **Step 2: Add the backfill** to `up()`, after the schema statements, portable across MySQL and SQLite:

```php
$this->addSql(<<<'SQL'
    UPDATE user u
    SET active_ai_config_id = (
        SELECT s.id FROM user_ai_settings s
        WHERE s.user_id = u.id AND s.model IS NOT NULL
        ORDER BY s.id ASC LIMIT 1
    )
    WHERE active_ai_config_id IS NULL
    SQL);
```

(Before the migration there is at most one row per user, so the `ORDER BY … LIMIT 1` only guards against the theoretical; it keeps the statement well-defined.)

- [ ] **Step 3: Verify forward on SQLite**

Run: `cd backend && php bin/console doctrine:migrations:migrate --no-interaction --env=test && php bin/console doctrine:schema:validate --env=test`
Expected: migrates clean; schema validates (mapping in sync).

Do **not** run this against the real dev database (see the "never clear the dev database" standing rule). Use a scratch/test database only.

- [ ] **Step 4: Verify forward on MySQL** via Docker:

Run: `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec php bin/console doctrine:schema:validate`
Expected: clean on MySQL too.

- [ ] **Step 5: Commit**

```bash
git add backend/migrations/VersionYYYYMMDDHHMMSS.php
git commit -m "feat(#334): migrate AI settings to many-per-account with an active pointer"
```

---

## Task 10: Frontend service — configs, activeId, per-config writes

**Files:**
- Modify: `frontend/src/app/settings/ai-settings.service.ts`
- Modify: `frontend/src/app/core/ai-availability.service.ts`
- Test: `frontend/src/app/settings/ai-settings.service.spec.ts` (create if absent)

**Interfaces:**
- Produces on `AiSettingsService`: signals `configs = signal<AiConfig[]>([])`, `activeId = signal<number|null>(null)`, `models = signal<readonly string[]>([])`, `busy`, `failure`; methods `load()`, `add(name, baseUrl, apiKey)`, `loadModels(id)`, `chooseModel(id, model)`, `rename(id, name)`, `activate(id)`, `remove(id)`.
- Types: `interface AiConfig { id: number; name: string|null; baseUrl: string; apiKeyHint: string|null; model: string|null; ready: boolean; active: boolean }`. `interface AiList { configs: AiConfig[]; activeId: number|null }`.
- After every write that returns a list, feed availability from the active config: `availability.apply({ ready: active?.ready ?? false, model: active?.model ?? null })`.

- [ ] **Step 1: Write failing Jest tests** with `HttpTestingController`: `load()` GETs `/api/me/ai` and fills `configs`/`activeId`; `add()` POSTs `/api/me/ai/configs` and appends + sets `models`; `activate(id)` PUTs `/api/me/ai/configs/{id}/active` and moves the active flag; `remove(id)` DELETEs and drops the row; a write feeds `AiAvailabilityService`.

- [ ] **Step 2: Run, expect failure.** `cd frontend && npm test -- ai-settings.service` — FAIL.

- [ ] **Step 3: Implement** the service around the new endpoints (mirror the existing `run()` helper and `busy`/`failure` handling). On `load()`, `activate()`, `remove()`, `chooseModel()`, `rename()` the server answers with the fresh list or a single config; re-fetch the list where the endpoint returns a single config, or patch the signal in place — prefer patching to avoid a round trip. After each, recompute availability from the active config.

- [ ] **Step 4: Run, expect pass.** Same command — PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/settings/ai-settings.service.ts frontend/src/app/core/ai-availability.service.ts frontend/src/app/settings/ai-settings.service.spec.ts
git commit -m "feat(#334): manage the AI configuration list in the settings service"
```

---

## Task 11: Frontend section — list, add form, row actions

**Files:**
- Modify: `frontend/src/app/settings/ai-section.component.ts` / `.html` / `.scss`
- Modify: transloco catalogs under `frontend/src/assets/i18n/` (all locales)
- Test: `frontend/src/app/settings/ai-section.component.spec.ts`

**Interfaces:** Consumes `AiSettingsService` (Task 10). Produces the settings UI; no other component depends on it.

- [ ] **Step 1: Write failing component tests** — renders one row per config with name/host/model/hint, shows the "active" badge on the active row, the add form calls `add(...)`, a row's Activate calls `activate(id)`, Change model opens the picker and calls `chooseModel`, Rename calls `rename`, Delete confirms then calls `remove`. Derived label (host + model) shows when `name` is null.

- [ ] **Step 2: Run, expect failure.** `cd frontend && npm test -- ai-section` — FAIL.

- [ ] **Step 3: Implement.** Component holds add-form signals (`newName`, `newBaseUrl`, `newApiKey`) and a per-row `choosingModelFor = signal<number|null>(null)`. Template: an `@for` over `ai.configs()` rendering each config card with the badge and the four actions, then the add-form group, then the error banner. Reuse `app-settings-card`, `app-field`, `app-button` (`variant="danger"` for delete), `app-searchable-select` for the model picker. Derive the host label with `new URL(baseUrl).host`. Put every spacing/colour in the sibling `.scss` using tokens — no hex, no raw px. Add copy keys (`settings.ai.configs.*`: `add`, `activate`, `active`, `changeModel`, `rename`, `delete`, `deleteConfirm`, `namePlaceholder`, `derivedLabel`) to every locale JSON.

- [ ] **Step 4: Run, expect pass.** Same command — PASS.

- [ ] **Step 5: Frontend gate**

Run: `cd frontend && npm run check`
Expected: ESLint + Prettier + Stylelint + Jest all clean.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/settings frontend/src/assets/i18n
git commit -m "feat(#334): switch the AI settings section to a saved-configuration list"
```

---

## Task 12: Full verification + mutation gate

- [ ] **Step 1: Backend full suite (SQLite)** — `cd backend && php bin/phpunit` — all green.
- [ ] **Step 2: Backend full suite (MySQL)** — `docker compose exec php vendor/bin/phpunit` — all green (note the known unrelated rate-limiter flake in isolation).
- [ ] **Step 3: Gates** — `cd backend && composer check && composer md`; PhpStorm inspections on changed PHP (ERROR/WARNING zero).
- [ ] **Step 4: Mutation on the diff** — `cd backend && composer infection:diff` — meets `minMsi`; kill any escaped mutant on the active-resolution / auto-activate / activate-reverify branches. If isolation is in doubt, prove it with `infection --noop` (every noop mutant must survive).
- [ ] **Step 5: Frontend** — `cd frontend && npm run check` — clean.
- [ ] **Step 6: Scan `backend/var/log/dev.log`** for deprecations/swallowed errors from the run; fix any.
- [ ] **Step 7: PR** — open against `develop`, body `Closes #334`, summarising the model change, the API replacement, and the two post-merge steps (migrate the live Docker DB, restart the worker). After merge, verify #334 closed, run the live migration, and `docker compose restart worker`.

---

## Self-Review

**Spec coverage:** §1 data model → Tasks 1,2,3,9. §2 activation rule → Task 5 (`activateWhenNoneActive`, `activate` model guard). §3 configurator → Tasks 4,5. §4 API → Task 8. §5 response shapes → Task 7 (+ `MeJson` in Task 4). §6 frontend → Tasks 10,11. §7 migration → Task 9. Testing section → per-task tests + Task 12. Out-of-band steps → Task 12 Step 7. No gaps.

**Placeholder scan:** every code step carries real code; the migration class name is the one placeholder by necessity (Doctrine stamps the timestamp) and Task 9 Step 1 generates it. Test bodies for Tasks 3/6/8/10/11 describe exact assertions with the real method names; the implementer writes the fixture bootstrapping to match the existing sibling tests (named explicitly) rather than an invented harness.

**Type consistency:** `getActiveAiProviderSettings`/`setActiveAiProviderSettings` (Task 2) are used verbatim in Tasks 4,5,8. `addConfiguration`/`chooseModel`/`rename`/`activate`/`deleteConfiguration`/`listConfigurations` (Task 5) match Task 8's calls. `AddedConfiguration->configuration`/`->modelIds` (Task 5) match Task 8's `added(...)`. `AiConfigurationForUser::require` (Task 6) matches Task 8. `AiSettingsJson::configuration(?int $activeId)`/`list`/`added` (Task 7) match Task 8. Frontend `AiConfig`/`AiList` fields (Task 10) match `AiSettingsJson::configuration` keys (Task 7). Consistent.
