# Duplicate an AI Provider Configuration — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Duplicate action to each AI provider configuration that reuses the stored (encrypted) base URL and API key in a new configuration with no model, so a second model on the same provider needs no re-entry of the key.

**Architecture:** A new server-side writer method on `AiProviderConfigurator` opens the source row's sealed key and re-seals it into a fresh `AiProviderSettings` row (model null, not active, name "Copy of …"). A thin `POST /api/me/ai/configs/{id}/duplicate` action exposes it, returning the standard config JSON. The Angular `AiSettingsService` gains a `duplicate()` method and each row in `ai-section.component` gains a Duplicate button.

**Tech Stack:** Symfony 7.4 / PHP 8.4 (backend), Angular 20 / signals (frontend), PHPUnit + Jest, libsodium via `ApiKeyCipher`.

## Global Constraints

- **Branch:** all work lands on `feature/347-duplicate-ai-provider-config`, branched off `develop`. **Do not commit to `feature/346-maintenance-tick-endpoint`** (shared checkout, concurrent session).
- **Re-add the spec first:** the design doc lives in the session scratchpad, not in the repo. On the new branch, copy it back to `docs/superpowers/specs/2026-08-10-duplicate-ai-provider-config-design.md` and commit it before Task 1.
- **Clean Code is mandatory** (see CLAUDE.md): intent-revealing names, one-thing methods, guard clauses, ≤3 params, no boolean-flag params, immutability, depend on interfaces.
- **Every touched `src` file must be PHPMD-clean and pass `composer check`** (PSR-12, PHPStan level max, `ThinControllerRule`) and PhpStorm inspections (block on ERROR/WARNING) before commit.
- **`declare(strict_types=1)`** in every PHP file.
- **No hex colours / raw px / media-query literals in `.scss` outside `theme/`**; component styles live in a sibling `.scss` (not inline). This feature adds no new styles — the Duplicate button reuses `app-button`.
- **The API key never reaches the client.** The JSON shape exposes only `apiKeyHint`. Re-sealing happens entirely inside `AiProviderConfigurator`; the opened plaintext is passed only to `ApiKeyCipher::seal` and never returned or logged.
- **Frontend gate:** `npm run check` (ESLint + Prettier + Stylelint + Jest). Prettier is 100-col.
- **Mutation gate:** `composer infection:diff` over changed files must meet `minMsi`.
- **Run parallel tests with `TEST_TOKEN`** set for isolation.

---

## File structure

| File | Change | Responsibility |
|---|---|---|
| `backend/src/Service/Ai/AiProviderConfigurator.php` | Modify | Add `duplicateConfiguration()` + private `copyName()` |
| `backend/tests/Service/Ai/AiProviderConfiguratorTest.php` | Modify | Unit/integration cases for duplication |
| `backend/src/Controller/Api/AiSettingsController.php` | Modify | Add `duplicate()` action + route |
| `backend/tests/Controller/Api/AiSettingsControllerTest.php` | Modify | Functional cases for the endpoint |
| `frontend/src/app/settings/ai-settings.service.ts` | Modify | Add `duplicate(id)` |
| `frontend/src/app/settings/ai-settings.service.spec.ts` | Modify | Spec for `duplicate()` |
| `frontend/src/app/settings/ai-section.component.html` | Modify | Duplicate button per row |
| `frontend/src/app/settings/ai-section.component.spec.ts` | Modify | Spec for the button wiring |
| `frontend/public/i18n/en.json`, `de.json` | Modify | `settings.ai.configs.duplicate` label |

---

## Task 1: Backend — `AiProviderConfigurator::duplicateConfiguration`

**Files:**
- Modify: `backend/src/Service/Ai/AiProviderConfigurator.php`
- Test: `backend/tests/Service/Ai/AiProviderConfiguratorTest.php`

