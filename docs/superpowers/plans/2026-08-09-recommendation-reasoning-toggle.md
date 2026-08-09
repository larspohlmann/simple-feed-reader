# Per-config reasoning toggle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let each AI configuration ask the provider not to reason on recommendation calls, by sending `reasoning: {"effort": "none"}` when a per-config `suppressReasoning` flag is on (the default).

**Architecture:** A boolean column on `AiProviderSettings` flows through `CompletionRequest` into `OpenAiCompatibleChatClient`, which adds the OpenRouter `reasoning` extension only when the flag is set. A new `PUT /api/me/ai/configs/{id}/reasoning` route (mirroring `rename`) edits the flag; the settings page shows a checkbox per row. Default on fixes #320 for OpenRouter/LM Studio out of the box; a strict endpoint (direct OpenAI) turns it off.

**Tech Stack:** Symfony 7.4 / PHP 8.4 backend, Doctrine ORM + migrations (MySQL + SQLite), PHPUnit; Angular 20 signals + Jest + Transloco frontend.

## Global Constraints

- `declare(strict_types=1);` in every PHP file. PSR-12 (`composer cs`). PHPStan level max over `src` and `tests`. PHPMD codesize-clean on every touched `src` file. PhpStorm inspections clean (ERROR/WARNING) on changed PHP.
- Clean Code: names reveal intent; functions do one thing; guard clauses; no boolean-flag parameters that split behaviour; `final readonly` value objects; controllers stay thin (no private method that carries responsibility — enforced by `ThinControllerRule`).
- Frontend: standalone components + signals, no NgModules. No hex colours and no raw `px`/media-query literals in `.scss` outside `src/app/theme/`. Component styles in the sibling `.scss` via `styleUrl`, never inline. Prettier 100-col. Run `npm run check` from `frontend/`.
- Datetimes are naive UTC (not relevant here — no dates added).
- Migrations are verified on a scratch DB, never the real dev DB; the test schema is built from ORM metadata and never runs a migration, so the migration needs its own verification. Applied to the live Docker MySQL only after merge.
- New i18n keys must be added to BOTH `frontend/public/i18n/en.json` and `frontend/public/i18n/de.json`.
- Field naming, verbatim: entity property `suppressReasoning`; DB column `suppress_reasoning`; getter `suppressesReasoning(): bool`; setter `setSuppressReasoning(bool)`; DTO `SetReasoningRequest` with public `bool $suppressReasoning`; JSON key `suppressReasoning`; the payload member is `'reasoning' => ['effort' => 'none']`.

---

## File Structure

**Backend**
- `backend/src/Entity/AiProviderSettings.php` — new column + accessors (modify).
- `backend/migrations/VersionYYYYMMDDHHMMSS.php` — add `suppress_reasoning` (create).
- `backend/src/Service/Recommendation/CompletionRequest.php` — new field (modify).
- `backend/src/Service/Recommendation/OpenAiCompatibleChatClient.php` — conditional payload member, extracted `completionPayload()` (modify).
- `backend/src/Service/Recommendation/RecommendationRunAdvancer.php` — pass the flag in `requestFor()` (modify).
- `backend/src/Dto/Ai/SetReasoningRequest.php` — request DTO (create).
- `backend/src/Service/Ai/AiProviderConfigurator.php` — `setSuppressReasoning()` (modify).
- `backend/src/Controller/Api/AiSettingsController.php` — new route (modify).
- `backend/src/Http/AiSettingsJson.php` — new key (modify).

**Backend tests**
- `tests/Entity/AiProviderSettingsTest.php`, `tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php`, `tests/Support/StubChatClient.php`, `tests/Service/Recommendation/RecommendationRunAdvancerTest.php`, `tests/Service/Ai/AiProviderConfiguratorTest.php`, `tests/Controller/Api/AiSettingsControllerTest.php`, `tests/Http/AiSettingsJsonTest.php`.

**Frontend**
- `frontend/src/app/settings/ai-settings.service.ts` (+ `.spec.ts`) — `AiConfig` field + `setReasoning` (modify).
- `frontend/src/app/settings/ai-section.component.html` / `.ts` (+ `.spec.ts`) / `.scss` — checkbox row (modify).
- `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` — new strings (modify).

---

### Task 1: Entity column + accessors

**Files:**
- Modify: `backend/src/Entity/AiProviderSettings.php`
- Test: `backend/tests/Entity/AiProviderSettingsTest.php`

