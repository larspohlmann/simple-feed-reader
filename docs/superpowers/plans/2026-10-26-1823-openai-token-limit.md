# OpenAI token limit (#1823) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Recommendation runs against the official OpenAI API stop failing with HTTP 400: each connection learns which
token-limit parameter its model takes and the model's output cap, and sends requests that respect both.

**Architecture:** Two new rejected-request fallbacks join `SuppressedReasoningFallback` behind a composite. One reads
OpenAI's "Unsupported parameter: 'max_tokens'" 400 and switches the connection to `max_completion_tokens`; the other
reads "supports at most N completion tokens" and records N. Both facts live on `ChosenModel`, so choosing another
model forgets them. The request factory clamps the output bound to the recorded cap (also taken from the catalog where
the provider reports one, as OpenRouter does), and the client names the parameter the connection learned.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM, PHPUnit 12.

**Spec:** GitHub issue #1823 plus the live findings below; no separate design doc.

## Global Constraints

- Branch `fix/1823-openai-token-limit` in the main checkout, PR into `develop`, body says `Closes #1823`.
- Commit format `fix(#1823): …`; no Claude attribution anywhere.
- Default stays `max_tokens`: LM Studio, Ollama, OpenRouter and gpt-4.x accept it. Never send both parameters — OpenAI rejects that with a 400.
- Run both suite legs before the PR: native SQLite (`composer test:parallel`) and MySQL (`docker compose exec -T php composer test`). Check the containers serve this checkout's code first.
- Clean Code rules in CLAUDE.md apply; every touched `src` file PHPMD-clean; `composer check` green.

## Findings this plan rests on (measured 2026-10-26 against api.openai.com)

| Model family | `max_tokens` | `max_completion_tokens` | Output cap |
|---|---|---|---|
| gpt-4o, gpt-4o-mini | accepted | accepted | 16,384 |
| gpt-4.1, gpt-4.1-mini | accepted | accepted | 32,768 |
| gpt-5, -mini, -nano, 5.1, 5.2, 5.4(-mini/-nano), 5.5, 5.6-*, 6-*, 6.1-sol, chat-latest | **400 Unsupported parameter** | accepted | 128,000 |
| o3 | **400 Unsupported parameter** | accepted | 100,003 |

- Both parameters at once: `400 Setting 'max_tokens' and 'max_completion_tokens' at the same time is not supported.`
- The cap error reads `max_tokens is too large: 35000. This model supports at most 32768 completion tokens, whereas you provided 35000.` — the same wording whichever parameter was sent.
- `reasoning: {effort: none}` (sent when suppression is on) is a 400 on **every** OpenAI model. `SuppressedReasoningFallback` already absorbs it and retries without; out of scope here beyond ordering (Task 4).
- The app's error message is `That provider refused the request (status 400): <OpenAI error.message>` (`ProviderErrorReason`, clipped at 500 chars — both patterns fit well inside).

## File map

| File | Change |
|---|---|
| `backend/src/Enum/TokenLimitParameter.php` | Create: the wire name of the output bound |
| `backend/src/Entity/ModelDescriptor.php` | Add `?int $maxOutputTokens = null` |
| `backend/src/Entity/ChosenModel.php` | Store `modelMaxOutputTokens` and `tokenLimitParameter`; reset on choose/forget |
| `backend/src/Entity/AiProviderSettings.php` | Getters + `recordMaxTokensRefused()`, `recordOutputCap()` |
| `backend/migrations/Version20261026150000.php` | Create: two columns on `user_ai_settings` |
| `backend/src/Service/Ai/ModelCatalog/OpenAiCompatibleCatalog.php` | Read `top_provider.max_completion_tokens` |
| `backend/src/Service/Recommendation/Llm/Completion/Model/CompletionRequestModel.php` | Add `TokenLimitParameter $tokenLimitParameter = MaxTokens` |
| `backend/src/Service/Recommendation/Llm/Completion/ChatCompletionClient/OpenAiCompatibleChatClient.php` | Name the parameter from the request |
| `backend/src/Service/Recommendation/Llm/Prompt/Factory/RecommendationCompletionRequestFactory.php` | Clamp to the cap, pass the parameter |
| `backend/src/Service/Recommendation/Llm/Run/MaxTokensRefusedFallback.php` | Create |
| `backend/src/Service/Recommendation/Llm/Run/OutputCapFallback.php` | Create |
| `backend/src/Service/Recommendation/Run/RejectedRequestFallback/CompositeRejectedRequestFallback.php` | Create |
| `backend/config/services.yaml` | Alias the interface to the composite, name its members |
| tests mirroring each of the above | |

---

### Task 1: The connection remembers its model's token-limit parameter and output cap

**Files:**
- Create: `backend/src/Enum/TokenLimitParameter.php`
- Modify: `backend/src/Entity/ModelDescriptor.php`, `backend/src/Entity/ChosenModel.php`, `backend/src/Entity/AiProviderSettings.php`
- Create: `backend/migrations/Version20261026150000.php`
- Test: `backend/tests/Entity/AiProviderSettingsTest.php`

**Interfaces:**
- Produces: `enum App\Enum\TokenLimitParameter: string { MaxTokens = 'max_tokens'; MaxCompletionTokens = 'max_completion_tokens' }`
- Produces: `ModelDescriptor::__construct(string $id, ?int $contextWindow, ?ScoringProtocol $scoringProtocol = null, ?int $maxOutputTokens = null)`
- Produces on `AiProviderSettings`: `getTokenLimitParameter(): TokenLimitParameter`, `getModelMaxOutputTokens(): ?int`, `recordMaxTokensRefused(): void`, `recordOutputCap(int $tokens): void` (both `record…` throw `\LogicException` without a model, like `recordSuppressionRefused()`)

- [ ] **Step 1: Write the failing tests** — append to `backend/tests/Entity/AiProviderSettingsTest.php`. The class already has `settings(?string $name = null)`, `sealed(string $ciphertext = …)` and `connectionOn(string $model)` (which always reports a 32768 window); the new helper takes a whole descriptor, so it gets its own name, `connectionChoosing()`. Add `use App\Enum\TokenLimitParameter;` to the imports.

