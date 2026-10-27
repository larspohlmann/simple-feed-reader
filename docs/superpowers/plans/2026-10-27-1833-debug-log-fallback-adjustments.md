# Debug log: what a rejected-request fallback adjusted (#1833) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a rejected-request fallback recovers a run, the debug log shows, right after the rejected call, an entry naming the setting it changed and the new value.

**Architecture:** Each fallback returns a `RequestAdjustmentModel` (setting + new value) instead of a bare `bool`. A new `RejectedRequestRecovery` service asks the composite fallback and, when it adjusts, writes the adjustment onto the newest `transport-failed` row of the run's `recommendation_run_log` (two new nullable columns). No new row type, so the ETA averages over call rows stay untouched. The debug-log JSON carries an `adjustment` object per entry. The SPA renders it as its own list item after that call, in both the recommendation and profile logs.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Doctrine ORM + DBAL, Doctrine Migrations, Angular 20 (standalone, signals), Transloco, Jest.

**Spec:** GitHub issue #1833 (https://github.com/larspohlmann/simple-feed-reader/issues/1833); the fallbacks come from #1823 (`docs/superpowers/plans/2026-10-26-1823-openai-token-limit.md`).

## Global Constraints

- CLAUDE.md rules bind every task: `final readonly` services, names that reveal intent, no comments unless a future reader would get the code wrong without one, HTTP statuses as `Response::HTTP_*` in tests too, queries only in `src/Repository/`.
- A value an entity stores lives in `App\Enum` / `App\Entity`; a repository may name a Service model (`docs/architecture.md` §8).
- New migrations follow `migrations/Version20261026150000.php`: `skipIf` guards in `up()` and `down()`, a description naming the issue.
- Wire values are kebab-case enum strings, like `CallVerdict` (`transport-failed`).
- Frontend: component styles in a sibling `.scss`, no hex colours or ad-hoc `px` outside the theme, every `DebugLogEntry` literal carries the new `adjustment` field.
- Commit subjects use `type(#1833): …`. No Claude attribution anywhere.
- Backend gates: `composer check`, `composer md`, `composer test:parallel`, MySQL leg `docker compose exec -T php composer test`, `composer infection:diff`. Frontend gate: `npm run check` (from `frontend/`), plus `npx ng build`.

---

### Task 1: Fallbacks report what they adjusted

**Files:**
- Create: `backend/src/Enum/RequestAdjustment.php`
- Create: `backend/src/Service/Recommendation/Run/Model/RequestAdjustmentModel.php`
- Modify: `backend/src/Service/Recommendation/Run/RejectedRequestFallback/RejectedRequestFallbackInterface.php`
- Modify: `backend/src/Service/Recommendation/Run/RejectedRequestFallback/CompositeRejectedRequestFallback.php`
- Modify: `backend/src/Service/Recommendation/Llm/Run/MaxTokensRefusedFallback.php`, `OutputCapFallback.php`, `SuppressedReasoningFallback.php`
- Modify: `backend/src/Service/Recommendation/Run/RecommendationRunFailure.php:38`, `backend/src/Service/Recommendation/Profile/ProfileRunFailure.php:41` (call site only, see Step 7)
- Test: `backend/tests/Service/Recommendation/Llm/Run/MaxTokensRefusedFallbackTest.php`, `OutputCapFallbackTest.php`, `SuppressedReasoningFallbackTest.php`, `backend/tests/Service/Recommendation/Run/RejectedRequestFallback/CompositeRejectedRequestFallbackTest.php`, `RecordingFallback.php`, `RejectedRequestFallbackWiringTest.php`

**Interfaces:**
- Produces: `enum App\Enum\RequestAdjustment: string { TokenParameter = 'token-parameter'; OutputCap = 'output-cap'; ReasoningSuppression = 'reasoning-suppression'; }`
- Produces: `final readonly class RequestAdjustmentModel { public RequestAdjustment $setting; public ?string $value; static tokenParameter(TokenLimitParameter): self; static outputCap(int): self; static reasoningSuppressionDropped(): self }`
- Produces: `RejectedRequestFallbackInterface::adjust(AiProviderSettings $connection, ProviderRejectedRequestException $rejection): ?RequestAdjustmentModel` (replaces `absorbs(): bool`)