**Interfaces:**
- Produces: `AiProviderSettings::suppressesReasoning(): bool` (default `true` on a new row), `AiProviderSettings::setSuppressReasoning(bool $suppressReasoning): void`. The flag is NOT reset by `replaceConnection()`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Entity/AiProviderSettingsTest.php`:

```php
public function testANewRowSuppressesReasoningByDefault(): void
{
    self::assertTrue($this->settings()->suppressesReasoning());
}

public function testSettingSuppressReasoningRoundTrips(): void
{
    $settings = $this->settings();

    $settings->setSuppressReasoning(false);

    self::assertFalse($settings->suppressesReasoning());
}

public function testReplacingTheConnectionKeepsTheReasoningPreference(): void
{
    $settings = $this->settings();
    $settings->setSuppressReasoning(false);

    $settings->replaceConnection(
        'https://other.example.test/v1',
        $this->sealed('b3RoZXI='),
        'wxyz',
        new \DateTimeImmutable('2026-08-06 11:00:00'),
    );

    self::assertFalse($settings->suppressesReasoning());
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Entity/AiProviderSettingsTest.php`
Expected: FAIL — `Call to undefined method App\Entity\AiProviderSettings::suppressesReasoning()`.

- [ ] **Step 3: Add the column and accessors**

In `AiProviderSettings.php`, add the property after `$modelContextWindow` (keep the `use Doctrine\DBAL\Types\Types;` import already present):

```php
/**
 * Whether the recommendation call asks the provider not to reason. Default
 * true: ranking never needs a thinking phase, and a reasoning model that
 * reasons here is pure cost (#320/#323). A strict endpoint that rejects the
 * `reasoning` field — a direct OpenAI URL — turns it off.
 */
#[ORM\Column(options: ['default' => 1])]
private bool $suppressReasoning = true;
```

Add the accessors next to `getModel()`/`chooseModel()`:

```php
public function suppressesReasoning(): bool
{
    return $this->suppressReasoning;
}

public function setSuppressReasoning(bool $suppressReasoning): void
{
    $this->suppressReasoning = $suppressReasoning;
}
```

Leave `replaceConnection()` untouched — the preference survives an endpoint or key change.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Entity/AiProviderSettingsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Entity/AiProviderSettings.php backend/tests/Entity/AiProviderSettingsTest.php
git commit -m "feat(#323): carry a per-config suppressReasoning flag on AiProviderSettings"
```

---

### Task 2: Migration

**Files:**
- Create: `backend/migrations/VersionYYYYMMDDHHMMSS.php` (use the real UTC timestamp; match the format of the newest file in `backend/migrations/`)

**Interfaces:**
- Produces: the `user_ai_settings.suppress_reasoning` column (`TINYINT(1) NOT NULL DEFAULT 1` on MySQL, `BOOLEAN NOT NULL DEFAULT 1` on SQLite). Existing rows become "suppress".

- [ ] **Step 1: Write the migration**

Create the file (follow `Version20260809130249.php` for the platform-aware structure):

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Each AI configuration can ask the provider not to reason (#323).
 *
 * `user_ai_settings` gains `suppress_reasoning`, default 1: ranking never
 * needs a thinking phase, so existing rows suppress like new ones. A strict
 * endpoint turns it off per configuration.
 */
final class VersionYYYYMMDDHHMMSS extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-config suppress_reasoning to user_ai_settings (#323)';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_ai_settings ADD suppress_reasoning TINYINT(1) DEFAULT 1 NOT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE user_ai_settings ADD COLUMN suppress_reasoning BOOLEAN DEFAULT 1 NOT NULL');

            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_ai_settings DROP suppress_reasoning');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE user_ai_settings DROP COLUMN suppress_reasoning');

            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }
}
```

- [ ] **Step 2: Verify the migration on a scratch SQLite DB (never the dev DB)**

Run from `backend/`:

```bash
SCRATCH=/private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/reasoning-migration-check.sqlite
rm -f "$SCRATCH"
DATABASE_URL="sqlite:///$SCRATCH" php bin/console doctrine:migrations:migrate --no-interaction --env=dev
DATABASE_URL="sqlite:///$SCRATCH" php bin/console doctrine:schema:validate --env=dev
rm -f "$SCRATCH"
```

Expected: migrations apply cleanly from empty, and `schema:validate` reports the mapping and database are in sync (proving the ORM `#[ORM\Column]` from Task 1 matches the DDL here).