**Interfaces:**
- Consumes: existing `ProviderCredentials AiProviderConfigurator::credentials(AiProviderSettings)` (opens the sealed key), `ApiKeyCipher::seal(int $userId, string $plainKey): SealedApiKey`, the `AiProviderSettings` constructor `(User, ?string $name, string $baseUrl, SealedApiKey, string $apiKeyHint, \DateTimeImmutable $verifiedAt)`, `AiProviderSettings::setSuppressReasoning(bool)`, `::setBatchConcurrency(int)`, the private `identify(User): int`, and `AiProviderSettingsRepository::countForUser(User): int`.
- Produces: `public function duplicateConfiguration(AiProviderSettings $source): AiProviderSettings` — persists and returns the new row. Throws `TooManyConfigurationsException` (cap) and `ApiKeyUnreadableException` (unreadable source key, via `credentials()`).

- [ ] **Step 1: Write the failing test — the copy re-seals to the same key, copies hint/settings, has no model, and is not active**

Add to `AiProviderConfiguratorTest.php`:

```php
public function testDuplicateReusesTheKeyAndStartsWithoutAModel(): void
{
    $configurator = $this->configurator(['gpt-4o', 'gpt-4o-mini']);
    $user = $this->user('cfg-duplicate@example.test');
    $added = $configurator->addConfiguration($user, 'Work OpenAI', 'https://api.example.test/v1', 'sk-abcdef1234');
    $configurator->chooseModel($added->configuration, 'gpt-4o');
    $configurator->setBatchConcurrency($added->configuration, 3);
    $configurator->setSuppressReasoning($added->configuration, false);

    $copy = $configurator->duplicateConfiguration($added->configuration);

    self::assertNotSame($added->configuration->getId(), $copy->getId());
    self::assertSame('Copy of Work OpenAI', $copy->getName());
    self::assertSame('https://api.example.test/v1', $copy->getBaseUrl());
    self::assertSame($added->configuration->getApiKeyHint(), $copy->getApiKeyHint());
    self::assertNull($copy->getModel());
    self::assertSame(3, $copy->batchConcurrency());
    self::assertFalse($copy->suppressesReasoning());
    self::assertNotSame($copy, $user->getActiveAiProviderSettings());
    // The re-sealed key opens back to the same plaintext under the copy's own row.
    self::assertSame('sk-abcdef1234', $configurator->credentials($copy)->apiKey);
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php bin/phpunit --filter testDuplicateReusesTheKeyAndStartsWithoutAModel`
Expected: FAIL — `Call to undefined method App\Service\Ai\AiProviderConfigurator::duplicateConfiguration()`.

- [ ] **Step 3: Implement `duplicateConfiguration` and `copyName`**

In `AiProviderConfigurator.php`, add the method (place it after `addConfiguration`) and the private helper (place it near `identify`). Note the name column is 120 chars, so the generated name is capped with `mb_substr`:

```php
    /**
     * A second model on a provider the account already configured needs the
     * same endpoint and key, so this reuses both rather than making the account
     * re-enter a key it can no longer read. The source is already a verified
     * row, so no live call is made: the copy carries the source's verifiedAt.
     * The model is deliberately left unset — choosing a different one is the
     * whole point — and the copy is not activated.
     *
     * @throws ApiKeyUnreadableException     the source key cannot be opened
     * @throws TooManyConfigurationsException the account is at the cap
     */
    public function duplicateConfiguration(AiProviderSettings $source): AiProviderSettings
    {
        $user = $source->getUser();

        if ($this->repository->countForUser($user) >= self::MAX_CONFIGURATIONS) {
            throw new TooManyConfigurationsException(
                'This account already holds the maximum number of AI configurations.',
            );
        }

        $sealed = $this->cipher->seal($this->identify($user), $this->credentials($source)->apiKey);

        $copy = new AiProviderSettings(
            $user,
            $this->copyName($source->getName()),
            $source->getBaseUrl(),
            $sealed,
            $source->getApiKeyHint(),
            $source->getVerifiedAt() ?? $this->clock->now(),
        );
        $copy->setSuppressReasoning($source->suppressesReasoning());
        $copy->setBatchConcurrency($source->batchConcurrency());

        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        return $copy;
    }
```