- [ ] **Step 1: Create the enum**

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum RequestAdjustment: string
{
    case TokenParameter = 'token-parameter';
    case OutputCap = 'output-cap';
    case ReasoningSuppression = 'reasoning-suppression';
}
```

- [ ] **Step 2: Create the model**

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

use App\Enum\RequestAdjustment;
use App\Enum\TokenLimitParameter;

/** What a fallback changed on the connection after a rejection, and the value the next request carries. */
final readonly class RequestAdjustmentModel
{
    private function __construct(public RequestAdjustment $setting, public ?string $value)
    {
    }

    public static function tokenParameter(TokenLimitParameter $parameter): self
    {
        return new self(RequestAdjustment::TokenParameter, $parameter->value);
    }

    public static function outputCap(int $tokens): self
    {
        return new self(RequestAdjustment::OutputCap, (string) $tokens);
    }

    public static function reasoningSuppressionDropped(): self
    {
        return new self(RequestAdjustment::ReasoningSuppression, null);
    }
}
```

- [ ] **Step 3: Change the interface**

Replace the method in `RejectedRequestFallbackInterface.php` and its docblock:

```php
/** A rejection the next tick can answer differently: adjusting records the change on the connection, and the run goes on. */
interface RejectedRequestFallbackInterface
{
    /** Null when this fallback has no answer to the rejection. */
    public function adjust(
        AiProviderSettings $connection,
        ProviderRejectedRequestException $rejection,
    ): ?RequestAdjustmentModel;
}
```

(import `App\Service\Recommendation\Run\Model\RequestAdjustmentModel`).

- [ ] **Step 4: Rewrite the fallback tests (failing first)**

Mechanical rules for `MaxTokensRefusedFallbackTest`, `OutputCapFallbackTest`, `SuppressedReasoningFallbackTest`:
- every `self::assertFalse(<fallback>->absorbs($a, $b))` becomes `self::assertNull(<fallback>->adjust($a, $b))`;
- every `self::assertTrue(<fallback>->absorbs($a, $b))` becomes an assertion on the returned adjustment with a per-file helper:

```php
private static function assertAdjusted(
    RequestAdjustment $setting,
    ?string $value,
    ?RequestAdjustmentModel $adjustment,
): void {
    self::assertNotNull($adjustment);
    self::assertSame($setting, $adjustment->setting);
    self::assertSame($value, $adjustment->value);
}
```

Expected values per true-case:
- `MaxTokensRefusedFallbackTest` (both true-cases): `self::assertAdjusted(RequestAdjustment::TokenParameter, 'max_completion_tokens', $this->fallbackFlushing($this->once())->adjust($connection, $rejection));`
- `OutputCapFallbackTest::testOpenAisCapIsRecordedOnTheConnection` and `testTheCapIsReadFromOtherWordingAroundIt`: `RequestAdjustment::OutputCap`, the cap the test's message names as a string (`'32768'` for the OpenAI wording; read the test's own message for the other).
- `OutputCapFallbackTest::testACapBelowTheRecordedOneReplacesIt`: `RequestAdjustment::OutputCap`, the lower cap the test sends, as a string.
- `SuppressedReasoningFallbackTest::testARejectedSuppressingRequestIsRecordedAsTheModelRefusingIt`: `RequestAdjustment::ReasoningSuppression`, `null`.

`RecordingFallback` returns `?RequestAdjustmentModel` instead of a `bool` verdict:

```php
final readonly class RecordingFallback implements RejectedRequestFallbackInterface
{
    /** @param \ArrayObject<int, string> $askedInOrder */
    public function __construct(
        private string $name,
        private ?RequestAdjustmentModel $adjustment,
        private \ArrayObject $askedInOrder,
    ) {
    }

    public function adjust(
        AiProviderSettings $connection,
        ProviderRejectedRequestException $rejection,
    ): ?RequestAdjustmentModel {
        $this->askedInOrder->append($this->name);

        return $this->adjustment;
    }
}
```

`CompositeRejectedRequestFallbackTest`:

```php
public function testTheFirstMemberThatAdjustsEndsTheChainAndItsAdjustmentIsReturned(): void
{
    $calls = new \ArrayObject();
    $outputCap = RequestAdjustmentModel::outputCap(32_768);
    $composite = new CompositeRejectedRequestFallback(
        new RecordingFallback('tokenParameter', null, $calls),
        new RecordingFallback('outputCap', $outputCap, $calls),
        new RecordingFallback('suppressedReasoning', RequestAdjustmentModel::reasoningSuppressionDropped(), $calls),
    );

    self::assertSame($outputCap, $composite->adjust($this->connection(), $this->rejection()));
    self::assertSame(['tokenParameter', 'outputCap'], $calls->getArrayCopy());
}

public function testNoMemberAdjustingLeavesTheRejectionStanding(): void
{
    $calls = new \ArrayObject();
    $composite = new CompositeRejectedRequestFallback(
        new RecordingFallback('tokenParameter', null, $calls),
        new RecordingFallback('outputCap', null, $calls),
        new RecordingFallback('suppressedReasoning', null, $calls),
    );

    self::assertNull($composite->adjust($this->connection(), $this->rejection()));
    self::assertSame(['tokenParameter', 'outputCap', 'suppressedReasoning'], $calls->getArrayCopy());
}
```

`RejectedRequestFallbackWiringTest`: replace each `self::assertTrue($fallback->absorbs(…))` with `self::assertSame(RequestAdjustment::<Setting>, $fallback->adjust(…)?->setting);` using `TokenParameter`, `ReasoningSuppression`, `OutputCap`, `ReasoningSuppression` in the order the assertions appear.

- [ ] **Step 5: Run the tests to see them fail**

Run: `cd backend && php bin/phpunit tests/Service/Recommendation/Llm/Run tests/Service/Recommendation/Run/RejectedRequestFallback`
Expected: errors, `Call to undefined method …::adjust()`.

- [ ] **Step 6: Implement the fallbacks and the composite**

`MaxTokensRefusedFallback::absorbs` becomes:

```php
public function adjust(
    AiProviderSettings $connection,
    ProviderRejectedRequestException $rejection,
): ?RequestAdjustmentModel {
    if (!$this->refusesMaxTokens($connection, $rejection)) {
        return null;
    }

    $connection->recordMaxTokensRefused();
    $this->entityManager->flush();

    return RequestAdjustmentModel::tokenParameter($connection->getTokenLimitParameter());
}
```

`OutputCapFallback::absorbs` becomes:

```php
public function adjust(
    AiProviderSettings $connection,
    ProviderRejectedRequestException $rejection,
): ?RequestAdjustmentModel {
    $cap = $this->capNamedIn($rejection);
    if (null === $cap) {
        return null;
    }

    $recorded = $connection->getModelMaxOutputTokens();
    $connection->recordOutputCap($cap);
    if ($recorded === $connection->getModelMaxOutputTokens()) {
        return null;
    }
    $this->entityManager->flush();

    return RequestAdjustmentModel::outputCap($cap);
}
```

`SuppressedReasoningFallback::absorbs` becomes:

```php
public function adjust(
    AiProviderSettings $connection,
    ProviderRejectedRequestException $rejection,
): ?RequestAdjustmentModel {
    if (!$this->mayBeARefusedSuppression($connection, $rejection)) {
        return null;
    }

    $connection->recordSuppressionRefused();
    $this->entityManager->flush();

    return RequestAdjustmentModel::reasoningSuppressionDropped();
}
```

`CompositeRejectedRequestFallback::absorbs` becomes:

```php
public function adjust(
    AiProviderSettings $connection,
    ProviderRejectedRequestException $rejection,
): ?RequestAdjustmentModel {
    return $this->tokenParameter->adjust($connection, $rejection)
        ?? $this->outputCap->adjust($connection, $rejection)
        ?? $this->suppressedReasoning->adjust($connection, $rejection);
}
```

- [ ] **Step 7: Keep the two callers compiling**

In `RecommendationRunFailure::failRejected` and `ProfileRunFailure::failRejected`, change `if ($this->fallback->absorbs($tick->connection, $rejection))` to `if (null !== $this->fallback->adjust($tick->connection, $rejection))`. Task 2 replaces this seam.