- [ ] **Step 3: Confirm no metadata drift remains**

Run: `cd backend && php bin/console doctrine:migrations:diff --env=dev` on a freshly-migrated scratch DB (same `DATABASE_URL` trick), then discard any generated file. Expected: it reports "No changes detected" for `user_ai_settings`. Delete any file `diff` created.

- [ ] **Step 4: Commit**

```bash
git add backend/migrations/
git commit -m "feat(#323): migrate user_ai_settings with suppress_reasoning defaulting on"
```

---

### Task 3: CompletionRequest field + client payload

**Files:**
- Modify: `backend/src/Service/Recommendation/CompletionRequest.php`
- Modify: `backend/src/Service/Recommendation/OpenAiCompatibleChatClient.php`
- Test: `backend/tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `CompletionRequest` gains a fifth constructor parameter `bool $suppressReasoning` (public readonly). When `true`, the client's JSON body contains `reasoning.effort = "none"`; when `false`, the `reasoning` key is absent.

- [ ] **Step 1: Add the field to `CompletionRequest`**

Add the parameter (after `$responseSchema`) with a docblock line:

```php
public function __construct(
    public string $model,
    public array $messages,
    public int $maxAnswerTokens,
    public JsonSchema $responseSchema,
    // When true, the request asks the provider not to reason (#323). Sent as
    // the OpenRouter `reasoning: {"effort": "none"}` extension; an endpoint
    // that does not know the field ignores it.
    public bool $suppressReasoning,
) {
}
```

- [ ] **Step 2: Update the existing client test helpers, then write the failing tests**

In `OpenAiCompatibleChatClientTest.php`, update the `request()` helper to keep the existing whole-body assertion valid (reasoning OFF), and add a suppressing builder:

```php
private function request(): CompletionRequest
{
    return new CompletionRequest('m', $this->messages(), 2048, $this->schema(), false);
}

private function suppressingRequest(): CompletionRequest
{
    return new CompletionRequest('m', $this->messages(), 2048, $this->schema(), true);
}
```

Add two tests that capture the request body (reuse the `$seen` capture pattern from `testReturnsTheAssistantContentJoinedFromTheStream`; a small private helper that returns the decoded body keeps them short):

```php
public function testAsksTheProviderNotToReasonWhenSuppressed(): void
{
    $body = $this->captureRequestBody($this->suppressingRequest());

    self::assertSame(['effort' => 'none'], $body['reasoning']);
}

public function testOmitsTheReasoningFieldWhenNotSuppressed(): void
{
    $body = $this->captureRequestBody($this->request());

    self::assertArrayNotHasKey('reasoning', $body);
}
```

Add the capture helper to the test class:

```php
/** @return array<string, mixed> the decoded JSON request body */
private function captureRequestBody(CompletionRequest $request): array
{
    $seen = null;
    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
        $seen = $options['body'] ?? '';

        return new MockResponse('{"choices":[{"message":{"content":"{\"recommendations\":[]}"}}]}');
    });

    $this->clientUsing($client)->complete($this->credentials(), $request, new NullCompletionStreamObserver());
    self::assertIsString($seen);

    $decoded = json_decode($seen, true);
    self::assertIsArray($decoded);

    return $decoded;
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php`
Expected: the two new tests FAIL (no `reasoning` key produced yet); the pre-existing whole-body test still PASSES because `request()` now passes `false`.

- [ ] **Step 4: Extract `completionPayload()` and add the conditional member**

In `OpenAiCompatibleChatClient.php`, replace the inline `'json' => [ ... ]` in `request()` with `'json' => $this->completionPayload($request),` and add the private method (keeps `request()` thin and PHPMD-clean). Preserve every existing comment on the payload members:

```php
/**
 * @return array<string, mixed>
 */