```php
public function testANewModelIsAskedWithMaxTokensAndNoCap(): void
{
    $connection = $this->connectionChoosing(new ModelDescriptor('gpt-4.1-mini', null));

    self::assertSame(TokenLimitParameter::MaxTokens, $connection->getTokenLimitParameter());
    self::assertNull($connection->getModelMaxOutputTokens());
}

public function testTheCatalogsOutputCapIsKeptWithTheChosenModel(): void
{
    $connection = $this->connectionChoosing(
        new ModelDescriptor('openai/gpt-4.1-mini', 1_047_576, maxOutputTokens: 32_768),
    );

    self::assertSame(32_768, $connection->getModelMaxOutputTokens());
}

public function testARefusedMaxTokensSwitchesToMaxCompletionTokens(): void
{
    $connection = $this->connectionChoosing(new ModelDescriptor('gpt-5.4-nano', null));

    $connection->recordMaxTokensRefused();

    self::assertSame(TokenLimitParameter::MaxCompletionTokens, $connection->getTokenLimitParameter());
}

public function testARecordedOutputCapReplacesTheCatalogs(): void
{
    $connection = $this->connectionChoosing(new ModelDescriptor('gpt-4.1-mini', null, maxOutputTokens: 40_000));

    $connection->recordOutputCap(32_768);

    self::assertSame(32_768, $connection->getModelMaxOutputTokens());
}

public function testChoosingAnotherModelForgetsWhatTheLastOneTaught(): void
{
    $connection = $this->connectionChoosing(new ModelDescriptor('gpt-4.1-mini', null));
    $connection->recordMaxTokensRefused();
    $connection->recordOutputCap(32_768);

    $connection->chooseModel(new ModelDescriptor('gpt-5.4-nano', null), new \DateTimeImmutable('2026-10-26 11:00:00'));

    self::assertSame(TokenLimitParameter::MaxTokens, $connection->getTokenLimitParameter());
    self::assertNull($connection->getModelMaxOutputTokens());
}

public function testReplacingTheConnectionForgetsTheLearnedLimits(): void
{
    $connection = $this->connectionChoosing(new ModelDescriptor('gpt-4.1-mini', null));
    $connection->recordMaxTokensRefused();
    $connection->recordOutputCap(32_768);

    $connection->replaceConnection(
        'https://other.example.test/v1',
        $this->sealed('b3RoZXI='),
        'wxyz',
        new \DateTimeImmutable('2026-10-26 11:00:00'),
    );
    $connection->chooseModel(new ModelDescriptor('gpt-4.1-mini', null), new \DateTimeImmutable('2026-10-26 11:01:00'));

    self::assertSame(TokenLimitParameter::MaxTokens, $connection->getTokenLimitParameter());
    self::assertNull($connection->getModelMaxOutputTokens());
}

public function testAConnectionWithoutAModelCannotRecordARefusedMaxTokens(): void
{
    $this->expectException(\LogicException::class);

    $this->settings()->recordMaxTokensRefused();
}

public function testAConnectionWithoutAModelCannotRecordAnOutputCap(): void
{
    $this->expectException(\LogicException::class);

    $this->settings()->recordOutputCap(32_768);
}

private function connectionChoosing(ModelDescriptor $model): AiProviderSettings
{
    $connection = $this->settings();
    $connection->chooseModel($model, new \DateTimeImmutable('2026-10-26 10:00:00'));

    return $connection;
}
```

Note the semantics differ on purpose from the suppression refusal, which is keyed by model name and survives switching away and back (`testSwitchingBackToTheModelThatRefusedRemembersIt`): these two facts live on `ChosenModel` and any `chooseModel()` resets them. Re-learning costs one unbilled rejected call.

- [ ] **Step 2: Run to verify they fail**

Run (from `backend/`): `php bin/phpunit tests/Entity/AiProviderSettingsTest.php`
Expected: FAIL — `TokenLimitParameter` not found / unknown named parameter `maxOutputTokens`.

- [ ] **Step 3: Implement**

`backend/src/Enum/TokenLimitParameter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

/** The request field that bounds a completion's output; OpenAI's reasoning models refuse the older `max_tokens`. */
enum TokenLimitParameter: string
{
    case MaxTokens = 'max_tokens';
    case MaxCompletionTokens = 'max_completion_tokens';
}
```

`ModelDescriptor` constructor gains a fourth promoted parameter (update the class docblock: "…and the output cap, where the provider reports one"):

```php
public function __construct(
    public string $id,
    public ?int $contextWindow,
    public ?ScoringProtocol $scoringProtocol = null,
    public ?int $maxOutputTokens = null,
) {
```

`ChosenModel` — add the two columns, set them in `choose()`, reset them in `forget()`, and add accessors:

```php
/** Tokens; from /models where reported, else from the provider refusing a larger request. */
#[ORM\Column(nullable: true)]
private ?int $modelMaxOutputTokens = null;

#[ORM\Column(length: 32, enumType: TokenLimitParameter::class, options: ['default' => 'max_tokens'])]
private TokenLimitParameter $tokenLimitParameter = TokenLimitParameter::MaxTokens;

public function choose(ModelDescriptor $model): void
{
    $this->model = $model->id;
    $this->modelContextWindow = $model->contextWindow;
    $this->scoringProtocol = $model->scoringProtocol;
    $this->modelMaxOutputTokens = $model->maxOutputTokens;
    $this->tokenLimitParameter = TokenLimitParameter::MaxTokens;
}

public function forget(): void
{
    $this->model = null;
    $this->modelContextWindow = null;
    $this->scoringProtocol = null;
    $this->modelMaxOutputTokens = null;
    $this->tokenLimitParameter = TokenLimitParameter::MaxTokens;
}

public function capOutput(int $tokens): void
{
    $this->modelMaxOutputTokens = $tokens;
}

public function refuseMaxTokens(): void
{
    $this->tokenLimitParameter = TokenLimitParameter::MaxCompletionTokens;
}

public function getModelMaxOutputTokens(): ?int
{
    return $this->modelMaxOutputTokens;
}

public function getTokenLimitParameter(): TokenLimitParameter
{
    return $this->tokenLimitParameter;
}
```

`AiProviderSettings` — next to `recordSuppressionRefused()`:

```php
public function getModelMaxOutputTokens(): ?int
{
    return $this->chosenModel->getModelMaxOutputTokens();
}

public function getTokenLimitParameter(): TokenLimitParameter
{
    return $this->chosenModel->getTokenLimitParameter();
}

public function recordMaxTokensRefused(): void
{
    $this->requireModelForARefusal();
    $this->chosenModel->refuseMaxTokens();
}

public function recordOutputCap(int $tokens): void
{
    $this->requireModelForARefusal();
    $this->chosenModel->capOutput($tokens);
}
```

and refactor `recordSuppressionRefused()` onto the same guard (third occurrence of the throw → DRY):

```php
public function recordSuppressionRefused(): void
{
    $this->suppressionRefusedByModel = $this->requireModelForARefusal();
}

private function requireModelForARefusal(): string
{
    return $this->getModel()
        ?? throw new \LogicException('A connection without a model has sent no request to refuse.');
}
```

`backend/migrations/Version20261026150000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_ai_settings.model_max_output_tokens and token_limit_parameter: what the chosen model caps '
            . 'its output at and which field bounds it (#1823).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('user_ai_settings')->hasColumn('token_limit_parameter'),
            'user_ai_settings.token_limit_parameter already exists.',
        );

        $this->addSql('ALTER TABLE user_ai_settings ADD model_max_output_tokens INT DEFAULT NULL');
        $this->addSql("ALTER TABLE user_ai_settings ADD token_limit_parameter VARCHAR(32) DEFAULT 'max_tokens' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('user_ai_settings')->hasColumn('token_limit_parameter'),
            'user_ai_settings.token_limit_parameter is already gone.',
        );

        $this->addSql('ALTER TABLE user_ai_settings DROP COLUMN model_max_output_tokens');
        $this->addSql('ALTER TABLE user_ai_settings DROP COLUMN token_limit_parameter');
    }
}
```

- [ ] **Step 4: Run tests and verify the migration natively**

Run: `php bin/phpunit tests/Entity/AiProviderSettingsTest.php tests/Entity/ModelDescriptorTest.php` → PASS.
Then prove the migration from empty on SQLite (never the dev DB — use a throwaway file):

```bash
DATABASE_URL="sqlite:///$PWD/var/migrate-1823.db" bin/console doctrine:migrations:migrate --no-interaction
DATABASE_URL="sqlite:///$PWD/var/migrate-1823.db" bin/console doctrine:schema:validate
rm var/migrate-1823.db
```