- [ ] **Step 8: Run the tests and the suite**

Run: `cd backend && php bin/phpunit tests/Service/Recommendation && composer stan`
Expected: PASS, no PHPStan errors.

- [ ] **Step 9: Commit**

```bash
git add backend/src/Enum/RequestAdjustment.php backend/src/Service/Recommendation backend/tests/Service/Recommendation
git commit -m "refactor(#1833): a rejected-request fallback reports what it adjusted, not just that it did"
```

---

### Task 2: The rejected call's log row records the adjustment

**Files:**
- Create: `backend/migrations/Version20261027090000.php`
- Create: `backend/src/Service/Recommendation/Run/RejectedRequestRecovery.php`
- Modify: `backend/src/Entity/RecommendationRunLog.php` (two columns after `$finishReason`)
- Modify: `backend/src/Repository/CallingRun.php` (owner column)
- Modify: `backend/src/Repository/RecommendationCallRepository.php` (`recordAdjustment`)
- Modify: `backend/src/Repository/RecommendationRunLogRepository.php` (`DebugLogRow` + list query)
- Modify: `backend/src/Http/RecommendationDebugLogJson.php` (`entry()`)
- Modify: `backend/src/Service/Recommendation/Run/RecommendationRunFailure.php`, `backend/src/Service/Recommendation/Profile/ProfileRunFailure.php`
- Test: `backend/tests/Service/Recommendation/Profile/ProfileRunTickTest.php`, `backend/tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php`, `backend/tests/Service/Recommendation/Run/TickPhasesTest.php:200-203`, `backend/tests/Http/RecommendationDebugLogJsonTest.php`

**Interfaces:**
- Consumes: `RejectedRequestFallbackInterface::adjust()`, `RequestAdjustmentModel`, `RequestAdjustment` (Task 1)
- Produces: `RejectedRequestRecovery::recovers(CallingRun $callingRun, AiProviderSettings $connection, ProviderRejectedRequestException $rejection): bool`
- Produces: `RecommendationCallRepository::recordAdjustment(CallingRun $callingRun, RequestAdjustmentModel $adjustment): void`
- Produces: `DebugLogRow` gains `adjustedSetting: ?RequestAdjustment, adjustedValue: ?string`; the JSON entry gains `adjustment: null|array{setting: string, value: ?string}` and loses no other key.

- [ ] **Step 1: Write the failing run-level tests**

In `ProfileRunTickTest`, extend `testAModelThatRefusesSuppressedReasoningIsAskedAgainWithoutIt` right after the first `$this->advance(...)` and its existing assertions:

```php
$rows = $this->logs()->listForProfileRun($this->owner, $profileRun->requireId());
self::assertSame([RequestAdjustment::ReasoningSuppression], array_column($rows, 'adjustedSetting'));
self::assertSame([null], array_column($rows, 'adjustedValue'));
```

and in `testARejectedCallIsSettledInTheRunLogWithTheProvidersReason` (a rejection no fallback answers) add:

```php
self::assertSame([null], array_column($rows, 'adjustedSetting'));
```

In `RecommendationRunAdvancerTest::testAWaveRejectedForSuppressedReasoningIsAskedAgainWithoutItAndTheRunCompletes`, after the existing `array_column($waveRows, 'verdict')` assertion:

```php
self::assertSame(
    [null, null, RequestAdjustment::ReasoningSuppression],
    array_column($waveRows, 'adjustedSetting'),
);
```

and, in the same test, that the first batch's row, which answered before the wave, stays unmarked:

```php
self::assertSame([null], array_column(\array_slice($this->logRowsOfLatestRun(), 0, 1), 'adjustedSetting'));
```

Only the newest rejected row of the run is marked. In that wave the three rejected rows were settled in index order, so the third is the newest.

In `RecommendationDebugLogJsonTest`, add `'adjustedSetting' => null, 'adjustedValue' => null` to `row()`, and these tests:

```php
public function testAnEntryWithoutAnAdjustmentCarriesNull(): void
{
    $entry = RecommendationDebugLogJson::list(new RecommendationDebugLogModel([self::row(7)], [], null, []))['entries'][0];

    self::assertNull($entry['adjustment']);
    self::assertArrayNotHasKey('adjustedSetting', $entry);
    self::assertArrayNotHasKey('adjustedValue', $entry);
}

public function testAnAdjustedEntryCarriesTheSettingAsAWireStringAndItsValue(): void
{
    $row = self::row(7);
    $row['adjustedSetting'] = RequestAdjustment::OutputCap;
    $row['adjustedValue'] = '32768';

    $entry = RecommendationDebugLogJson::list(new RecommendationDebugLogModel([$row], [], null, []))['entries'][0];

    self::assertSame(['setting' => 'output-cap', 'value' => '32768'], $entry['adjustment']);
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `cd backend && php bin/phpunit --filter 'RefusesSuppressedReasoning|RejectedCallIsSettled|WaveRejectedForSuppressedReasoning|RecommendationDebugLogJsonTest'`
Expected: FAIL (undefined index `adjustedSetting` / `adjustment`).

- [ ] **Step 3: Map the columns and write the migration**

In `RecommendationRunLog`, after `$finishReason` (import `App\Enum\RequestAdjustment`):

```php
/** What a fallback changed after this call was rejected; set on the newest rejected row of its run only. */
#[ORM\Column(length: 24, nullable: true, enumType: RequestAdjustment::class)]
private ?RequestAdjustment $adjustedSetting = null;

#[ORM\Column(length: 64, nullable: true)]
private ?string $adjustedValue = null;
```

`backend/migrations/Version20261027090000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recommendation_run_log.adjusted_setting and adjusted_value: what a rejected-request fallback '
            . 'changed after the call (#1833).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('recommendation_run_log')->hasColumn('adjusted_setting'),
            'recommendation_run_log.adjusted_setting already exists.',
        );

        $this->addSql('ALTER TABLE recommendation_run_log ADD adjusted_setting VARCHAR(24) DEFAULT NULL');
        $this->addSql('ALTER TABLE recommendation_run_log ADD adjusted_value VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('recommendation_run_log')->hasColumn('adjusted_setting'),
            'recommendation_run_log.adjusted_setting is already gone.',
        );

        $this->addSql('ALTER TABLE recommendation_run_log DROP COLUMN adjusted_setting');
        $this->addSql('ALTER TABLE recommendation_run_log DROP COLUMN adjusted_value');
    }
}
```

- [ ] **Step 4: Name the log's owner column on `CallingRun`**

```php
final readonly class CallingRun
{
    private function __construct(public string $table, public string $logOwnerColumn, public int $id)
    {
    }

    public static function recommendationRun(int $id): self
    {
        return new self('recommendation_run', 'run_id', $id);
    }

    public static function profileRun(int $id): self
    {
        return new self('profile_run', 'profile_run_id', $id);
    }
}
```

Update its docblock to: `/** The run a recorded call's liveness, usage and adjustments land on: a recommendation run or a profile run. */`

- [ ] **Step 5: Add `recordAdjustment` to `RecommendationCallRepository`**

The derived table is load-bearing: MySQL refuses an UPDATE whose subquery reads the table being updated (error 1093) unless that read is materialised.

```php
/** Onto the run's newest rejected call: the one whose rejection the adjustment answers. */
public function recordAdjustment(CallingRun $callingRun, RequestAdjustmentModel $adjustment): void
{
    $this->connection->executeStatement(
        'UPDATE recommendation_run_log SET adjusted_setting = :setting, adjusted_value = :value'
        . ' WHERE id = (SELECT newest.id FROM (SELECT MAX(id) AS id FROM recommendation_run_log'
        . ' WHERE ' . $callingRun->logOwnerColumn . ' = :runId AND verdict = :rejected) AS newest)',
        [
            'setting' => $adjustment->setting->value,
            'value' => $adjustment->value,
            'runId' => $callingRun->id,
            'rejected' => CallVerdict::TransportFailed->value,
        ],
    );
}
```

(imports: `App\Enum\CallVerdict`, `App\Service\Recommendation\Run\Model\RequestAdjustmentModel`).

- [ ] **Step 6: Create `RejectedRequestRecovery`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\AiProviderSettings;
use App\Repository\CallingRun;
use App\Repository\RecommendationCallRepository;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Recommendation\Run\RejectedRequestFallback\RejectedRequestFallbackInterface;

final readonly class RejectedRequestRecovery
{
    public function __construct(
        private RejectedRequestFallbackInterface $fallback,
        private RecommendationCallRepository $calls,
    ) {
    }

    public function recovers(
        CallingRun $callingRun,
        AiProviderSettings $connection,
        ProviderRejectedRequestException $rejection,
    ): bool {
        $adjustment = $this->fallback->adjust($connection, $rejection);
        if (null === $adjustment) {
            return false;
        }

        $this->calls->recordAdjustment($callingRun, $adjustment);

        return true;
    }
}
```