private function completionPayload(CompletionRequest $request): array
{
    $payload = [
        'model' => $request->model,
        // A strict json_schema, not the older json_object: current LM
        // Studio rejects json_object with a 400, and grammar-constrained
        // decoding also keeps a weak local model's answer parseable
        // (#329). The name and schema ride on the request, set by the
        // phase that built the prompt.
        'response_format' => [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => $request->responseSchema->name,
                'strict' => true,
                'schema' => $request->responseSchema->schema,
            ],
        ],
        'messages' => $request->messages,
        'stream' => true,
        // The only guard here that prevents spend rather than discarding
        // what was already billed: the byte caps and the timeouts all fire
        // after the provider has generated the tokens. Sized by the caller
        // from the same reserve the prompt left room for, so it can never
        // truncate a reply the prompt legitimately asked for.
        'max_tokens' => $request->maxAnswerTokens,
    ];

    if ($request->suppressReasoning) {
        // OpenRouter's reasoning extension: fully disables the thinking
        // phase, which ranking never needs (#323). An endpoint that does
        // not know the field ignores an unknown top-level member; a strict
        // one is why the flag is per-config rather than always on.
        $payload['reasoning'] = ['effort' => 'none'];
    }

    return $payload;
}
```

Note: the existing whole-body test asserts the exact array (`model`, `messages`, `response_format`, `stream`, `max_tokens`). `assertSame` on an associative array is order-independent, so key order does not matter — but keep `model` first and the four original keys present so that test stays green.

- [ ] **Step 5: Run the client tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Recommendation/CompletionRequest.php backend/src/Service/Recommendation/OpenAiCompatibleChatClient.php backend/tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php
git commit -m "feat(#323): send reasoning effort none when a request suppresses reasoning"
```

---

### Task 4: Advancer carries the flag; stub records it

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationRunAdvancer.php`
- Modify: `backend/tests/Support/StubChatClient.php`
- Test: `backend/tests/Service/Recommendation/RecommendationRunAdvancerTest.php`

**Interfaces:**
- Consumes: `AiProviderSettings::suppressesReasoning()` (Task 1), `CompletionRequest`'s fifth parameter (Task 3).
- Produces: `StubChatClient::calls()` entries gain a `suppressReasoning: bool` key.

- [ ] **Step 1: Record the flag in the stub**

In `StubChatClient.php`, extend the recorded call shape. Update both the `@var`/`@return` docblocks and the `complete()` body:

```php
$this->calls[] = [
    'model' => $request->model,
    'messages' => $request->messages,
    'maxAnswerTokens' => $request->maxAnswerTokens,
    'responseSchemaName' => $request->responseSchema->name,
    // Proves the advancer read the account's per-config preference into the
    // request rather than hardcoding it (#323).
    'suppressReasoning' => $request->suppressReasoning,
];
```

Add `suppressReasoning: bool,` to both the `@var array<...>` and the `calls(): array` `@return` shape.

- [ ] **Step 2: Write the failing test**

Add to `RecommendationRunAdvancerTest.php` (near the other `calls()` assertions). It seeds a ready config, flips the flag OFF, and proves the batch call carries the account's value rather than the default:

```php
public function testTheBatchCallCarriesTheAccountsReasoningPreference(): void
{
    $this->seedReadyAiSettings($this->user);
    $config = $this->em->getRepository(AiProviderSettings::class)->findOneBy(['user' => $this->user]);
    self::assertNotNull($config);
    $config->setSuppressReasoning(false);
    $this->em->flush();

    for ($i = 0; $i < 3; $i++) {
        $this->entry('entry-' . $i, sprintf('2026-07-%02dT00:00:00Z', 10 + $i));
    }
    $this->starter()->start($this->user);
    $this->advancer()->advance($this->user); // snapshot tick
    $this->stubChatClient()->queueContent('{"recommendations":[]}');
    $this->advancer()->advance($this->user); // batch tick

    $calls = $this->stubChatClient()->calls();
    self::assertNotSame([], $calls);
    self::assertFalse($calls[0]['suppressReasoning']);
}
```

Add `use App\Entity\AiProviderSettings;` to the test's imports if it is not already there.

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Recommendation/RecommendationRunAdvancerTest.php --filter testTheBatchCallCarriesTheAccountsReasoningPreference`
Expected: FAIL — `CompletionRequest::__construct()` missing the fifth argument in `requestFor()` (a TypeError), or an undefined `suppressReasoning` key.

- [ ] **Step 4: Pass the flag in `requestFor()`**

In `RecommendationRunAdvancer::requestFor()`, add the fifth argument:

```php
return new CompletionRequest(
    $settings->getModel() ?? '',
    $messages,
    $this->promptBuilder->outputTokenReserve($replyItemCount),
    $responseSchema->toJsonSchema(),
    $settings->suppressesReasoning(),
);
```