Expected: migrate succeeds; `[OK] The database schema is in sync with the mapping files.` (MySQL is proven by CI's migration leg.)

- [ ] **Step 5: Commit**

```bash
git add src/Enum/TokenLimitParameter.php src/Entity/ModelDescriptor.php src/Entity/ChosenModel.php src/Entity/AiProviderSettings.php migrations/Version20261026150000.php tests/Entity/AiProviderSettingsTest.php
git commit -m "fix(#1823): a connection remembers its model's token-limit parameter and output cap"
```

---

### Task 2: The catalog reads the output cap OpenRouter reports

**Files:**
- Modify: `backend/src/Service/Ai/ModelCatalog/OpenAiCompatibleCatalog.php:128-138` and add a reader beside `reportedContextWindow()`
- Test: `backend/tests/Service/Ai/ModelCatalog/OpenAiCompatibleCatalogTest.php` (`listedEntries()` provider)

**Interfaces:**
- Consumes: `ModelDescriptor::$maxOutputTokens` (Task 1)

- [ ] **Step 1: Write the failing cases** — add to `listedEntries()`:

```php
yield 'a model reporting its output cap (OpenRouter)' => [
    ['id' => 'openai/gpt-4.1-mini', 'context_length' => 1_047_576, 'top_provider' => ['max_completion_tokens' => 32_768]],
    new ModelDescriptor('openai/gpt-4.1-mini', 1_047_576, maxOutputTokens: 32_768),
];
yield 'an output cap that is no positive number' => [
    ['id' => 'acme/uncapped-1', 'context_length' => 32_000, 'top_provider' => ['max_completion_tokens' => null]],
    new ModelDescriptor('acme/uncapped-1', 32_000),
];
yield 'a top provider that is no object' => [
    ['id' => 'acme/odd-1', 'context_length' => 32_000, 'top_provider' => 'openai'],
    new ModelDescriptor('acme/odd-1', 32_000),
];
```

- [ ] **Step 2: Run to verify the first case fails**

Run: `php bin/phpunit tests/Service/Ai/ModelCatalog/OpenAiCompatibleCatalogTest.php`
Expected: FAIL on "a model reporting its output cap" (`maxOutputTokens` null vs 32768).

- [ ] **Step 3: Implement**

```php
yield new ModelDescriptor($entry['id'], $window, $protocol, self::reportedMaxOutputTokens($entry));
```

```php
/** @param array<mixed> $entry */
private static function reportedMaxOutputTokens(array $entry): ?int
{
    $topProvider = $entry['top_provider'] ?? null;
    $cap = \is_array($topProvider) ? ($topProvider['max_completion_tokens'] ?? null) : null;

    return \is_int($cap) && $cap > 0 ? $cap : null;
}
```

- [ ] **Step 4: Run** the same command → PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Ai/ModelCatalog/OpenAiCompatibleCatalog.php tests/Service/Ai/ModelCatalog/OpenAiCompatibleCatalogTest.php
git commit -m "fix(#1823): the model catalog keeps the output cap a gateway reports"
```

---

### Task 3: Requests carry the learned parameter and stay under the cap

**Files:**
- Modify: `backend/src/Service/Recommendation/Llm/Completion/Model/CompletionRequestModel.php`
- Modify: `backend/src/Service/Recommendation/Llm/Completion/ChatCompletionClient/OpenAiCompatibleChatClient.php:437-439`
- Modify: `backend/src/Service/Recommendation/Llm/Prompt/Factory/RecommendationCompletionRequestFactory.php`
- Test: `backend/tests/Service/Recommendation/Llm/Completion/ChatCompletionClient/OpenAiCompatibleChatClientTest.php`, `backend/tests/Service/Recommendation/Llm/Prompt/Factory/RecommendationCompletionRequestFactoryTest.php`

**Interfaces:**
- Consumes: Task 1's `TokenLimitParameter`, `AiProviderSettings::getTokenLimitParameter()`, `getModelMaxOutputTokens()`
- Produces: `CompletionRequestModel::__construct(string $model, array $messages, int $maxAnswerTokens, JsonSchemaModel $responseSchema, Reasoning $reasoning, TokenLimitParameter $tokenLimitParameter = TokenLimitParameter::MaxTokens)`

- [ ] **Step 1: Write the failing tests**

Client test (beside `testOmitsTheReasoningFieldWhenNotSuppressed`):

```php
public function testBoundsTheAnswerWithMaxCompletionTokensWhenTheRequestSaysSo(): void
{
    $body = $this->captureRequestBody(new CompletionRequestModel(
        'm',
        $this->messages(),
        2048,
        $this->schema(),
        Reasoning::Allowed,
        TokenLimitParameter::MaxCompletionTokens,
    ));

    self::assertSame(2048, $body['max_completion_tokens']);
    self::assertArrayNotHasKey('max_tokens', $body);
}
```

The existing whole-body assertion (`'max_tokens' => 2048`) stays and pins the default.

Factory tests:

```php
public function testTheOutputBoundStaysUnderTheModelsCap(): void
{
    $connection = $this->settings(suppressReasoning: false);
    $connection->recordOutputCap(16_384);

    $request = $this->factory->create($connection, $this->prompt());

    self::assertGreaterThan(16_384, $this->headroomFor(Reasoning::Allowed));
    self::assertSame(16_384, $request->maxAnswerTokens);
}

public function testACapAboveTheBoundLeavesTheBoundAlone(): void
{
    $connection = $this->settings(suppressReasoning: false);
    $connection->recordOutputCap(128_000);

    $request = $this->factory->create($connection, $this->prompt());

    self::assertSame($this->headroomFor(Reasoning::Allowed), $request->maxAnswerTokens);
}

public function testTheRequestNamesTheParameterTheConnectionLearned(): void
{
    $connection = $this->settings(suppressReasoning: false);
    $connection->recordMaxTokensRefused();

    $request = $this->factory->create($connection, $this->prompt());

    self::assertSame(TokenLimitParameter::MaxCompletionTokens, $request->tokenLimitParameter);
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/Recommendation/Llm/Completion/ChatCompletionClient/OpenAiCompatibleChatClientTest.php tests/Service/Recommendation/Llm/Prompt/Factory/RecommendationCompletionRequestFactoryTest.php`
Expected: FAIL (unknown sixth argument / property; bound not clamped).

- [ ] **Step 3: Implement**

`CompletionRequestModel` gains the last promoted parameter `public TokenLimitParameter $tokenLimitParameter = TokenLimitParameter::MaxTokens,`.

Client payload — replace the `'max_tokens'` entry; its comment stays word for word:

```php
            // The only guard that prevents spend rather than discarding billed tokens; the caller sizes it from the
            // reserve the prompt left, so it never truncates a reply the prompt asked for.
            $request->tokenLimitParameter->value => $request->maxAnswerTokens,
```

Factory:

```php
public function create(AiProviderSettings $connection, CallPromptModel $prompt): CompletionRequestModel
{
    $reasoning = Reasoning::preferredBy($connection);

    return new CompletionRequestModel(
        $connection->getModel() ?? '',
        $prompt->messages,
        $this->outputBoundFor($connection, $prompt, $reasoning),
        $prompt->schema->toJsonSchema(),
        $reasoning,
        $connection->getTokenLimitParameter(),
    );
}

private function outputBoundFor(AiProviderSettings $connection, CallPromptModel $prompt, Reasoning $reasoning): int
{
    $bound = $this->answerBudget->outputBoundTokens($prompt->replyItemCount, $prompt->schema, $reasoning);
    $cap = $connection->getModelMaxOutputTokens();

    return null === $cap ? $bound : min($bound, $cap);
}
```

The clamp only ever cuts reasoning headroom: the largest answer bound is a few thousand tokens and the smallest cap measured is 16,384, so `packBatches()`'s reservation (answer bound only) is unaffected.

- [ ] **Step 4: Run** the Step 2 command → PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Recommendation/Llm/Completion/Model/CompletionRequestModel.php src/Service/Recommendation/Llm/Completion/ChatCompletionClient/OpenAiCompatibleChatClient.php src/Service/Recommendation/Llm/Prompt/Factory/RecommendationCompletionRequestFactory.php tests/Service/Recommendation/Llm/Completion/ChatCompletionClient/OpenAiCompatibleChatClientTest.php tests/Service/Recommendation/Llm/Prompt/Factory/RecommendationCompletionRequestFactoryTest.php
git commit -m "fix(#1823): a request names the learned token parameter and stays under the model's cap"
```

---

### Task 4: Two fallbacks learn from OpenAI's refusals, ahead of the suppression fallback

**Files:**
- Create: `backend/src/Service/Recommendation/Llm/Run/MaxTokensRefusedFallback.php`
- Create: `backend/src/Service/Recommendation/Llm/Run/OutputCapFallback.php`
- Create: `backend/src/Service/Recommendation/Run/RejectedRequestFallback/CompositeRejectedRequestFallback.php`
- Modify: `backend/config/services.yaml` (next to the `ProviderCallHeartbeatInterface` alias, ~line 179)
- Test: `backend/tests/Service/Recommendation/Llm/Run/MaxTokensRefusedFallbackTest.php`, `.../OutputCapFallbackTest.php`, `backend/tests/Service/Recommendation/Run/RejectedRequestFallback/CompositeRejectedRequestFallbackTest.php`, plus one container test

**Interfaces:**
- Consumes: `RejectedRequestFallbackInterface::absorbs(AiProviderSettings, ProviderRejectedRequestException): bool`; Task 1's `record…` methods
- Produces: the interface now resolves to `CompositeRejectedRequestFallback(tokenParameter, outputCap, suppressedReasoning)`

**Why the order matters:** `SuppressedReasoningFallback` absorbs *any* 400/422 while suppression is on. Placed first, it would file OpenAI's `max_tokens` refusal as a refused suppression. The specific readings go first; it stays the catch-all. Expected sequence for gpt-4.1-mini with suppression on: cap refusal → cap recorded; `reasoning` refusal → suppression refused; then success. For gpt-5.4-nano: `max_tokens` refusal → parameter switched; `reasoning` refusal → suppression refused; then success. At most three rejected calls per connection and model, none billed.

- [ ] **Step 1: Write the failing tests**

Class bodies in the blocks below are written flush-left so the methods read as one listing; indent them by four spaces in the files (`composer cs` enforces PSR-12).

`backend/tests/Service/Recommendation/Llm/Run/MaxTokensRefusedFallbackTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Llm\Run;

use App\Entity\AiProviderSettings;
use App\Entity\ModelDescriptor;
use App\Entity\User;
use App\Enum\TokenLimitParameter;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Recommendation\Llm\Run\MaxTokensRefusedFallback;
use App\Tests\Support\AiProviderSettingsFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;

final class MaxTokensRefusedFallbackTest extends TestCase
{
private const string REFUSAL = "That provider refused the request (status 400): Unsupported parameter: 'max_tokens' "
    . "is not supported with this model. Use 'max_completion_tokens' instead.";

public function testOpenAisRefusalOfMaxTokensSwitchesTheConnection(): void
{
    $connection = $this->connectionOn('gpt-5.4-nano');

    self::assertTrue($this->fallbackFlushing($this->once())->absorbs($connection, new ProviderRejectedRequestException(400, self::REFUSAL)));
    self::assertSame(TokenLimitParameter::MaxCompletionTokens, $connection->getTokenLimitParameter());
}

public function testAnotherBadRequestIsLeftToTheNextFallback(): void
{
    $connection = $this->connectionOn('gpt-5.4-nano');
    $rejection = new ProviderRejectedRequestException(400, "That provider refused the request (status 400): Unknown parameter: 'reasoning'.");

    self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, $rejection));
    self::assertSame(TokenLimitParameter::MaxTokens, $connection->getTokenLimitParameter());
}

public function testTheSameWordsUnderAnotherStatusAreNotAbsorbed(): void
{
    self::assertFalse($this->fallbackFlushing($this->never())->absorbs(
        $this->connectionOn('gpt-5.4-nano'),
        new ProviderRejectedRequestException(422, self::REFUSAL),
    ));
}

public function testAConnectionAlreadyOnMaxCompletionTokensIsNotAbsorbedAgain(): void
{
    $connection = $this->connectionOn('gpt-5.4-nano');
    $connection->recordMaxTokensRefused();

    self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, new ProviderRejectedRequestException(400, self::REFUSAL)));
}

private function fallbackFlushing(InvocationOrder $flushes): MaxTokensRefusedFallback
{
    $entityManager = $this->createMock(EntityManagerInterface::class);
    $entityManager->expects($flushes)->method('flush');

    return new MaxTokensRefusedFallback($entityManager);
}

private function connectionOn(string $model): AiProviderSettings
{
    $connection = AiProviderSettingsFactory::build(
        new User('reader@example.test', new \DateTimeImmutable('2026-10-26 09:00:00')),
    );
    $connection->chooseModel(new ModelDescriptor($model, null), new \DateTimeImmutable('2026-10-26 10:00:00'));

    return $connection;
}
}
```

`backend/tests/Service/Recommendation/Llm/Run/OutputCapFallbackTest.php` — same imports as above except `OutputCapFallback` in place of `MaxTokensRefusedFallback` and no `TokenLimitParameter`:

```php
final class OutputCapFallbackTest extends TestCase
{
private const string TOO_LARGE = 'That provider refused the request (status 400): max_tokens is too large: 35000. '
    . 'This model supports at most 32768 completion tokens, whereas you provided 35000.';

public function testOpenAisCapIsRecordedOnTheConnection(): void
{
    $connection = $this->connectionOn('gpt-4.1-mini');

    self::assertTrue($this->fallbackFlushing($this->once())->absorbs($connection, new ProviderRejectedRequestException(400, self::TOO_LARGE)));
    self::assertSame(32_768, $connection->getModelMaxOutputTokens());
}

public function testACapNoLowerThanTheRecordedOneIsNotAbsorbed(): void
{
    $connection = $this->connectionOn('gpt-4.1-mini');
    $connection->recordOutputCap(32_768);

    self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, new ProviderRejectedRequestException(400, self::TOO_LARGE)));
}

public function testARejectionNamingNoCapIsNotAbsorbed(): void
{
    $connection = $this->connectionOn('gpt-4.1-mini');
    $rejection = new ProviderRejectedRequestException(400, 'That provider refused the request (status 400): No.');

    self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, $rejection));
    self::assertNull($connection->getModelMaxOutputTokens());
}

public function testACapUnderAnotherStatusIsNotAbsorbed(): void
{
    self::assertFalse($this->fallbackFlushing($this->never())->absorbs(
        $this->connectionOn('gpt-4.1-mini'),
        new ProviderRejectedRequestException(422, self::TOO_LARGE),
    ));
}

public function testACapOfZeroIsNotAbsorbed(): void
{
    $connection = $this->connectionOn('gpt-4.1-mini');
    $rejection = new ProviderRejectedRequestException(
        400,
        'That provider refused the request (status 400): This model supports at most 0 completion tokens.',
    );

    self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, $rejection));
    self::assertNull($connection->getModelMaxOutputTokens());
}

private function fallbackFlushing(InvocationOrder $flushes): OutputCapFallback
{
    $entityManager = $this->createMock(EntityManagerInterface::class);
    $entityManager->expects($flushes)->method('flush');

    return new OutputCapFallback($entityManager);
}

private function connectionOn(string $model): AiProviderSettings
{
    $connection = AiProviderSettingsFactory::build(
        new User('reader@example.test', new \DateTimeImmutable('2026-10-26 09:00:00')),
    );
    $connection->chooseModel(new ModelDescriptor($model, null), new \DateTimeImmutable('2026-10-26 10:00:00'));

    return $connection;
}
}
```

`backend/tests/Service/Recommendation/Run/RejectedRequestFallback/RecordingFallback.php` — a double that answers a fixed verdict and logs its name into a log the test shares between members, so the test sees the order they were asked in:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\RejectedRequestFallback;

use App\Entity\AiProviderSettings;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Recommendation\Run\RejectedRequestFallback\RejectedRequestFallbackInterface;

final readonly class RecordingFallback implements RejectedRequestFallbackInterface
{
    /** @param \ArrayObject<int, string> $askedInOrder */
    public function __construct(
        private string $name,
        private bool $verdict,
        private \ArrayObject $askedInOrder,
    ) {
    }

    public function absorbs(AiProviderSettings $connection, ProviderRejectedRequestException $rejection): bool
    {
        $this->askedInOrder->append($this->name);

        return $this->verdict;
    }
}
```

`backend/tests/Service/Recommendation/Run/RejectedRequestFallback/CompositeRejectedRequestFallbackTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\RejectedRequestFallback;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Recommendation\Run\RejectedRequestFallback\CompositeRejectedRequestFallback;
use App\Tests\Support\AiProviderSettingsFactory;
use PHPUnit\Framework\TestCase;

final class CompositeRejectedRequestFallbackTest extends TestCase
{
    public function testTheFirstMemberThatAbsorbsEndsTheChain(): void
{
    $calls = new \ArrayObject();
    $composite = new CompositeRejectedRequestFallback(
        new RecordingFallback('tokenParameter', false, $calls),
        new RecordingFallback('outputCap', true, $calls),
        new RecordingFallback('suppressedReasoning', true, $calls),
    );

    self::assertTrue($composite->absorbs($this->connection(), new ProviderRejectedRequestException(400, 'No.')));
    self::assertSame(['tokenParameter', 'outputCap'], $calls->getArrayCopy());
}

public function testNoMemberAbsorbingLeavesTheRejectionStanding(): void
{
    $calls = new \ArrayObject();
    $composite = new CompositeRejectedRequestFallback(
        new RecordingFallback('tokenParameter', false, $calls),
        new RecordingFallback('outputCap', false, $calls),
        new RecordingFallback('suppressedReasoning', false, $calls),
    );

    self::assertFalse($composite->absorbs($this->connection(), new ProviderRejectedRequestException(400, 'No.')));
    self::assertSame(['tokenParameter', 'outputCap', 'suppressedReasoning'], $calls->getArrayCopy());
}

private function connection(): AiProviderSettings
{
    return AiProviderSettingsFactory::build(
        new User('reader@example.test', new \DateTimeImmutable('2026-10-26 09:00:00')),
    );
}
}
```

`backend/tests/Service/Recommendation/Run/RejectedRequestFallback/RejectedRequestFallbackWiringTest.php` — the real members in the real order, as `services.yaml` compiles them. It walks the exact sequence OpenAI produced for both reported models, so a wrong order fails here: put the suppression member first and the first assertion of each test fails, because that member files the token refusal as a refused suppression. The connection is never persisted: the members' `flush()` then has nothing to write, which keeps this test off the database.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\RejectedRequestFallback;

use App\Entity\AiProviderSettings;
use App\Entity\ModelDescriptor;
use App\Entity\User;
use App\Enum\TokenLimitParameter;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Recommendation\Run\RejectedRequestFallback\RejectedRequestFallbackInterface;
use App\Tests\Support\AiProviderSettingsFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The members' unit tests build the composite by hand; only the compiled container proves which order runs. */
final class RejectedRequestFallbackWiringTest extends KernelTestCase
{
    private const string MAX_TOKENS_REFUSED = "That provider refused the request (status 400): Unsupported parameter: "
        . "'max_tokens' is not supported with this model. Use 'max_completion_tokens' instead.";

    private const string TOO_LARGE = 'That provider refused the request (status 400): max_tokens is too large: 35000. '
        . 'This model supports at most 32768 completion tokens, whereas you provided 35000.';

    private const string REASONING_REFUSED = "That provider refused the request (status 400): Unknown parameter: "
        . "'reasoning'.";

    public function testAReasoningModelLearnsItsParameterBeforeItsSuppressionIsDropped(): void
    {
        $fallback = $this->fallback();
        $connection = $this->suppressingConnectionOn('gpt-5.4-nano');

        self::assertTrue($fallback->absorbs($connection, $this->rejection(self::MAX_TOKENS_REFUSED)));
        self::assertSame(TokenLimitParameter::MaxCompletionTokens, $connection->getTokenLimitParameter());
        self::assertFalse($connection->refusesSuppressedReasoning());

        self::assertTrue($fallback->absorbs($connection, $this->rejection(self::REASONING_REFUSED)));
        self::assertTrue($connection->refusesSuppressedReasoning());
    }

    public function testACappedModelLearnsItsCapBeforeItsSuppressionIsDropped(): void
    {
        $fallback = $this->fallback();
        $connection = $this->suppressingConnectionOn('gpt-4.1-mini');

        self::assertTrue($fallback->absorbs($connection, $this->rejection(self::TOO_LARGE)));
        self::assertSame(32_768, $connection->getModelMaxOutputTokens());
        self::assertFalse($connection->refusesSuppressedReasoning());

        self::assertTrue($fallback->absorbs($connection, $this->rejection(self::REASONING_REFUSED)));
        self::assertTrue($connection->refusesSuppressedReasoning());
    }

    private function fallback(): RejectedRequestFallbackInterface
    {
        self::bootKernel();
        $fallback = self::getContainer()->get(RejectedRequestFallbackInterface::class);
        self::assertInstanceOf(RejectedRequestFallbackInterface::class, $fallback);

        return $fallback;
    }

    private function suppressingConnectionOn(string $model): AiProviderSettings
    {
        $connection = AiProviderSettingsFactory::build(
            new User('fallback-wiring@example.test', new \DateTimeImmutable('2026-10-26 09:00:00')),
        );
        $connection->chooseModel(new ModelDescriptor($model, null), new \DateTimeImmutable('2026-10-26 09:05:00'));
        self::assertTrue($connection->suppressesReasoning());

        return $connection;
    }

    private function rejection(string $message): ProviderRejectedRequestException
    {
        return new ProviderRejectedRequestException(400, $message);
    }
}
```

The suppression member also checks that the connection's engine has a suppress-reasoning setting (`RecommendationEngineResolver::capabilitiesFor()`); a chosen LLM model (no scoring protocol) has one, which `SuppressedReasoningFallbackTest` already relies on.

**Break-test the wiring test before committing** (CLAUDE.md memory: verify a guard by breaking what it guards): swap `$tokenParameter` and `$suppressedReasoning` in `services.yaml`, run the wiring test, and quote the FAIL for each of the two tests. Then restore the file with an Edit, not `git checkout --`.

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/Recommendation/Llm/Run tests/Service/Recommendation/Run/RejectedRequestFallback`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

`MaxTokensRefusedFallback.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Run;

use App\Entity\AiProviderSettings;
use App\Enum\TokenLimitParameter;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Recommendation\Run\RejectedRequestFallback\RejectedRequestFallbackInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

final readonly class MaxTokensRefusedFallback implements RejectedRequestFallbackInterface
{
    private const string REFUSAL = "Unsupported parameter: 'max_tokens'";

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function absorbs(AiProviderSettings $connection, ProviderRejectedRequestException $rejection): bool
    {
        if (!$this->refusesMaxTokens($connection, $rejection)) {
            return false;
        }

        $connection->recordMaxTokensRefused();
        $this->entityManager->flush();

        return true;
    }

    private function refusesMaxTokens(AiProviderSettings $connection, ProviderRejectedRequestException $rejection): bool
    {
        return Response::HTTP_BAD_REQUEST === $rejection->status()
            && TokenLimitParameter::MaxTokens === $connection->getTokenLimitParameter()
            && str_contains($rejection->getMessage(), self::REFUSAL);
    }
}
```

`OutputCapFallback.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Run;

use App\Entity\AiProviderSettings;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Recommendation\Run\RejectedRequestFallback\RejectedRequestFallbackInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

final readonly class OutputCapFallback implements RejectedRequestFallbackInterface
{
    private const string CAP = '/supports at most (\d+) completion tokens/';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function absorbs(AiProviderSettings $connection, ProviderRejectedRequestException $rejection): bool
    {
        $cap = $this->capNamedIn($rejection);
        if (null === $cap || !$this->lowers($connection, $cap)) {
            return false;
        }

        $connection->recordOutputCap($cap);
        $this->entityManager->flush();

        return true;
    }

    private function capNamedIn(ProviderRejectedRequestException $rejection): ?int
    {
        if (Response::HTTP_BAD_REQUEST !== $rejection->status()) {
            return null;
        }
        if (1 !== preg_match(self::CAP, $rejection->getMessage(), $match)) {
            return null;
        }
        $cap = (int) $match[1];

        return $cap > 0 ? $cap : null;
    }

    private function lowers(AiProviderSettings $connection, int $cap): bool
    {
        $recorded = $connection->getModelMaxOutputTokens();

        return null === $recorded || $cap < $recorded;
    }
}
```

`CompositeRejectedRequestFallback.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\RejectedRequestFallback;

use App\Entity\AiProviderSettings;
use App\Service\Ai\Exception\ProviderRejectedRequestException;

/** Specific readings first: the suppressed-reasoning member absorbs any 400 and would misfile the others' refusals. */
final readonly class CompositeRejectedRequestFallback implements RejectedRequestFallbackInterface
{
    public function __construct(
        private RejectedRequestFallbackInterface $tokenParameter,
        private RejectedRequestFallbackInterface $outputCap,
        private RejectedRequestFallbackInterface $suppressedReasoning,
    ) {
    }

    public function absorbs(AiProviderSettings $connection, ProviderRejectedRequestException $rejection): bool
    {
        return $this->tokenParameter->absorbs($connection, $rejection)
            || $this->outputCap->absorbs($connection, $rejection)
            || $this->suppressedReasoning->absorbs($connection, $rejection);
    }
}
```

`services.yaml`, after the heartbeat block:

```yaml
    App\Service\Recommendation\Run\RejectedRequestFallback\RejectedRequestFallbackInterface: '@App\Service\Recommendation\Run\RejectedRequestFallback\CompositeRejectedRequestFallback'
    App\Service\Recommendation\Run\RejectedRequestFallback\CompositeRejectedRequestFallback:
        arguments:
            $tokenParameter: '@App\Service\Recommendation\Llm\Run\MaxTokensRefusedFallback'
            $outputCap: '@App\Service\Recommendation\Llm\Run\OutputCapFallback'
            $suppressedReasoning: '@App\Service\Recommendation\Llm\Run\SuppressedReasoningFallback'
```

- [ ] **Step 4: Run** the Step 2 command, then `php bin/phpunit tests/Service/Recommendation` → PASS (existing `RecommendationRunFailure`/`ProfileRunFailure` tests still green).

- [ ] **Step 5: Commit**

```bash
git add src/Service/Recommendation/Llm/Run/MaxTokensRefusedFallback.php src/Service/Recommendation/Llm/Run/OutputCapFallback.php src/Service/Recommendation/Run/RejectedRequestFallback/CompositeRejectedRequestFallback.php config/services.yaml tests/Service/Recommendation/Llm/Run tests/Service/Recommendation/Run/RejectedRequestFallback
git commit -m "fix(#1823): learn the token parameter and output cap from OpenAI's refusals"
```

---

### Task 5: Gates, live proof against OpenAI, PR

**Files:** none new in the repo. The live probe lives in the session scratchpad.

- [ ] **Step 1: Full native gates** (from `backend/`; parallel is fine, each sets its own `TEST_TOKEN`):

```bash
composer check
composer md
composer test:parallel
composer infection:diff
```

Expected: all green; `infection:diff` at or above `minMsi`. PhpStorm `lint_files` on every changed PHP file: no ERROR/WARNING.

- [ ] **Step 2: Live proof with the real fallback order.** Save this as `live-1823.php` in the session scratchpad (never in the repo). It boots the checkout's kernel natively with its own cache and log directories, reads the newest `api.openai.com` connection from the shared MySQL, **detaches** it so no `flush()` of the fallbacks writes to that database, and then drives the production factory, client and fallback chain the way a run's ticks would: build the request, send it, and on a rejection ask the fallback and send again.

```php
<?php

declare(strict_types=1);

use App\Entity\AiProviderSettings;
use App\Entity\ModelDescriptor;
use App\Kernel;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Ai\Factory\ProviderConnectionFactory;
use App\Service\Recommendation\Llm\Completion\ChatCompletionClient\ChatCompletionClientInterface;
use App\Service\Recommendation\Llm\Completion\CompletionStreamObserver\CompletionStreamObserverInterface;
use App\Service\Recommendation\Llm\Prompt\Factory\RecommendationCompletionRequestFactory;
use App\Service\Recommendation\Llm\Prompt\Model\CallPromptModel;
use App\Service\Recommendation\Llm\Prompt\Model\RecommendationResponseSchema;
use App\Service\Recommendation\Run\Model\CallProgressModel;
use App\Service\Recommendation\Run\RejectedRequestFallback\RejectedRequestFallbackInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Dotenv\Dotenv;

$backend = $argv[1];
require $backend . '/vendor/autoload.php';
(new Dotenv())->bootEnv($backend . '/.env');

const PROBED_SERVICES = [
    ProviderConnectionFactory::class,
    RecommendationCompletionRequestFactory::class,
    ChatCompletionClientInterface::class,
    RejectedRequestFallbackInterface::class,
];
const MAXIMUM_SENDS = 4;

$kernel = new class ('dev', true) extends Kernel {
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach (PROBED_SERVICES as $id) {
                    $container->hasAlias($id)
                        ? $container->getAlias($id)->setPublic(true)
                        : $container->getDefinition($id)->setPublic(true);
                }
            }
        });
    }

    public function getProjectDir(): string
    {
        return $GLOBALS['backend'];
    }

    public function getCacheDir(): string
    {
        return __DIR__ . '/live-1823-cache';
    }

    public function getLogDir(): string
    {
        return __DIR__ . '/live-1823-log';
    }
};
$kernel->boot();
$container = $kernel->getContainer();