- [ ] **Step 7: Route both failure handlers through it**

`RecommendationRunFailure`: replace the constructor parameter `private RejectedRequestFallbackInterface $fallback` with `private RejectedRequestRecovery $recovery` and the check with

```php
if ($this->recovery->recovers(
    CallingRun::recommendationRun($tick->run->requireId()),
    $tick->connection,
    $rejection,
)) {
    return RecommendationRunReportModel::fromRun($tick->run);
}
```

`ProfileRunFailure`: same swap (`use App\Service\Recommendation\Run\RejectedRequestRecovery;`), check becomes

```php
if ($this->recovery->recovers(CallingRun::profileRun($tick->profileRun->requireId()), $tick->connection, $rejection)) {
    return;
}
```

Drop the now-unused `RejectedRequestFallbackInterface` imports. In `TickPhasesTest::phases()` fetch `RejectedRequestRecovery::class` from the container instead of the interface and pass it to `new RecommendationRunFailure(...)`.

- [ ] **Step 8: Carry the columns to the debug log**

`RecommendationRunLogRepository`: add `adjustedSetting: ?RequestAdjustment, adjustedValue: ?string` to the `DebugLogRow` phpstan-type and to the `@var` on `$rows`, select `'l.adjustedSetting AS adjustedSetting'` and `'l.adjustedValue AS adjustedValue'`, and add `'adjustedSetting' => $row['adjustedSetting'], 'adjustedValue' => $row['adjustedValue'],` to the mapped array.

`RecommendationDebugLogJson::entry()`:

```php
private static function entry(array $row): array
{
    $setting = $row['adjustedSetting'];
    $value = $row['adjustedValue'];
    unset($row['adjustedSetting'], $row['adjustedValue']);

    return [
        ...$row,
        'phase' => $row['phase']->value,
        'verdict' => $row['verdict']?->value,
        'createdAt' => $row['createdAt']->format(\DATE_ATOM),
        'finishedAt' => $row['finishedAt']?->format(\DATE_ATOM),
        'adjustment' => null === $setting ? null : ['setting' => $setting->value, 'value' => $value],
    ];
}
```

- [ ] **Step 9: Run the tests, the migration leg and the gates**

Run: `cd backend && php bin/phpunit tests/Service/Recommendation tests/Http tests/Repository && composer check && composer md`
Expected: PASS.

Migration check (SQLite, scratch DB): `DATABASE_URL="sqlite:///$PWD/var/migrate-check.db" bin/console doctrine:migrations:migrate --no-interaction && DATABASE_URL="sqlite:///$PWD/var/migrate-check.db" bin/console doctrine:schema:validate --skip-sync=false; rm -f var/migrate-check.db`
Expected: migrations run; mapping and database in sync.

Apply to the dev Docker MySQL: `docker compose exec -T php bin/console doctrine:migrations:migrate --no-interaction`.

- [ ] **Step 10: Commit**

```bash
git add backend
git commit -m "feat(#1833): the rejected call's log row records what the fallback adjusted"
```

---

### Task 3: The debug log shows the adjustment as its own entry