```php
    /**
     * The `name` column holds 120 characters, so a long source name is trimmed
     * to keep the prefixed copy inside it.
     */
    private function copyName(?string $sourceName): string
    {
        if (null === $sourceName || '' === $sourceName) {
            return 'Copy';
        }

        return mb_substr('Copy of '.$sourceName, 0, self::NAME_MAX_LENGTH);
    }
```

Add the constant next to the existing ones at the top of the class:

```php
    private const int NAME_MAX_LENGTH = 120; // matches AiProviderSettings::$name column length
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && php bin/phpunit --filter testDuplicateReusesTheKeyAndStartsWithoutAModel`
Expected: PASS.

- [ ] **Step 5: Write the failing test — an unnamed source becomes "Copy", and the cap is enforced**

```php
public function testDuplicateOfAnUnnamedConfigurationIsNamedCopy(): void
{
    $configurator = $this->configurator(['gpt-4o']);
    $user = $this->user('cfg-duplicate-unnamed@example.test');
    $added = $configurator->addConfiguration($user, null, 'https://api.example.test/v1', 'sk-abcdef1234');

    $copy = $configurator->duplicateConfiguration($added->configuration);

    self::assertSame('Copy', $copy->getName());
}

public function testDuplicateRefusesBeyondTheCap(): void
{
    $configurator = $this->configurator(['gpt-4o']);
    $user = $this->user('cfg-duplicate-cap@example.test');
    $first = $configurator->addConfiguration($user, null, 'https://api.example.test/v1', 'sk-key-0000');
    for ($i = 1; $i < 20; ++$i) {
        $configurator->addConfiguration($user, null, 'https://api.example.test/v1', sprintf('sk-key-%04d', $i));
    }

    $this->expectException(TooManyConfigurationsException::class);
    $configurator->duplicateConfiguration($first->configuration);
}
```

- [ ] **Step 6: Run the two tests to verify they pass**

Run: `cd backend && php bin/phpunit --filter 'testDuplicateOfAnUnnamedConfigurationIsNamedCopy|testDuplicateRefusesBeyondTheCap'`
Expected: PASS (the implementation already satisfies both).

- [ ] **Step 7: Persisted-state check — read the copy back from the database**

```php
public function testDuplicatePersistsAnIndependentRow(): void
{
    $configurator = $this->configurator(['gpt-4o']);
    $user = $this->user('cfg-duplicate-persist@example.test');
    $added = $configurator->addConfiguration($user, 'Original', 'https://api.example.test/v1', 'sk-abcdef1234');
    $configurator->duplicateConfiguration($added->configuration);

    // clear() first: otherwise the identity map serves the entities this test
    // already holds and the count/name would pass without any real write.
    $this->em->clear();
    $stored = $configurator->listConfigurations($this->reload('cfg-duplicate-persist@example.test'));

    self::assertCount(2, $stored);
    self::assertSame(['Original', 'Copy of Original'], array_map(
        static fn ($each): ?string => $each->getName(),
        $stored,
    ));
}
```

- [ ] **Step 8: Run the persisted-state test**

Run: `cd backend && php bin/phpunit --filter testDuplicatePersistsAnIndependentRow`
Expected: PASS.

- [ ] **Step 9: Lint the changed file**

Run: `cd backend && composer check && composer md`
Expected: no findings on `AiProviderConfigurator.php`. Also run PhpStorm inspections on the file; resolve any ERROR/WARNING.

- [ ] **Step 10: Commit**

```bash
git add backend/src/Service/Ai/AiProviderConfigurator.php backend/tests/Service/Ai/AiProviderConfiguratorTest.php
git commit -m "feat(#347): AiProviderConfigurator can duplicate a configuration"
```

---

## Task 2: Backend — `POST /api/me/ai/configs/{id}/duplicate`

**Files:**
- Modify: `backend/src/Controller/Api/AiSettingsController.php`
- Test: `backend/tests/Controller/Api/AiSettingsControllerTest.php`