$entityManager = $container->get('doctrine.orm.entity_manager');
$connection = $entityManager->createQueryBuilder()
    ->select('settings')->from(AiProviderSettings::class, 'settings')
    ->where('settings.baseUrl LIKE :host')->setParameter('host', '%api.openai.com%')
    ->orderBy('settings.id', 'DESC')->setMaxResults(1)
    ->getQuery()->getOneOrNullResult();
if (!$connection instanceof AiProviderSettings) {
    fwrite(STDERR, "No api.openai.com connection found\n");
    exit(1);
}
$entityManager->detach($connection);

$providerConnection = $container->get(ProviderConnectionFactory::class)->forSettings($connection);
$requests = $container->get(RecommendationCompletionRequestFactory::class);
$client = $container->get(ChatCompletionClientInterface::class);
$fallback = $container->get(RejectedRequestFallbackInterface::class);
$observer = new class implements CompletionStreamObserverInterface {
    public function streamProgressed(CallProgressModel $progress): void
    {
    }
};
$prompt = new CallPromptModel(
    [['role' => 'user', 'content' => 'Describe a reader of Rust and cycling news in one sentence. '
        . 'Answer as JSON: {"profile": "..."}']],
    1,
    RecommendationResponseSchema::Distillation,
);

foreach (['gpt-5.4-nano', 'gpt-4.1-mini', 'gpt-4o-mini'] as $model) {
    $connection->chooseModel(new ModelDescriptor($model, null), new \DateTimeImmutable());
    $connection->setSuppressReasoning(true);
    echo "\n== {$model}\n";

    for ($send = 1; $send <= MAXIMUM_SENDS; ++$send) {
        $request = $requests->create($connection, $prompt);
        echo "  send {$send}: {$request->tokenLimitParameter->value}={$request->maxAnswerTokens}, "
            . "reasoning {$request->reasoning->name}\n";
        try {
            echo '  ANSWER: ' . $client->complete($providerConnection, $request, $observer) . "\n";
            break;
        } catch (ProviderRejectedRequestException $rejection) {
            echo "  rejected: {$rejection->getMessage()}\n";
            if (!$fallback->absorbs($connection, $rejection)) {
                echo "  NOT ABSORBED — the fix does not cover this\n";
                break;
            }
        }
    }
}
```

Run it from `backend/` (MySQL's port is published by the Docker stack; this only reads):

```bash
APP_ENV=dev DATABASE_URL='mysql://feedreader:feedreader@127.0.0.1:33306/feedreader?serverVersion=8.4&charset=utf8mb4' php -d memory_limit=512M <scratchpad>/live-1823.php "$PWD"
```

Expected (the order of the first two rejections per model is OpenAI's choice, not ours):

```
== gpt-5.4-nano
  send 1: max_tokens=11000, reasoning Suppressed      → rejected: Unsupported parameter: 'max_tokens' … / Unknown parameter: 'reasoning'
  send 2: …                                            → rejected: the other of the two
  send 3: max_completion_tokens=35000, reasoning Allowed → ANSWER: {"profile": "…"}