**Files:**
- Create: `frontend/src/app/settings/debug-log/debug-log-adjustment.component.ts`, `.html`, `.scss`, `.spec.ts`
- Modify: `frontend/src/app/settings/settings.models.ts` (`DebugLogAdjustment`, `DebugLogEntry.adjustment`)
- Modify: `frontend/src/app/settings/recommendations/recommendation-debug-log.component.html:59-68` + `.ts` imports
- Modify: `frontend/src/app/settings/profile/profile-debug-log.component.html:4-13` + `.ts` imports
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (after `debugFinishTruncated`)
- Modify (fixtures, add `adjustment: null` to every `DebugLogEntry` literal; `tsc` lists any missed): `debug-log-entry.component.spec.ts`, `recommendation-debug-log.component.spec.ts`, `profile-debug-log.component.spec.ts`, `settings-api.spec.ts`, `debug-log-details.service.spec.ts`
- Test: `debug-log-adjustment.component.spec.ts`, `profile-debug-log.component.spec.ts`, `recommendation-debug-log.component.spec.ts`

**Interfaces:**
- Consumes: JSON `entries[].adjustment: null | { setting: 'token-parameter' | 'output-cap' | 'reasoning-suppression', value: string | null }` (Task 2)
- Produces: `<app-debug-log-adjustment [adjustment]="…" />`

- [ ] **Step 1: Extend the model**

In `settings.models.ts`, above `DebugLogEntry`:

```ts
/** What a fallback changed after the provider rejected a call, and the value the next attempt sends. */
export interface DebugLogAdjustment {
  setting: 'token-parameter' | 'output-cap' | 'reasoning-suppression';
  value: string | null;
}
```

and in `DebugLogEntry`, after `finishReason`:

```ts
  /** Set on the newest rejected call of a run when a fallback answered the rejection. */
  adjustment: DebugLogAdjustment | null;
```

Add `adjustment: null,` to every `DebugLogEntry` fixture listed under Files.

- [ ] **Step 2: Write the failing component spec**

`debug-log-adjustment.component.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { DebugLogAdjustment } from '../settings.models';
import { DebugLogAdjustmentComponent } from './debug-log-adjustment.component';

describe('DebugLogAdjustmentComponent', () => {
  function textOf(adjustment: DebugLogAdjustment): string {
    TestBed.configureTestingModule({
      imports: [DebugLogAdjustmentComponent, provideTranslocoTesting()],
    });
    const fixture = TestBed.createComponent(DebugLogAdjustmentComponent);
    fixture.componentRef.setInput('adjustment', adjustment);
    fixture.detectChanges();
    return (fixture.nativeElement as HTMLElement).textContent ?? '';
  }

  it('names the token parameter the next attempt sends', () => {
    expect(textOf({ setting: 'token-parameter', value: 'max_completion_tokens' })).toContain(
      'Adjusted for the next attempt: token limit sent as max_completion_tokens',
    );
  });

  it('names the lowered output cap', () => {
    expect(textOf({ setting: 'output-cap', value: '32768' })).toContain(
      'Adjusted for the next attempt: output limit lowered to 32768 tokens',
    );
  });

  it('says reasoning is no longer suppressed', () => {
    expect(textOf({ setting: 'reasoning-suppression', value: null })).toContain(
      'Adjusted for the next attempt: reasoning no longer suppressed',
    );
  });
});
```