- [ ] **Step 5: Run the advancer suite to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Recommendation/RecommendationRunAdvancerTest.php`
Expected: PASS (all cases, including the new one).

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Recommendation/RecommendationRunAdvancer.php backend/tests/Support/StubChatClient.php backend/tests/Service/Recommendation/RecommendationRunAdvancerTest.php
git commit -m "feat(#323): flow each config's reasoning preference into the completion request"
```

---

### Task 5: API — DTO, configurator, route, JSON key

**Files:**
- Create: `backend/src/Dto/Ai/SetReasoningRequest.php`
- Modify: `backend/src/Service/Ai/AiProviderConfigurator.php`
- Modify: `backend/src/Controller/Api/AiSettingsController.php`
- Modify: `backend/src/Http/AiSettingsJson.php`
- Test: `backend/tests/Http/AiSettingsJsonTest.php`, `backend/tests/Service/Ai/AiProviderConfiguratorTest.php`, `backend/tests/Controller/Api/AiSettingsControllerTest.php`

**Interfaces:**
- Consumes: `AiProviderSettings::setSuppressReasoning()` (Task 1).
- Produces: `SetReasoningRequest { public bool $suppressReasoning }`; `AiProviderConfigurator::setSuppressReasoning(AiProviderSettings $settings, bool $suppressReasoning): void`; route `PUT /api/me/ai/configs/{id}/reasoning` named `api_me_ai_set_reasoning`; JSON key `suppressReasoning` on every configuration payload.

- [ ] **Step 1: Write the failing JSON-shape test**

Add to `tests/Http/AiSettingsJsonTest.php` (mirror the existing `configuration()` assertions):

```php
public function testTheConfigurationShapeCarriesTheReasoningPreference(): void
{
    $settings = $this->settings('gpt-4o');
    $settings->setSuppressReasoning(false);

    $shape = AiSettingsJson::configuration($settings, null);

    self::assertFalse($shape['suppressReasoning']);
}
```

(If `$this->settings()` in that test does not choose a model, use whatever builder the file already uses for a plain configuration — the flag is independent of the model.)

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php bin/phpunit tests/Http/AiSettingsJsonTest.php`
Expected: FAIL — undefined key `suppressReasoning`.

- [ ] **Step 3: Add the JSON key**

In `AiSettingsJson::configuration()`, add the key to the returned array (after `'model'`):

```php
'suppressReasoning' => $settings->suppressesReasoning(),
```

- [ ] **Step 4: Run the JSON test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Http/AiSettingsJsonTest.php`
Expected: PASS.

- [ ] **Step 5: Create the DTO**

Create `backend/src/Dto/Ai/SetReasoningRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SetReasoningRequest
{
    public function __construct(
        #[Assert\NotNull]
        public bool $suppressReasoning,
    ) {
    }
}
```

- [ ] **Step 6: Write the failing configurator test**

Add to `tests/Service/Ai/AiProviderConfiguratorTest.php` (follow the existing `rename` test there for setup — a persisted `AiProviderSettings` and a real `EntityManager`):

```php
public function testSetSuppressReasoningPersistsTheFlag(): void
{
    $settings = $this->persistedConfiguration();

    $this->configurator()->setSuppressReasoning($settings, false);

    $reloaded = $this->reload($settings);
    self::assertFalse($reloaded->suppressesReasoning());
}
```

Use whatever helper the file already has to persist a configuration and to reload it (mirror the nearest existing test; if none reloads, assert on `$settings->suppressesReasoning()` after the call — the flush is what is under test).

- [ ] **Step 7: Run it to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Ai/AiProviderConfiguratorTest.php`
Expected: FAIL — `Call to undefined method ...::setSuppressReasoning()`.

- [ ] **Step 8: Add the configurator method**

In `AiProviderConfigurator.php`, next to `rename()`:

```php
public function setSuppressReasoning(AiProviderSettings $settings, bool $suppressReasoning): void
{
    $settings->setSuppressReasoning($suppressReasoning);
    $this->entityManager->flush();
}
```

- [ ] **Step 9: Run the configurator test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Ai/AiProviderConfiguratorTest.php`
Expected: PASS.

- [ ] **Step 10: Write the failing controller tests**

In `tests/Controller/Api/AiSettingsControllerTest.php`, add a functional test and extend the ownership data provider:

```php
public function testSettingReasoningChangesTheConfiguration(): void
{
    $client = $this->clientAnswering(['gpt-4o']);
    $this->accountOn($client, 'ai-reasoning@example.test');
    $added = $this->addConfiguration($client);
    $id = $added['id'];
    self::assertIsInt($id);
    self::assertTrue($added['suppressReasoning']); // default on

    $this->putJson($client, sprintf('/api/me/ai/configs/%d/reasoning', $id), '{"suppressReasoning":false}');

    self::assertResponseIsSuccessful();
    self::assertFalse($this->payload($client)['suppressReasoning']);

    $client->request('GET', '/api/me/ai');
    $payload = $this->payload($client);
    self::assertIsArray($payload['configs']);
    self::assertIsArray($payload['configs'][0]);
    self::assertFalse($payload['configs'][0]['suppressReasoning']);
}
```

Add to the `idBearingRoutes()` provider:

```php
yield 'setting reasoning' => ['PUT', '/reasoning', '{"suppressReasoning":false}'];
```

- [ ] **Step 11: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Controller/Api/AiSettingsControllerTest.php`
Expected: FAIL — the new route 404s (route not defined) and the ownership case does not reach the 404 branch it expects.

- [ ] **Step 12: Add the controller route**

In `AiSettingsController.php`, add the action (mirror `rename` exactly — ownership-scoped, no rate-limit guard, returns the configuration). Add `use App\Dto\Ai\SetReasoningRequest;`:

```php
#[Route('/configs/{id}/reasoning', name: 'api_me_ai_set_reasoning', requirements: ['id' => '\d+'], methods: ['PUT'])]
public function setReasoning(
    #[CurrentUser] User $user,
    int $id,
    #[MapRequestPayload] SetReasoningRequest $request,
): JsonResponse {
    try {
        $configuration = $this->configuration->require($user, $id);
    } catch (ConfigurationNotFoundException $e) {
        throw new AiConfigurationNotFoundApiException($e);
    }

    $this->configurator->setSuppressReasoning($configuration, $request->suppressReasoning);

    return new JsonResponse(
        AiSettingsJson::configuration($configuration, $this->configurator->settingsFor($user)?->getId()),
    );
}
```

- [ ] **Step 13: Run the controller suite to verify it passes**

Run: `cd backend && php bin/phpunit tests/Controller/Api/AiSettingsControllerTest.php`
Expected: PASS (including the data-provider ownership case for `/reasoning`).

- [ ] **Step 14: Commit**

```bash
git add backend/src/Dto/Ai/SetReasoningRequest.php backend/src/Service/Ai/AiProviderConfigurator.php backend/src/Controller/Api/AiSettingsController.php backend/src/Http/AiSettingsJson.php backend/tests/Http/AiSettingsJsonTest.php backend/tests/Service/Ai/AiProviderConfiguratorTest.php backend/tests/Controller/Api/AiSettingsControllerTest.php
git commit -m "feat(#323): add PUT /api/me/ai/configs/{id}/reasoning and expose the flag"
```

- [ ] **Step 15: Backend quality gate**

Run: `cd backend && composer check && composer md` (warm the cache first if PHPStan complains: `php bin/console cache:warmup`).
Also run PhpStorm inspections on the changed PHP via `mcp__phpstorm__lint_files`; block on ERROR/WARNING.
Expected: all clean. Fix any PHPMD/inspection finding on a touched `src` file before moving on.

---

### Task 6: Frontend service + type

**Files:**
- Modify: `frontend/src/app/settings/ai-settings.service.ts`
- Test: `frontend/src/app/settings/ai-settings.service.spec.ts`

**Interfaces:**
- Produces: `AiConfig` gains `readonly suppressReasoning: boolean`; `AiSettingsService.setReasoning(id: number, suppressReasoning: boolean): void` issues `PUT /api/me/ai/configs/{id}/reasoning` with body `{ suppressReasoning }` and upserts the returned config.

- [ ] **Step 1: Add `suppressReasoning` to the test factory and write the failing test**

In `ai-settings.service.spec.ts`, add `suppressReasoning: true,` to the `config()` factory object (after `active: false,`), then add:

```js
it('sets the reasoning preference in place', () => {
  svc.load();
  ctrl.expectOne(`${base}/api/me/ai`).flush({ configs: [config({ id: 5 })], activeId: null });

  svc.setReasoning(5, false);
  const request = ctrl.expectOne(`${base}/api/me/ai/configs/5/reasoning`);
  expect(request.request.method).toBe('PUT');
  expect(request.request.body).toEqual({ suppressReasoning: false });
  request.flush(config({ id: 5, suppressReasoning: false }));

  expect(svc.configs()[0].suppressReasoning).toBe(false);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd frontend && npx jest ai-settings.service`
Expected: FAIL — `svc.setReasoning is not a function` / type error on `suppressReasoning`.

- [ ] **Step 3: Add the field and method**

In `ai-settings.service.ts`, add to the `AiConfig` interface (after `active`):

```ts
readonly suppressReasoning: boolean;
```

Add the method next to `rename()`:

```ts
setReasoning(id: number, suppressReasoning: boolean): void {
  this.run(
    this.http.put<AiConfig>(`${this.base}/api/me/ai/configs/${id}/reasoning`, { suppressReasoning }),
    (config) => this.upsert(config),
  );
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `cd frontend && npx jest ai-settings.service`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/settings/ai-settings.service.ts frontend/src/app/settings/ai-settings.service.spec.ts
git commit -m "feat(#323): add setReasoning to the AI settings service"
```

---

### Task 7: Frontend checkbox row + i18n

**Files:**
- Modify: `frontend/src/app/settings/ai-section.component.html`
- Modify: `frontend/src/app/settings/ai-section.component.ts`
- Modify: `frontend/src/app/settings/ai-section.component.scss`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/settings/ai-section.component.spec.ts`

**Interfaces:**
- Consumes: `AiSettingsService.setReasoning` (Task 6).
- Produces: a per-row checkbox bound to `config.suppressReasoning` that calls `ai.setReasoning(config.id, checked)` on change.

- [ ] **Step 1: Add i18n strings (en + de)**

In `frontend/public/i18n/en.json`, under `settings.ai.configs`, add:

```json
"reasoning": "Ask the model not to reason",
"reasoningHint": "Leave on for most providers. Turn it off only if the endpoint rejects the request (for example a direct OpenAI URL)."
```

In `frontend/public/i18n/de.json`, under `settings.ai.configs`, add:

```json
"reasoning": "Das Modell nicht nachdenken lassen",
"reasoningHint": "Für die meisten Anbieter aktiviert lassen. Nur ausschalten, wenn der Endpunkt die Anfrage ablehnt (zum Beispiel eine direkte OpenAI-URL)."
```

- [ ] **Step 2: Write the failing component test**

In `ai-section.component.spec.ts`, add `suppressReasoning: true,` to the local `config()` factory (after `active: false,`). Then add a test (follow the file's existing render/interaction pattern — query the rendered checkbox, toggle it, assert the service call). Use the component's existing test harness helpers:

```js
it('toggles the reasoning preference for a row', () => {
  const setReasoning = jest.spyOn(ai, 'setReasoning').mockImplementation(() => undefined);
  ai.configs.set([config({ id: 7, suppressReasoning: true })]);
  fixture.detectChanges();

  const checkbox: HTMLInputElement = fixture.nativeElement.querySelector('.reasoning-toggle input');
  expect(checkbox).not.toBeNull();
  expect(checkbox.checked).toBe(true);

  checkbox.checked = false;
  checkbox.dispatchEvent(new Event('change'));

  expect(setReasoning).toHaveBeenCalledWith(7, false);
});
```

(Match how the spec obtains `ai` and `fixture` — reuse the existing `beforeEach` wiring; do not introduce a new TestBed setup.)

- [ ] **Step 3: Run it to verify it fails**

Run: `cd frontend && npx jest ai-section.component`
Expected: FAIL — no `.reasoning-toggle input` in the DOM.

- [ ] **Step 4: Add the checkbox to the template**

In `ai-section.component.html`, inside the `@else` branch of the `who` block (the non-renaming display, after the `model` span, still within `.who`), add:

```html
<label class="reasoning-toggle">
  <input
    type="checkbox"
    [checked]="config.suppressReasoning"
    [disabled]="ai.busy()"
    (change)="toggleReasoning(config, $event)"
  />
  <span>{{ 'settings.ai.configs.reasoning' | transloco }}</span>
</label>
```

- [ ] **Step 5: Add the handler to the component**

In `ai-section.component.ts`, add:

```ts
toggleReasoning(config: AiConfig, event: Event): void {
  this.ai.setReasoning(config.id, (event.target as HTMLInputElement).checked);
}
```

- [ ] **Step 6: Style the toggle**

In `ai-section.component.scss`, add a rule for `.reasoning-toggle` using existing spacing tokens (no raw px, no hex). Follow the file's current token usage; a minimal rule:

```scss
.reasoning-toggle {
  display: flex;
  align-items: center;
  gap: var(--space-2xs);
}
```

(Use whichever spacing token the file already uses for inline gaps; if `--space-2xs` is not defined, pick the nearest existing one used elsewhere in this file.)

- [ ] **Step 7: Run the component test to verify it passes**

Run: `cd frontend && npx jest ai-section.component`
Expected: PASS.

- [ ] **Step 8: Frontend quality gate**

Run: `cd frontend && npm run check`
Expected: ESLint + Prettier + Stylelint + Jest all pass. Fix Prettier wrapping and any Stylelint hex/px finding before committing.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/app/settings/ai-section.component.html frontend/src/app/settings/ai-section.component.ts frontend/src/app/settings/ai-section.component.scss frontend/src/app/settings/ai-section.component.spec.ts frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#323): add the per-config reasoning checkbox to the settings page"
```

---

### Task 8: Full-suite verification, mutation gate, live-run proof

**Files:** none (verification only).

- [ ] **Step 1: Backend suite (SQLite leg)**

Run: `cd backend && php bin/phpunit`
Expected: green.

- [ ] **Step 2: Backend suite (MySQL leg) + migration on the live Docker DB**

Run:

```bash
docker compose up -d
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php vendor/bin/phpunit
```

Expected: the migration applies to the running MySQL (so php-fpm does not 500 with `SQLSTATE[42S22]`), and the suite is green. Then scan `backend/var/log/dev.log` for deprecations or swallowed errors.

- [ ] **Step 3: Mutation gate over the changed files**

Run: `cd backend && composer infection:diff`
Expected: at or above `minMsi` in `infection.json5`. Any escaped mutant on the new conditional (`if ($request->suppressReasoning)`) or the accessors means a missing assertion — add it. Ensure `TEST_TOKEN` isolation holds if run in parallel.

- [ ] **Step 4: Frontend gate**

Run: `cd frontend && npm run check`
Expected: green.

- [ ] **Step 5: Live run proof (the real deliverable, per project rule)**

With the Docker stack up and an OpenRouter or LM Studio configuration active, start a recommendation run through the UI and confirm it completes with 0 transport failures. Then flip the row's checkbox off and on and confirm the setting persists across a reload. Gates green is not enough — a run must complete.

- [ ] **Step 6: Push and open the PR**

```bash
git push -u origin feature/323-recommendation-reasoning-toggle
```

Open a PR into `develop` whose body says `Closes #323`. After merge, verify the issue closed.

---

## Self-Review

**Spec coverage:**
- Entity column + default + accessors + independence from `replaceConnection()` → Task 1. ✓
- Migration (both dialects, default 1, verified on scratch, applied to live after merge) → Task 2 + Task 8 Step 2. ✓
- `CompletionRequest` field + client conditional payload (`completionPayload()` extraction) → Task 3. ✓
- `requestFor()` flows the flag, both phases → Task 4 (batch phase asserted; `requestFor()` is the single builder both phases call, so dedup carries it too). ✓
- DTO + configurator + route (mirrors `rename`, no rate limit, ownership-404) + JSON key → Task 5. ✓
- Frontend type + service `setReasoning` → Task 6. ✓
- Component checkbox + hint + i18n (en/de) + scss → Task 7. ✓
- Tests: entity, client present/absent, advancer flow, JSON key, configurator, functional + ownership, service, component → Tasks 1–7. ✓
- Mutation gate, both suite legs, live-run proof → Task 8. ✓

**Placeholder scan:** The only intentional placeholder is `VersionYYYYMMDDHHMMSS` (the migration timestamp is generated at author time) — Task 2 Step 1 says to use the real UTC timestamp matching the newest migration's format. No other TBD/TODO.

**Type consistency:** `suppressReasoning` (property/param/JSON key/DTO field), `suppressesReasoning()` (getter), `setSuppressReasoning()` (entity + configurator writer), `setReasoning()` (frontend service method), `api_me_ai_set_reasoning` (route name), `'reasoning' => ['effort' => 'none']` (payload) — used consistently across all tasks. `CompletionRequest`'s fifth parameter is `bool $suppressReasoning` in Task 3 and supplied by `requestFor()` in Task 4 and the test helpers in Task 3.