== gpt-4.1-mini
  … rejected: … supports at most 32768 completion tokens … / Unrecognized request argument supplied: reasoning
  send 3: max_tokens=32768, reasoning Allowed           → ANSWER
== gpt-4o-mini
  same shape, ending max_tokens=16384
```

Pass criteria: every model ends in an `ANSWER`, nothing prints `NOT ABSORBED`, at most 3 sends per model. If `NOT ABSORBED` appears, quote the rejection and stop — do not widen a pattern without the user. Cost: three short answers, well under one cent; the rejections are not billed. If OpenAI answers 429 `insufficient_quota`, the account's credit has run out: tell the user, do not retry in a loop. Delete the script's cache and log directories afterwards.

- [ ] **Step 3: Push and open the PR** into `develop`, body `Closes #1823`, including the findings table above and the live-proof output. Bind it with the ccd_pr tools.

- [ ] **Step 4: Apply the migration to the dev DB** right after Task 1 lands, so the running app matches the mapping: `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`. After merge, reply on #1823 asking the reporter to retry after the next release.

## Out of scope

- Mapping suppression to OpenAI's own `reasoning_effort`: its lowest value differs per model (`none` on gpt-5.4+/6, `minimal` on gpt-5, `low` on o3; gpt-4.x rejects the field), and the existing fallback already recovers. Worth its own issue if the extra rejected call or the lost suppression matters.
- Showing the learned limits in the AI settings UI.
- A manual "maximum output tokens" setting.