(`provideTranslocoTesting()` loads the shipped `public/i18n/en.json`, so Step 4's keys are what these assert.)

In `profile-debug-log.component.spec.ts` (its `entry(over)` builder gets `adjustment: null` in Step 1) add:

```ts
it('lists an adjustment as its own entry right after the rejected call', () => {
  const fixture = mount(false);
  http.expectOne(LOG).flush({
    entries: [
      entry({
        id: 31,
        verdict: 'transport-failed',
        errorDetail: 'No.',
        adjustment: { setting: 'output-cap', value: '32768' },
      }),
      entry({ id: 32, attempt: 3 }),
    ],
  });
  fixture.detectChanges();

  const items = Array.from((fixture.nativeElement as HTMLElement).querySelectorAll('li'));
  expect(items.map((item) => item.querySelector('app-debug-log-adjustment') !== null)).toEqual([
    false,
    true,
    false,
  ]);
});
```

In `recommendation-debug-log.component.spec.ts`:

```ts
it('lists an adjustment as its own entry right after the rejected call', () => {
  debugLog.mockReturnValue(
    of({
      run: null,
      runs: [],
      entries: [
        { ...TRANSPORT_FAILED_ENTRY, adjustment: { setting: 'token-parameter', value: 'max_completion_tokens' } },
        DEDUP_ENTRY,
      ],
    }),
  );
  const fixture = mount();

  const items = Array.from((fixture.nativeElement as HTMLElement).querySelectorAll('li'));
  expect(items.map((item) => item.querySelector('app-debug-log-adjustment') !== null)).toEqual([
    false,
    true,
    false,
  ]);
});
```

Both fixtures carry `runId: 7`, so they render in one group, which this order needs.

- [ ] **Step 3: Run the specs to see them fail**

Run: `docker compose exec -T frontend npx jest src/app/settings`
Expected: FAIL (cannot find module `./debug-log-adjustment.component`).

- [ ] **Step 4: Add the translations**

`en.json`, after `debugFinishTruncated`:

```json
"debugAdjustedTokenParameter": "Adjusted for the next attempt: token limit sent as {{ value }}",
"debugAdjustedOutputCap": "Adjusted for the next attempt: output limit lowered to {{ value }} tokens",
"debugAdjustedReasoning": "Adjusted for the next attempt: reasoning no longer suppressed",
```

`de.json`, same place:

```json
"debugAdjustedTokenParameter": "Für den nächsten Versuch angepasst: Token-Limit wird als {{ value }} gesendet",
"debugAdjustedOutputCap": "Für den nächsten Versuch angepasst: Ausgabelimit auf {{ value }} Tokens gesenkt",
"debugAdjustedReasoning": "Für den nächsten Versuch angepasst: Reasoning wird nicht mehr unterdrückt",
```

- [ ] **Step 5: Create the component**

`debug-log-adjustment.component.ts`:

```ts
import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { DebugLogAdjustment } from '../settings.models';

/** The change a fallback made after the call above it was rejected. */
@Component({
  selector: 'app-debug-log-adjustment',
  imports: [TranslocoPipe],
  templateUrl: './debug-log-adjustment.component.html',
  styleUrl: './debug-log-adjustment.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DebugLogAdjustmentComponent {
  readonly adjustment = input.required<DebugLogAdjustment>();
}
```

`debug-log-adjustment.component.html`:

```html
@let change = adjustment();
@switch (change.setting) {
  @case ('token-parameter') {
    {{ 'settings.ai.recommendations.debugAdjustedTokenParameter' | transloco: { value: change.value } }}
  }
  @case ('output-cap') {
    {{ 'settings.ai.recommendations.debugAdjustedOutputCap' | transloco: { value: change.value } }}
  }
  @default {
    {{ 'settings.ai.recommendations.debugAdjustedReasoning' | transloco }}
  }
}
```

`debug-log-adjustment.component.scss`:

```scss
@use '../../theme/glass-rim';

/* Same box as a call entry, quieter: it notes a change, it is not a call. */
:host {
  @include glass-rim.filled(var(--surface-0));

  display: block;
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius);
  color: var(--accent);
  font-size: var(--fs-xs);
}
```

- [ ] **Step 6: Render it in both lists**

`recommendation-debug-log.component.html`, inside the `@for`, after the entry's `</li>`:

```html
              @if (entry.adjustment; as adjustment) {
                <li>
                  <app-debug-log-adjustment [adjustment]="adjustment" />
                </li>
              }
```

`profile-debug-log.component.html`, same after its `</li>` (match its indentation). Add `DebugLogAdjustmentComponent` to both components' `imports`.

- [ ] **Step 7: Run the specs and the gate**

Run: `docker compose exec -T frontend npx jest src/app/settings && cd frontend && npm run check && npx ng build`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add frontend
git commit -m "feat(#1833): the debug log lists a fallback's adjustment as its own entry"
```

---

### Task 4: Live check

- [ ] **Step 1:** In the app at :4200, run recommendations on the OpenAI connection after resetting its learned token parameter and cap (`token_limit_parameter = 'max_tokens'`, `model_max_output_tokens = NULL` for that connection only, through the app's own settings save or a one-row UPDATE with the stack quiet). Open Diagnostics → Debug log and confirm an "Adjusted for the next attempt: …" entry follows each rejected call, with the value the next attempt sent.
- [ ] **Step 2:** Screenshot the log for the PR.