**Interfaces:**
- Consumes: `AiConfigurationForUser::require(User, int): AiProviderSettings` (throws `ConfigurationNotFoundException`), `AiProviderConfigurator::duplicateConfiguration(AiProviderSettings): AiProviderSettings`, `AiProviderConfigurator::settingsFor(User): ?AiProviderSettings`, `AiSettingsJson::configuration(AiProviderSettings, ?int): array`, `RateLimitGuard::enforceForUser`. Existing API exceptions `AiConfigurationNotFoundApiException`, `TooManyAiConfigurationsApiException`, `AiKeyUnreadableApiException` are already imported in the controller.
- Produces: route `api_me_ai_duplicate` → `201` with the standard configuration JSON.

- [ ] **Step 1: Write the failing functional test — duplicate returns 201 with a keyless copy**

Add to `AiSettingsControllerTest.php`. Follow the existing helpers: `clientAnswering()`, `authenticate()`, the JSON-decode pattern used by the add test. Look at `testAddingAConfigurationReturnsItWithTheOfferedModels` (around line 147) for the exact decode/asserts idiom and reuse its shape.

```php
public function testDuplicatingAConfigurationReturnsAKeylessCopy(): void
{
    $client = $this->clientAnswering(['gpt-4o', 'gpt-4o-mini']);
    $this->authenticate($client, 'dup-endpoint@example.test');
    $source = $this->addConfig($client, 'Work OpenAI');           // helper below; returns the created id
    $this->putJson($client, sprintf('/api/me/ai/configs/%d/model', $source), ['model' => 'gpt-4o']);

    $client->request('POST', sprintf('/api/me/ai/configs/%d/duplicate', $source));

    self::assertResponseStatusCodeSame(201);
    $body = json_decode((string) $client->getResponse()->getContent(), true);
    self::assertIsArray($body);
    self::assertSame('Copy of Work OpenAI', $body['name']);
    self::assertSame('https://api.example.test/v1', $body['baseUrl']);
    self::assertNull($body['model']);
    self::assertFalse($body['ready']);
    self::assertFalse($body['active']);
    self::assertArrayNotHasKey('apiKey', $body);
    self::assertArrayNotHasKey('apiKeyCiphertext', $body);
    self::assertNotSame($source, $body['id']);
}
```

If the test file has no `addConfig`/`putJson` helpers with these exact names, use whatever equivalent helpers already exist there (the file already has POST/PUT JSON helpers — see lines 79/84). Do not invent duplicates; reuse the file's own helpers and adjust the calls above to match their signatures.

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php bin/phpunit --filter testDuplicatingAConfigurationReturnsAKeylessCopy`
Expected: FAIL — `404` (no route `api_me_ai_duplicate` yet).

- [ ] **Step 3: Add the controller action**

In `AiSettingsController.php`, add after the `add()` action (keep it thin — read id, delegate, return):

```php
    #[Route('/configs/{id}/duplicate', name: 'api_me_ai_duplicate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function duplicate(#[CurrentUser] User $user, int $id): JsonResponse
    {
        try {
            $source = $this->configuration->require($user, $id);
            $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);
            $copy = $this->configurator->duplicateConfiguration($source);
        } catch (ConfigurationNotFoundException $e) {
            throw new AiConfigurationNotFoundApiException($e);
        } catch (TooManyConfigurationsException $e) {
            throw new TooManyAiConfigurationsApiException($e);
        } catch (ApiKeyUnreadableException $e) {
            throw new AiKeyUnreadableApiException($e);
        }

        return new JsonResponse(
            AiSettingsJson::configuration($copy, $this->configurator->settingsFor($user)?->getId()),
            Response::HTTP_CREATED,
        );
    }
```

All referenced exception classes and `Response` are already imported in this file; no new `use` lines are needed.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && php bin/phpunit --filter testDuplicatingAConfigurationReturnsAKeylessCopy`
Expected: PASS.

- [ ] **Step 5: Write the failing test — a foreign/missing id answers 404**

```php
public function testDuplicatingAnUnknownConfigurationIs404(): void
{
    $client = $this->clientAnswering(['gpt-4o']);
    $this->authenticate($client, 'dup-unknown@example.test');

    $client->request('POST', '/api/me/ai/configs/999999/duplicate');

    self::assertResponseStatusCodeSame(404);
}
```

- [ ] **Step 6: Run the 404 test**

Run: `cd backend && php bin/phpunit --filter testDuplicatingAnUnknownConfigurationIs404`
Expected: PASS.

- [ ] **Step 7: Run the whole AI controller suite + lint**

Run: `cd backend && php bin/phpunit --filter AiSettingsControllerTest && composer check && composer md`
Expected: green; no findings on `AiSettingsController.php`; `ThinControllerRule` passes (the action carries no logic). Run PhpStorm inspections on the file and resolve ERROR/WARNING.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Controller/Api/AiSettingsController.php backend/tests/Controller/Api/AiSettingsControllerTest.php
git commit -m "feat(#347): POST /api/me/ai/configs/{id}/duplicate"
```

---

## Task 3: Frontend — `AiSettingsService.duplicate`

**Files:**
- Modify: `frontend/src/app/settings/ai-settings.service.ts`
- Test: `frontend/src/app/settings/ai-settings.service.spec.ts`

**Interfaces:**
- Consumes: existing private `run<T>()`, `upsert(config: AiConfig)`, and `AiConfig` shape.
- Produces: `duplicate(id: number): void` — POSTs to `/api/me/ai/configs/${id}/duplicate` and `upsert`s the returned `AiConfig`.

- [ ] **Step 1: Write the failing spec**

Add to `ai-settings.service.spec.ts` (uses the file's existing `config()` factory and `base`/`ctrl` setup):

```ts
it('duplicates a configuration and adds the copy to the list', () => {
  svc.configs.set([config({ id: 1, name: 'Work OpenAI', active: true, ready: true, model: 'gpt-4o' })]);

  svc.duplicate(1);
  const request = ctrl.expectOne(`${base}/api/me/ai/configs/1/duplicate`);
  expect(request.request.method).toBe('POST');

  request.flush(config({ id: 2, name: 'Copy of Work OpenAI' }));

  expect(svc.configs().map((each) => each.id)).toEqual([1, 2]);
  expect(svc.configs()[1].name).toBe('Copy of Work OpenAI');
  expect(svc.configs()[1].active).toBe(false);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd frontend && npx jest ai-settings.service`
Expected: FAIL — `svc.duplicate is not a function`.

- [ ] **Step 3: Implement `duplicate`**

In `ai-settings.service.ts`, add after `activate()`:

```ts
  duplicate(id: number): void {
    this.run(
      this.http.post<AiConfig>(`${this.base}/api/me/ai/configs/${id}/duplicate`, {}),
      (config) => this.upsert(config),
    );
  }
```

- [ ] **Step 4: Run the spec to verify it passes**

Run: `cd frontend && npx jest ai-settings.service`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/settings/ai-settings.service.ts frontend/src/app/settings/ai-settings.service.spec.ts
git commit -m "feat(#347): AiSettingsService.duplicate"
```

---

## Task 4: Frontend — Duplicate button per row + i18n

**Files:**
- Modify: `frontend/src/app/settings/ai-section.component.html`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/settings/ai-section.component.spec.ts`

**Interfaces:**
- Consumes: `AiSettingsService.duplicate(id)` from Task 3, existing `app-button`, the `config` loop variable, and the `ai.busy()` signal.
- Produces: a `.duplicate` button on each config row that calls `ai.duplicate(config.id)`.

- [ ] **Step 1: Add the i18n key to both locales**

In `frontend/public/i18n/en.json`, inside `settings.ai.configs`, add after `"changeModel"`:

```json
        "duplicate": "Duplicate",
```

In `frontend/public/i18n/de.json`, inside the same block, after its `"changeModel"`:

```json
        "duplicate": "Duplizieren",
```

- [ ] **Step 2: Write the failing component spec**

Add to `ai-section.component.spec.ts`. Match the file's existing harness (TestBed setup, how it stubs `AiSettingsService`, how it queries the DOM). Mirror the pattern the spec already uses for the `.change-model` or `.delete` button; the assertion is that clicking `.duplicate` calls `ai.duplicate` with the row id:

```ts
it('duplicates the row when the Duplicate button is clicked', () => {
  // arrange one config row via the same stub/setup the other button tests use,
  // with config id 7, then:
  const button: HTMLButtonElement = fixture.nativeElement.querySelector('.duplicate button');
  expect(button).not.toBeNull();
  button.click();
  expect(duplicateSpy).toHaveBeenCalledWith(7);
});
```

Use the spec file's existing spy/stub mechanism for `AiSettingsService` (do not introduce a new mocking style); name the spy to match the file's convention.

- [ ] **Step 3: Run it to verify it fails**

Run: `cd frontend && npx jest ai-section.component`
Expected: FAIL — `.duplicate button` is null.

- [ ] **Step 4: Add the button to the template**

In `ai-section.component.html`, inside the `.acts` block that renders when `renamingId() !== config.id`, add the Duplicate button after `.change-model` and before `.rename`:

```html
                <app-button
                  class="duplicate"
                  [disabled]="ai.busy()"
                  (click)="ai.duplicate(config.id)"
                >
                  {{ 'settings.ai.configs.duplicate' | transloco }}
                </app-button>
```

No component `.ts` change is needed — the template calls `ai.duplicate` directly, the same way `.change-model` calls `ai.loadModels` and `.activate` calls `ai.activate`.

- [ ] **Step 5: Run the spec to verify it passes**

Run: `cd frontend && npx jest ai-section.component`
Expected: PASS.

- [ ] **Step 6: Run the frontend gate**

Run: `cd frontend && npm run check`
Expected: ESLint + Prettier + Stylelint + Jest all green. (Prettier is 100-col; the button block above already fits.)

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/settings/ai-section.component.html frontend/src/app/settings/ai-section.component.spec.ts frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#347): Duplicate button on each AI provider row"
```

---

## Task 5: Full verification + PR

**Files:** none (verification only).

- [ ] **Step 1: Backend suite (SQLite, native)**

Run: `cd backend && php bin/phpunit`
Expected: green.

- [ ] **Step 2: Backend suite (MySQL leg, Docker)**

Run: `docker compose exec php vendor/bin/phpunit`
Expected: green. (A pre-existing rate-limiter flake in the full MySQL run is known and unrelated — re-run the failing case in isolation to confirm.)

- [ ] **Step 3: Mutation gate over the branch's changes**

Run: `cd backend && composer infection:diff`
Expected: meets `minMsi`. Address escaped mutants on the new lines (they arrive as annotations); a full-sweep score below the gate is expected and not a regression.

- [ ] **Step 4: Frontend gate**

Run: `cd frontend && npm run check`
Expected: green.

- [ ] **Step 5: Scan the backend dev log**

Run: `cd backend && tail -n 60 var/log/dev.log`
Expected: no new deprecations or swallowed errors from the duplicate path.

- [ ] **Step 6: Open the PR into `develop`**

```bash
git push -u origin feature/347-duplicate-ai-provider-config
gh pr create --base develop --title "feat: duplicate an AI provider configuration (#347)" --body "Closes #347"
```

Confirm CI is green on the exact SHA, then verify #347 auto-closed on merge (do not close it by hand).

---

## Self-review notes

- **Spec coverage:** re-seal server-side (Task 1), copy baseUrl/hint/suppressReasoning/batchConcurrency/verifiedAt + null model + not active + "Copy of …" name (Task 1), 20-cap + 404 (Tasks 1–2), no live verification (Task 1 makes no catalog call), 201 + no key leak (Task 2), `duplicate()` upsert (Task 3), per-row button + i18n (Task 4). All covered.
- **Type consistency:** `duplicateConfiguration(AiProviderSettings): AiProviderSettings` used identically in Tasks 1 and 2; `duplicate(id: number): void` used identically in Tasks 3 and 4; JSON keys match `AiSettingsJson::configuration`.
- **Name length:** generated name capped at 120 via `NAME_MAX_LENGTH` to fit the column; unnamed source → `"Copy"`.
- **verifiedAt nullability:** source `getVerifiedAt()` is `?DateTimeImmutable`; the `?? $this->clock->now()` guard keeps the non-null constructor contract satisfied even for the (in-practice-impossible) null case.
