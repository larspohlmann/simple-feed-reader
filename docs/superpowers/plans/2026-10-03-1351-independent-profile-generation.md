# The For You profile is generated on its own (#1351) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Simplify the recommendation runs. A run stops building the reader's profile: distillation, its phase and states, its degrade and fallback branches and #1349's per-connection borrowing leave the run code, and a run only reads a frozen copy of the stored profile. The profile gets a module of its own — `ProfileRun`, schedule, connection, manual start, a top-level Profile settings section — so the machinery that leaves the runs lands there, not in run code.

**Architecture:** All new machinery lives in `Service/Recommendation/Profile/`: a `ProfileRun` is ticked by `ProfileRunTick` (history → fingerprint → one LLM call → `StoredProfile` on `user_recommendation_settings`) under the per-user lock recommendation runs already take (`ai-recommendations-{userId}`, extracted into `Run/UserTickLock` so the advancer *loses* its lock code). Profile runs have their own drivers (worker messages, `ForYouSweep`, the drainer). Recommendation-run code depends on the profile module through exactly one narrow interface the profile module owns, `ProfileForRunInterface` ("the current profile, or a profile run on its way"), consumed at snapshot time and by the status resolver; a run without a stored profile stays `pending` — no new run state, column or phase. The profile module is part of the `Recommendation` service module, so `ServiceModuleCycleRule` sees no new module edge.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine ORM 3.6 + DBAL 4.4 + Migrations (MySQL 8.4, SQLite), PHPUnit 12, Angular 20 (signals, standalone), Transloco, Jest.

**Spec:** GitHub issue #1351 (`gh issue view 1351 --repo larspohlmann/simple-feed-reader`), with Lars's rulings relayed in planning: simplification of the runs is the goal; the wait for a profile stays, minimal, behind one profile-owned interface. Context: `docs/superpowers/plans/2026-10-02-1349-profile-connection-per-jev.md` (what Task 8 removes), `docs/superpowers/plans/2026-08-21-493-two-step-recommendation-profile.md` (where distillation came from).

## Simplification ledger

What leaves the recommendation-run code (`Service/Recommendation/{Run,Llm,Jev}`, `Entity/RecommendationRun*`, `RunProfile`, `TickContext`):

| Leaves | Task |
|---|---|
| `Llm/Run/ProviderPhase/DistillationPhase` (the run's distillation phase) | 5 |
| `Llm/Run/RecommendationProfileDistiller` (moves, reshaped, to `Profile/ProfileDistiller`) | 5 |
| `Profile/ProfileDistiller/ProfileDistillerInterface` and its `services.yaml` alias | 5 |
| `Jev/JevProfileStep` — the Jev distillation half, its stored-profile fallback, `NO_PROFILE_CONNECTION` | 5 |
| `RunProfile::$distilled` / `record()`, `RecommendationRun::recordProfile()` / `isDistilled()`, column `recommendation_run.distilled` | 5 |
| `RecommendationRunProgress::$distillPending` and the `$distilled` parameter; `CallPhase::Distill` from both engines' `phases()` (the LLM's single-call phases 2 → 1, Jev's 1 → 0) | 5 |
| `LlmRecommendationEngine`'s distillation branch; the degrade-to-no-profile path through `InvalidReplyRetry` | 5 |
| `CallSlotModel::distillation()` | 5 |
| `TickContext::$borrowedProfile`, `borrowingProfileFrom()`, `profileTick()`, `connectionInFlight()`; `Run/Model/BorrowedProfileModel` | 5 |
| `TickContextFactory`'s borrowing branch and its `ProfileConnectionResolver` dependency; `TickLockTtl`'s borrowed connection | 5 |
| The lock mechanics in `RecommendationRunAdvancer` (`LockFactory`, `TickLockKeepalive`, `LOCK_NAME_PREFIX`, `lockNameFor()`, the shutdown hook) — into `UserTickLock` | 3 |
| #1349: `AiProfileConnectionController`, `ChooseProfileConnectionRequest`, `ProfileConnectionResolver`, `ProfileConnectionChooser`, `ProfileNotBorrowedException`, `profile_not_borrowed`, `AiProviderSettings::$profileConnection`, `findBorrowersOf()`, column `user_ai_settings.profile_connection_id`, the frontend's per-row picker | 8 |
| `keptCap`/`viewedCap` and the profile drill-in from the recommendation settings (API and card) | 1, 7, 9 |

What run code gains: `RecommendationRun::freezeProfile()` (replaces `recordProfile()`), `RecommendationRun::isResumable()`, the `ProfileForRunInterface` call in `SnapshotPhase` (one `match`), `waitingForProfile` on the run report, `JevRecommendationEngine::NO_PROFILE` (replaces two `JevProfileStep` messages). Task 5's report quotes `git diff --stat` for the run paths: it must show a net deletion.

## Global Constraints

- Branch `feature/1351-independent-profile-generation` off `develop` (the executor creates it; this plan is not committed by the planner). Another Claude session may share this checkout: check `git status` and the branch before any `switch`, `reset` or `stash`. Never commit to `develop`.
- Commits: `feat(#1351): …` / `test(#1351): …` / `refactor(#1351): …` / `docs(#1351): …`, lower-case summary, no attribution lines.
- CLAUDE.md house style is binding: `final readonly class`, intent-revealing names (no abbreviations, no single letters), guard clauses, no boolean flag parameters, comments only for non-obvious invariants (one line, three at most), thin controllers (no entity mutation, no private methods), queries only in `src/Repository`, domain code knows no HTTP, errors are typed exceptions mapped in `src/Http/Problem/ExceptionProblems/*Problems`, response arrays only in `src/Http/*Json`. PSR-12, 120 columns; code lines in this plan longer than 120 columns are wrapped by the implementer.
- Role folders (docs/architecture.md §10): services in the module root, `Model/`, `Pass/`, `Support/`, `Exception/`, a folder per interface. `ServiceRoleRule` names the home of any misplaced class; follow it.
- Recommendation-run code may reach the profile module only through `ProfileForRunInterface`. Drivers that serve both kinds of run (`ForYouSweep`, the drain command, the terminate listener, the worker) are not run code and call the profile module's sweep directly.
- Every `src` file a task touches must be PHPMD-clean (`composer md`) before its commit. phptramp: no new 3+-hop tunnel across classes; the per-call `ProfileTick` pass exists so the run, connection, settings and history are not threaded.
- Datetimes are naive UTC; every new timestamp comes from the injected `ClockInterface`.
- Migrations: CI migrates from empty on SQLite and MySQL, then runs `doctrine:schema:validate`. The migrated schema must match the ORM mapping exactly. Index names Doctrine generates come from DBAL's `_generateIdentifierName` (uppercase hex `crc32` of table and column, unpadded): `IDX_FED40DFFA76ED395` (`profile_run.user_id`), `IDX_83A9855E23D39AC1` (`user_recommendation_settings.profile_connection_id`). FK names are free (DBAL compares FKs by definition), index names are not.
- Live dev DB: never clear it, never write SQL to it behind the app. Apply each migration with `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction` at the end of the task that adds it.
- Deletion checks are binding for every new pin: break the covered code, run the test, quote the FAIL in the task report, restore. Restore by copying aside first (`cp <file> "$TMPDIR/<name>.orig"` … `mv` back), never `git checkout -- <file>`.
- Frontend: Jest only inside the Docker `frontend` container (`docker compose exec -T frontend npm test -- <pattern>`), one Jest process at a time; Prettier 100 columns; no hex colours, no ad-hoc `px` spacing, no media-query literals in `.scss`; component styles in a sibling `.scss`; every new string in both `frontend/public/i18n/en.json` and `de.json` (the only locales).
- Native-iOS rule (docs/architecture.md §6): every new endpoint is bearer-authenticated, stateless, JSON in, `application/problem+json` out, no browser-only input.
- Commands: backend commands run from `backend/`; `docker compose …` from the repository root.
- An implementer reports to the planner, who amends this plan in-branch. A placeholder name such as "the class's own `pendingRun()`" means: use the existing helper of that test class, or add the one-line helper the step shows.

## Decisions this plan makes (the issue left them open)

1. **Module.** Profile generation lives in `Service/Recommendation/Profile/`. It reuses the LLM completion stack (`Llm/Completion`, `Llm/Prompt`), the run log recorder and `Run/UserTickLock` — the profile module depends on run plumbing, never the reverse except through `ProfileForRunInterface`.
2. **`ProfileRun` reuses** `RunStatus` (never `cancelled`), and the `RunCallAttempts` and `ProviderUsage` embeddables. Own columns: `run_trigger` (`manual` / `scheduled` / `recommendation`; `trigger` is a MySQL reserved word), `fingerprint` (sha256), `outcome` (`generated` / `unchanged` / `no_history`), `streamed_chars`. `MAX_ATTEMPTS = 3`, `MAX_TRANSPORT_FAILURES = 3`.
3. **Stored profile** is an embeddable `StoredProfile` on `RecommendationSettings` over `profile_text` (exists), `profile_generated_at`, `profile_provider_host`, `profile_model`. Host plus model is "the connection and model": the connection itself may be renamed or deleted later.
4. **Fingerprint** = sha256 of the JSON of the favourite, kept and viewed entry ids (history order), the three caps, the connection id and its model, computed once when the run starts.
5. **Skip rule.** Only a `scheduled` run skips, only when a profile is stored, and only when the newest *completed* profile run has the same fingerprint (`outcome = unchanged`). Empty history completes with `no_history` and stores nothing, for every trigger.
6. **Connection resolution** (`ProfileConnections::usableFor()`): the chosen connection when it can build profiles (ready, profile source `own`); a chosen connection that cannot gives **none** (no silent fallback); nothing chosen gives the active connection when it can. None → the section says "choose a connection", the finder skips the account, a manual start answers `422 profile_connection_missing`, a profile run started for a waiting recommendation run fails with that message.
7. **Taking turns.** Both kinds of tick take `UserTickLock`; each passes its own TTL (`TickLockTtl::secondsFor()` for the active connection, `secondsForConnection()` of the profile connection for a profile tick). The recommendation advancer never ticks a profile run.
8. **A profile tick never throws a provider failure.** Transport failures and a deferring 429 count one strike each (fails at 3 with `The AI provider at {base} failed: {detail}`); an unreadable key fails the run at once; an unusable reply is retried with the corrective tail and fails the run after 3. A lost lock discards the tick's work. A failure never touches the stored profile.
9. **The wait, minimal.** `ProfileForRunInterface::profileFor($user, $runCreatedAt)`: stored profile → `ready(text)`; else the newest profile run, if active or started since the run was created, decides (`building` / `failed(error)` / `ready(null)` on completion without a profile); else it starts a `recommendation`-trigger profile run and answers `building`. `SnapshotPhase` freezes on `ready`, returns on `building` (the run stays `pending`), fails the run with `Profile generation failed: {error}` on `failed`. An empty candidate pool completes before the question is asked. `waitingForProfile` is set by the status resolver (`isBuildingFor()`) on any pending report. A run that failed before its snapshot is no longer resumable (`isResumable()`); a new run asks again.
10. **Engine phases.** `CallPhase::Distill` stays for profile-run log rows only. The ETA learns from runs carrying exactly the new phases, so estimates return after the first completed run on the new code. A Jev run without a profile fails at its first engine tick with `JevRecommendationEngine::NO_PROFILE`.
11. **Run log.** `RecommendationRunLog` gets named constructors `forRun()` / `forProfileRun()` (constructor private), so exactly one of `run_id` / `profile_run_id` is set; no database CHECK. Profile-run rows are trimmed to the newest `RunLogRetention::RUNS` profile runs whenever a profile run starts. `RecordedCall` writes liveness and usage onto the run a `CallingRun` (repository value) names.
12. **API.** `GET/PUT /api/me/ai/profile`, `POST /api/me/ai/profile/runs`, `GET /api/me/ai/profile/runs/current`, plus `GET /api/me/ai/profile/runs/current/log` for the debug panel (the issue's item 10 needs it). `GET /api/recommendations/runs/debug-log/{id}` serves a profile row's bodies too. Limiter `ai_profile_runs`: 10 per user per hour, sliding window, enforced before the start like `ai_recommendation_starts`.
13. **Purge and reset.** `DELETE /api/recommendations/runs` keeps the stored profile, the profile runs and their log. Account reset also deletes profile runs and their log rows.
14. **Migrations.** `Version20261003100000` (Task 1: `profile_run`, settings columns, run-log owner columns, auto-generate users to a daily profile), `Version20261003110000` (Task 5: drop `recommendation_run.distilled`), `Version20261003120000` (Task 8: the active Jev connection's pointer into the account setting, then drop `user_ai_settings.profile_connection_id`).
15. **Frontend.** New section `settings/profile/` (`path: 'profile'`, icon `psychology`, right after `ai`) on the shared `DraftSettingsService`: schedule and connection save instantly, the two caps wait behind the save bar; it polls `runs/current` every 2 s while a run is active and re-reads its state when the run ends. The debug panel shows the newest profile run's calls.

## Task order

1. Persistence — `ProfileRun`, `StoredProfile`, profile settings, run-log owner.
2. The run log records a profile run's calls.
3. A profile run generates the profile under the shared lock (and `UserTickLock` leaves the advancer).
4. Scheduling and drivers for profile runs.
5. **Recommendation runs lose their distillation** — the simplification.
6. A run without a stored profile waits for one, through `ProfileForRunInterface`.
7. The profile API.
8. #1349's borrowing goes.
9. The Profile settings section.
10. Docs, full gates, a real run.

## File Structure

| File | Change | Task |
|---|---|---|
| `backend/src/Enum/ProfileRunTrigger.php`, `ProfileRunOutcome.php` | create | 1 |
| `backend/src/Entity/ProfileRun.php`, `StoredProfile.php`, `ProfileSettingsValues.php` | create | 1 |
| `backend/src/Entity/RecommendationSettings.php`, `RecommendationSettingsValues.php` | profile fields; `historyCaps` → `favoritesCap` | 1 |
| `backend/src/Entity/RecommendationRunLog.php` | named constructors, `profileRun` | 1 |
| `backend/src/Repository/ProfileRunRepository.php` | create | 1 |
| `backend/src/Service/Recommendation/Settings/*`, `backend/src/Dto/Recommendation/SaveRecommendationSettingsRequest.php` | the split | 1 |
| `backend/migrations/Version20261003100000.php` | create | 1 |
| `backend/src/Repository/CallingRun.php`; `RecommendationCallRepository`, `RecommendationRunLogRepository` | per-run target, profile-run rows | 2 |
| `backend/src/Service/Recommendation/Run/Pass/RecordedCall.php`, `RecommendationCallRecorder.php`, `Factory/RecommendationRunLogFactory.php`, `Model/ProviderCallRouteModel.php` | profile-run calls | 2 |
| `backend/src/Service/Recommendation/Llm/Run/RecommendationProviderCall.php`, `CompletionCallRecorder.php` | route, profile begin | 2 |
| `backend/src/Service/Recommendation/Run/UserTickLock.php`, `Pass/HeldTickLock.php` | create; the advancer shrinks | 3 |
| `backend/src/Service/Recommendation/Profile/*` (connections, tick, opening, distiller, generation, failure, advancer, starter, fingerprint, pass) | create | 3 |
| `backend/src/Service/Recommendation/Profile/DueProfileRunFinder.php`, `ProfileRunSweep.php`; `backend/src/Service/Worker/*` | create | 4 |
| `ForYouSweep`, `ForYouSweepReportModel`, `ForYouSweepReportJson`, drain command, terminate listener, `AccountWipeRepository` | profile runs | 4 |
| Run entities and services, engines | distillation out | 5 |
| `backend/migrations/Version20261003110000.php` | create | 5 |
| `backend/src/Service/Recommendation/Profile/ProfileForRun/*`, `Profile/Model/RunProfileModel.php`, `RunProfileState.php` | create | 6 |
| `SnapshotPhase`, `RecommendationRunReportModel`, `RecommendationRunStatusResolver`, `RecommendationRunStatusJson`, `RecommendationRunStarter`, For You toast | the wait | 6 |
| `backend/src/Controller/Api/ProfileController.php`, `Dto/Recommendation/SaveProfileSettingsRequest.php`, `Http/ProfileSettingsJson.php`, `Http/ProfileRunJson.php`, profile provider/editor/log loader | create | 7 |
| `RecommendationSettingsJson`, `RecommendationDebugLogJson`, `RecommendationRunProblems`, `rate_limiter.yaml` | — | 7 |
| #1349's classes, the per-connection column, the AI section picker | delete | 8 |
| `backend/migrations/Version20261003120000.php` | create | 8 |
| `frontend/src/app/settings/profile/*` | create | 9 |
| recommendation card and service | lose profile and two caps | 9 |
| `docs/recommendations-runs.md`, `docs/for-you-scheduling.md`, `README.md` | — | 10 |

---
### Task 1: Persistence — `ProfileRun`, the stored profile and the profile settings

**Files:**
- Create: `backend/src/Enum/ProfileRunTrigger.php`, `backend/src/Enum/ProfileRunOutcome.php`
- Create: `backend/src/Entity/ProfileRun.php`, `backend/src/Entity/StoredProfile.php`, `backend/src/Entity/ProfileSettingsValues.php`
- Create: `backend/src/Repository/ProfileRunRepository.php`
- Create: `backend/migrations/Version20261003100000.php`
- Modify: `backend/src/Entity/RecommendationSettings.php`, `backend/src/Entity/RecommendationSettingsValues.php`, `backend/src/Entity/RecommendationRunLog.php`
- Modify: `backend/src/Service/Recommendation/Settings/RecommendationSettingsWriter.php`, `backend/src/Service/Recommendation/Settings/Pass/AccountRecommendationSettings.php`
- Modify: `backend/src/Dto/Recommendation/SaveRecommendationSettingsRequest.php`
- Modify: `backend/src/Service/Recommendation/Run/Factory/RecommendationRunLogFactory.php`, `backend/src/Service/Recommendation/Llm/Run/RecommendationProfileDistiller.php` (transitional, deleted in Task 5)
- Test: `backend/tests/Entity/ProfileRunTest.php` (create), `backend/tests/Entity/RecommendationRunLogTest.php` (create), `backend/tests/Repository/ProfileRunRepositoryTest.php` (create), `backend/tests/Entity/RecommendationSettingsTest.php`, `backend/tests/Entity/RecommendationSettingsValuesTest.php`, `backend/tests/Service/Recommendation/Settings/RecommendationSettingsWriterTest.php`, `backend/tests/Service/Recommendation/Settings/RecommendationSettingsResolverTest.php`, `backend/tests/Controller/Api/RecommendationSettingsControllerTest.php`, plus the mechanical fallout listed in Step 11.

**Interfaces:**
- Consumes: `RunStatus`, `RunCallAttempts`, `ProviderUsage`, `InvalidRunStatusException` (exist).
- Produces:
  - `enum ProfileRunTrigger: string { Manual = 'manual'; Scheduled = 'scheduled'; Recommendation = 'recommendation' }`
  - `enum ProfileRunOutcome: string { Generated = 'generated'; Unchanged = 'unchanged'; NoHistory = 'no_history' }`
  - `ProfileRun::__construct(User $user, ProfileRunTrigger $trigger, \DateTimeImmutable $createdAt)`; `MAX_ATTEMPTS = 3`, `MAX_TRANSPORT_FAILURES = 3`; `start(string $fingerprint, ?string $providerHost, string $model): void`; `recordInvalidReply(string $reply): void`; `hasExhaustedAttempts(): bool`; `recordTransportFailure(): void`; `hasExhaustedTransportRetries(): bool`; `complete(ProfileRunOutcome $outcome, \DateTimeImmutable $when): void`; `fail(string $error, \DateTimeImmutable $when): void`; getters `getId()`, `getUser()`, `getStatus()`, `getTrigger()`, `getCreatedAt()`, `getCompletedAt()`, `getError()`, `getFingerprint()`, `getOutcome()`, `getStreamedChars()`, `getAttempts()`, `getTransportFailures()`, `getLastInvalidReply()`, `getProviderHost()`, `getModel()`, `getPromptTokens()`, `getCompletionTokens()`, `getCostNanoCredits()`; `requireId()` (trait).
  - `StoredProfile::__construct(?string $text, ?\DateTimeImmutable $generatedAt, ?string $providerHost, ?string $model)`, `StoredProfile::none()`, `getText()`, `getGeneratedAt()`, `getProviderHost()`, `getModel()`.
  - `ProfileSettingsValues::__construct(?int $intervalHours, ?AiProviderSettings $connection, int $keptCap, int $viewedCap)`, `ProfileSettingsValues::defaults()`.
  - `RecommendationSettings::updateProfileSettings(ProfileSettingsValues): void`, `profileSettings(): ProfileSettingsValues`, `storeProfile(StoredProfile): void`, `getStoredProfile(): StoredProfile`, `forgetProfileConnection(AiProviderSettings): void`. `update()` / `values()` no longer touch the kept/viewed caps or the profile.
  - `RecommendationSettingsValues` drops `historyCaps` and `profileText`, gains `public int $favoritesCap` (second positional parameter).
  - `RecommendationSettingsWriter::save(User, RecommendationSettingsValues)`, `saveProfileSettings(User, ProfileSettingsValues)`, `storeProfile(User, StoredProfile)`.
  - `RecommendationRunLog::forRun(RecommendationRun $run, CallPhase $phase, ?int $batchNumber, int $attempt, string $requestBody, \DateTimeImmutable $createdAt): self`, `RecommendationRunLog::forProfileRun(ProfileRun $profileRun, int $attempt, string $requestBody, \DateTimeImmutable $createdAt): self`, `getRun(): ?RecommendationRun`, `getProfileRun(): ?ProfileRun`.
  - `ProfileRunRepository::findActiveForUser(User): ?ProfileRun`, `findLatestForUser(User): ?ProfileRun`, `findAllActive(): list<ProfileRun>` (oldest first, at most 10), `hasActiveRun(): bool`, `latestCompletedFingerprintFor(User): ?string`, `findNewestIdsForUser(User, int $limit): list<int>`.
  - Columns: table `profile_run`; `user_recommendation_settings.profile_interval_hours`, `profile_generated_at`, `profile_provider_host`, `profile_model`, `profile_connection_id`; `recommendation_run_log.run_id` nullable, `recommendation_run_log.profile_run_id`.

- [ ] **Step 1: Write the failing `ProfileRun` entity test**

Create `backend/tests/Entity/ProfileRunTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Exception\InvalidRunStatusException;
use App\Entity\ProfileRun;
use App\Entity\User;
use App\Enum\ProfileRunOutcome;
use App\Enum\ProfileRunTrigger;
use App\Enum\RunStatus;
use PHPUnit\Framework\TestCase;

final class ProfileRunTest extends TestCase
{
    public function testANewRunIsPendingWithItsTriggerAndNoFingerprint(): void
    {
        $profileRun = $this->profileRun();

        self::assertSame(RunStatus::Pending, $profileRun->getStatus());
        self::assertSame(ProfileRunTrigger::Scheduled, $profileRun->getTrigger());
        self::assertSame('2026-10-03 09:00:00', $profileRun->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertNull($profileRun->getFingerprint());
        self::assertNull($profileRun->getOutcome());
    }

    public function testStartRecordsTheFingerprintAndTheModelItCalls(): void
    {
        $profileRun = $this->profileRun();

        $profileRun->start('fingerprint-7', 'llm.example.test', 'qwen3-14b');

        self::assertSame(RunStatus::Running, $profileRun->getStatus());
        self::assertSame('fingerprint-7', $profileRun->getFingerprint());
        self::assertSame('llm.example.test', $profileRun->getProviderHost());
        self::assertSame('qwen3-14b', $profileRun->getModel());
    }

    public function testARunningRunCannotStartAgain(): void
    {
        $profileRun = $this->runningProfileRun();

        $this->expectException(InvalidRunStatusException::class);
        $profileRun->start('fingerprint-8', 'llm.example.test', 'qwen3-14b');
    }

    public function testCompleteRecordsTheOutcomeAndTheTime(): void
    {
        $profileRun = $this->runningProfileRun();

        $profileRun->complete(ProfileRunOutcome::Unchanged, new \DateTimeImmutable('2026-10-03 09:04:00'));

        self::assertSame(RunStatus::Completed, $profileRun->getStatus());
        self::assertSame(ProfileRunOutcome::Unchanged, $profileRun->getOutcome());
        self::assertSame('2026-10-03 09:04:00', $profileRun->getCompletedAt()?->format('Y-m-d H:i:s'));
    }

    public function testAPendingRunCannotComplete(): void
    {
        $this->expectException(InvalidRunStatusException::class);
        $this->profileRun()->complete(ProfileRunOutcome::Generated, new \DateTimeImmutable('2026-10-03 09:04:00'));
    }

    public function testTheThirdUnusableReplyExhaustsTheAttempts(): void
    {
        $profileRun = $this->runningProfileRun();
        $profileRun->recordInvalidReply('not json');
        $profileRun->recordInvalidReply('still not json');

        self::assertFalse($profileRun->hasExhaustedAttempts());

        $profileRun->recordInvalidReply('{"profil":"typo"}');

        self::assertTrue($profileRun->hasExhaustedAttempts());
        self::assertSame(3, $profileRun->getAttempts());
        self::assertSame('{"profil":"typo"}', $profileRun->getLastInvalidReply());
    }

    public function testTheThirdTransportFailureExhaustsTheRetries(): void
    {
        $profileRun = $this->runningProfileRun();
        $profileRun->recordTransportFailure();
        $profileRun->recordTransportFailure();

        self::assertFalse($profileRun->hasExhaustedTransportRetries());

        $profileRun->recordTransportFailure();

        self::assertTrue($profileRun->hasExhaustedTransportRetries());
        self::assertSame(3, $profileRun->getTransportFailures());
    }

    public function testAPendingRunCannotRecordAnUnusableReply(): void
    {
        $this->expectException(InvalidRunStatusException::class);
        $this->profileRun()->recordInvalidReply('not json');
    }

    public function testFailIsLegalFromPendingAndRecordsTheError(): void
    {
        $profileRun = $this->profileRun();

        $profileRun->fail('No connection can build your profile.', new \DateTimeImmutable('2026-10-03 09:01:00'));

        self::assertSame(RunStatus::Failed, $profileRun->getStatus());
        self::assertSame('No connection can build your profile.', $profileRun->getError());
        self::assertSame('2026-10-03 09:01:00', $profileRun->getCompletedAt()?->format('Y-m-d H:i:s'));
    }

    public function testACompletedRunCannotFail(): void
    {
        $profileRun = $this->runningProfileRun();
        $profileRun->complete(ProfileRunOutcome::Generated, new \DateTimeImmutable('2026-10-03 09:04:00'));

        $this->expectException(InvalidRunStatusException::class);
        $profileRun->fail('late', new \DateTimeImmutable('2026-10-03 09:05:00'));
    }

    private function profileRun(): ProfileRun
    {
        return new ProfileRun(
            new User('profile-run@example.test', new \DateTimeImmutable('2026-10-03 08:00:00')),
            ProfileRunTrigger::Scheduled,
            new \DateTimeImmutable('2026-10-03 09:00:00'),
        );
    }

    private function runningProfileRun(): ProfileRun
    {
        $profileRun = $this->profileRun();
        $profileRun->start('fingerprint-7', 'llm.example.test', 'qwen3-14b');

        return $profileRun;
    }
}
```

- [ ] **Step 2: Write the failing settings-entity and run-log tests**

Replace `backend/tests/Entity/RecommendationSettingsTest.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AiProviderSettings;
use App\Entity\ProfileSettingsValues;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationSettings;
use App\Entity\RecommendationSettingsValues;
use App\Entity\SealedSecret;
use App\Entity\StoredProfile;
use App\Entity\User;
use App\Enum\RecommendationBatchSize;
use PHPUnit\Framework\TestCase;

final class RecommendationSettingsTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User('reader@example.test', new \DateTimeImmutable('2026-08-06 09:00:00'));
    }

    public function testUpdateAndValuesRoundTripTheRecommendationFields(): void
    {
        $settings = new RecommendationSettings($this->user);

        $settings->update($this->values(favoritesCap: 33));

        self::assertTrue($settings->values()->showScoreAndReasons);
        self::assertSame(RecommendationBatchSize::Large, $settings->values()->batchSize);
        self::assertSame(33, $settings->values()->favoritesCap);
    }

    public function testANewRowDoesNotShowScoreAndReasonsByDefault(): void
    {
        self::assertFalse((new RecommendationSettings($this->user))->values()->showScoreAndReasons);
    }

    public function testANewRowUsesTheMediumBatchSizeByDefault(): void
    {
        self::assertSame(RecommendationBatchSize::Medium, (new RecommendationSettings($this->user))->values()->batchSize);
    }

    public function testANewRowHasNoProfileAndTheDefaultProfileSettings(): void
    {
        $settings = new RecommendationSettings($this->user);

        self::assertNull($settings->getStoredProfile()->getText());
        self::assertNull($settings->profileSettings()->intervalHours);
        self::assertNull($settings->profileSettings()->connection);
        self::assertSame(40, $settings->profileSettings()->keptCap);
        self::assertSame(80, $settings->profileSettings()->viewedCap);
    }

    public function testUpdatingTheRecommendationFieldsKeepsTheProfileSettings(): void
    {
        $settings = new RecommendationSettings($this->user);
        $settings->updateProfileSettings(new ProfileSettingsValues(12, null, 15, 25));

        $settings->update($this->values(favoritesCap: 33));

        self::assertSame(12, $settings->profileSettings()->intervalHours);
        self::assertSame(15, $settings->profileSettings()->keptCap);
        self::assertSame(25, $settings->profileSettings()->viewedCap);
    }

    public function testUpdatingTheProfileSettingsKeepsTheFavoritesCap(): void
    {
        $settings = new RecommendationSettings($this->user);
        $settings->update($this->values(favoritesCap: 33));

        $settings->updateProfileSettings(new ProfileSettingsValues(168, null, 15, 25));

        self::assertSame(33, $settings->values()->favoritesCap);
        self::assertSame(168, $settings->profileSettings()->intervalHours);
    }

    public function testStoreProfileReplacesTheWholeProfile(): void
    {
        $settings = new RecommendationSettings($this->user);
        $settings->storeProfile(new StoredProfile('old', new \DateTimeImmutable('2026-10-01 06:00:00'), 'a.test', 'm1'));

        $settings->storeProfile(
            new StoredProfile('Likes databases.', new \DateTimeImmutable('2026-10-03 07:15:00'), 'b.test', 'm2'),
        );

        $stored = $settings->getStoredProfile();
        self::assertSame('Likes databases.', $stored->getText());
        self::assertSame('2026-10-03 07:15:00', $stored->getGeneratedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('b.test', $stored->getProviderHost());
        self::assertSame('m2', $stored->getModel());
    }

    public function testForgettingAConnectionClearsTheProfileConnectionOnlyWhenItIsThatOne(): void
    {
        $chosen = $this->connection('Chosen');
        $settings = new RecommendationSettings($this->user);
        $settings->updateProfileSettings(new ProfileSettingsValues(null, $chosen, 40, 80));

        $settings->forgetProfileConnection($this->connection('Other'));
        self::assertSame($chosen, $settings->profileSettings()->connection);

        $settings->forgetProfileConnection($chosen);
        self::assertNull($settings->profileSettings()->connection);
    }

    private function values(int $favoritesCap): RecommendationSettingsValues
    {
        return new RecommendationSettingsValues(
            guidancePrompt: 'stay on topic',
            favoritesCap: $favoritesCap,
            poolLimits: RecommendationPoolLimits::defaults(),
            contextWindow: 32768,
            batchSize: RecommendationBatchSize::Large,
            debugEnabled: false,
            autoGenerateIntervalHours: 12,
            showScoreAndReasons: true,
        );
    }

    private function connection(string $name): AiProviderSettings
    {
        return new AiProviderSettings(
            $this->user,
            $name,
            'https://llm.example.test/v1',
            new SealedSecret('ciphertext', 'nonce', 'salt', 1),
            'ab12',
            new \DateTimeImmutable('2026-10-01 06:00:00'),
        );
    }
}
```

Replace the body of `backend/tests/Entity/RecommendationSettingsValuesTest.php`'s only test with the new shape (drop the `RecommendationHistoryCaps` import):

```php
    public function testShowScoreAndReasonsDefaultsToFalse(): void
    {
        $values = new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: 10,
            poolLimits: new RecommendationPoolLimits(400, 3, 25),
            contextWindow: null,
            batchSize: RecommendationBatchSize::Medium,
            debugEnabled: false,
        );

        self::assertFalse($values->showScoreAndReasons);
    }
```

Create `backend/tests/Entity/RecommendationRunLogTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ProfileRun;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\ProfileRunTrigger;
use PHPUnit\Framework\TestCase;

final class RecommendationRunLogTest extends TestCase
{
    public function testARunRowNamesItsRunAndNoProfileRun(): void
    {
        $run = new RecommendationRun($this->user(), new \DateTimeImmutable('2026-10-03 09:00:00'));

        $log = RecommendationRunLog::forRun($run, CallPhase::Batch, 4, 2, '{"model":"m"}', $this->sentAt());

        self::assertSame($run, $log->getRun());
        self::assertNull($log->getProfileRun());
        self::assertSame(CallPhase::Batch, $log->getPhase());
        self::assertSame(4, $log->getBatchNumber());
        self::assertSame(2, $log->getAttempt());
    }

    public function testAProfileRunRowIsADistillCallWithoutABatchOrARun(): void
    {
        $profileRun = new ProfileRun($this->user(), ProfileRunTrigger::Manual, $this->sentAt());

        $log = RecommendationRunLog::forProfileRun($profileRun, 3, '{"model":"p"}', $this->sentAt());

        self::assertSame($profileRun, $log->getProfileRun());
        self::assertNull($log->getRun());
        self::assertSame(CallPhase::Distill, $log->getPhase());
        self::assertNull($log->getBatchNumber());
        self::assertSame(3, $log->getAttempt());
        self::assertSame('{"model":"p"}', $log->getRequestBody());
    }

    private function user(): User
    {
        return new User('run-log@example.test', new \DateTimeImmutable('2026-10-01 08:00:00'));
    }

    private function sentAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-03 09:01:00');
    }
}
```

- [ ] **Step 3: Run them and watch them fail**

Run: `php bin/phpunit tests/Entity/ProfileRunTest.php tests/Entity/RecommendationSettingsTest.php tests/Entity/RecommendationSettingsValuesTest.php tests/Entity/RecommendationRunLogTest.php`
Expected: errors — `Class "App\Entity\ProfileRun" not found`, `Unknown named parameter $favoritesCap`, `Call to undefined method App\Entity\RecommendationRunLog::forRun()`.

- [ ] **Step 4: Add the two enums**

`backend/src/Enum/ProfileRunTrigger.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

/** What started a profile run; only a scheduled one may skip the model call when its inputs are unchanged. */
enum ProfileRunTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
    case Recommendation = 'recommendation';
}
```

`backend/src/Enum/ProfileRunOutcome.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum ProfileRunOutcome: string
{
    case Generated = 'generated';
    case Unchanged = 'unchanged';
    case NoHistory = 'no_history';
}
```

- [ ] **Step 5: Add `ProfileRun`**

`backend/src/Entity/ProfileRun.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Exception\InvalidRunStatusException;
use App\Enum\ProfileRunOutcome;
use App\Enum\ProfileRunTrigger;
use App\Enum\RunStatus;
use App\Repository\ProfileRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One generation of the account's interest profile, ticked under the lock its recommendation runs take. */
#[ORM\Entity(repositoryClass: ProfileRunRepository::class)]
#[ORM\Table(name: 'profile_run')]
#[ORM\Index(name: 'idx_profile_run_user_status', columns: ['user_id', 'status'])]
final class ProfileRun
{
    use PersistedId;

    public const int MAX_ATTEMPTS = 3;

    public const int MAX_TRANSPORT_FAILURES = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: RunStatus::class)]
    private RunStatus $status = RunStatus::Pending;

    #[ORM\Column(name: 'run_trigger', length: 16, enumType: ProfileRunTrigger::class)]
    private ProfileRunTrigger $trigger;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $fingerprint = null;

    #[ORM\Column(length: 16, nullable: true, enumType: ProfileRunOutcome::class)]
    private ?ProfileRunOutcome $outcome = null;

    /** Written by RecordedCall straight to the database; this entity only reads it. */
    #[ORM\Column(options: ['default' => 0])]
    private int $streamedChars = 0;

    #[ORM\Embedded(class: RunCallAttempts::class, columnPrefix: false)]
    private RunCallAttempts $callAttempts;

    #[ORM\Embedded(class: ProviderUsage::class, columnPrefix: false)]
    private ProviderUsage $providerUsage;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(User $user, ProfileRunTrigger $trigger, \DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->trigger = $trigger;
        $this->createdAt = $createdAt;
        $this->callAttempts = new RunCallAttempts();
        $this->providerUsage = new ProviderUsage();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStatus(): RunStatus
    {
        return $this->status;
    }

    public function getTrigger(): ProfileRunTrigger
    {
        return $this->trigger;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getFingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public function getOutcome(): ?ProfileRunOutcome
    {
        return $this->outcome;
    }

    public function getStreamedChars(): int
    {
        return $this->streamedChars;
    }

    public function getAttempts(): int
    {
        return $this->callAttempts->attempts();
    }

    public function getTransportFailures(): int
    {
        return $this->callAttempts->transportFailures();
    }

    public function getLastInvalidReply(): ?string
    {
        return $this->callAttempts->lastInvalidReply();
    }

    public function getProviderHost(): ?string
    {
        return $this->providerUsage->getProviderHost();
    }

    public function getModel(): ?string
    {
        return $this->providerUsage->getModel();
    }

    public function getPromptTokens(): int
    {
        return $this->providerUsage->getPromptTokens();
    }

    public function getCompletionTokens(): int
    {
        return $this->providerUsage->getCompletionTokens();
    }

    public function getCostNanoCredits(): ?int
    {
        return $this->providerUsage->getCostNanoCredits();
    }

    public function start(string $fingerprint, ?string $providerHost, string $model): void
    {
        $this->guardStatus(RunStatus::Pending, 'start');

        $this->fingerprint = $fingerprint;
        $this->providerUsage->stamp($providerHost, $model);
        $this->status = RunStatus::Running;
    }

    public function recordInvalidReply(string $reply): void
    {
        $this->guardStatus(RunStatus::Running, 'record an invalid reply on');
        $this->callAttempts->recordInvalidReply($reply);
    }

    public function hasExhaustedAttempts(): bool
    {
        return $this->callAttempts->attempts() >= self::MAX_ATTEMPTS;
    }

    public function recordTransportFailure(): void
    {
        $this->guardStatus(RunStatus::Running, 'record a transport failure on');
        $this->callAttempts->recordTransportFailure();
    }

    public function hasExhaustedTransportRetries(): bool
    {
        return $this->callAttempts->transportFailures() >= self::MAX_TRANSPORT_FAILURES;
    }

    public function complete(ProfileRunOutcome $outcome, \DateTimeImmutable $when): void
    {
        $this->guardStatus(RunStatus::Running, 'complete');

        $this->status = RunStatus::Completed;
        $this->completedAt = $when;
        $this->outcome = $outcome;
        $this->callAttempts->reset();
    }

    /** Also legal from PENDING: a run that finds no usable connection never starts. */
    public function fail(string $error, \DateTimeImmutable $when): void
    {
        if (!$this->status->isActive()) {
            throw new InvalidRunStatusException('fail', $this->status);
        }

        $this->status = RunStatus::Failed;
        $this->completedAt = $when;
        $this->error = $error;
    }

    private function guardStatus(RunStatus $requiredStatus, string $transition): void
    {
        if ($requiredStatus !== $this->status) {
            throw new InvalidRunStatusException($transition, $this->status);
        }
    }
}
```

- [ ] **Step 6: Add `StoredProfile` and `ProfileSettingsValues`, and split `RecommendationSettings`**

`backend/src/Entity/StoredProfile.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** The account's latest generated profile; a failed profile run never replaces it. */
#[ORM\Embeddable]
final class StoredProfile
{
    #[ORM\Column(name: 'profile_text', type: Types::TEXT, nullable: true)]
    private ?string $text;

    #[ORM\Column(name: 'profile_generated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $generatedAt;

    #[ORM\Column(name: 'profile_provider_host', length: 255, nullable: true)]
    private ?string $providerHost;

    #[ORM\Column(name: 'profile_model', length: 255, nullable: true)]
    private ?string $model;

    public function __construct(
        ?string $text,
        ?\DateTimeImmutable $generatedAt,
        ?string $providerHost,
        ?string $model,
    ) {
        $this->text = $text;
        $this->generatedAt = $generatedAt;
        $this->providerHost = $providerHost;
        $this->model = $model;
    }

    public static function none(): self
    {
        return new self(null, null, null, null);
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getGeneratedAt(): ?\DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function getProviderHost(): ?string
    {
        return $this->providerHost;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }
}
```

`backend/src/Entity/ProfileSettingsValues.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

/** The profile section's stored choices: the schedule, the connection and the two history caps only it reads. */
final readonly class ProfileSettingsValues
{
    public function __construct(
        public ?int $intervalHours,
        public ?AiProviderSettings $connection,
        public int $keptCap,
        public int $viewedCap,
    ) {
    }

    public static function defaults(): self
    {
        return new self(null, null, RecommendationSettings::DEFAULT_KEPT_CAP, RecommendationSettings::DEFAULT_VIEWED_CAP);
    }
}
```

`backend/src/Entity/RecommendationSettingsValues.php` becomes:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RecommendationBatchSize;

/**
 * The stored recommendation settings row: every field is an override, so null (or no row) means "use the default".
 * The profile and its own settings travel apart, in StoredProfile and ProfileSettingsValues.
 */
final readonly class RecommendationSettingsValues
{
    public function __construct(
        public ?string $guidancePrompt,
        public int $favoritesCap,
        public RecommendationPoolLimits $poolLimits,
        public ?int $contextWindow,
        public RecommendationBatchSize $batchSize,
        public bool $debugEnabled,
        public ?int $autoGenerateIntervalHours = null,
        public bool $showScoreAndReasons = false,
    ) {
    }
}
```

In `backend/src/Entity/RecommendationSettings.php`:

1. Replace the `$profileText` property and its docblock with:

```php
    #[ORM\Embedded(class: StoredProfile::class, columnPrefix: false)]
    private StoredProfile $storedProfile;
```

2. Directly below `$autoGenerateIntervalHours`, add:

```php
    /** How often a profile run starts on its own; null means only by hand. */
    #[ORM\Column(nullable: true)]
    private ?int $profileIntervalHours = null;

    /** The connection that builds the profile; null means the active one. */
    #[ORM\ManyToOne(targetEntity: AiProviderSettings::class)]
    #[ORM\JoinColumn(name: 'profile_connection_id', nullable: true, onDelete: 'SET NULL')]
    private ?AiProviderSettings $profileConnection = null;
```

3. Replace the constructor, `update()` and `values()` with these, and add the five methods below them:

```php
    public function __construct(User $user)
    {
        $this->user = $user;
        $this->storedProfile = StoredProfile::none();
    }

    public function update(RecommendationSettingsValues $values): void
    {
        $this->guidancePrompt = $values->guidancePrompt;
        $this->favoritesCap = $values->favoritesCap;
        $this->candidatePoolSize = $values->poolLimits->candidatePoolSize;
        $this->lookbackDays = $values->poolLimits->lookbackDays;
        $this->picksLimit = $values->poolLimits->picksLimit;
        $this->contextWindow = $values->contextWindow;
        $this->batchSize = $values->batchSize;
        $this->debugEnabled = $values->debugEnabled;
        $this->autoGenerateIntervalHours = $values->autoGenerateIntervalHours;
        $this->showScoreAndReasons = $values->showScoreAndReasons;
    }

    public function values(): RecommendationSettingsValues
    {
        return new RecommendationSettingsValues(
            guidancePrompt: $this->guidancePrompt,
            favoritesCap: $this->favoritesCap,
            poolLimits: new RecommendationPoolLimits($this->candidatePoolSize, $this->lookbackDays, $this->picksLimit),
            contextWindow: $this->contextWindow,
            batchSize: $this->batchSize,
            debugEnabled: $this->debugEnabled,
            autoGenerateIntervalHours: $this->autoGenerateIntervalHours,
            showScoreAndReasons: $this->showScoreAndReasons,
        );
    }

    public function updateProfileSettings(ProfileSettingsValues $values): void
    {
        $this->profileIntervalHours = $values->intervalHours;
        $this->profileConnection = $values->connection;
        $this->keptCap = $values->keptCap;
        $this->viewedCap = $values->viewedCap;
    }

    public function profileSettings(): ProfileSettingsValues
    {
        return new ProfileSettingsValues(
            $this->profileIntervalHours,
            $this->profileConnection,
            $this->keptCap,
            $this->viewedCap,
        );
    }

    public function storeProfile(StoredProfile $profile): void
    {
        $this->storedProfile = $profile;
    }

    public function getStoredProfile(): StoredProfile
    {
        return $this->storedProfile;
    }

    public function forgetProfileConnection(AiProviderSettings $connection): void
    {
        if ($this->profileConnection === $connection) {
            $this->profileConnection = null;
        }
    }
```

Drop the now unused `RecommendationHistoryCaps` import from `RecommendationSettings.php`.

- [ ] **Step 7: Give `RecommendationRunLog` its two named constructors**

In `backend/src/Entity/RecommendationRunLog.php`:

1. Below the existing `#[ORM\Index(name: 'idx_recommendation_run_log_run', …)]`, add `#[ORM\Index(name: 'idx_recommendation_run_log_profile_run', columns: ['profile_run_id'])]`.
2. Replace the `$run` property with:

```php
    #[ORM\ManyToOne(targetEntity: RecommendationRun::class)]
    #[ORM\JoinColumn(name: 'run_id', nullable: true, onDelete: 'CASCADE')]
    private ?RecommendationRun $run;

    #[ORM\ManyToOne(targetEntity: ProfileRun::class)]
    #[ORM\JoinColumn(name: 'profile_run_id', nullable: true, onDelete: 'CASCADE')]
    private ?ProfileRun $profileRun;
```

3. Replace the constructor and `getRun()` with:

```php
    /** Private: forRun() and forProfileRun() are the only ways in, so exactly one of the two owners is set. */
    private function __construct(
        ?RecommendationRun $run,
        ?ProfileRun $profileRun,
        CallPhase $phase,
        ?int $batchNumber,
        int $attempt,
        string $requestBody,
        \DateTimeImmutable $createdAt,
    ) {
        $this->run = $run;
        $this->profileRun = $profileRun;
        $this->phase = $phase;
        $this->batchNumber = $batchNumber;
        $this->attempt = $attempt;
        $this->requestBody = $requestBody;
        $this->createdAt = $createdAt;
        $this->receipt = new CallReceipt();
    }

    public static function forRun(
        RecommendationRun $run,
        CallPhase $phase,
        ?int $batchNumber,
        int $attempt,
        string $requestBody,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self($run, null, $phase, $batchNumber, $attempt, $requestBody, $createdAt);
    }

    public static function forProfileRun(
        ProfileRun $profileRun,
        int $attempt,
        string $requestBody,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self(null, $profileRun, CallPhase::Distill, null, $attempt, $requestBody, $createdAt);
    }

    public function getRun(): ?RecommendationRun
    {
        return $this->run;
    }

    public function getProfileRun(): ?ProfileRun
    {
        return $this->profileRun;
    }
```

Remove the old `/** @noinspection AutowireWrongClass … */` line with the constructor. Update the class docblock's first sentence to: `One provider-call attempt of a recommendation run or a profile run: the request as sent, …` (rest unchanged).

In `backend/src/Service/Recommendation/Run/Factory/RecommendationRunLogFactory.php`, `create()` returns `RecommendationRunLog::forRun(` with the same arguments instead of `new RecommendationRunLog(`.

PHPMD may flag the private constructor's seven parameters (`ExcessiveParameterList` threshold 10 — it does not); if it does, report it rather than suppressing.

- [ ] **Step 8: Run the entity tests and watch them pass**

Run: `php bin/phpunit tests/Entity/ProfileRunTest.php tests/Entity/RecommendationSettingsTest.php tests/Entity/RecommendationSettingsValuesTest.php tests/Entity/RecommendationRunLogTest.php`
Expected: OK (21 tests).

- [ ] **Step 9: Move the writer, the resolver and the recommendation DTO onto the split**

`backend/src/Service/Recommendation/Settings/RecommendationSettingsWriter.php` becomes:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Settings;

use App\Entity\ProfileSettingsValues;
use App\Entity\RecommendationSettings;
use App\Entity\RecommendationSettingsValues;
use App\Entity\StoredProfile;
use App\Entity\User;
use App\Repository\RecommendationSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The only writer of RecommendationSettings, one method per part of the row. Blank guidance normalises to null here,
 * the resolver's "use the default".
 */
final readonly class RecommendationSettingsWriter
{
    public function __construct(
        private RecommendationSettingsRepository $recommendationSettings,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(User $user, RecommendationSettingsValues $values): void
    {
        $this->loadOrCreate($user)->update($this->withNormalisedGuidance($values));
        $this->entityManager->flush();
    }

    public function saveProfileSettings(User $user, ProfileSettingsValues $values): void
    {
        $this->loadOrCreate($user)->updateProfileSettings($values);
        $this->entityManager->flush();
    }

    public function storeProfile(User $user, StoredProfile $profile): void
    {
        $this->loadOrCreate($user)->storeProfile($profile);
        $this->entityManager->flush();
    }

    private function loadOrCreate(User $user): RecommendationSettings
    {
        $settings = $this->recommendationSettings->findForUser($user);

        if (null !== $settings) {
            return $settings;
        }

        $settings = new RecommendationSettings($user);
        $this->entityManager->persist($settings);

        return $settings;
    }

    private function withNormalisedGuidance(RecommendationSettingsValues $values): RecommendationSettingsValues
    {
        $guidancePrompt = $values->guidancePrompt;

        if (null === $guidancePrompt || '' === trim($guidancePrompt)) {
            $guidancePrompt = null;
        }

        return new RecommendationSettingsValues(
            guidancePrompt: $guidancePrompt,
            favoritesCap: $values->favoritesCap,
            poolLimits: $values->poolLimits,
            contextWindow: $values->contextWindow,
            batchSize: $values->batchSize,
            debugEnabled: $values->debugEnabled,
            autoGenerateIntervalHours: $values->autoGenerateIntervalHours,
            showScoreAndReasons: $values->showScoreAndReasons,
        );
    }
}
```

In `backend/src/Service/Recommendation/Settings/Pass/AccountRecommendationSettings.php`, `forConnection()` reads the caps and the profile from their new homes. Replace its `return new EffectiveRecommendationSettingsModel(…)` arguments `guidancePrompt`, `profileText` and `historyCaps` with:

```php
            guidancePrompt: $values?->guidancePrompt,
            profileText: $this->row?->getStoredProfile()->getText(),
            historyCaps: $this->historyCaps($values),
```

and add the private method (imports: `App\Entity\ProfileSettingsValues`, `App\Entity\RecommendationSettingsValues`; `RecommendationHistoryCaps` stays):

```php
    /** The favorites cap is the recommendation form's; kept and viewed are the profile section's. */
    private function historyCaps(?RecommendationSettingsValues $values): RecommendationHistoryCaps
    {
        $profileSettings = $this->row?->profileSettings() ?? ProfileSettingsValues::defaults();

        return new RecommendationHistoryCaps(
            $values->favoritesCap ?? RecommendationSettings::DEFAULT_FAVORITES_CAP,
            $profileSettings->keptCap,
            $profileSettings->viewedCap,
        );
    }
```

In `backend/src/Dto/Recommendation/SaveRecommendationSettingsRequest.php` delete the `keptCap` and `viewedCap` constructor parameters with their `#[Assert\Range]` attributes, drop the `RecommendationHistoryCaps` import, and in `values()` replace `historyCaps: new RecommendationHistoryCaps(…),` with `favoritesCap: $this->favoritesCap,`. A client that still sends the two fields is not rejected: `MapRequestPayload` ignores unknown keys.

`RecommendationSettingsBounds` stays as it is here; Task 7 moves the two caps from `EXPERT_FIELDS` into `PROFILE_FIELDS`.

In `backend/src/Service/Recommendation/Llm/Run/RecommendationProfileDistiller.php` (deleted in Task 5) replace the `storeProfile` call with the run's own stamp, so it compiles meanwhile (import `App\Entity\StoredProfile`):

```php
        $this->settingsWriter->storeProfile(
            $run->getUser(),
            new StoredProfile($profile, $run->getCreatedAt(), $run->getProviderHost(), $run->getModel()),
        );
```

Rewrite `backend/tests/Service/Recommendation/Settings/RecommendationSettingsWriterTest.php`'s tests (keep `setUp()` and `userWithoutSettingsRow()`; imports: `ProfileSettingsValues`, `RecommendationPoolLimits`, `RecommendationSettings`, `RecommendationSettingsValues`, `StoredProfile`, `User`, `RecommendationBatchSize`):

```php
    public function testStoreProfilePersistsTheWholeProfile(): void
    {
        $this->writer->storeProfile($this->user, $this->typographyProfile());

        $stored = $this->reloaded()->getStoredProfile();
        self::assertSame('Likes long-form essays on typography.', $stored->getText());
        self::assertSame('2026-10-03 07:15:00', $stored->getGeneratedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('llm.example.test', $stored->getProviderHost());
        self::assertSame('qwen3-14b', $stored->getModel());
    }

    public function testStoreProfileCreatesARowWhenNoneExists(): void
    {
        $this->writer->storeProfile($this->userWithoutSettingsRow(), $this->typographyProfile());

        self::assertNotNull($this->recommendationSettings->findForUser($this->userWithoutSettingsRow()));
    }

    public function testStoreProfileLeavesTheSettingsAndTheProfileSettingsUntouched(): void
    {
        $this->writer->save($this->user, $this->values(showScoreAndReasons: false));
        $this->writer->saveProfileSettings($this->user, new ProfileSettingsValues(48, null, 20, 30));

        $this->writer->storeProfile($this->user, $this->typographyProfile());

        $reloaded = $this->reloaded();
        self::assertSame('Only cats.', $reloaded->values()->guidancePrompt);
        self::assertSame(10, $reloaded->values()->favoritesCap);
        self::assertSame(65536, $reloaded->values()->contextWindow);
        self::assertTrue($reloaded->values()->debugEnabled);
        self::assertSame(48, $reloaded->profileSettings()->intervalHours);
        self::assertSame(20, $reloaded->profileSettings()->keptCap);
        self::assertSame(30, $reloaded->profileSettings()->viewedCap);
    }

    public function testSavingSettingsPersistsShowScoreAndReasons(): void
    {
        $this->writer->save($this->user, $this->values(showScoreAndReasons: true));

        self::assertTrue($this->reloaded()->values()->showScoreAndReasons);
    }

    public function testSavingSettingsKeepsTheStoredProfileAndTheProfileSettings(): void
    {
        $this->writer->storeProfile($this->user, $this->typographyProfile());
        $this->writer->saveProfileSettings($this->user, new ProfileSettingsValues(168, null, 20, 30));

        $this->writer->save($this->user, $this->values(showScoreAndReasons: false));

        $reloaded = $this->reloaded();
        self::assertSame('Likes long-form essays on typography.', $reloaded->getStoredProfile()->getText());
        self::assertSame(168, $reloaded->profileSettings()->intervalHours);
        self::assertSame(20, $reloaded->profileSettings()->keptCap);
        self::assertSame(30, $reloaded->profileSettings()->viewedCap);
    }

    public function testSaveProfileSettingsPersistsTheScheduleAndTheCaps(): void
    {
        $this->writer->saveProfileSettings($this->user, new ProfileSettingsValues(6, null, 7, 9));

        $profileSettings = $this->reloaded()->profileSettings();
        self::assertSame(6, $profileSettings->intervalHours);
        self::assertSame(7, $profileSettings->keptCap);
        self::assertSame(9, $profileSettings->viewedCap);
    }

    private function values(bool $showScoreAndReasons): RecommendationSettingsValues
    {
        return new RecommendationSettingsValues(
            guidancePrompt: 'Only cats.',
            favoritesCap: 10,
            poolLimits: RecommendationPoolLimits::defaults(),
            contextWindow: 65536,
            batchSize: RecommendationBatchSize::Large,
            debugEnabled: true,
            showScoreAndReasons: $showScoreAndReasons,
        );
    }

    private function typographyProfile(): StoredProfile
    {
        return new StoredProfile(
            'Likes long-form essays on typography.',
            new \DateTimeImmutable('2026-10-03 07:15:00'),
            'llm.example.test',
            'qwen3-14b',
        );
    }

    private function reloaded(): RecommendationSettings
    {
        $this->entityManager->clear();
        $reloaded = $this->recommendationSettings->findForUser($this->user);
        self::assertNotNull($reloaded);

        return $reloaded;
    }
```

`reloaded()` clears the identity map, so `$this->user` is detached afterwards; `findForUser()` binds it by id, which Doctrine accepts for a detached entity. If it does not, re-fetch the user by id inside `reloaded()` and report it.

In `backend/tests/Service/Recommendation/Settings/RecommendationSettingsResolverTest.php`:

1. In `testUserOverrideBeatsTheProviderWindow`, replace `historyCaps: new RecommendationHistoryCaps(10, 20, 30),` with `favoritesCap: 10,`, add `$row->updateProfileSettings(new ProfileSettingsValues(null, null, 20, 30));` right after the `update(…)` call, and keep the three cap assertions (10, 20, 30): together they now pin that the favorites cap and the profile caps meet in `historyCaps`.
2. Replace the `settingsRowFor()` helper with:

```php
    private function settingsRowFor(
        User $user,
        ?string $profileText = null,
        bool $showScoreAndReasons = false,
    ): RecommendationSettings {
        $row = new RecommendationSettings($user);
        $row->update(new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: RecommendationSettings::DEFAULT_FAVORITES_CAP,
            poolLimits: RecommendationPoolLimits::defaults(),
            contextWindow: null,
            batchSize: RecommendationBatchSize::Medium,
            debugEnabled: false,
            showScoreAndReasons: $showScoreAndReasons,
        ));
        $row->storeProfile(new StoredProfile($profileText, null, null, null));
        $this->entityManager->persist($row);
        $this->entityManager->flush();

        return $row;
    }
```

3. Swap the `RecommendationHistoryCaps` import for `ProfileSettingsValues` and `StoredProfile`.

In `backend/tests/Controller/Api/RecommendationSettingsControllerTest.php`, `testSavingFullSettingsEchoesTheNewStateAndPersists`: the payload still sends `keptCap: 15` and `viewedCap: 30`, and the two assertions become `self::assertSame(40, $payload['keptCap']);` and `self::assertSame(80, $payload['viewedCap']);` with the comment `// The recommendation form no longer owns these two caps; the profile section does.` dropped — the test name already says what is persisted. Also update the later GET assertions in the same test the same way if it repeats them.

- [ ] **Step 10: Add `ProfileRunRepository` with its test**

`backend/src/Repository/ProfileRunRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProfileRun;
use App\Entity\User;
use App\Enum\RunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProfileRun>
 */
final class ProfileRunRepository extends ServiceEntityRepository
{
    private const int MAXIMUM_RUNS_PER_SWEEP = 10;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProfileRun::class);
    }

    public function findActiveForUser(User $user): ?ProfileRun
    {
        /** @var ProfileRun|null $profileRun */
        $profileRun = $this->activeStatusQuery()
            ->andWhere('p.user = :user')->setParameter('user', $user)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $profileRun;
    }

    public function findLatestForUser(User $user): ?ProfileRun
    {
        /** @var ProfileRun|null $profileRun */
        $profileRun = $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')->setParameter('user', $user)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $profileRun;
    }

    /** @return list<ProfileRun> oldest first, so a capped sweep reaches every account in turn */
    public function findAllActive(): array
    {
        /** @var list<ProfileRun> $profileRuns */
        $profileRuns = $this->activeStatusQuery()
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(self::MAXIMUM_RUNS_PER_SWEEP)
            ->getQuery()
            ->getResult();

        return $profileRuns;
    }

    public function hasActiveRun(): bool
    {
        $activeRunCount = $this->activeStatusQuery()
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $activeRunCount > 0;
    }

    public function latestCompletedFingerprintFor(User $user): ?string
    {
        /** @var list<array{fingerprint: ?string}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('p.fingerprint AS fingerprint')
            ->andWhere('p.user = :user')->setParameter('user', $user)
            ->andWhere('p.status = :completed')->setParameter('completed', RunStatus::Completed)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();

        return $rows[0]['fingerprint'] ?? null;
    }

    /** @return list<int> newest first */
    public function findNewestIdsForUser(User $user, int $limit): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('p.id AS id')
            ->andWhere('p.user = :user')->setParameter('user', $user)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'id');
    }

    private function activeStatusQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status IN (:active)')->setParameter('active', RunStatus::active());
    }
}
```

`backend/tests/Repository/ProfileRunRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ProfileRun;
use App\Entity\User;
use App\Enum\ProfileRunOutcome;
use App\Enum\ProfileRunTrigger;
use App\Repository\ProfileRunRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;

final class ProfileRunRepositoryTest extends DbTestCase
{
    use SeedsUsers;

    public function testTheActiveRunIsTheAccountsOwnUnfinishedOne(): void
    {
        $owner = $this->user('profile-runs-active@example.test');
        $this->completed($owner, 'fp-done');
        $running = $this->running($owner, 'fp-running');
        $this->running($this->user('profile-runs-other@example.test'), 'fp-other');

        self::assertSame($running->getId(), $this->profileRuns()->findActiveForUser($owner)?->getId());
    }

    public function testTheLatestRunIsTheNewestWhateverItsStatus(): void
    {
        $owner = $this->user('profile-runs-latest@example.test');
        $this->running($owner, 'fp-running');
        $failed = $this->failed($owner);

        self::assertSame($failed->getId(), $this->profileRuns()->findLatestForUser($owner)?->getId());
    }

    public function testTheLatestCompletedFingerprintSkipsRunningAndFailedRuns(): void
    {
        $owner = $this->user('profile-runs-fingerprint@example.test');
        $this->completed($owner, 'fp-older');
        $this->completed($owner, 'fp-newest-completed');
        $this->failed($owner);
        $this->running($owner, 'fp-running');

        self::assertSame('fp-newest-completed', $this->profileRuns()->latestCompletedFingerprintFor($owner));
    }

    public function testAnAccountWithoutACompletedRunHasNoFingerprint(): void
    {
        $owner = $this->user('profile-runs-no-fingerprint@example.test');
        $this->running($owner, 'fp-running');

        self::assertNull($this->profileRuns()->latestCompletedFingerprintFor($owner));
    }

    public function testEveryActiveRunComesOldestFirst(): void
    {
        $older = $this->running($this->user('profile-runs-all-a@example.test'), 'fp-a');
        $this->completed($this->user('profile-runs-all-b@example.test'), 'fp-b');
        $newer = $this->running($this->user('profile-runs-all-c@example.test'), 'fp-c');

        $ids = array_map(
            static fn (ProfileRun $profileRun): ?int => $profileRun->getId(),
            $this->profileRuns()->findAllActive(),
        );

        self::assertSame([$older->getId(), $newer->getId()], $ids);
        self::assertTrue($this->profileRuns()->hasActiveRun());
    }

    public function testNoActiveRunAnywhereReadsAsNone(): void
    {
        $this->completed($this->user('profile-runs-none@example.test'), 'fp-done');

        self::assertFalse($this->profileRuns()->hasActiveRun());
    }

    public function testTheNewestIdsStopAtTheLimit(): void
    {
        $owner = $this->user('profile-runs-ids@example.test');
        $this->completed($owner, 'fp-1');
        $second = $this->completed($owner, 'fp-2');
        $third = $this->failed($owner);

        self::assertSame(
            [$third->requireId(), $second->requireId()],
            $this->profileRuns()->findNewestIdsForUser($owner, 2),
        );
    }

    private function running(User $owner, string $fingerprint): ProfileRun
    {
        $profileRun = new ProfileRun($owner, ProfileRunTrigger::Scheduled, new \DateTimeImmutable('2026-10-03 09:00:00'));
        $profileRun->start($fingerprint, 'llm.example.test', 'qwen3-14b');
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        return $profileRun;
    }

    private function completed(User $owner, string $fingerprint): ProfileRun
    {
        $profileRun = $this->running($owner, $fingerprint);
        $profileRun->complete(ProfileRunOutcome::Generated, new \DateTimeImmutable('2026-10-03 09:02:00'));
        $this->entityManager->flush();

        return $profileRun;
    }

    private function failed(User $owner): ProfileRun
    {
        $profileRun = new ProfileRun($owner, ProfileRunTrigger::Manual, new \DateTimeImmutable('2026-10-03 09:00:00'));
        $profileRun->fail('No connection can build your profile.', new \DateTimeImmutable('2026-10-03 09:00:01'));
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        return $profileRun;
    }

    private function profileRuns(): ProfileRunRepository
    {
        /** @var ProfileRunRepository $profileRuns */
        $profileRuns = $this->entityManager->getRepository(ProfileRun::class);

        return $profileRuns;
    }
}
```

dama's static driver rolls each test back, so the exact-list and `hasActiveRun()` assertions see only this test's rows.

- [ ] **Step 11: Mechanical fallout of the `RecommendationSettingsValues` and `RecommendationRunLog` changes**

Apply these rewrites, then run each listed file:

1. In every `new RecommendationSettingsValues(` call, `historyCaps: RecommendationHistoryCaps::defaults(),` becomes `favoritesCap: RecommendationSettings::DEFAULT_FAVORITES_CAP,` (import `App\Entity\RecommendationSettings`, drop `RecommendationHistoryCaps` where it is then unused). Files: `tests/Support/RecommendationRunFixtures.php`, `tests/Command/RecommendationDrainCommandTest.php`, `tests/Service/Recommendation/Settings/RecommendationSettingsRoundTripTest.php`, `tests/Service/Recommendation/Run/RecommendationPipelineTest.php`, `tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php` (two calls), `tests/Service/Recommendation/Run/ForYouSweepTest.php`, `tests/Service/Recommendation/Run/DueRecommendationRunFinderTest.php`, `tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php`, `tests/Service/Worker/StartDueRecommendationRunsHandlerTest.php`.
2. `tests/Service/Account/AccountResetTest.php`: `historyCaps: new RecommendationHistoryCaps(1, 1, 1),` becomes `favoritesCap: 1,`.
3. `tests/Service/Backup/AccountBackupExporterTest.php` (`testTheAccountLineCarriesNoRecommendationSettings`): the `historyCaps` line becomes `favoritesCap: RecommendationSettings::DEFAULT_FAVORITES_CAP,`, the `profileText:` argument is removed, and after `update(…)` add `$settings->storeProfile(new StoredProfile('Reads long-form essays about urban planning.', null, null, null));`.
4. Readers of the old profile field: `tests/Service/Recommendation/Run/RecommendationPipelineTest.php::storedProfileText()` and `tests/Service/Recommendation/Llm/Run/RecommendationProfileDistillerTest.php` (its `storedProfileText()` at the bottom) read `$settings?->getStoredProfile()->getText()` instead of `$settings?->values()->profileText`.
5. Every `new RecommendationRunLog(` in `tests/` becomes `RecommendationRunLog::forRun(` with the same arguments: `tests/Support/RecommendationRunFixtures.php`, `tests/Http/RecommendationDebugLogJsonTest.php`, `tests/Service/Recommendation/Run/RecommendationCallRecorderTest.php`, `tests/Service/Recommendation/Run/RecommendationRunStarterTest.php`, `tests/Service/Recommendation/Run/Pass/RecordedCallTest.php`, `tests/Service/Account/AccountResetTest.php`.

`EffectiveRecommendationSettingsModel` keeps `historyCaps` and `profileText`, so the tests that build it (`TickContextTest`, `RecommendationHistoryLoaderTest`, `RecommendationPromptBuilderTest`, `RecommendationSettingsJsonTest`) need no change.

Run: `php bin/phpunit tests/Entity tests/Repository/ProfileRunRepositoryTest.php tests/Service/Recommendation/Settings tests/Controller/Api/RecommendationSettingsControllerTest.php tests/Service/Backup/AccountBackupExporterTest.php tests/Service/Account/AccountResetTest.php tests/Http/RecommendationDebugLogJsonTest.php`
Expected: all pass — except a schema error if the test bootstrap builds a schema the code no longer matches (it builds from metadata, so it should not). Then run `php bin/phpunit tests/Service/Recommendation tests/Service/Worker tests/Command` and fix any compile fallout the list above missed the same mechanical way.

- [ ] **Step 12: Write the migration**

Create `backend/migrations/Version20261003100000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Accounts with a recommendation schedule start with a daily profile; everyone else generates it by hand. */
final class Version20261003100000 extends AbstractMigration
{
    private const string PROFILE_RUN_COLUMNS = <<<'SQL'
        status VARCHAR(16) NOT NULL,
        run_trigger VARCHAR(16) NOT NULL,
        created_at DATETIME NOT NULL,
        completed_at DATETIME DEFAULT NULL,
        fingerprint VARCHAR(64) DEFAULT NULL,
        outcome VARCHAR(16) DEFAULT NULL,
        provider_host VARCHAR(255) DEFAULT NULL,
        model VARCHAR(255) DEFAULT NULL,
        cost_nano_credits BIGINT DEFAULT NULL,
        SQL;

    private const string SQLITE_RUN_LOG_CALL_COLUMNS = ' phase VARCHAR(16) NOT NULL, batch_number INTEGER DEFAULT NULL,'
        . ' attempt INTEGER NOT NULL, request_body CLOB NOT NULL, response_text CLOB NOT NULL,'
        . ' verdict VARCHAR(24) DEFAULT NULL, wire_bytes INTEGER DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL,'
        . ' finished_at DATETIME DEFAULT NULL, error_detail CLOB DEFAULT NULL, finish_reason VARCHAR(32) DEFAULT NULL,'
        . ' request_id VARCHAR(255) DEFAULT NULL, answering_model VARCHAR(255) DEFAULT NULL,'
        . ' cost_nano_credits BIGINT DEFAULT NULL,';

    private const string SQLITE_RUN_LOG_RUN_CONSTRAINT = ' CONSTRAINT FK_recommendation_run_log_run FOREIGN KEY (run_id)'
        . ' REFERENCES recommendation_run (id) ON DELETE CASCADE';

    private const string RUN_LOG_COPIED_COLUMNS = 'id, run_id, phase, batch_number, attempt, request_body, '
        . 'response_text, verdict, wire_bytes, created_at, finished_at, error_detail, finish_reason, request_id, '
        . 'answering_model, cost_nano_credits';

    public function getDescription(): string
    {
        return 'Add profile_run, the stored profile and the profile settings, and profile-run log rows (#1351).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('profile_run'), 'profile_run already exists.');

        if ($this->mysql()) {
            $this->upMySql();
        } else {
            $this->upSqlite();
        }

        $this->addSql('UPDATE user_recommendation_settings SET profile_interval_hours = 24 WHERE auto_generate_interval_hours IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('profile_run'), 'profile_run does not exist.');

        $this->addSql('DELETE FROM recommendation_run_log WHERE run_id IS NULL');

        if ($this->mysql()) {
            $this->downMySql();

            return;
        }

        $this->downSqlite();
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function upMySql(): void
    {
        $this->addSql('CREATE TABLE profile_run (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, '
            . self::PROFILE_RUN_COLUMNS
            . ' error LONGTEXT DEFAULT NULL, streamed_chars INT DEFAULT 0 NOT NULL, attempts INT DEFAULT 0 NOT NULL,'
            . ' transport_failures INT DEFAULT 0 NOT NULL, last_invalid_reply LONGTEXT DEFAULT NULL,'
            . ' prompt_tokens INT DEFAULT 0 NOT NULL, completion_tokens INT DEFAULT 0 NOT NULL,'
            . ' reasoning_tokens INT DEFAULT 0 NOT NULL, cached_tokens INT DEFAULT 0 NOT NULL,'
            . ' INDEX IDX_FED40DFFA76ED395 (user_id), INDEX idx_profile_run_user_status (user_id, status),'
            . ' PRIMARY KEY (id), CONSTRAINT FK_FED40DFFA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)'
            . ' ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE user_recommendation_settings ADD profile_interval_hours INT DEFAULT NULL, ADD profile_generated_at DATETIME DEFAULT NULL, ADD profile_provider_host VARCHAR(255) DEFAULT NULL, ADD profile_model VARCHAR(255) DEFAULT NULL, ADD profile_connection_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE user_recommendation_settings ADD CONSTRAINT FK_83A9855E23D39AC1 FOREIGN KEY (profile_connection_id) REFERENCES user_ai_settings (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_83A9855E23D39AC1 ON user_recommendation_settings (profile_connection_id)');
        $this->addSql('ALTER TABLE recommendation_run_log MODIFY run_id INT DEFAULT NULL, ADD profile_run_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE recommendation_run_log ADD CONSTRAINT FK_recommendation_run_log_profile_run FOREIGN KEY (profile_run_id) REFERENCES profile_run (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_recommendation_run_log_profile_run ON recommendation_run_log (profile_run_id)');
    }

    private function upSqlite(): void
    {
        $this->addSql('CREATE TABLE profile_run (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER NOT NULL, '
            . self::PROFILE_RUN_COLUMNS
            . ' error CLOB DEFAULT NULL, streamed_chars INTEGER DEFAULT 0 NOT NULL, attempts INTEGER DEFAULT 0 NOT NULL,'
            . ' transport_failures INTEGER DEFAULT 0 NOT NULL, last_invalid_reply CLOB DEFAULT NULL,'
            . ' prompt_tokens INTEGER DEFAULT 0 NOT NULL, completion_tokens INTEGER DEFAULT 0 NOT NULL,'
            . ' reasoning_tokens INTEGER DEFAULT 0 NOT NULL, cached_tokens INTEGER DEFAULT 0 NOT NULL,'
            . ' CONSTRAINT FK_FED40DFFA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE'
            . ' NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_FED40DFFA76ED395 ON profile_run (user_id)');
        $this->addSql('CREATE INDEX idx_profile_run_user_status ON profile_run (user_id, status)');
        $this->addSql('ALTER TABLE user_recommendation_settings ADD COLUMN profile_interval_hours INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE user_recommendation_settings ADD COLUMN profile_generated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE user_recommendation_settings ADD COLUMN profile_provider_host VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_recommendation_settings ADD COLUMN profile_model VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_recommendation_settings ADD COLUMN profile_connection_id INTEGER DEFAULT NULL REFERENCES user_ai_settings (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_83A9855E23D39AC1 ON user_recommendation_settings (profile_connection_id)');
        $this->rebuildSqliteRunLogWithProfileRuns();
    }

    private function downMySql(): void
    {
        $this->addSql('ALTER TABLE recommendation_run_log DROP FOREIGN KEY FK_recommendation_run_log_profile_run');
        $this->addSql('DROP INDEX idx_recommendation_run_log_profile_run ON recommendation_run_log');
        $this->addSql('ALTER TABLE recommendation_run_log DROP profile_run_id, MODIFY run_id INT NOT NULL');
        $this->addSql('ALTER TABLE user_recommendation_settings DROP FOREIGN KEY FK_83A9855E23D39AC1');
        $this->addSql('DROP INDEX IDX_83A9855E23D39AC1 ON user_recommendation_settings');
        $this->addSql('ALTER TABLE user_recommendation_settings DROP profile_interval_hours, DROP profile_generated_at, DROP profile_provider_host, DROP profile_model, DROP profile_connection_id');
        $this->addSql('DROP TABLE profile_run');
    }

    private function downSqlite(): void
    {
        $this->rebuildSqliteRunLogWithoutProfileRuns();
        $this->addSql('DROP INDEX IDX_83A9855E23D39AC1');
        foreach (['profile_connection_id', 'profile_model', 'profile_provider_host', 'profile_generated_at', 'profile_interval_hours'] as $column) {
            $this->addSql(\sprintf('ALTER TABLE user_recommendation_settings DROP COLUMN %s', $column));
        }
        $this->addSql('DROP TABLE profile_run');
    }

    /** SQLite cannot relax NOT NULL in place, so the table is rebuilt around the copied rows. */
    private function rebuildSqliteRunLogWithProfileRuns(): void
    {
        $this->renameSqliteRunLogAside();
        $this->addSql('CREATE TABLE recommendation_run_log (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' run_id INTEGER DEFAULT NULL, profile_run_id INTEGER DEFAULT NULL,'
            . self::SQLITE_RUN_LOG_CALL_COLUMNS
            . self::SQLITE_RUN_LOG_RUN_CONSTRAINT
            . ', CONSTRAINT FK_recommendation_run_log_profile_run FOREIGN KEY (profile_run_id)'
            . ' REFERENCES profile_run (id) ON DELETE CASCADE)');
        $this->copySqliteRunLogBack();
        $this->addSql('CREATE INDEX idx_recommendation_run_log_profile_run ON recommendation_run_log (profile_run_id)');
    }

    private function rebuildSqliteRunLogWithoutProfileRuns(): void
    {
        $this->renameSqliteRunLogAside();
        $this->addSql('CREATE TABLE recommendation_run_log (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' run_id INTEGER NOT NULL,'
            . self::SQLITE_RUN_LOG_CALL_COLUMNS
            . self::SQLITE_RUN_LOG_RUN_CONSTRAINT
            . ')');
        $this->copySqliteRunLogBack();
    }

    /** Index names are global in SQLite, so the old table's indexes go before the new table takes their names. */
    private function renameSqliteRunLogAside(): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_recommendation_run_log_profile_run');
        $this->addSql('DROP INDEX idx_recommendation_run_log_run');
        $this->addSql('ALTER TABLE recommendation_run_log RENAME TO recommendation_run_log_previous');
    }

    private function copySqliteRunLogBack(): void
    {
        $this->addSql(\sprintf(
            'INSERT INTO recommendation_run_log (%1$s) SELECT %1$s FROM recommendation_run_log_previous',
            self::RUN_LOG_COPIED_COLUMNS,
        ));
        $this->addSql('DROP TABLE recommendation_run_log_previous');
        $this->addSql('CREATE INDEX idx_recommendation_run_log_run ON recommendation_run_log (run_id)');
    }

    /** Refuses any platform but the two supported ones: better a refusal than DDL nobody tested. */
    private function mysql(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        return $platform instanceof AbstractMySQLPlatform;
    }
}
```

The SQLite rebuild relies on SQLite ≥ 3.35 for the `DROP COLUMN` in `downSqlite()` (CI's 3.45 and the Docker image qualify).

- [ ] **Step 13: Migrate from empty on scratch SQLite and scratch MySQL, then validate**

From the repository root, first prove that the Docker stack serves this checkout:

```bash
bash backend/bin/e2e-preflight.sh "$(git rev-parse --show-toplevel)"
```

From `backend/`:

```bash
export SCRATCH_SQLITE='sqlite:///%kernel.project_dir%/var/migration-1351.db'
rm -f var/migration-1351.db
DATABASE_URL="$SCRATCH_SQLITE" php bin/console doctrine:migrations:migrate --no-interaction
DATABASE_URL="$SCRATCH_SQLITE" php bin/console doctrine:schema:validate
```

From the repository root:

```bash
export SCRATCH_MYSQL='mysql://root:root@mysql:3306/feedreader_migration_1351?serverVersion=8.4&charset=utf8mb4'
docker compose exec -T -e DATABASE_URL="$SCRATCH_MYSQL" php bin/console doctrine:database:drop --force --if-exists
docker compose exec -T -e DATABASE_URL="$SCRATCH_MYSQL" php bin/console doctrine:database:create
docker compose exec -T -e DATABASE_URL="$SCRATCH_MYSQL" php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T -e DATABASE_URL="$SCRATCH_MYSQL" php bin/console doctrine:schema:validate
```

Expected on both: `[OK] Successfully migrated to version: DoctrineMigrations\Version20261003100000` and `[OK] The database schema is in sync with the mapping files.` A mismatch names the column or index; fix the migration, never the mapping, unless the mapping is what is wrong.

- [ ] **Step 14: Verify the carry-over, the copied log rows and `down()`, both platforms**

Per platform prefix (as in Step 13), starting from an empty scratch database:

```bash
<prefix> doctrine:migrations:migrate 'DoctrineMigrations\Version20261002200000' --no-interaction
<prefix> dbal:run-sql "INSERT INTO app_user (id, email, roles, status, created_at) VALUES (1, 'carry-1@example.test', '[]', 'active', '2026-10-02 12:00:00'), (2, 'carry-2@example.test', '[]', 'active', '2026-10-02 12:00:00')"
<prefix> dbal:run-sql "INSERT INTO user_recommendation_settings (id, user_id, auto_generate_interval_hours) VALUES (1, 1, 6), (2, 2, NULL)"
<prefix> dbal:run-sql "INSERT INTO recommendation_run (id, user_id, status, created_at, batch_winners) VALUES (7, 1, 'completed', '2026-10-02 12:00:00', '[]')"
<prefix> dbal:run-sql "INSERT INTO recommendation_run_log (id, run_id, phase, attempt, request_body, response_text, created_at) VALUES (31, 7, 'batch', 2, 'req', 'resp', '2026-10-02 12:01:00')"
<prefix> doctrine:migrations:migrate --no-interaction
<prefix> dbal:run-sql "SELECT user_id, profile_interval_hours FROM user_recommendation_settings ORDER BY user_id"
<prefix> dbal:run-sql "SELECT id, run_id, profile_run_id, attempt FROM recommendation_run_log"
```

Expected: `1 24`, `2 NULL`; the log row reads `31 7 NULL 2`. If the `INSERT INTO recommendation_run` fails for a missing NOT NULL column, add that column with a literal the error names and record it in the report.

Then down and up again:

```bash
<prefix> doctrine:migrations:migrate prev --no-interaction
<prefix> dbal:run-sql "SELECT id, run_id, attempt FROM recommendation_run_log"
<prefix> doctrine:migrations:migrate --no-interaction
```

Expected: `31 7 2` survives both directions. If SQLite refuses `DROP COLUMN profile_connection_id` (a column that carries a foreign key), record the error verbatim and report it; do not work around it here.

Clean up: `rm -f var/migration-1351.db` (from `backend/`) and `docker compose exec -T -e DATABASE_URL="$SCRATCH_MYSQL" php bin/console doctrine:database:drop --force` (from the root).

- [ ] **Step 15: Deletion checks**

1. In `ProfileRun::hasExhaustedAttempts()`, change `>=` to `>`. `testTheThirdUnusableReplyExhaustsTheAttempts` must fail. Restore.
2. In `ProfileRun::complete()`, drop `$this->outcome = $outcome;`. `testCompleteRecordsTheOutcomeAndTheTime` must fail. Restore.
3. In `RecommendationSettings::update()`, add `$this->keptCap = 40;`. `testUpdatingTheRecommendationFieldsKeepsTheProfileSettings` must fail. Restore.
4. In `RecommendationSettings::forgetProfileConnection()`, drop the `if` (clear unconditionally). `testForgettingAConnectionClearsTheProfileConnectionOnlyWhenItIsThatOne` must fail. Restore.
5. In `AccountRecommendationSettings::historyCaps()`, swap `$profileSettings->keptCap` for `RecommendationSettings::DEFAULT_KEPT_CAP`. `testUserOverrideBeatsTheProviderWindow` must fail on `20`. Restore.
6. In `ProfileRunRepository::latestCompletedFingerprintFor()`, drop the status condition. `testTheLatestCompletedFingerprintSkipsRunningAndFailedRuns` must fail. Restore.
7. In `RecommendationRunLog::forProfileRun()`, pass `CallPhase::Batch`. `testAProfileRunRowIsADistillCallWithoutABatchOrARun` must fail. Restore.

- [ ] **Step 16: Gates, live migration, commit**

```bash
php bin/phpunit tests/Entity tests/Repository tests/Service/Recommendation tests/Service/Worker tests/Command tests/Controller/Api/RecommendationSettingsControllerTest.php tests/Service/Account tests/Service/Backup tests/Http
composer cs && composer stan && composer md && composer tramp
```

Back up the dev settings table, then apply the migration to the live dev database (the mapping now matches):

```bash
docker compose exec -T mysql mysqldump -uroot -proot feedreader user_recommendation_settings recommendation_run_log > "$TMPDIR/before-1351-task1.sql"
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

**Executor note:** the dev database name and credentials are read from `docker-compose.yml` / `.env`; adjust the dump line to them.

```bash
git add backend/src/Enum/ProfileRunTrigger.php backend/src/Enum/ProfileRunOutcome.php backend/src/Entity backend/src/Repository/ProfileRunRepository.php backend/src/Service/Recommendation backend/src/Dto/Recommendation/SaveRecommendationSettingsRequest.php backend/migrations/Version20261003100000.php backend/tests
git commit -m "feat(#1351): a profile run, the stored profile and the profile settings have their own columns"
```

*Amended (Task 1 execution):*
1. `RecommendationSettings` reached 17 fields and PHPMD `TooManyFields` reports above 15. The interval and the two caps live in a new embeddable `App\Entity\ProfileTuning` (`intervalHours`, `keptCap`, `viewedCap`; columns `profile_interval_hours`, `kept_cap`, `viewed_cap`, `columnPrefix: false`, defaults kept), which `updateProfileSettings()` replaces whole and `profileSettings()` reads. Step 6's `$profileIntervalHours`, `$keptCap` and `$viewedCap` properties do not exist; the migration and the public API of Step 6 are unchanged.
2. Step 11 missed three more `storeProfile(` callers: `tests/Service/Recommendation/Jev/JevPipelineTest.php`, `JevProfileStepTest.php` and `JevRecommendationEngineTest.php` pass `new StoredProfile('…', null, null, null)`.
3. `BackupSchemaCoverageTest::ACCOUNT_SCOPED_WHOLLY_DROPPED` declares `ProfileRun::class`, and `docs/backup.md` section 6.2 gets a `ProfileRun` row (the doc test requires both).
4. Step 9: `RecommendationSettingsControllerTest` asserts 40/80 only in the later persisted-state block (the echo block was already 15/30 -> 40/80 in the same test); `testUserOverrideBeatsTheProviderWindow` had no `viewed` assertion, so one (`30`) is added.
5. Step 15 deletion check 3 applies to `update()` re-adding `$this->profileTuning = ProfileTuning::defaults();`; check 7 breaks the `$profileRun, CallPhase::Distill` argument.


---
### Task 2: The run log records a profile run's calls

**Files:**
- Create: `backend/src/Repository/CallingRun.php`
- Create: `backend/src/Service/Recommendation/Run/Model/ProviderCallRouteModel.php`
- Modify: `backend/src/Repository/RecommendationCallRepository.php`, `backend/src/Repository/RecommendationRunLogRepository.php`
- Modify: `backend/src/Service/Recommendation/Run/Pass/RecordedCall.php`, `backend/src/Service/Recommendation/Run/RecommendationCallRecorder.php`, `backend/src/Service/Recommendation/Run/Factory/RecommendationRunLogFactory.php`
- Modify: `backend/src/Service/Recommendation/Llm/Run/CompletionCallRecorder.php`, `backend/src/Service/Recommendation/Llm/Run/RecommendationProviderCall.php`, `backend/src/Service/Recommendation/Llm/Run/RecommendationConsolidationResolver.php`, `backend/src/Service/Recommendation/Llm/Run/RecommendationProfileDistiller.php`
- Modify: `backend/src/Service/Recommendation/Run/Pass/TickContext.php` (`callRoute()`)
- Test: `backend/tests/Service/Recommendation/Run/RecommendationCallRecorderTest.php`, `backend/tests/Repository/RecommendationRunLogRepositoryTest.php`, `backend/tests/Service/Recommendation/Run/Pass/RecordedCallTest.php`, `backend/tests/Service/Recommendation/Run/Pass/TickContextTest.php`

**Interfaces:**
- Consumes (Task 1): `ProfileRun`, `RecommendationRunLog::forProfileRun()`, `RecommendationRunLog::getProfileRun()`.
- Produces:
  - `App\Repository\CallingRun::recommendationRun(int $id): self`, `CallingRun::profileRun(int $id): self`, public `string $table`, `int $id`.
  - `RecommendationCallRepository::recordStreamedChars(CallingRun $callingRun, int $streamedChars): void`, `addUsage(CallingRun $callingRun, ProviderCallUsageModel $usage): void`.
  - `RecordedCall::__construct(RecommendationCallRepository $calls, ClockInterface $clock, CallingRun $callingRun, int $logId)`.
  - `RecommendationCallRecorder::beginForProfileRun(ProfileRun $profileRun, string $renderedRequest): RecordedCall`.
  - `RecommendationRunLogFactory::createForProfileRun(ProfileRun $profileRun, string $renderedRequest): RecommendationRunLog`.
  - `CompletionCallRecorder::beginForProfileRun(ProfileRun $profileRun, CompletionRequestModel $request): RecordedCall`.
  - `RecommendationRunLogRepository::countProfileRunAttempts(ProfileRun): int`, `listForProfileRun(User, int $profileRunId): list<DebugLogRow>` (its `runId` key carries the profile run's id), `streamingTextForProfileRun(User, int $profileRunId): array<int, string>`, `deleteForUserOutsideProfileRuns(User, list<int> $keptProfileRunIds): void`; `getOneForUser()` also finds the account's profile-run rows.
  - `ProviderCallRouteModel::__construct(AiProviderSettings $connection, RetryPlanModel $retryPlan)`; `TickContext::callRoute(): ProviderCallRouteModel`; `RecommendationProviderCall::complete(ProviderCallRouteModel $route, CompletionRequestModel $request, RecordedCall $recordedCall): string`.

- [ ] **Step 1: Write the failing recorder tests**

Append to `backend/tests/Service/Recommendation/Run/RecommendationCallRecorderTest.php` (imports: `App\Entity\ProfileRun`, `App\Enum\ProfileRunTrigger`, `App\Service\Ai\Model\ProviderCallUsageModel`):

```php
    public function testAProfileRunCallOpensADistillRowUnderTheProfileRun(): void
    {
        $profileRun = $this->runningProfileRun();

        $this->recorder->beginForProfileRun($profileRun, $this->request([['role' => 'user', 'content' => 'history']]));

        $rows = $this->logs->listForProfileRun($this->user, $profileRun->requireId());
        self::assertCount(1, $rows);
        self::assertSame(CallPhase::Distill, $rows[0]['phase']);
        self::assertNull($rows[0]['batchNumber']);
        self::assertSame(1, $rows[0]['attempt']);
        self::assertSame($profileRun->requireId(), $rows[0]['runId']);
        self::assertSame([], $this->logRows(), 'a profile-run row is not the recommendation run\'s');
    }

    public function testASecondProfileRunCallCountsTheAttempt(): void
    {
        $profileRun = $this->runningProfileRun();
        $this->recorder->beginForProfileRun($profileRun, $this->request([]));

        $this->recorder->beginForProfileRun($profileRun, $this->request([]));

        $rows = $this->logs->listForProfileRun($this->user, $profileRun->requireId());
        self::assertSame([1, 2], array_column($rows, 'attempt'));
    }

    public function testAProfileRunCallsLivenessAndUsageLandOnTheProfileRun(): void
    {
        $profileRun = $this->runningProfileRun();
        $call = $this->recorder->beginForProfileRun($profileRun, $this->request([]));

        $this->clock->modify('+3 seconds');
        $call->progressed(new CallProgressModel('{"prof', 2_345, usage: new ProviderCallUsageModel(910, 37, 0, 0, 1_500)));

        self::assertSame(2_345, $this->profileRunColumns($profileRun)['streamed_chars']);

        $call->finishUsable('{"profile":"Likes maps."}');

        $columns = $this->profileRunColumns($profileRun);
        self::assertSame(0, $columns['streamed_chars']);
        self::assertSame(910, $columns['prompt_tokens']);
        self::assertSame(1_500, $columns['cost_nano_credits']);
        self::assertSame(0, $this->runColumns()['prompt_tokens'], 'the recommendation run is not billed');
    }

    private function runningProfileRun(): ProfileRun
    {
        $profileRun = new ProfileRun($this->user, ProfileRunTrigger::Manual, new \DateTimeImmutable('2026-10-03T09:00:00Z'));
        $profileRun->start('fingerprint-1', 'llm.example.test', 'qwen3-14b');
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        return $profileRun;
    }

    /** @return array{streamed_chars: int, prompt_tokens: int, cost_nano_credits: ?int} */
    private function profileRunColumns(ProfileRun $profileRun): array
    {
        /** @var array{streamed_chars: int|string, prompt_tokens: int|string, cost_nano_credits: int|string|null} $row */
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT streamed_chars, prompt_tokens, cost_nano_credits FROM profile_run WHERE id = ?',
            [$profileRun->requireId()],
        );

        return [
            'streamed_chars' => (int) $row['streamed_chars'],
            'prompt_tokens' => (int) $row['prompt_tokens'],
            'cost_nano_credits' => null === $row['cost_nano_credits'] ? null : (int) $row['cost_nano_credits'],
        ];
    }

    /** @return array{prompt_tokens: int} */
    private function runColumns(): array
    {
        /** @var array{prompt_tokens: int|string} $row */
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT prompt_tokens FROM recommendation_run WHERE id = ?',
            [$this->run->requireId()],
        );

        return ['prompt_tokens' => (int) $row['prompt_tokens']];
    }
```

- [ ] **Step 2: Write the failing repository tests**

Read `backend/tests/Repository/RecommendationRunLogRepositoryTest.php`'s setUp and helpers first and reuse them (it builds users, runs and logs through `RecommendationRunFixtures`). Append these tests, building profile-run rows with `RecommendationRunLog::forProfileRun()`:

```php
    public function testTheRunListLeavesProfileRunRowsOut(): void
    {
        [$owner, $run, $profileRun] = $this->ownerWithARunAndAProfileRun('log-lists@example.test');

        self::assertSame([CallPhase::Batch], array_column($this->logs()->listForRun($owner, $run->requireId()), 'phase'));
        self::assertSame(
            [CallPhase::Distill],
            array_column($this->logs()->listForProfileRun($owner, $profileRun->requireId()), 'phase'),
        );
    }

    public function testAProfileRunRowIsReadableByItsOwnerOnly(): void
    {
        [$owner, , $profileRun] = $this->ownerWithARunAndAProfileRun('log-detail@example.test');
        $rowId = $this->logs()->listForProfileRun($owner, $profileRun->requireId())[0]['id'];

        self::assertSame('{"profile-request":1}', $this->logs()->getOneForUser($owner, $rowId)->getRequestBody());

        $this->expectException(RecordNotFoundException::class);
        $this->logs()->getOneForUser($this->fixtureUser('log-detail-stranger@example.test'), $rowId);
    }

    public function testTheProfileRunTrimKeepsTheNamedProfileRunsAndEveryRecommendationRow(): void
    {
        [$owner, $run, $kept] = $this->ownerWithARunAndAProfileRun('log-trim@example.test');
        $dropped = $this->profileRunWithOneRow($owner);

        $this->logs()->deleteForUserOutsideProfileRuns($owner, [$kept->requireId()]);

        self::assertCount(1, $this->logs()->listForProfileRun($owner, $kept->requireId()));
        self::assertSame([], $this->logs()->listForProfileRun($owner, $dropped->requireId()));
        self::assertCount(1, $this->logs()->listForRun($owner, $run->requireId()));
    }

    public function testThePurgeOfRecommendationRowsLeavesProfileRunRows(): void
    {
        [$owner, $run, $profileRun] = $this->ownerWithARunAndAProfileRun('log-purge@example.test');

        $this->logs()->deleteForUser($owner);

        self::assertSame([], $this->logs()->listForRun($owner, $run->requireId()));
        self::assertCount(1, $this->logs()->listForProfileRun($owner, $profileRun->requireId()));
    }

    public function testAProfileRunsAttemptsAreCountedOnItsOwn(): void
    {
        [$owner, , $profileRun] = $this->ownerWithARunAndAProfileRun('log-count@example.test');
        $this->profileRunWithOneRow($owner);

        self::assertSame(1, $this->logs()->countProfileRunAttempts($profileRun));
    }

    /** @return array{User, RecommendationRun, ProfileRun} */
    private function ownerWithARunAndAProfileRun(string $email): array
    {
        $owner = $this->fixtureUser($email);
        $run = $this->fixtures->createRun($owner);
        $this->fixtures->log($run, CallPhase::Batch, 1, 1, '{"batch-request":1}');
        $this->entityManager->flush();

        return [$owner, $run, $this->profileRunWithOneRow($owner)];
    }

    private function profileRunWithOneRow(User $owner): ProfileRun
    {
        $profileRun = new ProfileRun($owner, ProfileRunTrigger::Manual, new \DateTimeImmutable('2026-10-03T09:00:00Z'));
        $this->entityManager->persist($profileRun);
        $this->entityManager->persist(RecommendationRunLog::forProfileRun(
            $profileRun,
            1,
            '{"profile-request":1}',
            new \DateTimeImmutable('2026-10-03T09:00:05Z'),
        ));
        $this->entityManager->flush();

        return $profileRun;
    }
```

`fixtureUser()`, `fixtures` and `logs()` stand for whatever the existing test class already names its user factory, fixtures and repository accessor; rename to match. Imports: `App\Entity\ProfileRun`, `App\Entity\RecommendationRun`, `App\Entity\RecommendationRunLog`, `App\Entity\User`, `App\Enum\CallPhase`, `App\Enum\ProfileRunTrigger`, `App\Repository\Exception\RecordNotFoundException`.

- [ ] **Step 3: Run them and watch them fail**

Run: `php bin/phpunit tests/Service/Recommendation/Run/RecommendationCallRecorderTest.php tests/Repository/RecommendationRunLogRepositoryTest.php`
Expected: errors, `Call to undefined method …RecommendationCallRecorder::beginForProfileRun()` and `…RecommendationRunLogRepository::listForProfileRun()`.

- [ ] **Step 4: `CallingRun` and the repository writes**

`backend/src/Repository/CallingRun.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

/** The run a recorded call's liveness and usage land on: a recommendation run or a profile run. */
final readonly class CallingRun
{
    private function __construct(public string $table, public int $id)
    {
    }

    public static function recommendationRun(int $id): self
    {
        return new self('recommendation_run', $id);
    }

    public static function profileRun(int $id): self
    {
        return new self('profile_run', $id);
    }
}
```

In `backend/src/Repository/RecommendationCallRepository.php` replace `recordStreamedChars()`, `addUsage()` and `addCost()` with:

```php
    public function recordStreamedChars(CallingRun $callingRun, int $streamedChars): void
    {
        $this->connection->update(
            $callingRun->table,
            ['streamed_chars' => $streamedChars],
            ['id' => $callingRun->id],
        );
    }

    /** SQL arithmetic, not read-modify-write: one batch wave settles several calls against the same run. */
    public function addUsage(CallingRun $callingRun, ProviderCallUsageModel $usage): void
    {
        $this->connection->executeStatement(
            'UPDATE ' . $callingRun->table . ' SET'
            . ' prompt_tokens = prompt_tokens + :promptTokens,'
            . ' completion_tokens = completion_tokens + :completionTokens,'
            . ' reasoning_tokens = reasoning_tokens + :reasoningTokens,'
            . ' cached_tokens = cached_tokens + :cachedTokens'
            . ' WHERE id = :runId',
            [
                'promptTokens' => $usage->promptTokens,
                'completionTokens' => $usage->completionTokens,
                'reasoningTokens' => $usage->reasoningTokens,
                'cachedTokens' => $usage->cachedTokens,
                'runId' => $callingRun->id,
            ],
        );

        $this->addCost($callingRun, $usage->costNanoCredits);
    }
```

```php
    /** An unpriced call leaves the column NULL: null means no price was reported, 0 would claim the run was free. */
    private function addCost(CallingRun $callingRun, ?int $costNanoCredits): void
    {
        if (null === $costNanoCredits) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE ' . $callingRun->table
            . ' SET cost_nano_credits = COALESCE(cost_nano_credits, 0) + :costNanoCredits'
            . ' WHERE id = :runId',
            ['costNanoCredits' => $costNanoCredits, 'runId' => $callingRun->id],
        );
    }
```

Change the class docblock's "A recorded provider call's writes" to "A recorded provider call's writes, onto its run-log row and the run that `CallingRun` names".

- [ ] **Step 5: `RecordedCall`, the recorder and the log factory**

In `backend/src/Service/Recommendation/Run/Pass/RecordedCall.php`: the constructor's `private readonly int $runId,` becomes `private readonly CallingRun $callingRun,` (import `App\Repository\CallingRun`), and the three uses `$this->runId` become `$this->callingRun`.

`backend/src/Service/Recommendation/Run/RecommendationCallRecorder.php` becomes:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\ProfileRun;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Repository\CallingRun;
use App\Repository\RecommendationCallRepository;
use App\Service\Recommendation\Run\Factory\RecommendationRunLogFactory;
use App\Service\Recommendation\Run\Model\CallSlotModel;
use App\Service\Recommendation\Run\Pass\RecordedCall;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Opens the run-log row for one provider call the moment it is sent and hands back the RecordedCall that settles it.
 * Every run records, debug on or off: the log is the history the ETA reads.
 */
final readonly class RecommendationCallRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RecommendationCallRepository $calls,
        private ClockInterface $clock,
        private RecommendationRunLogFactory $logFactory,
    ) {
    }

    public function begin(RecommendationRun $run, CallSlotModel $slot, string $renderedRequest): RecordedCall
    {
        return $this->opened(
            $this->logFactory->create($run, $slot, $renderedRequest),
            CallingRun::recommendationRun($run->requireId()),
        );
    }

    public function beginForProfileRun(ProfileRun $profileRun, string $renderedRequest): RecordedCall
    {
        return $this->opened(
            $this->logFactory->createForProfileRun($profileRun, $renderedRequest),
            CallingRun::profileRun($profileRun->requireId()),
        );
    }

    private function opened(RecommendationRunLog $log, CallingRun $callingRun): RecordedCall
    {
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return new RecordedCall($this->calls, $this->clock, $callingRun, $log->requireId());
    }
}
```

In `backend/src/Service/Recommendation/Run/Factory/RecommendationRunLogFactory.php` add (import `App\Entity\ProfileRun`):

```php
    public function createForProfileRun(ProfileRun $profileRun, string $renderedRequest): RecommendationRunLog
    {
        return RecommendationRunLog::forProfileRun(
            $profileRun,
            $this->logs->countProfileRunAttempts($profileRun) + 1,
            $renderedRequest,
            $this->clock->now(),
        );
    }
```

In `backend/src/Service/Recommendation/Llm/Run/CompletionCallRecorder.php` add (import `App\Entity\ProfileRun`):

```php
    public function beginForProfileRun(ProfileRun $profileRun, CompletionRequestModel $request): RecordedCall
    {
        return $this->callRecorder->beginForProfileRun($profileRun, RenderedCompletionRequest::of($request));
    }
```

In `backend/tests/Service/Recommendation/Run/Pass/RecordedCallTest.php`, `call()` builds `new RecordedCall($calls, $this->clock, CallingRun::recommendationRun($runId), $logId)` (import `App\Repository\CallingRun`).

- [ ] **Step 6: The repository's profile-run reads, trim and count**

In `backend/src/Repository/RecommendationRunLogRepository.php` (import `App\Entity\ProfileRun`):

1. Replace `listForRun()`'s body with `return $this->listRowsOf($user, self::OWNER_RUN, $runId);` and add beside it:

```php
    /** @return list<DebugLogRow> the rows of one profile run; `runId` carries the profile run's id */
    public function listForProfileRun(User $user, int $profileRunId): array
    {
        return $this->listRowsOf($user, self::OWNER_PROFILE_RUN, $profileRunId);
    }
```

2. Move the old `listForRun()` body into a private method that names its owner association (constants at the top of the class: `private const string OWNER_RUN = 'run';` and `private const string OWNER_PROFILE_RUN = 'profileRun';`):

```php
    /**
     * @param self::OWNER_* $owner
     *
     * @return list<DebugLogRow>
     */
    private function listRowsOf(User $user, string $owner, int $ownerId): array
    {
        /** @var list<array{id: int, runId: int, phase: CallPhase, batchNumber: ?int, attempt: int,
         *     verdict: ?CallVerdict, requestBytes: int|string, responseBytes: int|string,
         *     wireBytes: int, createdAt: \DateTimeImmutable, finishedAt: ?\DateTimeImmutable,
         *     errorDetail: ?string, finishReason: ?string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select(
                'l.id AS id',
                \sprintf('IDENTITY(l.%s) AS runId', $owner),
                'l.phase AS phase',
                'l.batchNumber AS batchNumber',
                'l.attempt AS attempt',
                'l.verdict AS verdict',
                'LENGTH(l.requestBody) AS requestBytes',
                'LENGTH(l.responseText) AS responseBytes',
                'l.wireBytes AS wireBytes',
                'l.createdAt AS createdAt',
                'l.finishedAt AS finishedAt',
                'l.errorDetail AS errorDetail',
                'l.finishReason AS finishReason',
            )
            ->join('l.' . $owner, 'r')
            ->where('r.user = :user')
            ->andWhere('r.id = :owner')
            ->setParameter('user', $user)
            ->setParameter('owner', $ownerId)
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        // LENGTH() comes back as a string on some drivers; the contract is int.
        return array_map(
            static fn (array $row): array => [
                'id' => $row['id'],
                'runId' => (int) $row['runId'],
                'phase' => $row['phase'],
                'batchNumber' => $row['batchNumber'],
                'attempt' => $row['attempt'],
                'verdict' => $row['verdict'],
                'requestBytes' => (int) $row['requestBytes'],
                'responseBytes' => (int) $row['responseBytes'],
                'wireBytes' => $row['wireBytes'],
                'createdAt' => $row['createdAt'],
                'finishedAt' => $row['finishedAt'],
                'errorDetail' => $row['errorDetail'],
                'finishReason' => $row['finishReason'],
            ],
            $rows,
        );
    }
```

3. Do the same split for `streamingTextForRun()`: its body moves to `private function streamingTextOf(User $user, string $owner, int $ownerId): array` with `->join('l.' . $owner, 'r')` and `->andWhere('r.id = :owner')`, and two public methods delegate:

```php
    /** @return array<int, string> log id => response text so far */
    public function streamingTextForRun(User $user, int $runId): array
    {
        return $this->streamingTextOf($user, self::OWNER_RUN, $runId);
    }

    /** @return array<int, string> log id => response text so far */
    public function streamingTextForProfileRun(User $user, int $profileRunId): array
    {
        return $this->streamingTextOf($user, self::OWNER_PROFILE_RUN, $profileRunId);
    }
```

4. Replace `getOneForUser()`'s query with one that finds a row through either owner:

```php
    public function getOneForUser(User $user, int $logId): RecommendationRunLog
    {
        /** @var RecommendationRunLog|null $log */
        $log = $this->createQueryBuilder('l')
            ->leftJoin('l.run', 'r')
            ->leftJoin('l.profileRun', 'p')
            ->where('l.id = :id')
            ->andWhere('r.user = :user OR p.user = :user')
            ->setParameter('id', $logId)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();

        return $log ?? throw new RecordNotFoundException('No such debug log entry.');
    }
```

5. Add the count and the trim:

```php
    public function countProfileRunAttempts(ProfileRun $profileRun): int
    {
        /** @var int|string $count */
        $count = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.profileRun = :profileRun')
            ->setParameter('profileRun', $profileRun)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * Drops the account's profile-run rows except those of the named profile runs; recommendation-run rows stay.
     *
     * @param list<int> $keptProfileRunIds
     */
    public function deleteForUserOutsideProfileRuns(User $user, array $keptProfileRunIds): void
    {
        $query = $this->createQueryBuilder('l')
            ->join('l.profileRun', 'p')
            ->where('p.user = :user')
            ->setParameter('user', $user);

        if ([] !== $keptProfileRunIds) {
            $query->andWhere('p.id NOT IN (:kept)')->setParameter('kept', $keptProfileRunIds);
        }

        $this->rowIds->delete(RecommendationRunLog::class, $this->rowIds->selectedBy($query));
    }
```

`deleteForUser()` and `deleteForUserOutsideRuns()` keep their inner `join('l.run', 'r')`: that join is what keeps the purge and the run-log trim off profile-run rows (decision 13). Add one line to `deleteForUser()`'s callers' contract by naming it in its docblock: `/** The account's recommendation-run rows; profile-run rows are not this purge's. */`.

- [ ] **Step 7: The provider call takes a route, not a tick**

`backend/src/Service/Recommendation/Run/Model/ProviderCallRouteModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

use App\Entity\AiProviderSettings;
use App\Service\Ai\Model\RetryPlanModel;

/** Where one provider call goes and how long it may wait out a rate limit. */
final readonly class ProviderCallRouteModel
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        public AiProviderSettings $connection,
        public RetryPlanModel $retryPlan,
    ) {
    }
}
```

In `TickContext` add (import the model):

```php
    public function callRoute(): ProviderCallRouteModel
    {
        return new ProviderCallRouteModel($this->connection, $this->retryPlan());
    }
```

`RecommendationProviderCall::complete()` becomes (import `ProviderCallRouteModel`, drop the `TickContext` import):

```php
    public function complete(
        ProviderCallRouteModel $route,
        CompletionRequestModel $request,
        RecordedCall $recordedCall,
    ): string {
        try {
            return $this->completion->complete(
                $this->connectionFactory->forSettings($route->connection),
                $request,
                new RecordedCallObserver($recordedCall),
                $route->retryPlan,
            );
        } catch (\Throwable $exception) {
            $recordedCall->abortAfterTransportFailure($exception->getMessage());

            throw $exception;
        }
    }
```

Its two callers pass `$tick->callRoute()`: `RecommendationConsolidationResolver` (line ~76) and `RecommendationProfileDistiller` (line ~53).

Add to `backend/tests/Service/Recommendation/Run/Pass/TickContextTest.php` (reusing its existing tick builder):

```php
    public function testTheCallRouteIsTheTicksConnectionUnderItsDriversRetryPlan(): void
    {
        $tick = $this->tickDrivenBy(TickDriver::Worker);

        $route = $tick->callRoute();

        self::assertSame($tick->connection, $route->connection);
        self::assertEquals(RetryPlanModel::blocking(), $route->retryPlan);
    }
```

`tickDrivenBy()` stands for the test class's own way to build a `TickContext`; if it has none with a driver parameter, add one beside its existing builder that passes the driver through. Import `App\Service\Ai\Model\RetryPlanModel`.

- [ ] **Step 8: Run the tests and watch them pass**

Run: `php bin/phpunit tests/Service/Recommendation/Run/RecommendationCallRecorderTest.php tests/Repository/RecommendationRunLogRepositoryTest.php tests/Service/Recommendation/Run/Pass tests/Service/Recommendation/Llm tests/Controller/Api/RecommendationDebugLogControllerTest.php`
Expected: OK.

- [ ] **Step 9: Deletion checks**

1. In `RecommendationCallRecorder::beginForProfileRun()`, pass `CallingRun::recommendationRun(…)` with the profile run's id. `testAProfileRunCallsLivenessAndUsageLandOnTheProfileRun` must fail. Restore.
2. In `getOneForUser()`, drop `OR p.user = :user`. `testAProfileRunRowIsReadableByItsOwnerOnly` must fail. Restore.
3. In `deleteForUserOutsideProfileRuns()`, swap the join for `l.run`. `testTheProfileRunTrimKeepsTheNamedProfileRunsAndEveryRecommendationRow` must fail. Restore.
4. In `deleteForUser()`'s id query, swap `join('l.run', 'r')` for a `leftJoin` on both owners with `r.user = :user OR p.user = :user`. `testThePurgeOfRecommendationRowsLeavesProfileRunRows` must fail. Restore.
5. In `RecommendationRunLogFactory::createForProfileRun()`, drop `+ 1`. `testAProfileRunCallOpensADistillRowUnderTheProfileRun` must fail on the attempt. Restore.

- [ ] **Step 10: Gates, commit**

```bash
php bin/phpunit tests/Service/Recommendation tests/Repository tests/Controller/Api/RecommendationDebugLogControllerTest.php
composer cs && composer stan && composer md && composer tramp
git add backend/src backend/tests
git commit -m "feat(#1351): the run log records a profile run's calls on that profile run"
```

---
### Task 3: A profile run generates the profile under the shared lock

**Files:**
- Create: `backend/src/Service/Recommendation/Run/UserTickLock.php`, `backend/src/Service/Recommendation/Run/Pass/HeldTickLock.php`
- Create: `backend/src/Service/Recommendation/Profile/ProfileConnections.php`, `ProfileRunOpening.php`, `ProfileDistiller.php`, `ProfileGeneration.php`, `ProfileRunFailure.php`, `ProfileRunTick.php`, `ProfileRunAdvancer.php`, `ProfileRunStarter.php`
- Create: `backend/src/Service/Recommendation/Profile/Pass/ProfileTick.php`, `backend/src/Service/Recommendation/Profile/Support/ProfileInputFingerprint.php`, `backend/src/Service/Recommendation/Profile/Exception/ProfileConnectionMissingException.php`
- Modify: `backend/src/Service/Recommendation/Pool/Model/RecommendationHistoryModel.php` (`isEmpty()`)
- Modify: `backend/src/Service/Recommendation/Run/RecommendationRunAdvancer.php` (the lock moves out: −2 collaborators, −1 static, −the shutdown hook), `TickLockTtl.php` (`secondsForConnection()`), `RecommendationPollDriver.php`
- Modify: `backend/tests/Support/RecommendationRunFixtures.php` (profile helpers)
- Test: create `backend/tests/Service/Recommendation/Profile/ProfileConnectionsTest.php`, `ProfileRunTickTest.php`, `ProfileRunAdvancerTest.php`, `ProfileRunStarterTest.php`, `Support/ProfileInputFingerprintTest.php`; modify `backend/tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php`, `backend/tests/Service/Recommendation/Pool/RecommendationHistoryLoaderTest.php`

**Interfaces:**
- Consumes (Tasks 1–2): `ProfileRun` and its transitions, `ProfileRunRepository`, `StoredProfile`, `ProfileSettingsValues`, `RecommendationSettings::profileSettings()`, `RecommendationSettingsWriter::storeProfile()`, `CompletionCallRecorder::beginForProfileRun()`, `RecommendationProviderCall::complete(ProviderCallRouteModel, …)`, `ProviderCallRouteModel`, `RecommendationRunLogRepository::deleteForUserOutsideProfileRuns()`.
- Produces:
  - `UserTickLock::nameFor(User): string`; `UserTickLock::acquire(User $user, float $ttlSeconds): ?HeldTickLock` (null when another tick holds it); `HeldTickLock::release(): void`.
  - `TickLockTtl::secondsForConnection(?AiProviderSettings): float`; `secondsFor(User)` keeps its meaning (Task 5 drops its borrowed half).
  - `ProfileConnections::MISSING` (string); `usableFor(User): ?AiProviderSettings`; `canBuildProfiles(AiProviderSettings): bool`; `candidatesFor(User): list<AiProviderSettings>`.
  - `ProfileTick` pass: public `profileRun`, `connection`, `settings` (`EffectiveRecommendationSettingsModel`), `history` (`RecommendationHistoryModel`), `driver`; `callRoute(): ProviderCallRouteModel`.
  - `ProfileInputFingerprint::of(RecommendationHistoryModel, RecommendationHistoryCaps, AiProviderSettings): string`.
  - `ProfileRunTick::advance(ProfileRun, TickDriver): void` (never throws a provider failure); `ProfileRunTick::KEY_UNREADABLE`.
  - `ProfileGeneration::NO_USABLE_REPLY` (`'The model gave no usable profile in %d attempts.'`); `ProfileRunFailure::PROVIDER_FAILED` (`'The AI provider at %s failed: %s'`).
  - `ProfileRunAdvancer::advance(User, TickDriver): bool` — true when a profile run was ticked.
  - `ProfileRunStarter::start(User, ProfileRunTrigger): ProfileRun` (returns the active one if any); `startManually(User): ProfileRun` throws `ProfileConnectionMissingException`.
  - `RecommendationHistoryModel::isEmpty(): bool`.
  - `RecommendationRunAdvancer` knows nothing of profile runs: it only takes the shared lock through `UserTickLock`.
  - Fixtures: `RecommendationRunFixtures::seedFavorites(User, string $feedSlug, int $count): list<Entry>`, `chooseProfileConnection(User, AiProviderSettings): void`, `storeProfile(User, string $text): void`.

- [ ] **Step 1: Fixture helpers**

Add to `backend/tests/Support/RecommendationRunFixtures.php` (imports `App\Entity\EntryState`, `App\Entity\ProfileSettingsValues`, `App\Entity\StoredProfile`):

```php
    /**
     * $count favourites in a feed of their own, newest first, so a test can grow the history a second time.
     *
     * @return list<Entry>
     */
    public function seedFavorites(User $user, string $feedSlug, int $count): array
    {
        $feed = new Feed('https://example.com/' . $user->getEmail() . '/' . $feedSlug . '.xml');
        $feed->setTitle('Favourites ' . $feedSlug);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $entries = [];
        for ($index = 0; $index < $count; $index++) {
            $entry = $this->entry($feed, $feedSlug . '-' . $user->getEmail() . '-' . $index, $count - $index);
            $state = new EntryState($user, $entry);
            $state->markFavorite();
            $this->entityManager->persist($state);
            $entries[] = $entry;
        }
        $this->entityManager->flush();

        return $entries;
    }

    public function chooseProfileConnection(User $user, AiProviderSettings $connection): void
    {
        $row = $this->settingsRowOf($user);
        $current = $row->profileSettings();
        $row->updateProfileSettings(
            new ProfileSettingsValues($current->intervalHours, $connection, $current->keptCap, $current->viewedCap),
        );
        $this->entityManager->flush();
    }

    public function storeProfile(User $user, string $text): void
    {
        $this->settingsRowOf($user)->storeProfile(
            new StoredProfile($text, new \DateTimeImmutable('2026-10-03 06:00:00'), 'api.example.test', 'm'),
        );
        $this->entityManager->flush();
    }

    private function settingsRowOf(User $user): RecommendationSettings
    {
        $row = $this->entityManager->getRepository(RecommendationSettings::class)->findOneBy(['user' => $user]);
        if ($row instanceof RecommendationSettings) {
            return $row;
        }

        $row = new RecommendationSettings($user);
        $this->entityManager->persist($row);

        return $row;
    }
```

- [ ] **Step 2: Write the failing unit tests (history, fingerprint)**

Append to `backend/tests/Service/Recommendation/Pool/RecommendationHistoryLoaderTest.php`:

```php
    public function testAHistoryWithoutAnyEntryIsEmpty(): void
    {
        self::assertTrue($this->loader()->load($this->userId(), $this->settings())->isEmpty());
    }

    public function testOneViewedEntryMakesTheHistoryNonEmpty(): void
    {
        $state = new EntryState($this->user, $this->entry('A', '2026-07-10T00:00:00Z'));
        $state->markViewed(new \DateTimeImmutable('2026-07-10T09:00:00Z'));
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        self::assertFalse($this->loader()->load($this->userId(), $this->settings())->isEmpty());
    }
```

Create `backend/tests/Service/Recommendation/Profile/Support/ProfileInputFingerprintTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile\Support;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationHistoryCaps;
use App\Entity\SealedSecret;
use App\Entity\User;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Pool\Model\RecommendationHistoryModel;
use App\Service\Recommendation\Profile\Support\ProfileInputFingerprint;
use App\Tests\Support\AssignsEntityIds;
use PHPUnit\Framework\TestCase;

final class ProfileInputFingerprintTest extends TestCase
{
    use AssignsEntityIds;

    public function testTheSameInputsGiveTheSameFingerprint(): void
    {
        self::assertSame(
            ProfileInputFingerprint::of($this->history([11], [12], [13]), $this->caps(), $this->connection(5, 'm')),
            ProfileInputFingerprint::of($this->history([11], [12], [13]), $this->caps(), $this->connection(5, 'm')),
        );
    }

    public function testAnEntryMovingFromViewedToKeptChangesIt(): void
    {
        self::assertNotSame(
            ProfileInputFingerprint::of($this->history([11], [12], [13]), $this->caps(), $this->connection(5, 'm')),
            ProfileInputFingerprint::of($this->history([11], [12, 13], []), $this->caps(), $this->connection(5, 'm')),
        );
    }

    public function testACapChangesIt(): void
    {
        self::assertNotSame(
            ProfileInputFingerprint::of($this->history([11], [12], [13]), $this->caps(), $this->connection(5, 'm')),
            ProfileInputFingerprint::of(
                $this->history([11], [12], [13]),
                new RecommendationHistoryCaps(40, 41, 80),
                $this->connection(5, 'm'),
            ),
        );
    }

    public function testAnotherModelOnTheSameConnectionChangesIt(): void
    {
        self::assertNotSame(
            ProfileInputFingerprint::of($this->history([11], [12], [13]), $this->caps(), $this->connection(5, 'm')),
            ProfileInputFingerprint::of($this->history([11], [12], [13]), $this->caps(), $this->connection(5, 'm2')),
        );
    }

    public function testAnotherConnectionWithTheSameModelChangesIt(): void
    {
        self::assertNotSame(
            ProfileInputFingerprint::of($this->history([11], [12], [13]), $this->caps(), $this->connection(5, 'm')),
            ProfileInputFingerprint::of($this->history([11], [12], [13]), $this->caps(), $this->connection(6, 'm')),
        );
    }

    public function testItIsASha256(): void
    {
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            ProfileInputFingerprint::of($this->history([], [], []), $this->caps(), $this->connection(5, 'm')),
        );
    }

    /**
     * @param list<int> $favorites
     * @param list<int> $kept
     * @param list<int> $viewed
     */
    private function history(array $favorites, array $kept, array $viewed): RecommendationHistoryModel
    {
        $lines = static fn (array $ids): array => array_map(
            static fn (int $id): ArticleLineModel => new ArticleLineModel($id, 'Title ' . $id, 'Feed', '2026-10-01', null),
            $ids,
        );

        return new RecommendationHistoryModel($lines($favorites), $lines($kept), $lines($viewed));
    }

    private function caps(): RecommendationHistoryCaps
    {
        return new RecommendationHistoryCaps(40, 40, 80);
    }

    private function connection(int $id, string $model): AiProviderSettings
    {
        $user = new User('fingerprint@example.test', new \DateTimeImmutable('2026-10-01 06:00:00'));
        $connection = new AiProviderSettings(
            $user,
            null,
            'https://llm.example.test/v1',
            new SealedSecret('ciphertext', 'nonce', 'salt', 1),
            'ab12',
            new \DateTimeImmutable('2026-10-01 06:00:00'),
        );
        $connection->chooseModel($model, new \DateTimeImmutable('2026-10-01 06:00:00'), 32768);

        return self::withId($connection, $id);
    }
}
```

Run: `php bin/phpunit tests/Service/Recommendation/Pool/RecommendationHistoryLoaderTest.php tests/Service/Recommendation/Profile/Support`
Expected: errors, `Call to undefined method …RecommendationHistoryModel::isEmpty()` and `Class "…ProfileInputFingerprint" not found`.

- [ ] **Step 3: `isEmpty()` and the fingerprint**

In `RecommendationHistoryModel` add:

```php
    public function isEmpty(): bool
    {
        return [] === $this->favorites && [] === $this->kept && [] === $this->viewed;
    }
```

`backend/src/Service/Recommendation/Profile/Support/ProfileInputFingerprint.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Support;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationHistoryCaps;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Pool\Model\RecommendationHistoryModel;

/** What a profile depends on: the history's entry ids per section, the caps, and the connection and its model. */
final class ProfileInputFingerprint
{
    public static function of(
        RecommendationHistoryModel $history,
        RecommendationHistoryCaps $caps,
        AiProviderSettings $connection,
    ): string {
        return hash('sha256', json_encode([
            'favorites' => self::entryIds($history->favorites),
            'kept' => self::entryIds($history->kept),
            'viewed' => self::entryIds($history->viewed),
            'caps' => [$caps->favorites, $caps->kept, $caps->viewed],
            'connection' => $connection->getId(),
            'model' => $connection->getModel(),
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<ArticleLineModel> $lines
     *
     * @return list<int>
     */
    private static function entryIds(array $lines): array
    {
        return array_map(static fn (ArticleLineModel $line): int => $line->entryId, $lines);
    }

    private function __construct()
    {
    }
}
```

Run the Step 2 tests again. Expected: OK.

- [ ] **Step 4: Extract `UserTickLock` from the advancer**

The lock's mechanics (name, acquire, shutdown hook, keepalive) leave the recommendation advancer for a lock both kinds of tick take. Each caller passes its own TTL, so the recommendation side never reads the profile connection.

`backend/src/Service/Recommendation/Run/Pass/HeldTickLock.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Pass;

use App\Service\Recommendation\Run\ProviderCallHeartbeat\TickLockKeepalive;
use Symfony\Component\Lock\LockInterface;

/** One tick's hold on the per-user lock. */
final readonly class HeldTickLock
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(private LockInterface $lock, private TickLockKeepalive $keepalive)
    {
    }

    /** Disarms the keepalive before the release, never after: a beat in between would refresh a lock on its way out. */
    public function release(): void
    {
        $this->keepalive->release();
        $this->lock->release();
    }
}
```

`backend/src/Service/Recommendation/Run/UserTickLock.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\User;
use App\Service\Recommendation\Run\Pass\HeldTickLock;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\TickLockKeepalive;
use Symfony\Component\Lock\LockFactory;

/** The per-user lock every tick takes, a recommendation run's or a profile run's, so the two take turns. */
final readonly class UserTickLock
{
    private const string LOCK_NAME_PREFIX = 'ai-recommendations-';

    public function __construct(
        private LockFactory $lockFactory,
        private TickLockKeepalive $keepalive,
    ) {
    }

    /** The one place the lock's name is formed; RecommendationPollDriver logs this very name. */
    public static function nameFor(User $user): string
    {
        return self::LOCK_NAME_PREFIX . $user->requireId();
    }

    /** Null when another tick holds the lock: the healthy, frequent case, so it is not logged here. */
    public function acquire(User $user, float $ttlSeconds): ?HeldTickLock
    {
        $lockName = self::nameFor($user);
        $lock = $this->lockFactory->createLock($lockName, $ttlSeconds);

        if (!$lock->acquire()) {
            return null;
        }

        // A hard request kill (Strato's 240 s cap) never reaches the caller's finally and would strand the lock for
        // its whole TTL. The delete is token-scoped, so on the normal path this hook is a harmless no-op.
        register_shutdown_function(static function () use ($lock): void {
            try {
                $lock->release();
            } catch (\Throwable) {
                // A failed release during shutdown must not raise a second fatal; the TTL still bounds the stall.
            }
        });

        $this->keepalive->hold($lock, $lockName);

        return new HeldTickLock($lock, $this->keepalive);
    }
}
```

`TickLockTtl` gains a per-connection entry point, which `secondsFor()` now uses:

```php
    public function secondsFor(User $user): float
    {
        $active = $this->configurator->settingsFor($user);
        $borrowed = null === $active ? null : $this->profileConnections->borrowedFor($active);
        if (null === $borrowed) {
            return $this->secondsForConnection($active);
        }

        return max($this->secondsForConnection($active), $this->secondsForConnection($borrowed));
    }

    /** No connection gets the standard bound: a tick that only fails its run still holds the lock briefly. */
    public function secondsForConnection(?AiProviderSettings $connection): float
    {
        $timeouts = null === $connection
            ? ProviderTimeoutsModel::standard()
            : $this->connectionFactory->timeoutsFor($connection);

        return $timeouts->firstByteSeconds + self::MARGIN_SECONDS;
    }
```

Delete the now unused private `firstByteSeconds()`. The borrowed half goes in Task 5.

`RecommendationRunAdvancer` takes the lock through it. Its constructor becomes `$runs, $clock, $entityManager, $tickContexts, $phases, $tickLock (UserTickLock), $lockTtl (TickLockTtl)`; the `LockFactory` and `TickLockKeepalive` parameters, `LOCK_NAME_PREFIX` and `lockNameFor()` go, and `advance()` becomes:

```php
    public function advance(User $user, TickDriver $driver = TickDriver::Poll): RecommendationRunReportModel
    {
        $held = $this->tickLock->acquire($user, $this->lockTtl->secondsFor($user));
        if (null === $held) {
            return RecommendationRunReportModel::busy();
        }

        try {
            return $this->tick($user, $driver);
        } finally {
            $held->release();
        }
    }
```

`tick()` and the rest stay. The class docblock says `behind UserTickLock` instead of `behind a per-user lock`. `RecommendationPollDriver::logLockWithNoHeartbeatBehindIt()` logs `UserTickLock::nameFor($user)`.

`tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php::advancerWithFlushFailingEntityManager()` builds the advancer by hand: pass `$this->runs()`, the clock, `new FlushFailingEntityManager($this->entityManager)`, `TickContextFactory`, `TickPhases`, `UserTickLock`, `TickLockTtl` (all but the third from the container), and drop the imports it no longer needs.

Run: `php bin/phpunit tests/Service/Recommendation/Run tests/Service/Worker tests/Controller/Api/RecommendationRunControllerTest.php`
Expected: OK — a pure extraction; the lock tests in `RecommendationRunAdvancerTest` (TTL, stolen lock, busy) pin it.

- [ ] **Step 5: Write the failing `ProfileConnections` test**

`backend/tests/Service/Recommendation/Profile/ProfileConnectionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Profile\ProfileConnections;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class ProfileConnectionsTest extends DbTestCase
{
    use SeedsUsers;

    private RecommendationRunFixtures $fixtures;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('profile-connections@example.test');
    }

    public function testWithNothingChosenTheActiveLlmConnectionBuildsTheProfile(): void
    {
        $active = $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');

        self::assertSame($active, $this->connections()->usableFor($this->owner));
    }

    public function testAChosenConnectionWinsOverTheActiveOne(): void
    {
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');
        $chosen = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o');
        $this->fixtures->chooseProfileConnection($this->owner, $chosen);

        self::assertSame($chosen, $this->connections()->usableFor($this->owner));
    }

    public function testAChosenConnectionThatCannotBuildAProfileGivesNoneRatherThanTheActiveOne(): void
    {
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');
        $this->fixtures->chooseProfileConnection(
            $this->owner,
            $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'jev-latest'),
        );

        self::assertNull($this->connections()->usableFor($this->owner));
    }

    public function testAnActiveJevConnectionWithNothingChosenGivesNone(): void
    {
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'jev-latest');

        self::assertNull($this->connections()->usableFor($this->owner));
    }

    public function testTheCandidatesAreTheConnectionsThatCanBuildAProfile(): void
    {
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'jev-latest');
        $llm = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o');

        self::assertSame(
            [$llm->getId()],
            array_map(
                static fn (AiProviderSettings $connection): ?int => $connection->getId(),
                $this->connections()->candidatesFor($this->owner),
            ),
        );
    }

    private function connections(): ProfileConnections
    {
        /** @var ProfileConnections $connections */
        $connections = self::getContainer()->get(ProfileConnections::class);

        return $connections;
    }
}
```

Run: `php bin/phpunit tests/Service/Recommendation/Profile/ProfileConnectionsTest.php`
Expected: error, `Class "App\Service\Recommendation\Profile\ProfileConnections" not found`.

- [ ] **Step 6: `ProfileConnections`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Support\AiReadiness;
use App\Service\Recommendation\Engine\Model\RecommendationProfileSource;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;

/** Which saved connection builds the account's profile. */
final readonly class ProfileConnections
{
    public const string MISSING = 'No connection can build your profile. Choose one under Settings → Profile.';

    public function __construct(
        private RecommendationSettingsRepository $recommendationSettings,
        private AiProviderConfigurator $configurator,
        private RecommendationEngineResolver $engines,
    ) {
    }

    /** The chosen connection when it can build a profile, else none; with nothing chosen, the active one when it can. */
    public function usableFor(User $user): ?AiProviderSettings
    {
        $chosen = $this->recommendationSettings->findForUser($user)?->profileSettings()->connection;
        $candidate = $chosen ?? $this->configurator->settingsFor($user);

        return null !== $candidate && $this->canBuildProfiles($candidate) ? $candidate : null;
    }

    public function canBuildProfiles(AiProviderSettings $connection): bool
    {
        return AiReadiness::of($connection)
            && RecommendationProfileSource::Own === $this->engines->capabilitiesFor($connection)->profileSource;
    }

    /** @return list<AiProviderSettings> */
    public function candidatesFor(User $user): array
    {
        return array_values(array_filter(
            $this->configurator->listConfigurations($user),
            $this->canBuildProfiles(...),
        ));
    }
}
```

Run the Step 5 test. Expected: OK.

- [ ] **Step 7: Write the failing `ProfileRunTick` test**

`backend/tests/Service/Recommendation/Profile/ProfileRunTickTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Enum\ProfileRunOutcome;
use App\Enum\ProfileRunTrigger;
use App\Enum\RunStatus;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Recommendation\Llm\Prompt\Support\RecommendationPromptText;
use App\Service\Recommendation\Profile\ProfileConnections;
use App\Service\Recommendation\Profile\ProfileRunTick;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubChatClient;

final class ProfileRunTickTest extends DbTestCase
{
    use SeedsUsers;

    private RecommendationRunFixtures $fixtures;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('profile-run-tick@example.test');
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');
    }

    public function testAManualRunCallsTheModelOnceAndStoresTheProfile(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 2);
        $this->chat()->queueContent('{"profile":"Likes maps and cartography."}');
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);

        $this->tick()->advance($profileRun, TickDriver::Poll);

        self::assertSame(RunStatus::Completed, $profileRun->getStatus());
        self::assertSame(ProfileRunOutcome::Generated, $profileRun->getOutcome());
        self::assertCount(1, $this->chat()->calls());
        $stored = $this->storedProfile();
        self::assertSame('Likes maps and cartography.', $stored->getText());
        self::assertSame('qwen3-14b', $stored->getModel());
        self::assertSame('api.example.test', $stored->getProviderHost());
        self::assertNotNull($stored->getGeneratedAt());
    }

    public function testTheChosenProfileConnectionIsTheOneCalled(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
        $this->fixtures->chooseProfileConnection(
            $this->owner,
            $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'profile-llm'),
        );
        $this->chat()->queueContent('{"profile":"Likes maps."}');

        $this->tick()->advance($this->profileRun(ProfileRunTrigger::Manual), TickDriver::Poll);

        self::assertSame('profile-llm', $this->chat()->calls()[0]['model']);
        self::assertSame('profile-llm', $this->storedProfile()->getModel());
    }

    public function testWithoutHistoryTheRunCompletesWithoutACallAndStoresNothing(): void
    {
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);

        $this->tick()->advance($profileRun, TickDriver::Poll);

        self::assertSame(ProfileRunOutcome::NoHistory, $profileRun->getOutcome());
        self::assertSame([], $this->chat()->calls());
        self::assertNull($this->storedProfile()->getText());
    }

    public function testAScheduledRunWithUnchangedInputsSkipsTheCall(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 2);
        $this->chat()->queueContent('{"profile":"First."}');
        $this->tick()->advance($this->profileRun(ProfileRunTrigger::Manual), TickDriver::Poll);
        $scheduled = $this->profileRun(ProfileRunTrigger::Scheduled);

        $this->tick()->advance($scheduled, TickDriver::Poll);

        self::assertSame(ProfileRunOutcome::Unchanged, $scheduled->getOutcome());
        self::assertCount(1, $this->chat()->calls());
        self::assertSame('First.', $this->storedProfile()->getText());
    }

    public function testAManualRunWithUnchangedInputsStillCallsTheModel(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 2);
        $this->chat()->queueContent('{"profile":"First."}');
        $this->tick()->advance($this->profileRun(ProfileRunTrigger::Manual), TickDriver::Poll);
        $this->chat()->queueContent('{"profile":"Second."}');
        $manual = $this->profileRun(ProfileRunTrigger::Manual);

        $this->tick()->advance($manual, TickDriver::Poll);

        self::assertSame(ProfileRunOutcome::Generated, $manual->getOutcome());
        self::assertSame('Second.', $this->storedProfile()->getText());
    }

    public function testAScheduledRunAfterTheHistoryGrewCallsTheModel(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 2);
        $this->chat()->queueContent('{"profile":"First."}');
        $this->tick()->advance($this->profileRun(ProfileRunTrigger::Manual), TickDriver::Poll);
        $this->fixtures->seedFavorites($this->owner, 'rail', 1);
        $this->chat()->queueContent('{"profile":"Maps and trains."}');
        $scheduled = $this->profileRun(ProfileRunTrigger::Scheduled);

        $this->tick()->advance($scheduled, TickDriver::Poll);

        self::assertSame(ProfileRunOutcome::Generated, $scheduled->getOutcome());
        self::assertSame('Maps and trains.', $this->storedProfile()->getText());
    }

    public function testAScheduledRunWithoutAStoredProfileCallsTheModelEvenWhenUnchanged(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 2);
        $this->chat()->queueContent('{"profile":"First."}');
        $this->tick()->advance($this->profileRun(ProfileRunTrigger::Manual), TickDriver::Poll);
        $this->clearStoredProfile();
        $this->chat()->queueContent('{"profile":"Again."}');
        $scheduled = $this->profileRun(ProfileRunTrigger::Scheduled);

        $this->tick()->advance($scheduled, TickDriver::Poll);

        self::assertSame(ProfileRunOutcome::Generated, $scheduled->getOutcome());
    }

    public function testAnUnusableReplyIsRetriedWithTheCorrectionAndTheThirdFailsTheRunKeepingTheProfile(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
        $this->fixtures->storeProfile($this->owner, 'Earlier profile.');
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);
        foreach (['not json', 'still not json', '{"profil":"typo"}'] as $reply) {
            $this->chat()->queueContent($reply);
        }

        $this->tick()->advance($profileRun, TickDriver::Poll);
        self::assertSame(RunStatus::Running, $profileRun->getStatus());
        self::assertSame(1, $profileRun->getAttempts());

        $this->tick()->advance($profileRun, TickDriver::Poll);
        $this->tick()->advance($profileRun, TickDriver::Poll);

        self::assertSame(RunStatus::Failed, $profileRun->getStatus());
        self::assertSame('The model gave no usable profile in 3 attempts.', $profileRun->getError());
        self::assertSame('Earlier profile.', $this->storedProfile()->getText());
        $secondCall = $this->chat()->calls()[1]['messages'];
        self::assertSame(RecommendationPromptText::DISTILL_CORRECTIVE, $secondCall[\count($secondCall) - 1]['content']);
    }

    public function testATransportFailureIsAStrikeAndTheThirdFailsTheRunKeepingTheProfile(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
        $this->fixtures->storeProfile($this->owner, 'Earlier profile.');
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);
        for ($strike = 0; $strike < 3; $strike++) {
            $this->chat()->queueFailure(new ProviderUnreachableException('gone'));
        }

        $this->tick()->advance($profileRun, TickDriver::Poll);
        self::assertSame(RunStatus::Running, $profileRun->getStatus());
        self::assertSame(1, $profileRun->getTransportFailures());

        $this->tick()->advance($profileRun, TickDriver::Poll);
        $this->tick()->advance($profileRun, TickDriver::Poll);

        self::assertSame(RunStatus::Failed, $profileRun->getStatus());
        self::assertSame('The AI provider at https://api.example.test/v1 failed: gone', $profileRun->getError());
        self::assertSame('Earlier profile.', $this->storedProfile()->getText());
    }

    public function testWithoutAUsableConnectionTheRunFailsWithoutACall(): void
    {
        $owner = $this->user('profile-run-tick-jev@example.test');
        $this->fixtures->seedReadyAiSettingsFor($owner, 'jev-latest');
        $this->fixtures->seedFavorites($owner, 'maps', 1);
        $profileRun = new ProfileRun($owner, ProfileRunTrigger::Recommendation, new \DateTimeImmutable('2026-10-03 09:00:00'));
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        $this->tick()->advance($profileRun, TickDriver::Poll);

        self::assertSame(RunStatus::Failed, $profileRun->getStatus());
        self::assertSame(ProfileConnections::MISSING, $profileRun->getError());
        self::assertSame([], $this->chat()->calls());
    }

    private function profileRun(ProfileRunTrigger $trigger): ProfileRun
    {
        $profileRun = new ProfileRun($this->owner, $trigger, new \DateTimeImmutable('2026-10-03 09:00:00'));
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        return $profileRun;
    }

    private function storedProfile(): \App\Entity\StoredProfile
    {
        $row = $this->entityManager->getRepository(RecommendationSettings::class)->findOneBy(['user' => $this->owner]);

        return $row instanceof RecommendationSettings ? $row->getStoredProfile() : \App\Entity\StoredProfile::none();
    }

    private function clearStoredProfile(): void
    {
        $row = $this->entityManager->getRepository(RecommendationSettings::class)->findOneBy(['user' => $this->owner]);
        self::assertInstanceOf(RecommendationSettings::class, $row);
        $row->storeProfile(\App\Entity\StoredProfile::none());
        $this->entityManager->flush();
    }

    private function tick(): ProfileRunTick
    {
        /** @var ProfileRunTick $tick */
        $tick = self::getContainer()->get(ProfileRunTick::class);

        return $tick;
    }

    private function chat(): StubChatClient
    {
        /** @var StubChatClient $chat */
        $chat = self::getContainer()->get(StubChatClient::class);

        return $chat;
    }
}
```

Import `App\Entity\StoredProfile` at the top instead of the inline FQCNs. Run: `php bin/phpunit tests/Service/Recommendation/Profile/ProfileRunTickTest.php`
Expected: error, `Class "App\Service\Recommendation\Profile\ProfileRunTick" not found`.

- [ ] **Step 8: The tick, its pass and its four collaborators**

`backend/src/Service/Recommendation/Profile/Pass/ProfileTick.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Pass;

use App\Entity\AiProviderSettings;
use App\Entity\ProfileRun;
use App\Service\Recommendation\Pool\Model\RecommendationHistoryModel;
use App\Service\Recommendation\Run\Model\ProviderCallRouteModel;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;

/** What one profile tick reads about its account, read once before the tick does anything. */
final readonly class ProfileTick
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        public ProfileRun $profileRun,
        public AiProviderSettings $connection,
        public EffectiveRecommendationSettingsModel $settings,
        public RecommendationHistoryModel $history,
        public TickDriver $driver,
    ) {
    }

    public function callRoute(): ProviderCallRouteModel
    {
        return new ProviderCallRouteModel($this->connection, $this->driver->retryPlan());
    }
}
```

`backend/src/Service/Recommendation/Profile/ProfileRunOpening.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Enum\ProfileRunOutcome;
use App\Enum\ProfileRunTrigger;
use App\Repository\ProfileRunRepository;
use App\Service\Recommendation\Profile\Pass\ProfileTick;
use App\Service\Recommendation\Profile\Support\ProfileInputFingerprint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** Starts a pending profile run; without history, or as a scheduled run with unchanged inputs, it ends right there. */
final readonly class ProfileRunOpening
{
    public function __construct(
        private ProfileRunRepository $profileRuns,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function open(ProfileTick $tick): void
    {
        $profileRun = $tick->profileRun;
        $fingerprint = ProfileInputFingerprint::of($tick->history, $tick->settings->historyCaps, $tick->connection);
        $unchanged = $this->isUnchangedScheduledRun($tick, $fingerprint);

        $profileRun->start($fingerprint, self::hostOf($tick), $this->modelOf($tick));

        if ($tick->history->isEmpty()) {
            $profileRun->complete(ProfileRunOutcome::NoHistory, $this->clock->now());
        } elseif ($unchanged) {
            $profileRun->complete(ProfileRunOutcome::Unchanged, $this->clock->now());
        }

        $this->entityManager->flush();
    }

    private function isUnchangedScheduledRun(ProfileTick $tick, string $fingerprint): bool
    {
        return ProfileRunTrigger::Scheduled === $tick->profileRun->getTrigger()
            && null !== $tick->settings->profileText
            && $fingerprint === $this->profileRuns->latestCompletedFingerprintFor($tick->profileRun->getUser());
    }

    private function modelOf(ProfileTick $tick): string
    {
        return $tick->connection->getModel()
            ?? throw new \LogicException('A connection that builds profiles has a model.');
    }

    private static function hostOf(ProfileTick $tick): ?string
    {
        $host = parse_url($tick->connection->getBaseUrl(), \PHP_URL_HOST);

        return \is_string($host) ? $host : null;
    }
}
```

`backend/src/Service/Recommendation/Profile/ProfileDistiller.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Service\Recommendation\Exception\RecommendationTickLockLostException;
use App\Service\Recommendation\Llm\Prompt\Factory\RecommendationCompletionRequestFactory;
use App\Service\Recommendation\Llm\Prompt\Model\CallPromptModel;
use App\Service\Recommendation\Llm\Prompt\Model\RecommendationResponseSchema;
use App\Service\Recommendation\Llm\Prompt\RecommendationProfileParser;
use App\Service\Recommendation\Llm\Prompt\RecommendationPromptBuilder;
use App\Service\Recommendation\Llm\Prompt\Support\RecommendationPromptText;
use App\Service\Recommendation\Llm\Run\CompletionCallRecorder;
use App\Service\Recommendation\Llm\Run\Model\ProfileDistillationOutcomeModel;
use App\Service\Recommendation\Llm\Run\RecommendationProviderCall;
use App\Service\Recommendation\Profile\Pass\ProfileTick;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\TickLockKeepalive;

/** A profile run's one provider call: the history in, a short profile out; storing it is the caller's. */
final readonly class ProfileDistiller
{
    public function __construct(
        private RecommendationPromptBuilder $promptBuilder,
        private RecommendationCompletionRequestFactory $requestFactory,
        private CompletionCallRecorder $callRecorder,
        private RecommendationProviderCall $providerCall,
        private RecommendationProfileParser $profileParser,
        private TickLockKeepalive $keepalive,
    ) {
    }

    /** @throws RecommendationTickLockLostException when another process took the lock during the call */
    public function distill(ProfileTick $tick): ProfileDistillationOutcomeModel
    {
        $request = $this->requestFactory->create($tick->connection, new CallPromptModel(
            $this->promptBuilder->messagesWithCorrectiveTail(
                $this->promptBuilder->distillMessages($tick->history, $tick->settings),
                $tick->profileRun->getLastInvalidReply(),
                RecommendationPromptText::DISTILL_CORRECTIVE,
            ),
            1,
            RecommendationResponseSchema::Distillation,
        ));
        $recordedCall = $this->callRecorder->beginForProfileRun($tick->profileRun, $request);
        $content = $this->providerCall->complete($tick->callRoute(), $request, $recordedCall);
        $result = $this->profileParser->parse($content);

        if (!$result->usable) {
            $recordedCall->finishUnusable($content);
            $this->guardTheLock();

            return ProfileDistillationOutcomeModel::unusable($content);
        }

        $recordedCall->finishUsable($content);
        $this->guardTheLock();

        return ProfileDistillationOutcomeModel::usable(
            $result->profile ?? throw new \LogicException('A usable profile parse result has no profile text.'),
        );
    }

    private function guardTheLock(): void
    {
        if ($this->keepalive->hasLostTheLock()) {
            throw new RecommendationTickLockLostException();
        }
    }
}
```

`backend/src/Service/Recommendation/Profile/ProfileGeneration.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Entity\StoredProfile;
use App\Enum\ProfileRunOutcome;
use App\Service\Recommendation\Profile\Pass\ProfileTick;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** A running profile run's model call: a usable reply replaces the stored profile, an unusable one is retried. */
final readonly class ProfileGeneration
{
    public const string NO_USABLE_REPLY = 'The model gave no usable profile in %d attempts.';

    public function __construct(
        private ProfileDistiller $distiller,
        private RecommendationSettingsWriter $settingsWriter,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function advance(ProfileTick $tick): void
    {
        $outcome = $this->distiller->distill($tick);

        if (!$outcome->usable) {
            $this->retryOrFail($tick->profileRun, $outcome->requireUnusableReply());

            return;
        }

        $this->store(
            $tick->profileRun,
            $outcome->profileText ?? throw new \LogicException('A usable outcome carries its profile text.'),
        );
    }

    private function retryOrFail(ProfileRun $profileRun, string $unusableReply): void
    {
        $profileRun->recordInvalidReply($unusableReply);
        if ($profileRun->hasExhaustedAttempts()) {
            $profileRun->fail(\sprintf(self::NO_USABLE_REPLY, ProfileRun::MAX_ATTEMPTS), $this->clock->now());
        }

        $this->entityManager->flush();
    }

    private function store(ProfileRun $profileRun, string $profileText): void
    {
        $now = $this->clock->now();
        $this->settingsWriter->storeProfile(
            $profileRun->getUser(),
            new StoredProfile($profileText, $now, $profileRun->getProviderHost(), $profileRun->getModel()),
        );
        $profileRun->complete(ProfileRunOutcome::Generated, $now);
        $this->entityManager->flush();
    }
}
```

`backend/src/Service/Recommendation/Profile/ProfileRunFailure.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Service\Recommendation\Profile\Pass\ProfileTick;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\TickLockKeepalive;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** How a profile run ends badly; the stored profile is never touched here. */
final readonly class ProfileRunFailure
{
    public const string PROVIDER_FAILED = 'The AI provider at %s failed: %s';

    public function __construct(
        private TickLockKeepalive $keepalive,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function fail(ProfileRun $profileRun, string $message): void
    {
        $profileRun->fail($message, $this->clock->now());
        $this->entityManager->flush();
    }

    /** One strike; the run fails at MAX_TRANSPORT_FAILURES. A tick that lost its lock records nothing. */
    public function recordTransportFailure(ProfileTick $tick, string $failureDetail): void
    {
        $profileRun = $tick->profileRun;
        if ($this->keepalive->hasLostTheLock()) {
            $this->entityManager->refresh($profileRun);

            return;
        }

        $profileRun->recordTransportFailure();
        if ($profileRun->hasExhaustedTransportRetries()) {
            $profileRun->fail(
                \sprintf(self::PROVIDER_FAILED, $tick->connection->getBaseUrl(), $failureDetail),
                $this->clock->now(),
            );
        }
        $this->entityManager->flush();
    }
}
```

`backend/src/Service/Recommendation/Profile/ProfileRunTick.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\AiProviderSettings;
use App\Entity\ProfileRun;
use App\Enum\RunStatus;
use App\Service\Ai\Exception\AiKeyUnreadableException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Recommendation\Exception\RecommendationTickLockLostException;
use App\Service\Recommendation\Pool\RecommendationHistoryLoader;
use App\Service\Recommendation\Profile\Pass\ProfileTick;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Settings\RecommendationSettingsResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One tick of a profile run under the caller's lock: open it, or make its one model call. A provider failure is
 * recorded on the run and never thrown, so a recommendation tick that hands its turn to a profile run cannot fail.
 */
final readonly class ProfileRunTick
{
    public const string KEY_UNREADABLE = 'The stored API key can no longer be read.';

    public function __construct(
        private ProfileConnections $profileConnections,
        private RecommendationSettingsResolver $settingsResolver,
        private RecommendationHistoryLoader $historyLoader,
        private ProfileRunOpening $opening,
        private ProfileGeneration $generation,
        private ProfileRunFailure $failure,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function advance(ProfileRun $profileRun, TickDriver $driver): void
    {
        $connection = $this->profileConnections->usableFor($profileRun->getUser());
        if (null === $connection) {
            $this->failure->fail($profileRun, ProfileConnections::MISSING);

            return;
        }

        $tick = $this->tickFor($profileRun, $connection, $driver);
        try {
            $this->advanceWithin($tick);
        } catch (RecommendationTickLockLostException) {
            $this->entityManager->refresh($profileRun);
        } catch (
            ProviderUnreachableException | CredentialsRejectedException | RetryableProviderException
            | ProviderRateLimitedException $exception
        ) {
            $this->failure->recordTransportFailure($tick, $exception->getMessage());
        } catch (AiKeyUnreadableException) {
            $this->failure->fail($profileRun, self::KEY_UNREADABLE);
        }
    }

    private function tickFor(ProfileRun $profileRun, AiProviderSettings $connection, TickDriver $driver): ProfileTick
    {
        $user = $profileRun->getUser();
        $settings = $this->settingsResolver->forAccount($user)->forConnection($connection);

        return new ProfileTick(
            $profileRun,
            $connection,
            $settings,
            $this->historyLoader->load($user->requireId(), $settings),
            $driver,
        );
    }

    private function advanceWithin(ProfileTick $tick): void
    {
        if (RunStatus::Pending === $tick->profileRun->getStatus()) {
            $this->opening->open($tick);
        }

        if (RunStatus::Running === $tick->profileRun->getStatus()) {
            $this->generation->advance($tick);
        }
    }
}
```

Run the Step 7 test. Expected: OK (10 tests). If the queued `ProviderUnreachableException` reaches the tick as another type (the rate-limit loop may wrap it), assert on what the loop really throws and report the wrapping; the tick must still record a strike for it.

- [ ] **Step 9: Write the failing advancer and starter tests**

`backend/tests/Service/Recommendation/Profile/ProfileRunAdvancerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Entity\User;
use App\Enum\ProfileRunTrigger;
use App\Enum\RunStatus;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Profile\ProfileRunAdvancer;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\ProviderCallHeartbeatInterface;
use App\Service\Recommendation\Run\UserTickLock;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\TtlRecordingLockFactory;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\DoctrineDbalStore;

final class ProfileRunAdvancerTest extends DbTestCase
{
    use SeedsUsers;

    private RecommendationRunFixtures $fixtures;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('profile-run-advancer@example.test');
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
    }

    public function testItTicksTheAccountsActiveProfileRun(): void
    {
        $profileRun = $this->pendingProfileRun();
        $this->chat()->queueContent('{"profile":"Likes maps."}');

        self::assertTrue($this->advancer()->advance($this->owner, TickDriver::Poll));

        $this->entityManager->refresh($profileRun);
        self::assertSame(RunStatus::Completed, $profileRun->getStatus());
    }

    public function testWithoutAnActiveProfileRunThereIsNothingToTick(): void
    {
        self::assertFalse($this->advancer()->advance($this->owner, TickDriver::Poll));
    }

    public function testAHeldLockSkipsTheTurn(): void
    {
        $profileRun = $this->pendingProfileRun();
        $holder = self::lockFactory()->createLock(UserTickLock::nameFor($this->owner), 60.0);
        self::assertTrue($holder->acquire());

        try {
            self::assertFalse($this->advancer()->advance($this->owner, TickDriver::Poll));
        } finally {
            $holder->release();
        }

        $this->entityManager->refresh($profileRun);
        self::assertSame(RunStatus::Pending, $profileRun->getStatus());
    }

    public function testATickThatLostItsLockDuringTheCallStoresNothing(): void
    {
        $this->recordLocksOverTheRealStore();
        $this->fixtures->storeProfile($this->owner, 'Earlier profile.');
        $profileRun = $this->pendingProfileRun();
        $thief = null;
        $this->chat()->duringNextCall(function () use (&$thief): void {
            $thief = $this->stealTheTickLock();
            $this->providerCallHeartbeat()->beat();
        });
        $this->chat()->queueContent('{"profile":"Stolen profile."}');

        try {
            $this->advancer()->advance($this->owner, TickDriver::Poll);
        } finally {
            $thief?->release();
        }

        $this->entityManager->clear();
        $fresh = $this->entityManager->find(ProfileRun::class, $profileRun->requireId());
        self::assertSame(RunStatus::Running, $fresh?->getStatus());
        self::assertSame(0, $fresh?->getAttempts());
        self::assertSame('Earlier profile.', $this->storedProfileText());
    }

    private function pendingProfileRun(): ProfileRun
    {
        $profileRun = new ProfileRun($this->owner, ProfileRunTrigger::Manual, new \DateTimeImmutable('2026-10-03 09:00:00'));
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        return $profileRun;
    }

    private function storedProfileText(): ?string
    {
        /** @var \App\Entity\RecommendationSettings|null $row */
        $row = $this->entityManager->getRepository(\App\Entity\RecommendationSettings::class)
            ->findOneBy(['user' => $this->owner->requireId()]);

        return $row?->getStoredProfile()->getText();
    }

    /** Swapped in before the advancer is built, so the lock the container wires holds over the real store. */
    private function recordLocksOverTheRealStore(): void
    {
        self::getContainer()->set(
            LockFactory::class,
            new TtlRecordingLockFactory(new DoctrineDbalStore($this->entityManager->getConnection())),
        );
    }

    private function stealTheTickLock(): SharedLockInterface
    {
        $this->entityManager->getConnection()->executeStatement('DELETE FROM lock_keys');
        $thief = (new LockFactory(new DoctrineDbalStore($this->entityManager->getConnection())))
            ->createLock(UserTickLock::nameFor($this->owner), 60.0);
        self::assertTrue($thief->acquire());

        return $thief;
    }

    private function providerCallHeartbeat(): ProviderCallHeartbeatInterface
    {
        /** @var ProviderCallHeartbeatInterface $heartbeat */
        $heartbeat = self::getContainer()->get(ProviderCallHeartbeatInterface::class);

        return $heartbeat;
    }

    private static function lockFactory(): LockFactory
    {
        /** @var LockFactory $lockFactory */
        $lockFactory = self::getContainer()->get(LockFactory::class);

        return $lockFactory;
    }

    private function advancer(): ProfileRunAdvancer
    {
        /** @var ProfileRunAdvancer $advancer */
        $advancer = self::getContainer()->get(ProfileRunAdvancer::class);

        return $advancer;
    }

    private function chat(): StubChatClient
    {
        /** @var StubChatClient $chat */
        $chat = self::getContainer()->get(StubChatClient::class);

        return $chat;
    }
}
```

Import `RecommendationSettings` at the top instead of the inline FQCNs. The stolen-lock test is opened first in a pending state; the tick opens it (the open flushes `running`) before the stolen call, so `running` with zero attempts is the state a discarded call leaves.

`backend/tests/Service/Recommendation/Profile/ProfileRunStarterTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\ProfileRunTrigger;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Profile\Exception\ProfileConnectionMissingException;
use App\Service\Recommendation\Profile\ProfileRunStarter;
use App\Service\Recommendation\Run\Support\RunLogRetention;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class ProfileRunStarterTest extends DbTestCase
{
    use SeedsUsers;

    private RecommendationRunFixtures $fixtures;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('profile-run-starter@example.test');
    }

    public function testAManualStartOpensAPendingManualRun(): void
    {
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');

        $profileRun = $this->starter()->startManually($this->owner);

        self::assertSame(ProfileRunTrigger::Manual, $profileRun->getTrigger());
        self::assertNotNull($profileRun->getId());
    }

    public function testASecondStartReturnsTheActiveRun(): void
    {
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');
        $first = $this->starter()->start($this->owner, ProfileRunTrigger::Scheduled);

        $second = $this->starter()->startManually($this->owner);

        self::assertSame($first->getId(), $second->getId());
        self::assertSame(ProfileRunTrigger::Scheduled, $second->getTrigger());
    }

    public function testAManualStartWithoutAUsableConnectionIsRefused(): void
    {
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'jev-latest');

        $this->expectException(ProfileConnectionMissingException::class);
        $this->starter()->startManually($this->owner);
    }

    public function testAStartTrimsTheLogToTheNewestProfileRuns(): void
    {
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');
        $oldest = $this->finishedProfileRunWithOneRow();
        for ($index = 1; $index < RunLogRetention::RUNS; $index++) {
            $this->finishedProfileRunWithOneRow();
        }

        $this->starter()->start($this->owner, ProfileRunTrigger::Manual);

        self::assertSame([], $this->logs()->listForProfileRun($this->owner, $oldest->requireId()));
    }

    private function finishedProfileRunWithOneRow(): ProfileRun
    {
        $profileRun = new ProfileRun($this->owner, ProfileRunTrigger::Manual, new \DateTimeImmutable('2026-10-03 09:00:00'));
        $profileRun->fail('irrelevant', new \DateTimeImmutable('2026-10-03 09:00:01'));
        $this->entityManager->persist($profileRun);
        $this->entityManager->persist(RecommendationRunLog::forProfileRun(
            $profileRun,
            1,
            '{}',
            new \DateTimeImmutable('2026-10-03 09:00:00'),
        ));
        $this->entityManager->flush();

        return $profileRun;
    }

    private function starter(): ProfileRunStarter
    {
        /** @var ProfileRunStarter $starter */
        $starter = self::getContainer()->get(ProfileRunStarter::class);

        return $starter;
    }

    private function logs(): RecommendationRunLogRepository
    {
        /** @var RecommendationRunLogRepository $logs */
        $logs = self::getContainer()->get(RecommendationRunLogRepository::class);

        return $logs;
    }
}
```

With `RUNS = 10`, the loop leaves ten finished runs; the start makes an eleventh, so the oldest falls out of the window while nine of its successors keep theirs.

Append to `ProfileRunAdvancerTest` a TTL pin (imports `App\Service\Recommendation\Run\TickLockTtl`, `Symfony\Component\Lock\Store\InMemoryStore`):

```php
    /** The profile tick sizes the shared lock by the connection it calls, not by the account's active one. */
    public function testTheLockLastsAsLongAsTheSlowProfileConnectionNeeds(): void
    {
        $lockFactory = new TtlRecordingLockFactory(new InMemoryStore());
        self::getContainer()->set(LockFactory::class, $lockFactory);
        $slow = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'profile-llm');
        $slow->setSlowModel(true);
        $this->fixtures->chooseProfileConnection($this->owner, $slow);
        $this->pendingProfileRun();
        $this->chat()->queueContent('{"profile":"Likes maps."}');

        $this->advancer()->advance($this->owner, TickDriver::Poll);

        self::assertSame(
            900.0 + TickLockTtl::MARGIN_SECONDS,
            $lockFactory->lastTtlFor(UserTickLock::nameFor($this->owner)),
        );
    }
```

Run: `php bin/phpunit tests/Service/Recommendation/Profile`
Expected: errors for the missing classes.

- [ ] **Step 10: The advancers and the starter**

`backend/src/Service/Recommendation/Profile/Exception/ProfileConnectionMissingException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Exception;

final class ProfileConnectionMissingException extends \RuntimeException
{
}
```

`backend/src/Service/Recommendation/Profile/ProfileRunAdvancer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\User;
use App\Repository\ProfileRunRepository;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\TickLockTtl;
use App\Service\Recommendation\Run\UserTickLock;

/** The profile drivers' tick: the account's active profile run, under the lock its recommendation runs take. */
final readonly class ProfileRunAdvancer
{
    public function __construct(
        private UserTickLock $tickLock,
        private TickLockTtl $lockTtl,
        private ProfileConnections $profileConnections,
        private ProfileRunRepository $profileRuns,
        private ProfileRunTick $ticks,
    ) {
    }

    /** False when the account has no active profile run, or another tick holds the lock. */
    public function advance(User $user, TickDriver $driver): bool
    {
        $held = $this->tickLock->acquire(
            $user,
            $this->lockTtl->secondsForConnection($this->profileConnections->usableFor($user)),
        );
        if (null === $held) {
            return false;
        }

        try {
            return $this->tickTheActiveRun($user, $driver);
        } finally {
            $held->release();
        }
    }

    private function tickTheActiveRun(User $user, TickDriver $driver): bool
    {
        $profileRun = $this->profileRuns->findActiveForUser($user);
        if (null === $profileRun) {
            return false;
        }

        $this->ticks->advance($profileRun, $driver);

        return true;
    }
}
```

`backend/src/Service/Recommendation/Profile/ProfileRunStarter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Entity\User;
use App\Enum\ProfileRunTrigger;
use App\Repository\ProfileRunRepository;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Recommendation\Profile\Exception\ProfileConnectionMissingException;
use App\Service\Recommendation\Run\Support\RunLogRetention;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** Opens a profile run; an active one is returned as it is, so a second start opens no duplicate. */
final readonly class ProfileRunStarter
{
    public function __construct(
        private ProfileRunRepository $profileRuns,
        private ProfileConnections $profileConnections,
        private RecommendationRunLogRepository $logs,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /** @throws ProfileConnectionMissingException */
    public function startManually(User $user): ProfileRun
    {
        if (null === $this->profileConnections->usableFor($user)) {
            throw new ProfileConnectionMissingException(ProfileConnections::MISSING);
        }

        return $this->start($user, ProfileRunTrigger::Manual);
    }

    public function start(User $user, ProfileRunTrigger $trigger): ProfileRun
    {
        $active = $this->profileRuns->findActiveForUser($user);
        if (null !== $active) {
            return $active;
        }

        $profileRun = new ProfileRun($user, $trigger, $this->clock->now());
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        $this->logs->deleteForUserOutsideProfileRuns(
            $user,
            $this->profileRuns->findNewestIdsForUser($user, RunLogRetention::RUNS),
        );

        return $profileRun;
    }
}
```

`RunLogRetention`'s docblock gains one sentence at its end: `Profile runs keep their log rows under the same count, separately.`

- [ ] **Step 11: Run the tests and watch them pass**

Run: `php bin/phpunit tests/Service/Recommendation/Profile tests/Service/Recommendation/Run tests/Service/Worker tests/Controller/Api/RecommendationRunControllerTest.php tests/Service/Recommendation/Pool`
Expected: OK.

- [ ] **Step 12: Deletion checks**

1. In `ProfileRunOpening::isUnchangedScheduledRun()`, drop the trigger condition. `testAManualRunWithUnchangedInputsStillCallsTheModel` must fail. Restore.
2. Drop the `null !== $tick->settings->profileText` condition. `testAScheduledRunWithoutAStoredProfileCallsTheModelEvenWhenUnchanged` must fail. Restore.
3. Drop the `isEmpty()` branch in `open()`. `testWithoutHistoryTheRunCompletesWithoutACallAndStoresNothing` must fail. Restore.
4. In `ProfileGeneration::retryOrFail()`, fail on the first unusable reply. The unusable-reply test must fail at `Running`. Restore.
5. In `ProfileRunTick::advance()`, drop the `ProviderUnreachableException` catch type. The transport test must fail with an uncaught exception. Restore.
6. In `ProfileConnections::usableFor()`, use `$chosen ?? …` only when the chosen one can build (i.e. fall back to the active one). `testAChosenConnectionThatCannotBuildAProfileGivesNoneRatherThanTheActiveOne` must fail. Restore.
7. In `ProfileDistiller::guardTheLock()`, return early. `testATickThatLostItsLockDuringTheCallStoresNothing` must fail on the stored text. Restore.
8. In `ProfileRunAdvancer::advance()`, pass `$this->lockTtl->secondsFor($user)` instead. `testTheLockLastsAsLongAsTheSlowProfileConnectionNeeds` must fail. Restore.
10. In `ProfileRunStarter::start()`, drop the trim call. `testAStartTrimsTheLogToTheNewestProfileRuns` must fail. Restore.

- [ ] **Step 13: Gates, commit**

Step 4 is a pure extraction; commit it alone first (`refactor(#1351): the per-user tick lock leaves the recommendation advancer`) so the reviewer can read it apart.

```bash
php bin/phpunit tests/Service/Recommendation tests/Service/Worker tests/Command tests/Controller/Api tests/Repository
composer cs && composer stan && composer md && composer tramp
git add backend/src backend/tests
git commit -m "feat(#1351): a profile run generates the profile under the lock its recommendation runs take"
```

*Amended (Task 3 execution):*
1. `ServiceModuleCycleRule`: `Recommendation\Llm` is a declared sub-module, so `Recommendation\Profile` (part of `Recommendation`) may not name it, and Step 8's `Profile/ProfileDistiller` closed the cycle `Recommendation -> Recommendation\Llm -> Recommendation`. The lower module now owns the interface: `Profile/ProfileRunDistiller/ProfileRunDistillerInterface::distill(ProfileTick): ProfileDistillationOutcomeModel`, implemented by `Llm/Run/LlmProfileRunDistiller` (Step 8's distiller body without the lock guard, single implementation, autowired). The lock guard moves to `ProfileGeneration::advance()`, right after the call and before any write (it takes `TickLockKeepalive`); `Profile/ProfileDistiller.php` does not exist.
2. Task 5's move happens here: `ProfileDistillationOutcomeModel` is `Profile/Model/ProfileDistillationOutcomeModel` (its test in `tests/Service/Recommendation/Profile/Model/`), since the interface returns it; `Llm/Run/RecommendationProfileDistiller` imports it from there; its docblock says "the unusable reply its caller retries".
3. Nothing in `src` takes `ProfileRunTick`, `ProfileRunAdvancer` or `ProfileRunStarter` yet, so the test container finds them removed: `config/services_test.yaml` declares the three `autowire: true, public: true` under `# Public: no driver takes the profile run's services yet, so their tests would find them removed.` Task 4 deletes those entries once its drivers take them (keep any a test still needs).
4. `ProfileGeneration::store()` completes the run before `storeProfile()`, so the writer's flush writes the run and the profile in one transaction; a kill between two flushes would otherwise leave a stored profile under a `running` run.
5. Added pins: `ProfileRunTickTest::testADeferringRateLimitIsAStrikeAndTheThirdFailsTheRun`, `testAnUnreadableKeyFailsTheRunAtOnceKeepingTheProfile`, `ProfileRunAdvancerTest::testATickThatLostItsLockDuringAFailedCallRecordsNoStrike`. The stolen-lock test reads the run with `assertNotNull` and `->` (PHPStan rejects `?->` after the first assertion); the fingerprint test builds its lines in a typed `lines()` helper (PHPStan `list<>`).
6. `HeldTickLock` carries no `@noinspection AutowireWrongClass` (PhpStorm: redundant suppression, `Pass/` is not autowired). `docs/recommendations-runs.md` names `UserTickLock::nameFor()` instead of `lockNameFor()`.
7. Long lines wrapped (the `new ProfileRun(...)` lines in the tests, the `ProfileConnections::usableFor()` docblock reworded to "if" to fit 120 columns).

---
### Task 4: Profile runs are scheduled and driven like recommendation runs

**Files:**
- Create: `backend/src/Service/Recommendation/Profile/DueProfileRunFinder.php`, `backend/src/Service/Recommendation/Profile/ProfileRunSweep.php`
- Create: `backend/src/Service/Worker/Message/StartDueProfileRuns.php`, `AdvanceProfileRuns.php`; `backend/src/Service/Worker/Handler/StartDueProfileRunsHandler.php`, `AdvanceProfileRunsHandler.php`; `backend/src/Service/Worker/WorkerProfileRunSweep.php`
- Modify: `backend/src/Repository/RecommendationSettingsRepository.php` (`findWithProfileInterval()`), `backend/src/Repository/AccountWipeRepository.php`
- Modify: `backend/src/Service/Worker/WorkerSchedule.php`, `backend/src/Service/Worker/Handler/AdvanceRecommendationRunsHandler.php` (docblock)
- Modify: `backend/src/Service/Recommendation/Run/ForYouSweep.php`, `Model/ForYouSweepReportModel.php`, `backend/src/Http/ForYouSweepReportJson.php`
- Modify: `backend/src/Command/RecommendationDrainCommand.php`, `backend/src/EventListener/RecommendationDrainOnTerminateListener.php`
- Test: create `backend/tests/Service/Recommendation/Profile/DueProfileRunFinderTest.php`, `ProfileRunSweepTest.php`, `backend/tests/Service/Worker/StartDueProfileRunsHandlerTest.php`, `AdvanceProfileRunsHandlerTest.php`; modify `backend/tests/Service/Worker/WorkerScheduleWiringTest.php`, `backend/tests/Service/Recommendation/Run/ForYouSweepTest.php`, `backend/tests/Http/ForYouSweepReportJsonTest.php`, `backend/tests/Http/MaintenanceTickJsonTest.php`, `backend/tests/Command/RecommendationDrainCommandTest.php`, `backend/tests/EventListener/RecommendationDrainOnTerminateListenerTest.php`, `backend/tests/Service/Account/AccountResetTest.php`

**Interfaces:**
- Consumes (Tasks 1–3): `ProfileRunRepository`, `ProfileConnections::usableFor()`, `ProfileRunStarter::start()`, `ProfileRunAdvancer::advance()`, `RecommendationSettings::profileSettings()`, `RecommendationSettingsWriter::saveProfileSettings()`, `UserTickLock`.
- Produces:
  - `RecommendationSettingsRepository::findWithProfileInterval(): list<RecommendationSettings>`.
  - `DueProfileRunFinder::due(): list<User>`.
  - `ProfileRunSweep::startDueRuns(): int`, `advanceEveryActiveRun(TickDriver): int` (attempted runs), `activeRunCount(): int`.
  - `WorkerProfileRunSweep::sweep(RecommendationDriverKind): int`.
  - Messages `StartDueProfileRuns`, `AdvanceProfileRuns` and their handlers; schedule: `AdvanceProfileRuns` every 10 seconds, `StartDueProfileRuns` every 5 minutes.
  - `ForYouSweepReportModel` gains `startedProfileRuns`, `advancedProfileRuns`, `activeProfileRuns` (named, default 0); the sweep JSON gains the same three keys.

- [ ] **Step 1: Write the failing finder test**

`backend/tests/Service/Recommendation/Profile/DueProfileRunFinderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Entity\ProfileSettingsValues;
use App\Entity\User;
use App\Enum\ProfileRunOutcome;
use App\Enum\ProfileRunTrigger;
use App\Repository\ProfileRunRepository;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Profile\DueProfileRunFinder;
use App\Service\Recommendation\Profile\ProfileConnections;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use Symfony\Component\Clock\MockClock;

final class DueProfileRunFinderTest extends DbTestCase
{
    use SeedsUsers;

    private const string NOW = '2026-10-03 12:00:00';

    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
    }

    public function testDueOneIntervalAfterTheNewestRunEvenWhenItFailed(): void
    {
        $owner = $this->scheduledOwner('profile-due-failed@example.test', 6);
        $this->failedRunAt($owner, '2026-10-03 05:30:00');

        self::assertSame(['profile-due-failed@example.test'], $this->dueEmails());
    }

    public function testNotDueInsideTheInterval(): void
    {
        $owner = $this->scheduledOwner('profile-due-fresh@example.test', 6);
        $this->completedRunAt($owner, '2026-10-03 06:30:00');

        self::assertSame([], $this->dueEmails());
    }

    public function testDueExactlyOneIntervalLater(): void
    {
        $owner = $this->scheduledOwner('profile-due-boundary@example.test', 6);
        $this->completedRunAt($owner, '2026-10-03 06:00:00');

        self::assertSame(['profile-due-boundary@example.test'], $this->dueEmails());
    }

    public function testDueWithoutAnyRunYet(): void
    {
        $this->scheduledOwner('profile-due-first@example.test', 168);

        self::assertSame(['profile-due-first@example.test'], $this->dueEmails());
    }

    public function testNotDueWhileARunIsActive(): void
    {
        $owner = $this->scheduledOwner('profile-due-active@example.test', 6);
        $profileRun = new ProfileRun($owner, ProfileRunTrigger::Manual, new \DateTimeImmutable('2026-10-02 00:00:00'));
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        self::assertSame([], $this->dueEmails());
    }

    public function testSkippedWithoutAConnectionThatCanBuildTheProfile(): void
    {
        $owner = $this->user('profile-due-jev@example.test');
        $this->fixtures->seedReadyAiSettingsFor($owner, 'jev-latest');
        $this->schedule($owner, 6);

        self::assertSame([], $this->dueEmails());
    }

    public function testAManualScheduleIsNeverDue(): void
    {
        $owner = $this->user('profile-due-manual@example.test');
        $this->fixtures->seedReadyAiSettingsFor($owner, 'qwen3-14b');
        $this->writer()->saveProfileSettings($owner, new ProfileSettingsValues(null, null, 40, 80));

        self::assertSame([], $this->dueEmails());
    }

    private function scheduledOwner(string $email, int $hours): User
    {
        $owner = $this->user($email);
        $this->fixtures->seedReadyAiSettingsFor($owner, 'qwen3-14b');
        $this->schedule($owner, $hours);

        return $owner;
    }

    private function schedule(User $owner, int $hours): void
    {
        $this->writer()->saveProfileSettings($owner, new ProfileSettingsValues($hours, null, 40, 80));
    }

    private function failedRunAt(User $owner, string $createdAt): void
    {
        $profileRun = new ProfileRun($owner, ProfileRunTrigger::Scheduled, new \DateTimeImmutable($createdAt));
        $profileRun->fail('irrelevant', new \DateTimeImmutable($createdAt));
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();
    }

    private function completedRunAt(User $owner, string $createdAt): void
    {
        $profileRun = new ProfileRun($owner, ProfileRunTrigger::Scheduled, new \DateTimeImmutable($createdAt));
        $profileRun->start('fingerprint', 'api.example.test', 'qwen3-14b');
        $profileRun->complete(ProfileRunOutcome::Unchanged, new \DateTimeImmutable($createdAt));
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();
    }

    /** @return list<string> */
    private function dueEmails(): array
    {
        $container = self::getContainer();
        /** @var RecommendationSettingsRepository $settings */
        $settings = $container->get(RecommendationSettingsRepository::class);
        /** @var ProfileRunRepository $profileRuns */
        $profileRuns = $container->get(ProfileRunRepository::class);
        /** @var ProfileConnections $connections */
        $connections = $container->get(ProfileConnections::class);
        $finder = new DueProfileRunFinder($settings, $profileRuns, $connections, new MockClock(self::NOW));

        return array_map(static fn (User $user): string => $user->getEmail(), $finder->due());
    }

    private function writer(): RecommendationSettingsWriter
    {
        /** @var RecommendationSettingsWriter $writer */
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);

        return $writer;
    }
}
```

Run: `php bin/phpunit tests/Service/Recommendation/Profile/DueProfileRunFinderTest.php`
Expected: error, `Class "App\Service\Recommendation\Profile\DueProfileRunFinder" not found`.

- [ ] **Step 2: The finder and its query**

In `backend/src/Repository/RecommendationSettingsRepository.php` add:

```php
    /**
     * Every account that scheduled its profile; the finder decides which of them are due right now.
     *
     * @return list<RecommendationSettings>
     */
    public function findWithProfileInterval(): array
    {
        /** @var list<RecommendationSettings> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.profileTuning.intervalHours IS NOT NULL')
            ->getQuery()
            ->getResult();

        return $rows;
    }
```

`backend/src/Service/Recommendation/Profile/DueProfileRunFinder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Repository\ProfileRunRepository;
use App\Repository\RecommendationSettingsRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * The accounts a scheduled sweep starts a profile run for: a schedule chosen, a connection that can build the
 * profile (none means skipped, not started and failed), no profile run in flight, and the newest one an interval old.
 */
final readonly class DueProfileRunFinder
{
    public function __construct(
        private RecommendationSettingsRepository $settings,
        private ProfileRunRepository $profileRuns,
        private ProfileConnections $profileConnections,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<User> */
    public function due(): array
    {
        $due = [];

        foreach ($this->settings->findWithProfileInterval() as $row) {
            if ($this->isDue($row)) {
                $due[] = $row->getUser();
            }
        }

        return $due;
    }

    private function isDue(RecommendationSettings $row): bool
    {
        $user = $row->getUser();
        if (null === $this->profileConnections->usableFor($user)) {
            return false;
        }

        if (null !== $this->profileRuns->findActiveForUser($user)) {
            return false;
        }

        $anchor = $this->profileRuns->findLatestForUser($user)?->getCreatedAt();
        if (null === $anchor) {
            return true;
        }

        return $this->clock->now() >= $anchor->modify(\sprintf('+%d hours', $row->profileSettings()->intervalHours));
    }
}
```

Run the Step 1 test. Expected: OK (7 tests).

- [ ] **Step 3: Write the failing sweep tests**

`backend/tests/Service/Recommendation/Profile/ProfileRunSweepTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Entity\ProfileSettingsValues;
use App\Entity\User;
use App\Enum\ProfileRunTrigger;
use App\Enum\RunStatus;
use App\Repository\ProfileRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Profile\ProfileRunSweep;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class ProfileRunSweepTest extends DbTestCase
{
    use SeedsUsers;

    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
    }

    public function testStartDueRunsOpensAScheduledRunPerDueAccount(): void
    {
        $first = $this->scheduledOwner('profile-sweep-a@example.test');
        $second = $this->scheduledOwner('profile-sweep-b@example.test');

        self::assertSame(2, $this->sweep()->startDueRuns());

        self::assertSame(ProfileRunTrigger::Scheduled, $this->profileRuns()->findActiveForUser($first)?->getTrigger());
        self::assertSame(ProfileRunTrigger::Scheduled, $this->profileRuns()->findActiveForUser($second)?->getTrigger());
    }

    public function testAdvanceTicksEveryActiveRunAndCountsThem(): void
    {
        $first = $this->scheduledOwner('profile-sweep-c@example.test');
        $second = $this->scheduledOwner('profile-sweep-d@example.test');
        $this->sweep()->startDueRuns();

        self::assertSame(2, $this->sweep()->activeRunCount());
        self::assertSame(2, $this->sweep()->advanceEveryActiveRun(TickDriver::Sweep));

        $this->entityManager->clear();
        self::assertSame(RunStatus::Completed, $this->profileRuns()->findLatestForUser($first)?->getStatus());
        self::assertSame(RunStatus::Completed, $this->profileRuns()->findLatestForUser($second)?->getStatus());
        self::assertSame(0, $this->sweep()->activeRunCount());
    }

    /** No history, so each run completes without a model call: the sweep is what is under test, not generation. */
    private function scheduledOwner(string $email): User
    {
        $owner = $this->user($email);
        $this->fixtures->seedReadyAiSettingsFor($owner, 'qwen3-14b');
        /** @var RecommendationSettingsWriter $writer */
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        $writer->saveProfileSettings($owner, new ProfileSettingsValues(24, null, 40, 80));

        return $owner;
    }

    private function sweep(): ProfileRunSweep
    {
        /** @var ProfileRunSweep $sweep */
        $sweep = self::getContainer()->get(ProfileRunSweep::class);

        return $sweep;
    }

    private function profileRuns(): ProfileRunRepository
    {
        /** @var ProfileRunRepository $profileRuns */
        $profileRuns = $this->entityManager->getRepository(ProfileRun::class);

        return $profileRuns;
    }
}
```

`backend/tests/Service/Worker/AdvanceProfileRunsHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\ProfileRun;
use App\Enum\ProfileRunTrigger;
use App\Enum\RunStatus;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Run\WorkerPresence;
use App\Service\Worker\Handler\AdvanceProfileRunsHandler;
use App\Service\Worker\Message\AdvanceProfileRuns;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class AdvanceProfileRunsHandlerTest extends DbTestCase
{
    use SeedsUsers;

    public function testAFiringTicksTheActiveProfileRunAsTheWorker(): void
    {
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $owner = $this->user('advance-profile-runs@example.test');
        (new RecommendationRunFixtures($this->entityManager, $cipher))->seedReadyAiSettingsFor($owner, 'qwen3-14b');
        $profileRun = new ProfileRun($owner, ProfileRunTrigger::Manual, new \DateTimeImmutable('2026-10-03 09:00:00'));
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();
        $profileRunId = $profileRun->requireId();

        $this->handler()(new AdvanceProfileRuns());

        self::assertSame(RunStatus::Completed, $this->entityManager->find(ProfileRun::class, $profileRunId)?->getStatus());
        self::assertTrue($this->presence()->hasPersistentRecommendationWorker());
    }

    private function handler(): AdvanceProfileRunsHandler
    {
        /** @var AdvanceProfileRunsHandler $handler */
        $handler = self::getContainer()->get(AdvanceProfileRunsHandler::class);

        return $handler;
    }

    private function presence(): WorkerPresence
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        return $presence;
    }
}
```

`backend/tests/Service/Worker/StartDueProfileRunsHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\ProfileRun;
use App\Entity\ProfileSettingsValues;
use App\Enum\ProfileRunTrigger;
use App\Repository\ProfileRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;
use App\Service\Worker\Handler\StartDueProfileRunsHandler;
use App\Service\Worker\Message\StartDueProfileRuns;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class StartDueProfileRunsHandlerTest extends DbTestCase
{
    use SeedsUsers;

    public function testAFiringStartsTheDueAccountsRunWithoutAdvancingIt(): void
    {
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $owner = $this->user('start-due-profile-runs@example.test');
        (new RecommendationRunFixtures($this->entityManager, $cipher))->seedReadyAiSettingsFor($owner, 'qwen3-14b');
        /** @var RecommendationSettingsWriter $writer */
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        $writer->saveProfileSettings($owner, new ProfileSettingsValues(12, null, 40, 80));

        /** @var StartDueProfileRunsHandler $handler */
        $handler = self::getContainer()->get(StartDueProfileRunsHandler::class);
        $handler(new StartDueProfileRuns());

        /** @var ProfileRunRepository $profileRuns */
        $profileRuns = $this->entityManager->getRepository(ProfileRun::class);
        $started = $profileRuns->findActiveForUser($owner);
        self::assertNotNull($started);
        self::assertSame(ProfileRunTrigger::Scheduled, $started->getTrigger());
        self::assertSame(RunStatus::Pending, $started->getStatus());
    }
}
```

Run: `php bin/phpunit tests/Service/Recommendation/Profile/ProfileRunSweepTest.php tests/Service/Worker/AdvanceProfileRunsHandlerTest.php tests/Service/Worker/StartDueProfileRunsHandlerTest.php`
Expected: errors, the classes are missing.

- [ ] **Step 4: The sweep, the worker sweep, the messages and handlers, the schedule**

`backend/src/Service/Recommendation/Profile/ProfileRunSweep.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Enum\ProfileRunTrigger;
use App\Repository\ProfileRunRepository;
use App\Service\Recommendation\Run\Model\TickDriver;
use Psr\Log\LoggerInterface;

/** Scheduled profiles: start the due runs, and tick every active one, for the cron sweep, the worker and the drainer. */
final readonly class ProfileRunSweep
{
    public function __construct(
        private DueProfileRunFinder $finder,
        private ProfileRunStarter $starter,
        private ProfileRunAdvancer $advancer,
        private ProfileRunRepository $profileRuns,
        private LoggerInterface $logger,
    ) {
    }

    public function startDueRuns(): int
    {
        $due = $this->finder->due();
        foreach ($due as $user) {
            $this->starter->start($user, ProfileRunTrigger::Scheduled);
        }

        return \count($due);
    }

    /** Counts attempted runs, failed ones included: the drain command loops until a pass attempts none. */
    public function advanceEveryActiveRun(TickDriver $driver): int
    {
        $profileRuns = $this->profileRuns->findAllActive();
        foreach ($profileRuns as $profileRun) {
            $this->advanceOne($profileRun, $driver);
        }

        return \count($profileRuns);
    }

    public function activeRunCount(): int
    {
        return \count($this->profileRuns->findAllActive());
    }

    private function advanceOne(ProfileRun $profileRun, TickDriver $driver): void
    {
        try {
            $this->advancer->advance($profileRun->getUser(), $driver);
        } catch (\Throwable $exception) {
            // The floor: a tick records every provider failure itself, so whatever lands here is unexpected.
            $this->logger->error('Profile sweep: unexpected failure advancing a profile run.', [
                'profileRunId' => $profileRun->getId(),
                'exception' => $exception,
            ]);
        }
    }
}
```

`backend/src/Service/Worker/WorkerProfileRunSweep.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Service\Recommendation\Profile\ProfileRunSweep;
use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\SweepStreamHeartbeat;
use App\Service\Recommendation\Run\WorkerPresence;
use Doctrine\ORM\EntityManagerInterface;

/** One worker-regime pass over every active profile run, for the worker's firing and the drain command. */
final readonly class WorkerProfileRunSweep
{
    public function __construct(
        private ProfileRunSweep $profileRuns,
        private WorkerPresence $presence,
        private SweepStreamHeartbeat $heartbeat,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function sweep(RecommendationDriverKind $kind): int
    {
        $this->heartbeat->sweepStarted($kind);

        try {
            $this->presence->mark($kind);

            return $this->profileRuns->advanceEveryActiveRun(TickDriver::Worker);
        } finally {
            $this->entityManager->clear();
            $this->heartbeat->sweepEnded();
        }
    }
}
```

Messages (each in its own file under `backend/src/Service/Worker/Message/`):

```php
<?php

declare(strict_types=1);

namespace App\Service\Worker\Message;

final readonly class StartDueProfileRuns
{
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Worker\Message;

final readonly class AdvanceProfileRuns
{
}
```

`backend/src/Service/Worker/Handler/StartDueProfileRunsHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Recommendation\Profile\ProfileRunSweep;
use App\Service\Worker\Message\StartDueProfileRuns;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Only starts due profile runs; ticking them is AdvanceProfileRuns' job. */
#[AsMessageHandler]
final readonly class StartDueProfileRunsHandler
{
    public function __construct(private ProfileRunSweep $profileRuns)
    {
    }

    public function __invoke(StartDueProfileRuns $message): void
    {
        $this->profileRuns->startDueRuns();
    }
}
```

`backend/src/Service/Worker/Handler/AdvanceProfileRunsHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use App\Service\Worker\Message\AdvanceProfileRuns;
use App\Service\Worker\WorkerProfileRunSweep;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AdvanceProfileRunsHandler
{
    public function __construct(private WorkerProfileRunSweep $sweep)
    {
    }

    public function __invoke(AdvanceProfileRuns $message): void
    {
        $this->sweep->sweep(RecommendationDriverKind::PersistentWorker);
    }
}
```

`AdvanceRecommendationRunsHandler`'s docblock loses "The only place the persistent worker's liveness key is claimed:"; it reads `Runs one WorkerRunSweep per ten-second firing and claims the persistent worker's liveness key, which the settings card reads to tell whether an install still needs a cron.`

In `WorkerSchedule::getSchedule()`, after the last entry (`VerifyPendingImages`) — see the amendment below:

```php
            ->add(RecurringMessage::every('10 seconds', new AdvanceProfileRuns()))
            ->add(RecurringMessage::every('5 minutes', new StartDueProfileRuns()))
```

In `WorkerScheduleWiringTest::testTheWorkerScheduleCarriesExactlyTheDecidedEntries`: count 9; append `AdvanceProfileRuns::class, StartDueProfileRuns::class,` to the class list and `'every 10 seconds', 'every 5 minutes',` to the frequency list. If the file's catch-up tests (`MessageGenerator` over a `MockClock`) count generated messages, raise their expected counts by the two new entries' firings in the same window and report the arithmetic.

Run the Step 3 tests and `php bin/phpunit tests/Service/Worker`. Expected: OK.

- [ ] **Step 5: Write the failing driver tests (cron sweep, drainer, terminate listener, reset)**

In `backend/tests/Service/Recommendation/Run/ForYouSweepTest.php`:

1. `sweepMarkingWith()` builds `ForYouSweep` by hand: add `$this->service(ProfileRunSweep::class),` as the fourth argument (after the advancer), matching the constructor order in Step 6.
2. Append:

```php
    public function testSweepOnceStartsAndTicksDueProfileRunsToo(): void
    {
        $owner = $this->user('sweep-profile@example.test');
        $this->fixtures->seedReadyAiSettings($owner);
        /** @var RecommendationSettingsWriter $writer */
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        $writer->saveProfileSettings($owner, new ProfileSettingsValues(6, null, 40, 80));

        $report = $this->sweep()->sweepOnce();

        self::assertSame(1, $report->startedProfileRuns);
        self::assertSame(1, $report->advancedProfileRuns);
        self::assertSame(0, $report->activeProfileRuns);
        self::assertSame(0, $report->startedRuns);
    }
```

The owner has no reading history, so its run completes without a model call in the one tick the sweep gives it. Imports: `App\Entity\ProfileSettingsValues`, `App\Service\Recommendation\Profile\ProfileRunSweep`.

`backend/tests/Http/ForYouSweepReportJsonTest.php`: the expected array becomes `['startedRuns' => 2, 'advancedRuns' => 3, 'activeRuns' => 1, 'startedProfileRuns' => 4, 'advancedProfileRuns' => 5, 'activeProfileRuns' => 6]` over `new ForYouSweepReportModel(2, 3, 1, 4, 5, 6)`. `backend/tests/Http/MaintenanceTickJsonTest.php`: the `recommendations` arrays gain `'startedProfileRuns' => 0, 'advancedProfileRuns' => 0, 'activeProfileRuns' => 0` (the first case builds `new ForYouSweepReportModel(1, 2, 3)` and keeps it — the three new fields default to 0; the `skipped` key stays last in the second case).

`backend/tests/EventListener/RecommendationDrainOnTerminateListenerTest.php`, append (import `App\Entity\ProfileRun`, `App\Enum\ProfileRunTrigger`):

```php
    public function testAnActiveProfileRunAloneSpawnsTheDrainer(): void
    {
        $this->entityManager->persist(
            new ProfileRun($this->user, ProfileRunTrigger::Manual, new \DateTimeImmutable('2026-10-03 09:00:00')),
        );
        $this->entityManager->flush();

        $request = $this->healthRequest();
        $response = $this->bootedKernel->handle($request);
        $this->bootedKernel->terminate($request, $response);

        self::assertSame([[RecommendationDrainSpawner::DRAIN_COMMAND, '--detach']], $this->launcher->launches);
    }
```

`backend/tests/Command/RecommendationDrainCommandTest.php`: `commandWith()` passes the container's `WorkerProfileRunSweep` as the new third constructor argument (Step 6's order), and append:

```php
    public function testTheDrainerTicksAProfileRunWithNoRecommendationRunActive(): void
    {
        $owner = $this->user('drain-profile@example.test');
        $this->fixtures->seedReadyAiSettings($owner);
        $profileRun = new ProfileRun($owner, ProfileRunTrigger::Manual, new \DateTimeImmutable('2026-10-03 09:00:00'));
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();
        $profileRunId = $profileRun->requireId();

        (new CommandTester($this->commandWith($this->lockFactory())))->execute([]);

        $this->entityManager->clear();
        self::assertSame(RunStatus::Completed, $this->entityManager->find(ProfileRun::class, $profileRunId)?->getStatus());
    }
```

`drainOwner()` stands for the test class's own way to create a user with a ready AI connection (it has one for its run cases; reuse it, or add one built from `UserFactory` and `RecommendationRunFixtures::seedReadyAiSettings()`). Imports: `App\Entity\ProfileRun`, `App\Enum\ProfileRunTrigger`, `App\Enum\RunStatus`, `Symfony\Component\Console\Tester\CommandTester` where missing.

`backend/tests/Service/Account/AccountResetTest.php`: in `seedAccount()`, also persist a profile run with one log row (`RecommendationRunLog::forProfileRun($profileRun, 1, '{}', new \DateTimeImmutable('2026-08-05T00:00:00Z'))`) and return it as a fifth element; in `testWipesEverythingTheUserOwns`, add:

```php
        self::assertSame([], $this->entityManager->getRepository(ProfileRun::class)->findBy(['user' => $userId]));
        self::assertSame(
            [],
            $this->entityManager->getRepository(RecommendationRunLog::class)->findBy(['profileRun' => $profileRunId]),
        );
```

with `$profileRunId = $profileRun->requireId();` taken before the reset. Destructuring sites of `seedAccount()` that take four elements keep working (a fifth element is ignored).

Run: `php bin/phpunit tests/Service/Recommendation/Run/ForYouSweepTest.php tests/Http/ForYouSweepReportJsonTest.php tests/Http/MaintenanceTickJsonTest.php tests/EventListener/RecommendationDrainOnTerminateListenerTest.php tests/Command/RecommendationDrainCommandTest.php tests/Service/Account/AccountResetTest.php`
Expected: failures for each new case (unknown properties, no spawn, the profile run still pending, profile rows surviving the reset).

- [ ] **Step 6: The drivers cover profile runs**

`ForYouSweepReportModel`:

```php
/**
 * One For You sweep: the runs it started, the active runs it advanced one tick, and the runs still active after, for
 * recommendation runs and profile runs apart.
 */
final readonly class ForYouSweepReportModel
{
    public function __construct(
        public int $startedRuns,
        public int $advancedRuns,
        public int $activeRuns,
        public int $startedProfileRuns = 0,
        public int $advancedProfileRuns = 0,
        public int $activeProfileRuns = 0,
    ) {
    }
}
```

`ForYouSweepReportJson::report()` (and its `@return` shape) adds `'startedProfileRuns'`, `'advancedProfileRuns'`, `'activeProfileRuns'` in that order after `activeRuns`.

`ForYouSweep`: add `private ProfileRunSweep $profileSweep,` to the constructor after `$advancer` (constructor order: `$finder, $starter, $advancer, $profileSweep, $runs, $presence, $heartbeat, $entityManager, $logger`) and replace `sweepOnce()` and `advanceEveryActiveRunAsTheDriver()`:

```php
    public function sweepOnce(): ForYouSweepReportModel
    {
        $startedRuns = $this->startDueRuns();
        $startedProfileRuns = $this->profileSweep->startDueRuns();
        [$advancedRuns, $advancedProfileRuns] = $this->advanceEveryActiveRunAsTheDriver();

        // The identity map is per-sweep state, not request state; clear it so
        // the remaining-active counts below are a fresh read from the database.
        $this->entityManager->clear();

        return new ForYouSweepReportModel(
            startedRuns: $startedRuns,
            advancedRuns: $advancedRuns,
            activeRuns: \count($this->runs->findAllActive()),
            startedProfileRuns: $startedProfileRuns,
            advancedProfileRuns: $advancedProfileRuns,
            activeProfileRuns: $this->profileSweep->activeRunCount(),
        );
    }

    /**
     * Marks the cron key before each run and beats it mid-call. The key is surrendered in `finally` and, for a request
     * the gateway kills (Strato's 240 s cap), by a shutdown hook: a stale key would keep the poll tick and the drain
     * spawner from recovering the run for FRESH_SECONDS.
     *
     * @return array{int, int} the recommendation runs, then the profile runs, it advanced
     */
    private function advanceEveryActiveRunAsTheDriver(): array
    {
        $advancedRuns = 0;
        $this->surrenderTheCronSweepKeyIfTheRequestIsKilled();
        $this->heartbeat->sweepStarted(RecommendationDriverKind::CronSweep);

        try {
            foreach ($this->runs->findAllActive() as $run) {
                $this->presence->mark(RecommendationDriverKind::CronSweep);
                $advancedRuns += $this->advanceOne($run);
            }
            $this->presence->mark(RecommendationDriverKind::CronSweep);
            $advancedProfileRuns = $this->profileSweep->advanceEveryActiveRun(TickDriver::Sweep);
        } finally {
            $this->heartbeat->sweepEnded();
            $this->surrenderTheCronSweepKey();
        }

        return [$advancedRuns, $advancedProfileRuns];
    }
```

The class docblock adds: `Profile runs ride along: sweepOnce() also starts the due ones and ticks every active one.`

`RecommendationDrainCommand`: add `private readonly WorkerProfileRunSweep $profileSweep,` as the third constructor parameter (after `$sweep`) and change the loop condition in `drainUntilDoneOrCapped()` to:

```php
        while ($this->sweepOnceMore() > 0) {
```

with

```php
    /** Both kinds of run, so a profile run with no recommendation run beside it still drains. */
    private function sweepOnceMore(): int
    {
        return $this->sweep->sweep(RecommendationDriverKind::OnDemandDrainer)
            + $this->profileSweep->sweep(RecommendationDriverKind::OnDemandDrainer);
    }
```

Update the command's description to `'Advance all active recommendation and profile runs until none is left'`.

`RecommendationDrainOnTerminateListener`: inject `ProfileRunRepository $profileRuns` after `$runs`, and the guard becomes:

```php
            if (!$this->runs->hasActiveRun() && !$this->profileRuns->hasActiveRun()) {
                return;
            }
```

`AccountWipeRepository::deleteRecommendationData()`, after `$this->deleteByUser(RecommendationRun::class, $user);`:

```php
        $this->entityManager->createQuery(sprintf(
            'DELETE FROM %s c WHERE IDENTITY(c.profileRun) IN (SELECT p.id FROM %s p WHERE p.user = :user)',
            RecommendationRunLog::class,
            ProfileRun::class,
        ))->setParameter('user', $user)->execute();
        $this->deleteByUser(ProfileRun::class, $user);
```

Run the Step 5 tests. Expected: OK.

- [ ] **Step 7: Deletion checks**

1. In `DueProfileRunFinder::isDue()`, drop the `usableFor()` guard. `testSkippedWithoutAConnectionThatCanBuildTheProfile` must fail. Restore.
2. In `DueProfileRunFinder::isDue()`, change `>=` to `>`. `testDueExactlyOneIntervalLater` must fail. Restore.
3. In `ForYouSweep::advanceEveryActiveRunAsTheDriver()`, drop the profile-sweep line (return `[$advancedRuns, 0]`). `testSweepOnceStartsAndTicksDueProfileRunsToo` must fail. Restore.
4. In `RecommendationDrainOnTerminateListener`, drop the profile-run condition. `testAnActiveProfileRunAloneSpawnsTheDrainer` must fail. Restore.
5. In `RecommendationDrainCommand::sweepOnceMore()`, drop the profile half. `testTheDrainerTicksAProfileRunWithNoRecommendationRunActive` must fail. Restore.
6. In `AccountWipeRepository`, drop the `deleteByUser(ProfileRun::class, …)` line. The reset test must fail. Restore.
7. In `WorkerSchedule`, drop the `AdvanceProfileRuns` entry. `WorkerScheduleWiringTest` must fail. Restore.

- [ ] **Step 8: Gates, commit**

```bash
php bin/phpunit tests/Service tests/Command tests/EventListener tests/Http tests/Controller/MaintenanceControllerTest.php tests/Repository
composer cs && composer stan && composer md && composer tramp
git add backend/src backend/tests
git commit -m "feat(#1351): profile runs start on their schedule and every driver ticks them"
```

*Amended (Task 4 execution):*
1. `findWithProfileInterval()` filters on `s.profileTuning.intervalHours`: since Task 1 the schedule lives in the `ProfileTuning` embeddable, and `RecommendationSettings` has no `profileIntervalHours` field.
2. The two schedule entries go at the end of `WorkerSchedule`, not after `StartDueRecommendationRuns`. The stateful checkpoint stores `(time, index)` into the recurring-message list, and an insertion would shift the index of every entry after it across the deploy, so one firing could be skipped or repeated. The docblock gains `New entries go last: the checkpoint stores a position in this list, which an insertion would shift.` `debug:scheduler` after the deploy shows the new entries on the existing anchor. The catch-up tests count only `AdvanceRecommendationRuns` and `PurgeFailedMessages`, so their counts do not change.
3. Carried from Task 3: `ProfileRunAdvancer::advance()` checks `findActiveForUser()` before it takes the lock and checks again under it. A profile tick for an account with no active run never takes the shared lock, so a recommendation tick never answers `busy` because of it. Pinned by `ProfileRunAdvancerTest::testWithoutAnActiveProfileRunTheLockIsNeverTaken` (a recording lock factory sees no lock).
4. All three `services_test.yaml` entries from Task 3 are deleted. The drivers now take these services, and every test still fetches them from the test container.
5. `StartDueProfileRunsHandlerTest` uses `assertNotNull` and `->`, comparing against `RunStatus::Pending` (PHPStan `nullsafe.neverNull`). `drainOwner()` became `$this->user()` plus `seedReadyAiSettings()`. Long lines are wrapped.
6. `AccountResetTest::testDoesNotTouchAnotherUsersRows` also checks that the bystander's profile run and its log row survive. This pins the profile-log subquery's correlation by user.

---
### Task 5: Recommendation runs lose their distillation and read a frozen copy of the stored profile

This is the simplification the issue exists for. The run keeps exactly one profile fact — the text it froze at its snapshot — and every distillation state, branch and borrowing goes. The net line count of `src/Service/Recommendation/{Run,Llm,Jev}` and `src/Entity/{RecommendationRun,RunProfile,RecommendationRunProgress}.php` must drop; quote `git diff --stat` for those paths in the task report.

**Files:**
- Create: `backend/migrations/Version20261003110000.php`
- (Done in Task 3, amendment 2.) Move: `backend/src/Service/Recommendation/Llm/Run/Model/ProfileDistillationOutcomeModel.php` → `backend/src/Service/Recommendation/Profile/Model/ProfileDistillationOutcomeModel.php` (its test to `backend/tests/Service/Recommendation/Profile/Model/`)
- Modify: `backend/src/Entity/RecommendationRun.php`, `RunProfile.php`, `RecommendationRunProgress.php`, `backend/src/Enum/RecommendationEngineKind.php`
- Modify: `backend/src/Service/Recommendation/Run/SnapshotPhase.php`, `TickPhases.php`, `TickLockTtl.php`, `Pass/TickContext.php`, `Factory/TickContextFactory.php`, `Model/CallSlotModel.php`
- Modify: `backend/src/Service/Recommendation/Llm/LlmRecommendationEngine.php`, `Llm/Run/InvalidReplyRetry.php` (docblock), `backend/src/Service/Recommendation/Jev/JevRecommendationEngine.php`
- Modify (Task 3 amendment 1: the imports are already in place; `Profile/ProfileDistiller.php` is `Llm/Run/LlmProfileRunDistiller.php`): `backend/config/services.yaml` (drop the `ProfileDistillerInterface` alias)
- Delete: `backend/src/Service/Recommendation/Llm/Run/ProviderPhase/DistillationPhase.php`, `Llm/Run/RecommendationProfileDistiller.php`, `Profile/ProfileDistiller/ProfileDistillerInterface.php`, `Jev/JevProfileStep.php`, `Run/Model/BorrowedProfileModel.php`; tests `tests/Service/Recommendation/Jev/JevProfileStepTest.php`, `tests/Service/Recommendation/Llm/Run/RecommendationProfileDistillerTest.php`
- Test: `backend/tests/Entity/RecommendationRunTest.php`, `RecommendationRunProgressTest.php`, `backend/tests/Enum/RecommendationEngineKindTest.php`, `backend/tests/Service/Recommendation/Run/SnapshotPhaseTest.php`, `backend/tests/Service/Recommendation/Jev/JevRecommendationEngineTest.php`, `backend/tests/Service/Recommendation/Run/TickLockTtlTest.php`, plus the fallout in Step 9.

**Interfaces:**
- Consumes (Tasks 1–3): `RecommendationRunFixtures::storeProfile()`; `EffectiveRecommendationSettingsModel::$profileText` (now read from `StoredProfile`).
- Produces:
  - `RecommendationRun::freezeProfile(?string $profileText): void` (pending only); `getProfileText(): ?string`. `recordProfile()` and `isDistilled()` are gone.
  - `RecommendationRunProgress::forBatchPlan(?array $candidateBatches, int $batchesDone, int $attempts, RecommendationEngineKind $engineKind)`; no `distillPending`.
  - `RecommendationEngineKind::Llm->phases()` = `[Batch, Consolidate]`, `Jev->phases()` = `[Batch]`.
  - `JevRecommendationEngine::NO_PROFILE`.
  - `TickContext` without `$borrowedProfile`, `borrowingProfileFrom()`, `profileTick()`, `connectionInFlight()`; `TickLockTtl::secondsFor()` reads only the active connection.
  - `recommendation_run.distilled` is dropped.

- [ ] **Step 1: Write the failing entity, progress and engine-kind tests**

In `backend/tests/Entity/RecommendationRunTest.php`:

1. The two `batchesTotal` assertions change: `[[1, 2], [3]]` gives `3` (`// 2 batches + consolidate`), `[[1, 2, 3]]` gives `2` (`// 1 batch + consolidate`).
2. Delete every test that calls `recordProfile()` or `isDistilled()`.
3. Append:

```php
    public function testAProfileFrozenBeforeTheSnapshotIsTheOneTheRunReads(): void
    {
        $run = $this->pendingRun();

        $run->freezeProfile('Likes rail and maps.');
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);

        self::assertSame('Likes rail and maps.', $run->getProfileText());
    }

    public function testARunningRunCannotFreezeAnotherProfile(): void
    {
        $run = $this->pendingRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);

        $this->expectException(InvalidRunStatusException::class);
        $run->freezeProfile('Later profile.');
    }
```

`pendingRun()` stands for however the test class builds a fresh run; if it has none, add `private function pendingRun(): RecommendationRun { return new RecommendationRun(new User('run@example.test', new \DateTimeImmutable('2026-10-01 06:00:00')), new \DateTimeImmutable('2026-10-03 09:00:00')); }`.

Replace `backend/tests/Entity/RecommendationRunProgressTest.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\RecommendationRunProgress;
use App\Enum\RecommendationEngineKind;
use PHPUnit\Framework\TestCase;

final class RecommendationRunProgressTest extends TestCase
{
    public function testConsolidationRunsEvenForASingleBatch(): void
    {
        self::assertTrue(
            RecommendationRunProgress::forBatchPlan([[1, 2, 3]], 1, 0, RecommendationEngineKind::Llm)
                ->isConsolidationPhase,
        );
    }

    public function testConsolidationWaitsUntilAllBatchesAreDone(): void
    {
        self::assertFalse(
            RecommendationRunProgress::forBatchPlan([[1], [2]], 1, 0, RecommendationEngineKind::Llm)
                ->isConsolidationPhase,
        );
    }

    public function testConsolidationNeverStartsWithoutAPlan(): void
    {
        self::assertFalse(
            RecommendationRunProgress::forBatchPlan(null, 0, 0, RecommendationEngineKind::Llm)->isConsolidationPhase,
        );
    }

    public function testTheLlmTotalCountsTheBatchesAndTheConsolidation(): void
    {
        self::assertSame(
            3,
            RecommendationRunProgress::forBatchPlan([[1], [2]], 0, 0, RecommendationEngineKind::Llm)->batchesTotal,
        );
    }

    public function testThePlanCountsItsBatchesAloneAndARunWithoutOneCountsNone(): void
    {
        $planned = RecommendationRunProgress::forBatchPlan([[1], [2]], 0, 0, RecommendationEngineKind::Llm);
        $unplanned = RecommendationRunProgress::forBatchPlan(null, 0, 0, RecommendationEngineKind::Llm);

        self::assertSame(2, $planned->batchCount);
        self::assertNull($unplanned->batchCount);
    }

    public function testAJevPlanCountsOnlyItsBatchesAndHasNoConsolidation(): void
    {
        $pending = RecommendationRunProgress::forBatchPlan([[1], [2], [3]], 0, 0, RecommendationEngineKind::Jev);
        $done = RecommendationRunProgress::forBatchPlan([[1], [2], [3]], 3, 0, RecommendationEngineKind::Jev);

        self::assertSame(3, $pending->batchesTotal);
        self::assertTrue($done->allBatchCallsDone);
        self::assertFalse($done->isConsolidationPhase);
    }
}
```

In `backend/tests/Enum/RecommendationEngineKindTest.php` replace the phase tests with:

```php
    public function testTheLlmScoresInBatchesThenConsolidates(): void
    {
        self::assertSame([CallPhase::Batch, CallPhase::Consolidate], RecommendationEngineKind::Llm->phases());
        self::assertSame(1, RecommendationEngineKind::Llm->singleCallPhaseCount());
    }

    public function testRunsAsksThePhasePlan(): void
    {
        self::assertTrue(RecommendationEngineKind::Llm->runs(CallPhase::Consolidate));
        self::assertFalse(RecommendationEngineKind::Llm->runs(CallPhase::Distill));
    }

    public function testJevOnlyAsksInBatches(): void
    {
        self::assertSame([CallPhase::Batch], RecommendationEngineKind::Jev->phases());
        self::assertSame(0, RecommendationEngineKind::Jev->singleCallPhaseCount());
    }
```

Add to `backend/tests/Service/Recommendation/Run/SnapshotPhaseTest.php`:

```php
    public function testTheStoredProfileIsFrozenIntoTheRun(): void
    {
        $this->fixtures->storeProfile($this->owner, 'Likes rail and maps.');
        $this->fixtures->seedFeedWithEntries($this->owner, 2);
        $run = $this->pendingRun();

        $this->snapshot(ScriptedRecommendationEngine::packing([[1]]))->advance($this->tick($run));

        self::assertSame('Likes rail and maps.', $run->getProfileText());
    }
```

`tick()` reads the settings when it is built, so the profile is stored first.

Run: `php bin/phpunit tests/Entity/RecommendationRunTest.php tests/Entity/RecommendationRunProgressTest.php tests/Enum/RecommendationEngineKindTest.php tests/Service/Recommendation/Run/SnapshotPhaseTest.php`
Expected: `freezeProfile()` undefined, `forBatchPlan()` argument count, `batchesTotal` 4 ≠ 3, the phase lists, and the frozen profile `null`.

- [ ] **Step 2: The run keeps one profile fact**

`backend/src/Entity/RunProfile.php` becomes:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** The stored profile as this run froze it before its snapshot, so a later profile run never changes its scoring. */
#[ORM\Embeddable]
final class RunProfile
{
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $profileText = null;

    public function freeze(?string $profileText): void
    {
        $this->profileText = $profileText;
    }

    public function getProfileText(): ?string
    {
        return $this->profileText;
    }
}
```

`RecommendationRunProgress`: delete the `distillPending` property and the `$distilled` parameter; `isConsolidationPhase` becomes `$hasPlan && $allBatchCallsDone && $engineKind->runs(CallPhase::Consolidate)`; the factory is `forBatchPlan(?array $candidateBatches, int $batchesDone, int $attempts, RecommendationEngineKind $engineKind): self`.

`RecommendationEngineKind::phases()`:

```php
    /** @return list<CallPhase> the phases a run of this kind calls the provider in, in order */
    public function phases(): array
    {
        return match ($this) {
            self::Llm => [CallPhase::Batch, CallPhase::Consolidate],
            self::Jev => [CallPhase::Batch],
        };
    }
```

`CallPhase::Distill` stays: profile-run log rows carry it.

In `RecommendationRun`: `getProgress()` drops `$this->isDistilled(),`; replace `recordProfile()`, `getProfileText()` and `isDistilled()` with:

```php
    public function freezeProfile(?string $profileText): void
    {
        $this->guardStatus(RunStatus::Pending, 'freeze a profile on');
        $this->runProfile->freeze($profileText);
    }

    public function getProfileText(): ?string
    {
        return $this->runProfile->getProfileText();
    }
```

and the class docblock's second sentence becomes `The candidate batches and the profile freeze at snapshot(), so a resume retries the exact failed batch; the reading history is read fresh.`

`SnapshotPhase::advance()` freezes before it snapshots (the empty-pool branch stays first and freezes nothing):

```php
        $run->freezeProfile($tick->settings->profileText);
        $run->snapshot(
            $tick->engineKind,
            $this->engines->engineOf($tick->engineKind)->packBatches($candidates, $tick),
        );
```

Its docblock: `Freezes a pending run's candidate pool into its engine's batches, and the stored profile into the run, without a provider call; an empty pool completes at once.`

Run the Step 1 tests. Expected: OK.

- [ ] **Step 3: The engines lose their distillation**

`LlmRecommendationEngine`: drop the `DistillationPhase` parameter and import; `providerPhaseFor()` becomes

```php
    private function providerPhaseFor(RecommendationRun $run): ProviderPhaseInterface
    {
        return $run->getProgress()->isConsolidationPhase ? $this->consolidation : $this->batch;
    }
```

and its docblock `The chat-completion engine: packs by the context window, then scores in batches and consolidates, against the profile the run froze.`

`JevRecommendationEngine`: the `JevProfileStep $profileStep` parameter becomes `RecommendationRunFailure $runFailure` (import `App\Service\Recommendation\Run\RecommendationRunFailure`), and:

```php
    public const string NO_PROFILE = 'Jev needs your reading profile, and there is no reading history to build one '
        . 'from yet. Read, keep or favourite a few articles, then start a new run.';
```

```php
    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        $run = $tick->run;
        if (null === $run->getProfileText()) {
            return $this->runFailure->fail($run, self::NO_PROFILE);
        }
        if ($run->getProgress()->allBatchCallsDone) {
            return $this->finalizer->finalize($run, $this->ranker->ranked($run->getWinners()));
        }

        return $this->batchWavePhase->advance(
            $tick,
            fn (array $batches): BatchWaveResultModel => $this->rounds->resolve(
                $this->wave,
                $this->waveOf($tick, $batches),
            ),
        );
    }
```

Delete `DistillationPhase.php`, `RecommendationProfileDistiller.php`, `ProfileDistillerInterface.php` (and its emptied folder), `JevProfileStep.php`, `BorrowedProfileModel.php`, the `ProfileDistillerInterface` line in `backend/config/services.yaml`, `CallSlotModel::distillation()` and its case in `CallSlotModelTest`. `git mv` the outcome model to `Service/Recommendation/Profile/Model/` (namespace `App\Service\Recommendation\Profile\Model`, docblock `What a profile run's model call settled to: the profile text, or the unusable reply it retries.`) and its test likewise; fix the imports in `ProfileDistiller` and `ProfileGeneration`. `InvalidReplyRetry`'s docblock: `The cross-tick retry consolidation uses; the batch phase retries inside its tick.`

`TickContext`: delete `$borrowedProfile`, `borrowingProfileFrom()`, `profileTick()` and `connectionInFlight()`. `TickPhases::advanceWithinTheEnvelope()` records the transport failure with `$tick->connection`. `TickContextFactory`: drop the `ProfileConnectionResolver` parameter and the borrowing branch; `create()` returns the plain `new TickContext(…)`. `TickLockTtl`: drop the `ProfileConnectionResolver` parameter; `secondsFor()` becomes `return $this->secondsForConnection($this->configurator->settingsFor($user));`.

In `backend/tests/Service/Recommendation/Run/TickLockTtlTest.php`, delete the three tests that seed `seedProfileConnectionFor()` (the recommendation tick no longer calls a profile connection; `ProfileRunAdvancerTest` pins the profile tick's TTL) and keep the standard-bound and slow-active-connection cases.

- [ ] **Step 4: Jev engine tests**

In `backend/tests/Service/Recommendation/Jev/JevRecommendationEngineTest.php`:

1. Replace the "absent profile connection" test (around line 258) with:

```php
    public function testARunWithoutAProfileFailsWithTheReasonAndNeverAsksSystemOne(): void
    {
        $run = $this->runningJevRun(frozenProfile: null);

        $report = $this->engine()->advance($this->jevTick($run));

        self::assertSame('failed', $report->status);
        self::assertSame(JevRecommendationEngine::NO_PROFILE, $report->error);
        self::assertSame([], $this->systemOne()->requests());
    }
```

2. The frozen-profile test (around line 276) builds its run with `freezeProfile(self::PROFILE)` before `snapshot()` instead of distilling, and keeps its assertion on the wave's `state.profile`.
3. The end-to-end flow (around line 306) stores `self::PROFILE` with `$this->fixtures->storeProfile($this->owner, self::PROFILE)` before the run starts and drops the queued distill reply and its advance: the first advance snapshots, the next is the warm-up wave.

`runningJevRun()`, `jevTick()`, `engine()` and `systemOne()->requests()` stand for the class's own builders and stub accessors; give the run builder a `?string $frozenProfile` parameter that calls `freezeProfile()` before `snapshot()`.

Run: `php bin/phpunit tests/Service/Recommendation/Jev/JevRecommendationEngineTest.php`
Expected: OK.

- [ ] **Step 5: The migration drops the column**

`backend/migrations/Version20261003110000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** A run that was mid-distillation at the deploy snapshots on with whatever profile_text it holds. */
final class Version20261003110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recommendation runs no longer record a distillation (#1351).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('recommendation_run')->hasColumn('distilled'),
            'recommendation_run.distilled is already gone.',
        );

        $this->addSql($this->mysql()
            ? 'ALTER TABLE recommendation_run DROP distilled'
            : 'ALTER TABLE recommendation_run DROP COLUMN distilled');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('recommendation_run')->hasColumn('distilled'),
            'recommendation_run.distilled already exists.',
        );

        $this->addSql($this->mysql()
            ? 'ALTER TABLE recommendation_run ADD distilled TINYINT(1) DEFAULT 0 NOT NULL'
            : 'ALTER TABLE recommendation_run ADD COLUMN distilled BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE recommendation_run SET distilled = 1 WHERE candidate_batches IS NOT NULL');
    }

    /** Refuses any platform but the two supported ones: better a refusal than DDL nobody tested. */
    private function mysql(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        return $platform instanceof AbstractMySQLPlatform;
    }
}
```

Migrate from empty on both platforms, validate, then `prev` and back up — the commands of Task 1 Steps 13–14 without a seed. Expected: `Version20261003110000` reached and the schema in sync on both.

- [ ] **Step 6: Deletion checks**

1. In `SnapshotPhase::advance()`, drop `$run->freezeProfile(…)`. `testTheStoredProfileIsFrozenIntoTheRun` must fail. Restore.
2. In `RecommendationRun::freezeProfile()`, drop the status guard. `testARunningRunCannotFreezeAnotherProfile` must fail. Restore.
3. In `JevRecommendationEngine::advance()`, drop the no-profile guard. `testARunWithoutAProfileFailsWithTheReasonAndNeverAsksSystemOne` must fail on the wave's `LogicException`. Restore.
4. In `RecommendationEngineKind::phases()`, put `CallPhase::Distill` back first for the LLM. The engine-kind and progress tests must fail. Restore.

- [ ] **Step 7: Fallout in the run tests**

Run `php bin/phpunit tests/Service/Recommendation tests/Service/Worker tests/Controller/Api/RecommendationRunControllerTest.php tests/Command tests/Repository tests/Http` and rework each failure by these rules:

1. **A test that drove a run through "snapshot, then distil".** Store a profile before the run starts (`$this->fixtures->storeProfile($user, 'a distilled profile')`, or a private helper in the class where it has its own fixture access), drop the queued distill reply and its advance. Every later `calls()` index and count drops by one; the `// distill` comments go. In `RecommendationRunAdvancerTest`, `startSnapshotAndDistill()` becomes:

```php
    private function startAndSnapshot(): RecommendationRun
    {
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user);
        $run = $this->activeRun();

        self::assertSame(3, $run->getProgress()->batchesTotal); // 2 batches + consolidate
        self::assertCount(2, $run->getCandidateBatches());
        self::assertCount(10, $run->getCandidateBatches()[0]);
        self::assertCount(10, $run->getCandidateBatches()[1]);

        return $run;
    }
```

   and `queueDistillReply()` goes once nothing calls it.
2. **A test where the one provider call that mattered was the distillation** (the worker fairness tests in `AdvanceRecommendationRunsHandlerTest`, the controller's tick tests): store a profile first, drop the distill reply, and let the first batch call be the call under test (queue `{"recommendations":[]}`); assert with `getProgress()->batchesDone` or `hasFirstBatchStarted()` instead of `distillPending`.
3. **A test of the distillation phase itself** (its transport failure, deferring 429, unusable reply, degrade-to-no-profile, the profile written to the settings; the borrowed profile connection in `TickPhasesTest`, `TickContextTest`, `TickContextFactoryTest`, `JevPipelineTest`): delete it. List each deleted test with the `ProfileRunTickTest` case that covers the behaviour now, or "behaviour removed" for the borrowing, in the task report.
4. **`batchesTotal`**: an LLM plan of *n* batches counts `n + 1`, a Jev plan `n`.
5. **ETA history** (`RecommendationEtaEstimatorTest`, `PhaseDurationsModelTest`, `RecommendationRunTimingRepositoryTest`): delete the `CallPhase::Distill` log rows and spans from the seeded history and keep every expected number — the distill span moves into the time between calls, which the model already adds (e.g. 10 s distill + 3 × 10 s + 30 s = 70 s either way). Rename the `distill:` parameters `pickup:`.
6. **`recordProfile()` callers** (`WaveContextLoaderTest`): give the run builder a `?string $frozenProfile` parameter and call `freezeProfile()` before `snapshot()`.

A failure that fits no rule: stop and report it.

- [ ] **Step 8: Gates, live migration, commit**

```bash
php bin/phpunit
composer cs && composer stan && composer md && composer tramp
git diff --stat develop -- src/Service/Recommendation/Run src/Service/Recommendation/Llm src/Service/Recommendation/Jev src/Entity/RecommendationRun.php src/Entity/RunProfile.php src/Entity/RecommendationRunProgress.php
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
git status
git add -A backend
git commit -m "refactor(#1351): recommendation runs read a frozen copy of the stored profile and no longer distil"
```

Check `git status` before `git add -A backend` that nothing outside this task is staged.

---
### Task 6: A run without a stored profile asks the profile module for one and waits in `pending`

The only coupling from recommendation-run code to the profile module: one interface the profile module owns, `ProfileForRunInterface`, consumed by `SnapshotPhase` (to freeze a profile, start one, or fail) and by `RecommendationRunStatusResolver` (to say "building your profile"). No run state, column or migration is added: the run stays `pending`; the profile module decides what "a profile run since this run started" means.

**Files:**
- Create: `backend/src/Service/Recommendation/Profile/ProfileForRun/ProfileForRunInterface.php`, `backend/src/Service/Recommendation/Profile/ProfileForRun/ProvisionedProfileForRun.php`
- Create: `backend/src/Service/Recommendation/Profile/Model/RunProfileModel.php`, `backend/src/Service/Recommendation/Profile/Model/RunProfileState.php`
- Modify: `backend/src/Service/Recommendation/Run/SnapshotPhase.php`, `Model/RecommendationRunReportModel.php`, `RecommendationRunStarter.php` (resume refuses a never-snapshotted run), `backend/src/Entity/RecommendationRun.php` (`isResumable()`)
- Modify: `backend/src/Service/Recommendation/Feed/RecommendationRunStatusResolver.php`, `backend/src/Http/RecommendationRunStatusJson.php`
- Modify (frontend): `frontend/src/app/reader/models.ts`, `frontend/src/app/reader/list/for-you-progress/for-you-progress.component.{ts,html,spec.ts}`, `frontend/public/i18n/{en,de}.json`
- Test: create `backend/tests/Service/Recommendation/Profile/ProfileForRun/ProvisionedProfileForRunTest.php`; modify `backend/tests/Service/Recommendation/Run/SnapshotPhaseTest.php`, `RecommendationRunStarterTest.php`, `backend/tests/Http/RecommendationRunStatusJsonTest.php`, `backend/tests/Controller/Api/RecommendationRunControllerTest.php`, `backend/tests/Entity/RecommendationRunTest.php`

**Interfaces:**
- Consumes (Tasks 1–5): `RecommendationSettingsRepository::findForUser()`, `StoredProfile::getText()`, `ProfileRunRepository::findLatestForUser()`, `findActiveForUser()`, `ProfileRunStarter::start()`, `RecommendationRun::freezeProfile()`.
- Produces:
  - `ProfileForRunInterface::profileFor(User $user, \DateTimeImmutable $runCreatedAt): RunProfileModel` — the stored profile; else the outcome of the newest profile run started since `$runCreatedAt`; else it starts one (`recommendation` trigger) and answers `building`.
  - `ProfileForRunInterface::isBuildingFor(User $user): bool`.
  - `enum RunProfileState { Ready, Building, Failed }`; `RunProfileModel::ready(?string)`, `building()`, `failed(string)`, public `state`, `text`, `error`.
  - `SnapshotPhase::PROFILE_FAILED` (`'Profile generation failed: %s'`).
  - `RecommendationRunReportModel::waitingForProfile(): self` and `$waitingForProfile`; status JSON key `waitingForProfile`.
  - `RecommendationRun::isResumable(): bool`.

- [ ] **Step 1: Write the failing profile-side test**

`backend/tests/Service/Recommendation/Profile/ProfileForRun/ProvisionedProfileForRunTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile\ProfileForRun;

use App\Entity\ProfileRun;
use App\Entity\User;
use App\Enum\ProfileRunOutcome;
use App\Enum\ProfileRunTrigger;
use App\Repository\ProfileRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Profile\Model\RunProfileState;
use App\Service\Recommendation\Profile\ProfileForRun\ProfileForRunInterface;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class ProvisionedProfileForRunTest extends DbTestCase
{
    use SeedsUsers;

    private const string RUN_CREATED_AT = '2026-10-03 09:00:00';

    private RecommendationRunFixtures $fixtures;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('profile-for-run@example.test');
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');
    }

    public function testAStoredProfileIsReadyAndStartsNothing(): void
    {
        $this->fixtures->storeProfile($this->owner, 'Likes rail and maps.');

        $profile = $this->profiles()->profileFor($this->owner, $this->runCreatedAt());

        self::assertSame(RunProfileState::Ready, $profile->state);
        self::assertSame('Likes rail and maps.', $profile->text);
        self::assertNull($this->profileRuns()->findLatestForUser($this->owner));
    }

    public function testWithoutAProfileItStartsAProfileRunForTheRecommendationAndSaysBuilding(): void
    {
        $profile = $this->profiles()->profileFor($this->owner, $this->runCreatedAt());

        self::assertSame(RunProfileState::Building, $profile->state);
        self::assertSame(
            ProfileRunTrigger::Recommendation,
            $this->profileRuns()->findActiveForUser($this->owner)?->getTrigger(),
        );
        self::assertTrue($this->profiles()->isBuildingFor($this->owner));
    }

    public function testAnActiveProfileRunFromBeforeTheRunIsWaitedOnNotDuplicated(): void
    {
        $earlier = $this->profileRunAt('2026-10-03 08:00:00');

        $profile = $this->profiles()->profileFor($this->owner, $this->runCreatedAt());

        self::assertSame(RunProfileState::Building, $profile->state);
        self::assertSame($earlier->getId(), $this->profileRuns()->findLatestForUser($this->owner)?->getId());
    }

    public function testAProfileRunThatFailedSinceTheRunStartedIsAFailure(): void
    {
        $this->profileRunAt('2026-10-03 09:00:30')->fail('The AI provider at x failed: y', new \DateTimeImmutable('2026-10-03 09:01:00'));
        $this->entityManager->flush();

        $profile = $this->profiles()->profileFor($this->owner, $this->runCreatedAt());

        self::assertSame(RunProfileState::Failed, $profile->state);
        self::assertSame('The AI provider at x failed: y', $profile->error);
    }

    public function testAProfileRunThatFailedBeforeTheRunStartedIsRetried(): void
    {
        $failed = $this->profileRunAt('2026-10-03 08:00:00');
        $failed->fail('old failure', new \DateTimeImmutable('2026-10-03 08:01:00'));
        $this->entityManager->flush();

        $profile = $this->profiles()->profileFor($this->owner, $this->runCreatedAt());

        self::assertSame(RunStatus::Pending, $this->profileRuns()->findLatestForUser($this->owner)?->getStatus());
        self::assertSame(RunProfileState::Building, $profile->state);
    }

    public function testAProfileRunThatFoundNoHistoryLetsTheRunGoOnWithoutAProfile(): void
    {
        $profileRun = $this->profileRunAt('2026-10-03 09:00:30');
        $profileRun->start('fingerprint', 'api.example.test', 'qwen3-14b');
        $profileRun->complete(ProfileRunOutcome::NoHistory, new \DateTimeImmutable('2026-10-03 09:00:31'));
        $this->entityManager->flush();

        $profile = $this->profiles()->profileFor($this->owner, $this->runCreatedAt());

        self::assertSame(RunProfileState::Ready, $profile->state);
        self::assertNull($profile->text);
        self::assertFalse($this->profiles()->isBuildingFor($this->owner));
    }

    private function profileRunAt(string $createdAt): ProfileRun
    {
        $profileRun = new ProfileRun($this->owner, ProfileRunTrigger::Scheduled, new \DateTimeImmutable($createdAt));
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        return $profileRun;
    }

    private function runCreatedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::RUN_CREATED_AT);
    }

    private function profiles(): ProfileForRunInterface
    {
        /** @var ProfileForRunInterface $profiles */
        $profiles = self::getContainer()->get(ProfileForRunInterface::class);

        return $profiles;
    }

    private function profileRuns(): ProfileRunRepository
    {
        /** @var ProfileRunRepository $profileRuns */
        $profileRuns = $this->entityManager->getRepository(ProfileRun::class);

        return $profileRuns;
    }
}
```

Import `App\Enum\RunStatus`. The interface has one implementation, so Symfony autowires the alias; if the test container cannot `get()` it (private, inlined), add `public: true` for the alias in `config/services_test.yaml` with the comment `# Public: ProvisionedProfileForRunTest fetches the interface, and its consumers inline it.`

Run: `php bin/phpunit tests/Service/Recommendation/Profile/ProfileForRun`
Expected: error, the interface is missing.

- [ ] **Step 2: The interface, its model and its implementation**

`backend/src/Service/Recommendation/Profile/Model/RunProfileState.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Model;

enum RunProfileState
{
    case Ready;
    case Building;
    case Failed;
}
```

`backend/src/Service/Recommendation/Profile/Model/RunProfileModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Model;

/** What a recommendation run may freeze: a profile (or none, when there is no history), not yet, or a failure. */
final readonly class RunProfileModel
{
    private function __construct(
        public RunProfileState $state,
        public ?string $text,
        public ?string $error,
    ) {
    }

    public static function ready(?string $text): self
    {
        return new self(RunProfileState::Ready, $text, null);
    }

    public static function building(): self
    {
        return new self(RunProfileState::Building, null, null);
    }

    public static function failed(string $error): self
    {
        return new self(RunProfileState::Failed, null, $error);
    }
}
```

`backend/src/Service/Recommendation/Profile/ProfileForRun/ProfileForRunInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\ProfileForRun;

use App\Entity\User;
use App\Service\Recommendation\Profile\Model\RunProfileModel;

/** The profile module's one face to recommendation runs: the current profile, or a profile run on its way. */
interface ProfileForRunInterface
{
    /** Starts a profile run when there is no profile and none has run since $runCreatedAt. */
    public function profileFor(User $user, \DateTimeImmutable $runCreatedAt): RunProfileModel;

    public function isBuildingFor(User $user): bool;
}
```

`backend/src/Service/Recommendation/Profile/ProfileForRun/ProvisionedProfileForRun.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\ProfileForRun;

use App\Entity\User;
use App\Enum\ProfileRunTrigger;
use App\Enum\RunStatus;
use App\Repository\ProfileRunRepository;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Recommendation\Profile\Model\RunProfileModel;
use App\Service\Recommendation\Profile\ProfileRunStarter;

final readonly class ProvisionedProfileForRun implements ProfileForRunInterface
{
    public function __construct(
        private RecommendationSettingsRepository $recommendationSettings,
        private ProfileRunRepository $profileRuns,
        private ProfileRunStarter $starter,
    ) {
    }

    public function profileFor(User $user, \DateTimeImmutable $runCreatedAt): RunProfileModel
    {
        $stored = $this->recommendationSettings->findForUser($user)?->getStoredProfile()->getText();
        if (null !== $stored) {
            return RunProfileModel::ready($stored);
        }

        $latest = $this->profileRuns->findLatestForUser($user);
        if (null === $latest || ($latest->getStatus()->isTerminal() && $latest->getCreatedAt() < $runCreatedAt)) {
            $this->starter->start($user, ProfileRunTrigger::Recommendation);

            return RunProfileModel::building();
        }

        return match ($latest->getStatus()) {
            RunStatus::Failed => RunProfileModel::failed($latest->getError() ?? ''),
            RunStatus::Completed => RunProfileModel::ready(null),
            RunStatus::Pending, RunStatus::Running, RunStatus::Cancelled => RunProfileModel::building(),
        };
    }

    public function isBuildingFor(User $user): bool
    {
        return null !== $this->profileRuns->findActiveForUser($user);
    }
}
```

A profile run never reaches `cancelled`; the arm only keeps the `match` exhaustive. Run the Step 1 test. Expected: OK (6 tests).

- [ ] **Step 3: Write the failing run-side tests**

In `backend/tests/Service/Recommendation/Run/SnapshotPhaseTest.php`, `snapshot()` passes the container's `ProfileForRunInterface` and `RecommendationRunFailure` as the two new last arguments (in that order), and these tests are appended (imports `App\Entity\ProfileRun`, `App\Repository\ProfileRunRepository`):

```php
    public function testWithoutAStoredProfileTheRunStaysPendingWhileAProfileRunStarts(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 2);
        $engine = ScriptedRecommendationEngine::packing([[1]]);
        $run = $this->pendingRun();

        $this->snapshot($engine)->advance($this->tick($run));

        self::assertSame(RunStatus::Pending, $run->getStatus());
        self::assertSame([], $engine->packedCandidates);
        self::assertNotNull($this->profileRuns()->findActiveForUser($this->owner));
    }

    public function testAFailedProfileRunFailsTheWaitingRunWithItsError(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 2);
        $run = $this->pendingRun();
        $phase = $this->snapshot(ScriptedRecommendationEngine::packing([[1]]));
        $phase->advance($this->tick($run));
        $this->profileRuns()->findActiveForUser($this->owner)
            ?->fail('No connection can build your profile.', new \DateTimeImmutable('2026-10-03 09:01:00'));
        $this->entityManager->flush();

        $phase->advance($this->tick($run));

        self::assertSame(RunStatus::Failed, $run->getStatus());
        self::assertSame('Profile generation failed: No connection can build your profile.', $run->getError());
    }

    private function profileRuns(): ProfileRunRepository
    {
        /** @var ProfileRunRepository $profileRuns */
        $profileRuns = $this->entityManager->getRepository(ProfileRun::class);

        return $profileRuns;
    }
```

The three tests that expect a plan (`testTheResolvedEnginesBatchesBecomeTheRunsPlan`, `testThePlanRecordsTheKindItWasPackedFor`, `testAJevTickRecordsTheJevKindWithAPlan`) begin with `$this->fixtures->storeProfile($this->owner, 'a stored profile');` — before `tick()` — or they would now wait; `testTheStoredProfileIsFrozenIntoTheRun` already stores one. The empty-pool tests stay unchanged: an empty pool completes before any profile is asked for, so `testAnEmptyPoolCompletesWithoutAskingTheEngine` also gains `self::assertNull($this->profileRuns()->findLatestForUser($this->owner));`.

The run is created by `createRun()` at `2026-08-08T10:00:00Z` and the profile run by the starter at the real now, so "since the run started" holds.

In `backend/tests/Http/RecommendationRunStatusJsonTest.php` add `'waitingForProfile' => false` to each exact expected array (also in `RecommendationRunControllerTest`), and:

```php
    public function testAWaitingReportSaysItWaitsForItsProfile(): void
    {
        $json = RecommendationRunStatusJson::report($this->statusOf(RecommendationRunReportModel::none()->waitingForProfile()));

        self::assertTrue($json['waitingForProfile']);
    }
```

`statusOf()` stands for the class's own wrapper of a report in a `RecommendationRunStatusModel`.

In `backend/tests/Controller/Api/RecommendationRunControllerTest.php` add (reusing its auth and fixture helpers; `$fixtures`, `auth()` and `payload()` stand for them):

```php
    public function testTheStatusOfARunWaitingForItsProfileSaysSo(): void
    {
        $client = static::createClient();
        [$headers, $user] = $this->auth('runs-waiting-profile@example.test');
        $this->fixtures()->seedSingleBatchFixture($user);

        $client->request('POST', '/api/recommendations/runs', server: $headers);
        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame('pending', $this->payload($client)['status']);
        self::assertTrue($this->payload($client)['waitingForProfile']);
    }
```

In `backend/tests/Entity/RecommendationRunTest.php`:

```php
    public function testOnlyARunThatFailedAfterItsSnapshotIsResumable(): void
    {
        $neverSnapshotted = $this->pendingRun();
        $neverSnapshotted->fail('Profile generation failed: gone', new \DateTimeImmutable('2026-10-03 09:05:00'));
        $snapshotted = $this->pendingRun();
        $snapshotted->snapshot(RecommendationEngineKind::Llm, [[1]]);
        $snapshotted->fail('The AI provider at x failed: y', new \DateTimeImmutable('2026-10-03 09:05:00'));

        self::assertFalse($neverSnapshotted->isResumable());
        self::assertTrue($snapshotted->isResumable());
    }
```

In `backend/tests/Service/Recommendation/Run/RecommendationRunStarterTest.php` (reusing its helpers):

```php
    public function testARunThatFailedBeforeItsSnapshotIsNotResumed(): void
    {
        $run = $this->fixtures->createRun($this->user);
        $run->fail('Profile generation failed: gone', new \DateTimeImmutable('2026-10-03 09:05:00'));
        $this->entityManager->flush();

        $this->expectException(NoResumableRecommendationRunException::class);
        $this->starter()->resume($this->user);
    }
```

Run: `php bin/phpunit tests/Service/Recommendation/Run/SnapshotPhaseTest.php tests/Http/RecommendationRunStatusJsonTest.php tests/Controller/Api/RecommendationRunControllerTest.php tests/Entity/RecommendationRunTest.php tests/Service/Recommendation/Run/RecommendationRunStarterTest.php`
Expected: failures — the run snapshots without a profile, no `waitingForProfile`, `isResumable()` missing.

- [ ] **Step 4: The run asks, waits or fails**

`SnapshotPhase` gains `private ProfileForRunInterface $profiles` and `private RecommendationRunFailure $runFailure` as its last constructor parameters; after the empty-pool branch, `advance()` continues:

```php
        $profile = $this->profiles->profileFor($run->getUser(), $run->getCreatedAt());

        return match ($profile->state) {
            RunProfileState::Building => RecommendationRunReportModel::fromRun($run),
            RunProfileState::Failed => $this->runFailure->fail($run, \sprintf(self::PROFILE_FAILED, $profile->error)),
            RunProfileState::Ready => $this->snapshotWith($tick, $candidates, $profile->text),
        };
    }

    /** @param list<ArticleLineModel> $candidates */
    private function snapshotWith(TickContext $tick, array $candidates, ?string $profileText): RecommendationRunReportModel
    {
        $run = $tick->run;
        $run->freezeProfile($profileText);
        $run->snapshot(
            $tick->engineKind,
            $this->engines->engineOf($tick->engineKind)->packBatches($candidates, $tick),
        );
        $this->entityManager->flush();

        return RecommendationRunReportModel::fromRun($run);
    }
```

with `public const string PROFILE_FAILED = 'Profile generation failed: %s';` and the docblock sentence `A run without a stored profile stays pending while the profile module builds one.` The `$tick->settings->profileText` read from Task 5 goes: the profile now comes only through the interface.

`RecommendationRunReportModel`: add `public bool $waitingForProfile = false,` as the last constructor parameter; `inBackground()` and `waitingForLock()` carry it (`waitingForProfile: $this->waitingForProfile,`), and add:

```php
    /** A pending run whose account has a profile run in flight: the run starts once the profile is there. */
    public function waitingForProfile(): self
    {
        return new self(
            $this->status,
            $this->batchesTotal,
            $this->batchesDone,
            $this->error,
            background: $this->background,
            waitingForLock: $this->waitingForLock,
            streamedChars: $this->streamedChars,
            start: $this->start,
            plan: $this->plan,
            waitingForProfile: true,
        );
    }
```

`RecommendationRunStatusResolver::forReport()` marks it, so a plain status read says it too (constructor gains `private ProfileForRunInterface $profiles`):

```php
    public function forReport(RecommendationRunReportModel $report, User $user): RecommendationRunStatusModel
    {
        return new RecommendationRunStatusModel(
            $this->withProfileWait($report, $user),
            $this->forYouSummaries->forUser($user),
            $this->clock->now(),
            $this->etaEstimator->estimateSeconds($report, $user),
        );
    }

    private function withProfileWait(RecommendationRunReportModel $report, User $user): RecommendationRunReportModel
    {
        return RunStatus::Pending->value === $report->status && $this->profiles->isBuildingFor($user)
            ? $report->waitingForProfile()
            : $report;
    }
```

`RecommendationRunStatusJson::report()` adds `'waitingForProfile' => $report->waitingForProfile,` after `'waitingForLock'`, and its docblock `` `waitingForProfile`: a pending run waits on a profile run. ``

`RecommendationRun`:

```php
    /** A run that failed before its snapshot has nothing to resume: a new run asks for a profile again. */
    public function isResumable(): bool
    {
        return RunStatus::Failed === $this->status && null !== $this->candidateBatches;
    }
```

`RecommendationRunStarter::resume()`'s guard becomes `if (null === $latest || !$latest->isResumable()) {` (message unchanged: `There is no failed run to resume.`).

Who ticks the profile run while the recommendation run waits: the profile drivers of Task 4 — the worker's `AdvanceProfileRuns`, the cron sweep, and the drainer the terminate listener spawns for an active profile run. A worker-less host that cannot spawn processes waits for its cron, as it does for recommendation runs.

Run the Step 3 tests and `php bin/phpunit tests/Service/Recommendation tests/Controller/Api tests/Http`. Expected: OK. A test whose run now waits because it stored no profile gets `storeProfile()` per Task 5's fallout rule 1.

- [ ] **Step 5: "Building your profile…" in the For You toast**

`frontend/src/app/reader/models.ts`, in `RecommendationRunReport` after `waitingForLock`:

```ts
  /** True while a pending run waits for its profile to be built. Optional for older-backend responses. */
  readonly waitingForProfile?: boolean;
```

`for-you-progress.component.ts` adds:

```ts
  protected readonly buildingProfile = computed(() => this.recs.report()?.waitingForProfile === true);
```

`for-you-progress.component.html` wraps the count line:

```html
@if (recs.running()) {
  @if (buildingProfile()) {
    <p class="for-you-progress">{{ 'reader.forYouBuildingProfile' | transloco }}</p>
  } @else {
    <p class="for-you-progress">
      <span>{{ 'reader.forYouProgress' | transloco: count() }}</span>
      @if (eta(); as phrase) {
        <!-- Hidden from the toast's live region on purpose: below a minute the
             estimate changes on every ticker bump, and announcing it each time
             would bury the count that actually moves once per batch (#398). -->
        <span class="eta" aria-hidden="true"> · {{ phrase.key | transloco: phrase.params }}</span>
      }
    </p>
  }
  <div
    class="track"
    role="progressbar"
    aria-valuemin="0"
    aria-valuemax="100"
    [attr.aria-valuenow]="percent()"
  >
    <span [style.width.%]="percent()"></span>
  </div>
}
```

i18n, under `reader` next to `forYouProgress`: en `"forYouBuildingProfile": "Building your profile…"`, de `"forYouBuildingProfile": "Dein Profil wird erstellt …"`.

Spec (`for-you-progress.component.spec.ts`):

```ts
  it('says the profile is being built while the run waits for it', () => {
    report.set(makeReport({ status: 'pending', waitingForProfile: true }));
    const element = build().nativeElement as HTMLElement;

    expect(element.querySelector('.for-you-progress')!.textContent).toContain('Building your profile');
    expect(element.textContent).not.toContain('4 of 24');
  });
```

Run: `docker compose exec -T frontend npm test -- for-you-progress`
Expected: the new case fails first (before the template change), then passes.

- [ ] **Step 6: Deletion checks**

1. In `ProvisionedProfileForRun::profileFor()`, drop the `$latest->getCreatedAt() < $runCreatedAt` part (any terminal run counts). `testAProfileRunThatFailedBeforeTheRunStartedIsRetried` must fail. Restore.
2. In `SnapshotPhase`, map `Failed` to `fromRun($run)`. `testAFailedProfileRunFailsTheWaitingRunWithItsError` must fail. Restore.
3. In `RecommendationRunStatusResolver::withProfileWait()`, return `$report`. `testTheStatusOfARunWaitingForItsProfileSaysSo` must fail. Restore.
4. In `RecommendationRun::isResumable()`, drop the batches condition. `testOnlyARunThatFailedAfterItsSnapshotIsResumable` must fail. Restore.
5. In `for-you-progress.component.html`, drop the `buildingProfile()` branch. The spec case must fail. Restore.

- [ ] **Step 7: Gates, commit**

```bash
php bin/phpunit tests/Service/Recommendation tests/Controller/Api tests/Http tests/Entity
composer cs && composer stan && composer md && composer tramp
docker compose exec -T frontend npm run check
git add backend frontend
git commit -m "feat(#1351): a run without a stored profile waits in pending while the profile module builds one"
```

---
### Task 7: The profile API

**Files:**
- Create: `backend/src/Controller/Api/ProfileController.php`
- Create: `backend/src/Dto/Recommendation/SaveProfileSettingsRequest.php`
- Create: `backend/src/Service/Recommendation/Profile/ProfileSettingsProvider.php`, `ProfileSettingsEditor.php`, `ProfileDebugLogLoader.php`
- Create: `backend/src/Service/Recommendation/Profile/Model/ProfileSettingsModel.php`, `ProfileSettingsChangeModel.php`, `ProfileDebugLogModel.php`; `backend/src/Service/Recommendation/Profile/Support/ProfileSchedule.php`
- Create: `backend/src/Http/ProfileSettingsJson.php`, `backend/src/Http/ProfileRunJson.php`
- Modify: `backend/src/Http/RecommendationDebugLogJson.php` (`profileRunLog()`), `backend/src/Http/RecommendationSettingsJson.php` (drops `profileText`, `keptCap`, `viewedCap`), `backend/src/Service/Recommendation/Settings/Support/RecommendationSettingsBounds.php` (`PROFILE_FIELDS`)
- Modify: `backend/src/Http/Problem/ExceptionProblems/RecommendationRunProblems.php`, `backend/config/packages/rate_limiter.yaml`
- Test: create `backend/tests/Controller/Api/ProfileControllerTest.php`, `backend/tests/Http/ProfileSettingsJsonTest.php`, `backend/tests/Http/ProfileRunJsonTest.php`; modify `backend/tests/Http/RecommendationSettingsJsonTest.php`, `backend/tests/Controller/Api/RecommendationSettingsControllerTest.php`

**Interfaces:**
- Consumes (Tasks 1–6): `ProfileConnections`, `ProfileRunStarter::startManually()`, `ProfileRunRepository::findLatestForUser()`, `RecommendationRunLogRepository::listForProfileRun()`/`streamingTextForProfileRun()`, `RecommendationSettingsWriter::saveProfileSettings()`, `RecommendationSettingsRepository::findForUser()`, `AiConfigurationForUser::require()`, `ProfileConnectionRejectedException` (exists), `ProfileConnectionMissingException`.
- Produces (HTTP, all bearer-authenticated, JSON in, `application/problem+json` out):
  - `GET /api/me/ai/profile` (`api_me_ai_profile_show`) and `PUT /api/me/ai/profile` (`api_me_ai_profile_save`, body `{intervalHours: null|6|12|24|48|168, connectionId: int|null, keptCap: int, viewedCap: int}`), both answering the state below.
  - `POST /api/me/ai/profile/runs` (`api_me_ai_profile_runs_start`, limiter `ai_profile_runs`: 10 per hour per user) and `GET /api/me/ai/profile/runs/current` (`api_me_ai_profile_runs_current`), both answering one run.
  - `GET /api/me/ai/profile/runs/current/log` (`api_me_ai_profile_runs_log`): `{entries: DebugLogEntry[]}` of the newest profile run.
  - State: `{profileText, generatedAt, generatedBy: {providerHost, model}|null, intervalHours, intervalChoices, connectionId, connection: {id, name, baseUrl, model}|null, candidates: [{id, name, baseUrl, model}], keptCap, viewedCap, defaults: {keptCap, viewedCap}, bounds: {keptCap: {min, max}, viewedCap: {min, max}}, debugEnabled}`.
  - Run: `{status: 'none'|'pending'|'running'|'completed'|'failed', id, trigger, outcome, error, createdAt, completedAt, providerHost, model, attempts, maxAttempts, transportFailures, maxTransportFailures, streamedChars}`.
  - Problems: `422 profile_connection_rejected` (exists), `422 profile_connection_missing`, `404` for another account's connection, `429 rate_limited`.

Architecture §6 checklist for the five routes: bearer token only (the `/api` firewall); stateless; JSON in and out, errors as `application/problem+json`; no `Origin`/`Referer`/CSRF/browser widget; no redirect; no links in the payload. Every box holds.

- [ ] **Step 1: Write the failing controller test**

`backend/tests/Controller/Api/ProfileControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ProfileControllerTest extends ApiTestCase
{
    private const string URI = '/api/me/ai/profile';

    /** Must match framework.rate_limiter.ai_profile_runs.limit in rate_limiter.yaml. */
    private const int START_BUDGET = 10;

    protected function setUp(): void
    {
        // The limiter counts in a filesystem pool that outlives the test, so a prior case's spend would trip a 429.
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    public function testANewAccountReadsTheDefaultsWithItsActiveConnection(): void
    {
        $client = static::createClient();
        [$headers, $user] = $this->auth('profile-show@example.test');
        $active = $this->fixtures()->seedReadyAiSettingsFor($user, 'qwen3-14b');

        $client->request('GET', self::URI, server: $headers);

        self::assertResponseIsSuccessful();
        $state = $this->payload($client);
        self::assertNull($state['profileText']);
        self::assertNull($state['generatedAt']);
        self::assertNull($state['intervalHours']);
        self::assertSame([null, 6, 12, 24, 48, 168], $state['intervalChoices']);
        self::assertNull($state['connectionId']);
        self::assertSame($active->getId(), $state['connection']['id'] ?? null);
        self::assertSame([$active->getId()], array_column($state['candidates'], 'id'));
        self::assertSame(40, $state['keptCap']);
        self::assertSame(80, $state['viewedCap']);
        self::assertSame(['min' => 0, 'max' => 500], $state['bounds']['keptCap']);
        self::assertFalse($state['debugEnabled']);
    }

    public function testSavingPersistsTheScheduleTheConnectionAndTheCaps(): void
    {
        $client = static::createClient();
        [$headers, $user] = $this->auth('profile-save@example.test');
        $this->fixtures()->seedReadyAiSettingsFor($user, 'qwen3-14b');
        $chosen = $this->fixtures()->seedInactiveAiSettingsFor($user, 'gpt-4o');

        $this->put($client, $headers, ['intervalHours' => 48, 'connectionId' => $chosen->getId(), 'keptCap' => 7, 'viewedCap' => 9]);
        self::assertResponseIsSuccessful();
        $client->request('GET', self::URI, server: $headers);

        $state = $this->payload($client);
        self::assertSame(48, $state['intervalHours']);
        self::assertSame($chosen->getId(), $state['connectionId']);
        self::assertSame($chosen->getId(), $state['connection']['id'] ?? null);
        self::assertSame(7, $state['keptCap']);
        self::assertSame(9, $state['viewedCap']);
    }

    public function testAConnectionThatCannotBuildAProfileIsRefused(): void
    {
        $client = static::createClient();
        [$headers, $user] = $this->auth('profile-jev@example.test');
        $jev = $this->fixtures()->seedReadyAiSettingsFor($user, 'jev-latest');

        $this->put($client, $headers, ['intervalHours' => null, 'connectionId' => $jev->getId(), 'keptCap' => 40, 'viewedCap' => 80]);

        $this->assertRejected($client, 422);
        self::assertSame('profile_connection_rejected', $this->payload($client)['type']);
    }

    public function testAnotherAccountsConnectionReadsAsMissing(): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('profile-owner@example.test');
        [, $stranger] = $this->auth('profile-stranger@example.test');
        $theirs = $this->fixtures()->seedReadyAiSettingsFor($stranger, 'gpt-4o');

        $this->put($client, $headers, ['intervalHours' => null, 'connectionId' => $theirs->getId(), 'keptCap' => 40, 'viewedCap' => 80]);

        $this->assertRejected($client, 404);
    }

    public function testAScheduleOutsideTheChoicesIsAValidationError(): void
    {
        $client = static::createClient();
        [$headers, $user] = $this->auth('profile-interval@example.test');
        $this->fixtures()->seedReadyAiSettingsFor($user, 'qwen3-14b');

        $this->put($client, $headers, ['intervalHours' => 5, 'connectionId' => null, 'keptCap' => 40, 'viewedCap' => 80]);

        $this->assertRejected($client, 422);
    }

    public function testStartingOpensAManualRunAndASecondStartReturnsIt(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->auth('profile-start@example.test');
        $this->fixtures()->seedReadyAiSettingsFor($user, 'qwen3-14b');

        $client->request('POST', self::URI . '/runs', server: $headers);
        self::assertResponseIsSuccessful();
        $first = $this->payload($client);
        $client->request('POST', self::URI . '/runs', server: $headers);

        self::assertSame('pending', $first['status']);
        self::assertSame('manual', $first['trigger']);
        self::assertSame($first['id'], $this->payload($client)['id']);
        $client->request('GET', self::URI . '/runs/current', server: $headers);
        self::assertSame($first['id'], $this->payload($client)['id']);
    }

    public function testStartingWithoutAUsableConnectionIsRefused(): void
    {
        $client = static::createClient();
        [$headers, $user] = $this->auth('profile-start-jev@example.test');
        $this->fixtures()->seedReadyAiSettingsFor($user, 'jev-latest');

        $client->request('POST', self::URI . '/runs', server: $headers);

        $this->assertRejected($client, 422);
        self::assertSame('profile_connection_missing', $this->payload($client)['type']);
    }

    public function testAnAccountThatNeverRanReadsNoCurrentRun(): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('profile-current-none@example.test');

        $client->request('GET', self::URI . '/runs/current', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame('none', $this->payload($client)['status']);
        self::assertNull($this->payload($client)['id']);
    }

    public function testAStartBeyondTheHoursBudgetIsRateLimited(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->auth('profile-budget@example.test');
        $this->fixtures()->seedReadyAiSettingsFor($user, 'qwen3-14b');

        for ($spent = 1; $spent <= self::START_BUDGET; ++$spent) {
            $client->request('POST', self::URI . '/runs', server: $headers);
            self::assertResponseIsSuccessful(sprintf('Request %d was inside the budget.', $spent));
        }
        $client->request('POST', self::URI . '/runs', server: $headers);

        $this->assertRejected($client, 429);
        self::assertSame('rate_limited', $this->payload($client)['type']);
        // An hour's window, not ai_recommendation_starts' quarter hour: the limiters autowire by parameter name.
        self::assertGreaterThan(15 * 60, (int) $client->getResponse()->headers->get('Retry-After'));
    }

    public function testTheLogOfAnAccountWithoutProfileRunsIsEmpty(): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('profile-log@example.test');

        $client->request('GET', self::URI . '/runs/current/log', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(['entries' => []], $this->payload($client));
    }

    public function testEveryRouteNeedsAToken(): void
    {
        $client = static::createClient();

        $client->request('GET', self::URI);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     */
    private function put(KernelBrowser $client, array $headers, array $body): void
    {
        $client->request(
            'PUT',
            self::URI,
            server: array_merge($headers, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array{0: array<string, string>, 1: User} */
    private function auth(string $email): array
    {
        $user = $this->factory()->create($email);
        /** @var JWTTokenManagerInterface $tokens */
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    private function fixtures(): RecommendationRunFixtures
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);

        return new RecommendationRunFixtures($entityManager, $cipher);
    }
}
```

The `debugEnabled` key is the recommendation settings' switch (one switch keeps both logs). Run: `php bin/phpunit tests/Controller/Api/ProfileControllerTest.php`
Expected: 404s for every route.

- [ ] **Step 2: The models, the schedule choices, the provider and the editor**

`backend/src/Service/Recommendation/Profile/Support/ProfileSchedule.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Support;

/** The schedules a profile may run on, in hours; null is "only by hand". Shared by the validator and the JSON. */
final class ProfileSchedule
{
    public const array INTERVAL_CHOICES = [null, 6, 12, 24, 48, 168];

    private function __construct()
    {
    }
}
```

`backend/src/Service/Recommendation/Profile/Model/ProfileSettingsChangeModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Model;

/** What the profile section asks to store; the connection is still an id the editor resolves for the account. */
final readonly class ProfileSettingsChangeModel
{
    public function __construct(
        public ?int $intervalHours,
        public ?int $connectionId,
        public int $keptCap,
        public int $viewedCap,
    ) {
    }
}
```

`backend/src/Service/Recommendation/Profile/Model/ProfileSettingsModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Model;

use App\Entity\AiProviderSettings;
use App\Entity\ProfileSettingsValues;
use App\Entity\StoredProfile;

/** Everything the profile section shows: the profile, its settings, and which connections can build it. */
final readonly class ProfileSettingsModel
{
    /** @param list<AiProviderSettings> $candidates */
    public function __construct(
        public StoredProfile $storedProfile,
        public ProfileSettingsValues $values,
        public ?AiProviderSettings $effectiveConnection,
        public array $candidates,
        public bool $debugEnabled,
    ) {
    }
}
```

`backend/src/Service/Recommendation/Profile/Model/ProfileDebugLogModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Model;

use App\Repository\RecommendationRunLogRepository;

/**
 * The newest profile run's calls, for the profile section's debug panel.
 *
 * @phpstan-import-type DebugLogRow from RecommendationRunLogRepository
 */
final readonly class ProfileDebugLogModel
{
    /**
     * @param list<DebugLogRow>  $rows
     * @param array<int, string> $streamingTextById
     */
    public function __construct(public array $rows, public array $streamingTextById)
    {
    }

    public static function empty(): self
    {
        return new self([], []);
    }
}
```

`backend/src/Service/Recommendation/Profile/ProfileSettingsProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\ProfileSettingsValues;
use App\Entity\StoredProfile;
use App\Entity\User;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Recommendation\Profile\Model\ProfileSettingsModel;

final readonly class ProfileSettingsProvider
{
    public function __construct(
        private RecommendationSettingsRepository $recommendationSettings,
        private ProfileConnections $profileConnections,
    ) {
    }

    public function forUser(User $user): ProfileSettingsModel
    {
        $row = $this->recommendationSettings->findForUser($user);

        return new ProfileSettingsModel(
            $row?->getStoredProfile() ?? StoredProfile::none(),
            $row?->profileSettings() ?? ProfileSettingsValues::defaults(),
            $this->profileConnections->usableFor($user),
            $this->profileConnections->candidatesFor($user),
            $row?->values()->debugEnabled ?? false,
        );
    }
}
```

`backend/src/Service/Recommendation/Profile/ProfileSettingsEditor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\AiProviderSettings;
use App\Entity\ProfileSettingsValues;
use App\Entity\User;
use App\Service\Ai\AiConfigurationForUser;
use App\Service\Ai\Exception\ConfigurationNotFoundException;
use App\Service\Recommendation\Exception\ProfileConnectionRejectedException;
use App\Service\Recommendation\Profile\Model\ProfileSettingsChangeModel;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;

final readonly class ProfileSettingsEditor
{
    public const string REJECTION = 'Only a ready LLM connection can build your profile.';

    public function __construct(
        private AiConfigurationForUser $configurations,
        private ProfileConnections $profileConnections,
        private RecommendationSettingsWriter $writer,
    ) {
    }

    /**
     * @throws ConfigurationNotFoundException when the connection is not this account's
     * @throws ProfileConnectionRejectedException when it cannot build a profile
     */
    public function save(User $user, ProfileSettingsChangeModel $change): void
    {
        $this->writer->saveProfileSettings($user, new ProfileSettingsValues(
            $change->intervalHours,
            $this->connectionFor($user, $change->connectionId),
            $change->keptCap,
            $change->viewedCap,
        ));
    }

    private function connectionFor(User $user, ?int $connectionId): ?AiProviderSettings
    {
        if (null === $connectionId) {
            return null;
        }

        $connection = $this->configurations->require($user, $connectionId);
        if (!$this->profileConnections->canBuildProfiles($connection)) {
            throw new ProfileConnectionRejectedException(self::REJECTION);
        }

        return $connection;
    }
}
```

`ProfileConnectionChooser::REJECTION` carries the same sentence; Task 8 deletes the chooser, so the constant has one home afterwards.

`backend/src/Service/Recommendation/Profile/ProfileDebugLogLoader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\User;
use App\Repository\ProfileRunRepository;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Recommendation\Profile\Model\ProfileDebugLogModel;

final readonly class ProfileDebugLogLoader
{
    public function __construct(
        private ProfileRunRepository $profileRuns,
        private RecommendationRunLogRepository $logs,
    ) {
    }

    public function forUser(User $user): ProfileDebugLogModel
    {
        $latest = $this->profileRuns->findLatestForUser($user);
        if (null === $latest) {
            return ProfileDebugLogModel::empty();
        }

        $profileRunId = $latest->requireId();

        return new ProfileDebugLogModel(
            $this->logs->listForProfileRun($user, $profileRunId),
            $this->logs->streamingTextForProfileRun($user, $profileRunId),
        );
    }
}
```

`RecommendationSettingsBounds`: delete the `keptCap` and `viewedCap` entries from `EXPERT_FIELDS` and add

```php
    /** @var array<string, array{min: int, max: int}> */
    public const array PROFILE_FIELDS = [
        'keptCap' => ['min' => self::KEPT_CAP_MINIMUM, 'max' => self::KEPT_CAP_MAXIMUM],
        'viewedCap' => ['min' => self::VIEWED_CAP_MINIMUM, 'max' => self::VIEWED_CAP_MAXIMUM],
    ];
```

- [ ] **Step 3: The DTO, the mappers, the problem, the limiter, the controller**

`backend/src/Dto/Recommendation/SaveProfileSettingsRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Recommendation;

use App\Service\Recommendation\Profile\Model\ProfileSettingsChangeModel;
use App\Service\Recommendation\Profile\Support\ProfileSchedule;
use App\Service\Recommendation\Settings\Support\RecommendationSettingsBounds;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SaveProfileSettingsRequest
{
    public function __construct(
        #[Assert\Choice(choices: ProfileSchedule::INTERVAL_CHOICES)]
        public ?int $intervalHours,
        #[Assert\Positive]
        public ?int $connectionId,
        #[Assert\Range(
            min: RecommendationSettingsBounds::KEPT_CAP_MINIMUM,
            max: RecommendationSettingsBounds::KEPT_CAP_MAXIMUM,
        )]
        public int $keptCap,
        #[Assert\Range(
            min: RecommendationSettingsBounds::VIEWED_CAP_MINIMUM,
            max: RecommendationSettingsBounds::VIEWED_CAP_MAXIMUM,
        )]
        public int $viewedCap,
    ) {
    }

    public function toChange(): ProfileSettingsChangeModel
    {
        return new ProfileSettingsChangeModel($this->intervalHours, $this->connectionId, $this->keptCap, $this->viewedCap);
    }
}
```

`backend/src/Http/ProfileSettingsJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationSettings;
use App\Service\Recommendation\Profile\Model\ProfileSettingsModel;
use App\Service\Recommendation\Profile\Support\ProfileSchedule;
use App\Service\Recommendation\Settings\Support\RecommendationSettingsBounds;

/** The profile section's state. `connection` is the one that builds the profile; null means none can. */
final class ProfileSettingsJson
{
    /** @return array<string, mixed> */
    public static function state(ProfileSettingsModel $profile): array
    {
        $stored = $profile->storedProfile;

        return [
            'profileText' => $stored->getText(),
            'generatedAt' => $stored->getGeneratedAt()?->format(\DateTimeInterface::ATOM),
            'generatedBy' => null === $stored->getModel()
                ? null
                : ['providerHost' => $stored->getProviderHost(), 'model' => $stored->getModel()],
            'intervalHours' => $profile->values->intervalHours,
            'intervalChoices' => ProfileSchedule::INTERVAL_CHOICES,
            'connectionId' => $profile->values->connection?->getId(),
            'connection' => null === $profile->effectiveConnection ? null : self::connection($profile->effectiveConnection),
            'candidates' => array_map(self::connection(...), $profile->candidates),
            'keptCap' => $profile->values->keptCap,
            'viewedCap' => $profile->values->viewedCap,
            'defaults' => [
                'keptCap' => RecommendationSettings::DEFAULT_KEPT_CAP,
                'viewedCap' => RecommendationSettings::DEFAULT_VIEWED_CAP,
            ],
            'bounds' => RecommendationSettingsBounds::PROFILE_FIELDS,
            'debugEnabled' => $profile->debugEnabled,
        ];
    }

    /** @return array{id: ?int, name: ?string, baseUrl: string, model: ?string} */
    private static function connection(AiProviderSettings $connection): array
    {
        return [
            'id' => $connection->getId(),
            'name' => $connection->getName(),
            'baseUrl' => $connection->getBaseUrl(),
            'model' => $connection->getModel(),
        ];
    }

    private function __construct()
    {
    }
}
```

`backend/src/Http/ProfileRunJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\ProfileRun;

/** One profile run; an account that never ran one reads status `none` with every other field empty. */
final class ProfileRunJson
{
    /** @return array<string, mixed> */
    public static function current(?ProfileRun $profileRun): array
    {
        return null === $profileRun ? self::none() : self::run($profileRun);
    }

    /** @return array<string, mixed> */
    public static function run(ProfileRun $profileRun): array
    {
        return [
            'status' => $profileRun->getStatus()->value,
            'id' => $profileRun->getId(),
            'trigger' => $profileRun->getTrigger()->value,
            'outcome' => $profileRun->getOutcome()?->value,
            'error' => $profileRun->getError(),
            'createdAt' => $profileRun->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'completedAt' => $profileRun->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            'providerHost' => $profileRun->getProviderHost(),
            'model' => $profileRun->getModel(),
            'attempts' => $profileRun->getAttempts(),
            'maxAttempts' => ProfileRun::MAX_ATTEMPTS,
            'transportFailures' => $profileRun->getTransportFailures(),
            'maxTransportFailures' => ProfileRun::MAX_TRANSPORT_FAILURES,
            'streamedChars' => $profileRun->getStreamedChars(),
        ];
    }

    /** @return array<string, mixed> */
    private static function none(): array
    {
        return [
            'status' => 'none',
            'id' => null,
            'trigger' => null,
            'outcome' => null,
            'error' => null,
            'createdAt' => null,
            'completedAt' => null,
            'providerHost' => null,
            'model' => null,
            'attempts' => 0,
            'maxAttempts' => ProfileRun::MAX_ATTEMPTS,
            'transportFailures' => 0,
            'maxTransportFailures' => ProfileRun::MAX_TRANSPORT_FAILURES,
            'streamedChars' => 0,
        ];
    }

    private function __construct()
    {
    }
}
```

`RecommendationDebugLogJson`: extract the `entries` mapping of `list()` into a private static `entries(array $rows, array $streamingTextById): array` (same body) that `list()` calls, and add:

```php
    /** @return array{entries: list<array<string, mixed>>} */
    public static function profileRunLog(ProfileDebugLogModel $log): array
    {
        return ['entries' => self::entries($log->rows, $log->streamingTextById)];
    }
```

`RecommendationSettingsJson::state()`: delete the `profileText`, `keptCap`, `viewedCap` keys and the `keptCap`/`viewedCap` entries of `expertDefaults`; its docblock's "the distilled profile and," goes. Update `RecommendationSettingsJsonTest` (its expected arrays lose those keys, and the profile-text tests at lines 22–74 are deleted) and `RecommendationSettingsControllerTest` (`testAnUnconfiguredAccountReportsAllDefaults` loses the two cap assertions; `testSavingFullSettingsEchoesTheNewStateAndPersists` loses its two cap assertions and its payload loses the two keys).

`RecommendationRunProblems::resolve()` gains, beside the `ProfileConnectionRejectedException` arm (import `App\Service\Recommendation\Profile\Exception\ProfileConnectionMissingException`):

```php
            $exception instanceof ProfileConnectionMissingException => new ResolvedProblem(new ApiProblem(
                'profile_connection_missing',
                'No connection can build the profile',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
```

`backend/config/packages/rate_limiter.yaml`, after `ai_recommendations`:

```yaml
        # Per user: "Generate now" always calls the model. Ten an hour is far beyond honest use (a profile takes one
        # call); the status poll reads /runs/current, which has no limiter.
        ai_profile_runs:
            policy: 'sliding_window'
            limit: 10
            interval: '1 hour'
            cache_pool: 'cache.rate_limiter'
```

`backend/src/Controller/Api/ProfileController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Recommendation\SaveProfileSettingsRequest;
use App\Entity\User;
use App\Http\ProfileRunJson;
use App\Http\ProfileSettingsJson;
use App\Http\RecommendationDebugLogJson;
use App\Repository\ProfileRunRepository;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Recommendation\Profile\ProfileDebugLogLoader;
use App\Service\Recommendation\Profile\ProfileRunStarter;
use App\Service\Recommendation\Profile\ProfileSettingsEditor;
use App\Service\Recommendation\Profile\ProfileSettingsProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** The interest profile: its settings, a manual start, and the newest run's status and calls. Reads have no limiter. */
#[Route('/api/me/ai/profile')]
final readonly class ProfileController
{
    public function __construct(
        private ProfileSettingsProvider $settings,
        private ProfileSettingsEditor $editor,
        private ProfileRunStarter $starter,
        private ProfileRunRepository $profileRuns,
        private ProfileDebugLogLoader $debugLogs,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $aiProfileRunsLimiter,
    ) {
    }

    #[Route('', name: 'api_me_ai_profile_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(ProfileSettingsJson::state($this->settings->forUser($user)));
    }

    #[Route('', name: 'api_me_ai_profile_save', methods: ['PUT'])]
    public function save(
        #[CurrentUser] User $user,
        #[MapRequestPayload] SaveProfileSettingsRequest $request,
    ): JsonResponse {
        $this->editor->save($user, $request->toChange());

        return new JsonResponse(ProfileSettingsJson::state($this->settings->forUser($user)));
    }

    /** Spends the ai_profile_runs budget even when the active run is returned: a click is a click. */
    #[Route('/runs', name: 'api_me_ai_profile_runs_start', methods: ['POST'])]
    public function start(#[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->aiProfileRunsLimiter, $user);

        return new JsonResponse(ProfileRunJson::run($this->starter->startManually($user)));
    }

    #[Route('/runs/current', name: 'api_me_ai_profile_runs_current', methods: ['GET'])]
    public function current(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(ProfileRunJson::current($this->profileRuns->findLatestForUser($user)));
    }

    #[Route('/runs/current/log', name: 'api_me_ai_profile_runs_log', methods: ['GET'])]
    public function log(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(RecommendationDebugLogJson::profileRunLog($this->debugLogs->forUser($user)));
    }
}
```

Run the Step 1 test. Expected: OK.

- [ ] **Step 4: Mapper unit tests**

`backend/tests/Http/ProfileRunJsonTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\ProfileRun;
use App\Entity\User;
use App\Enum\ProfileRunOutcome;
use App\Enum\ProfileRunTrigger;
use App\Http\ProfileRunJson;
use PHPUnit\Framework\TestCase;

final class ProfileRunJsonTest extends TestCase
{
    public function testACompletedRunCarriesItsOutcomeAndItsModel(): void
    {
        $profileRun = new ProfileRun(
            new User('profile-json@example.test', new \DateTimeImmutable('2026-10-01 06:00:00')),
            ProfileRunTrigger::Scheduled,
            new \DateTimeImmutable('2026-10-03 09:00:00'),
        );
        $profileRun->start('fingerprint', 'llm.example.test', 'qwen3-14b');
        $profileRun->complete(ProfileRunOutcome::Unchanged, new \DateTimeImmutable('2026-10-03 09:00:02'));

        $json = ProfileRunJson::current($profileRun);

        self::assertSame('completed', $json['status']);
        self::assertSame('scheduled', $json['trigger']);
        self::assertSame('unchanged', $json['outcome']);
        self::assertSame('qwen3-14b', $json['model']);
        self::assertSame('2026-10-03T09:00:02+00:00', $json['completedAt']);
        self::assertSame(3, $json['maxAttempts']);
    }

    public function testNoRunReadsAsNone(): void
    {
        $json = ProfileRunJson::current(null);

        self::assertSame('none', $json['status']);
        self::assertNull($json['outcome']);
    }
}
```

`backend/tests/Http/ProfileSettingsJsonTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\ProfileSettingsValues;
use App\Entity\StoredProfile;
use App\Http\ProfileSettingsJson;
use App\Service\Recommendation\Profile\Model\ProfileSettingsModel;
use PHPUnit\Framework\TestCase;

final class ProfileSettingsJsonTest extends TestCase
{
    public function testAGeneratedProfileSaysWhenAndByWhichModel(): void
    {
        $json = ProfileSettingsJson::state(new ProfileSettingsModel(
            new StoredProfile('Likes maps.', new \DateTimeImmutable('2026-10-03 07:15:00'), 'llm.example.test', 'qwen3-14b'),
            new ProfileSettingsValues(48, null, 7, 9),
            null,
            [],
            true,
        ));

        self::assertSame('Likes maps.', $json['profileText']);
        self::assertSame('2026-10-03T07:15:00+00:00', $json['generatedAt']);
        self::assertSame(['providerHost' => 'llm.example.test', 'model' => 'qwen3-14b'], $json['generatedBy']);
        self::assertSame(48, $json['intervalHours']);
        self::assertNull($json['connection']);
        self::assertSame(7, $json['keptCap']);
        self::assertTrue($json['debugEnabled']);
    }

    public function testNoProfileHasNoAttribution(): void
    {
        $json = ProfileSettingsJson::state(new ProfileSettingsModel(
            StoredProfile::none(),
            ProfileSettingsValues::defaults(),
            null,
            [],
            false,
        ));

        self::assertNull($json['generatedBy']);
        self::assertSame(['keptCap' => 40, 'viewedCap' => 80], $json['defaults']);
    }
}
```

Run: `php bin/phpunit tests/Http tests/Controller/Api`
Expected: OK.

- [ ] **Step 5: Deletion checks**

1. In `ProfileSettingsEditor::connectionFor()`, drop the `canBuildProfiles()` guard. `testAConnectionThatCannotBuildAProfileIsRefused` must fail. Restore.
2. In `ProfileController::start()`, drop the limiter line. `testAStartBeyondTheHoursBudgetIsRateLimited` must fail. Restore.
3. Rename the controller parameter to `$aiRecommendationStartsLimiter` (same limit, a 15-minute window). The budget test must fail on `Retry-After`. Restore.
4. In `RecommendationRunProblems`, drop the new arm. `testStartingWithoutAUsableConnectionIsRefused` must fail (500). Restore.
5. In `ProfileSettingsJson::state()`, map `connection` from `$profile->values->connection`. `testANewAccountReadsTheDefaultsWithItsActiveConnection` must fail. Restore.

- [ ] **Step 6: Gates, commit**

```bash
php bin/phpunit tests/Controller tests/Http tests/Service/Recommendation
composer cs && composer stan && composer md && composer tramp
php bin/console lint:container
git add backend
git commit -m "feat(#1351): the profile has its own settings, start, status and log endpoints"
```

---
### Task 8: #1349's per-connection borrowing goes

Pure removal, plus one migration that carries each account's choice over. After this task nothing in the codebase reads `user_ai_settings.profile_connection_id`, and the AI section has no profile picker.

**Files:**
- Create: `backend/migrations/Version20261003120000.php`
- Delete: `backend/src/Controller/Api/AiProfileConnectionController.php`, `backend/src/Dto/Ai/ChooseProfileConnectionRequest.php`, `backend/src/Service/Recommendation/Profile/ProfileConnectionResolver.php`, `ProfileConnectionChooser.php`, `backend/src/Service/Recommendation/Exception/ProfileNotBorrowedException.php`; tests `backend/tests/Controller/Api/AiProfileConnectionControllerTest.php`, `backend/tests/Service/Recommendation/Profile/ProfileConnectionChooserTest.php`, `ProfileConnectionResolverTest.php`
- Modify: `backend/src/Entity/AiProviderSettings.php` (−`$profileConnection` and its two accessors), `backend/src/Entity/User.php` (`getRecommendationSettings()`), `backend/src/Repository/AiProviderSettingsRepository.php` (−`findBorrowersOf()`), `backend/src/Service/Ai/AiProviderConfigurator.php`, `backend/src/Http/AiSettingsJson.php` (−`profileConnectionId`), `backend/src/Http/Problem/ExceptionProblems/RecommendationRunProblems.php` (−`profile_not_borrowed`), `backend/tests/Support/RecommendationRunFixtures.php` (−`seedProfileConnectionFor()`, −`seedProfileConnectionBorrowedBy()`, −`PROFILE_MODEL`/`PROFILE_BASE_URL` if unused)
- Modify (frontend): `frontend/src/app/settings/ai/ai-section.component.{ts,html,scss,spec.ts}`, `ai-settings.service.{ts,spec.ts}`, `ai-failure.ts`, `frontend/src/app/core/ai-availability.service.ts` (doc comment), `frontend/public/i18n/{en,de}.json`
- Test: `backend/tests/Service/Ai/AiProviderConfiguratorTest.php`, `backend/tests/Entity/AiProviderSettingsTest.php`, `backend/tests/Http/AiSettingsJsonTest.php`, `backend/tests/Service/Account/AccountDeleterTest.php`

**Interfaces:**
- Consumes (Task 1): `RecommendationSettings::forgetProfileConnection()`, `profileSettings()`; `ProfileSettingsValues`.
- Produces: `User::getRecommendationSettings(): ?RecommendationSettings`. Removed: `AiProviderSettings::getProfileConnection()`/`setProfileConnection()`, `PUT/DELETE /api/me/ai/configs/{id}/profile`, the `profileConnectionId` key of every AI config JSON, `AiConfig.profileConnectionId` in the frontend.

- [ ] **Step 1: Write the failing configurator test**

In `backend/tests/Service/Ai/AiProviderConfiguratorTest.php`, replace #1349's pointer-clearing test (`testDeletingAProfileConnectionClearsOnlyThePointersToIt`) with (imports `App\Entity\ProfileSettingsValues`, `App\Entity\RecommendationSettings`, `App\Entity\AiProviderSettings`, `App\Entity\User`):

```php
    public function testDeletingTheProfileConnectionClearsTheProfileSettingAndKeepsTheSchedule(): void
    {
        $configurator = $this->configurator(['gpt-4o']);
        $user = $this->user('cfg-delete-profile@example.test');
        $this->readyConfiguration($configurator, $user, 'gpt-4o');
        $chosen = $this->readyConfiguration($configurator, $user, 'gpt-4o');
        $settings = new RecommendationSettings($user);
        $settings->updateProfileSettings(new ProfileSettingsValues(24, $chosen, 40, 80));
        $this->entityManager->persist($settings);
        $this->entityManager->flush();
        $chosenId = $chosen->requireId();
        $userId = $user->requireId();
        $this->entityManager->clear();
        $reloadedUser = $this->entityManager->find(User::class, $userId);
        $reloadedChosen = $this->entityManager->find(AiProviderSettings::class, $chosenId);
        self::assertNotNull($reloadedUser);
        self::assertNotNull($reloadedChosen);

        $configurator->deleteConfiguration($reloadedChosen);

        self::assertNull($reloadedUser->getRecommendationSettings()?->profileSettings()->connection);
        $this->entityManager->clear();
        $persisted = $this->entityManager->getRepository(RecommendationSettings::class)->findOneBy(['user' => $userId]);
        self::assertNull($persisted?->profileSettings()->connection);
        self::assertSame(24, $persisted?->profileSettings()->intervalHours);
    }
```

The user is re-read so its inverse one-to-one `recommendationSettings` is loaded the way a request loads it. `$this->entityManager`, `configurator()`, `user()` and `readyConfiguration()` stand for the class's own accessors. If `deleteConfiguration()` runs on a configurator built over another entity manager than the test's, re-read through the one it uses.

Run: `php bin/phpunit tests/Service/Ai/AiProviderConfiguratorTest.php --filter ProfileSetting`
Expected: error, `Call to undefined method App\Entity\User::getRecommendationSettings()`.

- [ ] **Step 2: The configurator clears the account-level pointer; the per-connection one goes**

`User`:

```php
    public function getRecommendationSettings(): ?RecommendationSettings
    {
        return $this->recommendationSettings;
    }
```

`AiProviderConfigurator::releasePointersTo()`:

```php
    private function releasePointersTo(AiProviderSettings $settings): void
    {
        $user = $settings->getUser();
        if ($settings === $user->getActiveAiProviderSettings()) {
            $user->setActiveAiProviderSettings(null);
        }

        $user->getRecommendationSettings()?->forgetProfileConnection($settings);
    }
```

and the class docblock's last sentence becomes `The active configuration is a single pointer on User, not a per-row flag; the profile connection is a pointer on the account's recommendation settings.` This reaches `App\Entity` only, so the `Ai` module gains no edge to `Recommendation`.

`AiProviderSettings`: delete `$profileConnection`, `getProfileConnection()` and `setProfileConnection()`. `AiProviderSettingsRepository`: delete `findBorrowersOf()`. `AiSettingsJson`: delete the `profileConnectionId` key. `RecommendationRunProblems`: delete the `ProfileNotBorrowedException` arm and import. Delete the five classes and three test files in **Files**. In `RecommendationRunFixtures` delete `seedProfileConnectionFor()`, `seedProfileConnectionBorrowedBy()` and the two constants once `grep -rn "PROFILE_MODEL\|PROFILE_BASE_URL" tests` finds no other user. In `AiProviderSettingsTest` delete the three pointer tests (Task 1 of #1349); in `AiSettingsJsonTest` delete the `profileConnectionId` assertions and test; in `AccountDeleterTest` drop the pointer seeding #1349 added (the account delete removes `user_recommendation_settings` with the user either way).

Run: `php bin/phpunit tests/Service/Ai tests/Entity tests/Http tests/Service/Account tests/Controller/Api tests/Service/Recommendation`
Expected: OK.

- [ ] **Step 3: The migration carries the choice over, then drops the column**

`backend/migrations/Version20261003120000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The active Jev connection's profile connection becomes the account's; an inactive Jev row's pick is dropped. */
final class Version20261003120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move the profile connection from user_ai_settings to user_recommendation_settings (#1351).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('user_ai_settings')->hasColumn('profile_connection_id'),
            'user_ai_settings.profile_connection_id is already gone.',
        );

        if ($this->mysql()) {
            $this->upMySql();

            return;
        }

        $this->upSqlite();
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('user_ai_settings')->hasColumn('profile_connection_id'),
            'user_ai_settings.profile_connection_id already exists.',
        );

        if ($this->mysql()) {
            $this->addSql('ALTER TABLE user_ai_settings ADD profile_connection_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE user_ai_settings ADD CONSTRAINT FK_53B8EF3023D39AC1 FOREIGN KEY (profile_connection_id) REFERENCES user_ai_settings (id) ON DELETE SET NULL');
            $this->addSql('CREATE INDEX IDX_53B8EF3023D39AC1 ON user_ai_settings (profile_connection_id)');
            $this->addSql(<<<'SQL'
                UPDATE user_ai_settings active
                INNER JOIN app_user u ON u.active_ai_config_id = active.id
                INNER JOIN user_recommendation_settings s ON s.user_id = u.id
                SET active.profile_connection_id = s.profile_connection_id
                WHERE CAST(LEFT(active.model, 4) AS BINARY) = 'jev-'
                SQL);

            return;
        }

        $this->addSql('ALTER TABLE user_ai_settings ADD COLUMN profile_connection_id INTEGER DEFAULT NULL REFERENCES user_ai_settings (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_53B8EF3023D39AC1 ON user_ai_settings (profile_connection_id)');
        $this->addSql(<<<'SQL'
            UPDATE user_ai_settings
            SET profile_connection_id = (
                SELECT s.profile_connection_id FROM user_recommendation_settings s
                INNER JOIN app_user u ON u.id = s.user_id
                WHERE u.active_ai_config_id = user_ai_settings.id
            )
            WHERE substr(model, 1, 4) = 'jev-'
            SQL);
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function upMySql(): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO user_recommendation_settings (user_id)
            SELECT u.id FROM app_user u
            INNER JOIN user_ai_settings active ON active.id = u.active_ai_config_id
            WHERE CAST(LEFT(active.model, 4) AS BINARY) = 'jev-' AND active.profile_connection_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM user_recommendation_settings s WHERE s.user_id = u.id)
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE user_recommendation_settings s
            INNER JOIN app_user u ON u.id = s.user_id
            INNER JOIN user_ai_settings active ON active.id = u.active_ai_config_id
            SET s.profile_connection_id = active.profile_connection_id
            WHERE CAST(LEFT(active.model, 4) AS BINARY) = 'jev-' AND active.profile_connection_id IS NOT NULL
            SQL);
        $this->addSql('ALTER TABLE user_ai_settings DROP FOREIGN KEY FK_53B8EF3023D39AC1');
        $this->addSql('DROP INDEX IDX_53B8EF3023D39AC1 ON user_ai_settings');
        $this->addSql('ALTER TABLE user_ai_settings DROP profile_connection_id');
    }

    private function upSqlite(): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO user_recommendation_settings (user_id)
            SELECT u.id FROM app_user u
            INNER JOIN user_ai_settings active ON active.id = u.active_ai_config_id
            WHERE substr(active.model, 1, 4) = 'jev-' AND active.profile_connection_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM user_recommendation_settings s WHERE s.user_id = u.id)
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE user_recommendation_settings
            SET profile_connection_id = (
                SELECT active.profile_connection_id FROM app_user u
                INNER JOIN user_ai_settings active ON active.id = u.active_ai_config_id
                WHERE u.id = user_recommendation_settings.user_id
                  AND substr(active.model, 1, 4) = 'jev-' AND active.profile_connection_id IS NOT NULL
            )
            WHERE user_id IN (
                SELECT u.id FROM app_user u
                INNER JOIN user_ai_settings active ON active.id = u.active_ai_config_id
                WHERE substr(active.model, 1, 4) = 'jev-' AND active.profile_connection_id IS NOT NULL
            )
            SQL);
        $this->addSql('DROP INDEX IDX_53B8EF3023D39AC1');
        $this->addSql('ALTER TABLE user_ai_settings DROP COLUMN profile_connection_id');
    }

    /** Refuses any platform but the two supported ones: better a refusal than DDL nobody tested. */
    private function mysql(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        return $platform instanceof AbstractMySQLPlatform;
    }
}
```

The inserted row takes every column default, as a row created by `RecommendationSettingsWriter` does. If an insert fails for a column without a default, name it in the report and add it with its entity default.

- [ ] **Step 4: Verify the carry-over on both platforms**

Per platform prefix (Task 1 Step 13), from an empty scratch database:

```bash
<prefix> doctrine:migrations:migrate 'DoctrineMigrations\Version20261003110000' --no-interaction
<prefix> dbal:run-sql "INSERT INTO app_user (id, email, roles, status, created_at) VALUES (1, 'carry-1@example.test', '[]', 'active', '2026-10-02 12:00:00'), (2, 'carry-2@example.test', '[]', 'active', '2026-10-02 12:00:00'), (3, 'carry-3@example.test', '[]', 'active', '2026-10-02 12:00:00')"
<prefix> dbal:run-sql "INSERT INTO user_ai_settings (id, user_id, base_url, api_key_ciphertext, api_key_nonce, api_key_salt, api_key_hint, model) VALUES (1, 1, 'https://llm.example.test/v1', 'c', 'n', 's', 'ab12', 'gpt-4o'), (2, 1, 'https://jev.example.test/v1', 'c', 'n', 's', 'ab12', 'jev-latest'), (3, 2, 'https://llm.example.test/v1', 'c', 'n', 's', 'ab12', 'gpt-4o'), (4, 2, 'https://jev.example.test/v1', 'c', 'n', 's', 'ab12', 'jev-latest'), (5, 3, 'https://llm.example.test/v1', 'c', 'n', 's', 'ab12', 'gpt-4o'), (6, 3, 'https://jev.example.test/v1', 'c', 'n', 's', 'ab12', 'jev-latest')"
<prefix> dbal:run-sql "UPDATE user_ai_settings SET profile_connection_id = 1 WHERE id = 2"
<prefix> dbal:run-sql "UPDATE user_ai_settings SET profile_connection_id = 3 WHERE id = 4"
<prefix> dbal:run-sql "UPDATE user_ai_settings SET profile_connection_id = 5 WHERE id = 6"
<prefix> dbal:run-sql "UPDATE app_user SET active_ai_config_id = 2 WHERE id = 1"
<prefix> dbal:run-sql "UPDATE app_user SET active_ai_config_id = 4 WHERE id = 2"
<prefix> dbal:run-sql "UPDATE app_user SET active_ai_config_id = 5 WHERE id = 3"
<prefix> dbal:run-sql "INSERT INTO user_recommendation_settings (id, user_id, profile_interval_hours) VALUES (1, 1, 24)"
<prefix> doctrine:migrations:migrate --no-interaction
<prefix> dbal:run-sql "SELECT user_id, profile_connection_id, profile_interval_hours FROM user_recommendation_settings ORDER BY user_id"
<prefix> doctrine:schema:validate
```

Account 1 has a settings row and an active Jev row pointing at 1; account 2 has no settings row and an active Jev row pointing at 3; account 3's Jev row points at 5 but its active connection is the LLM. Expected: `1 1 24`, `2 3 NULL`, and no row for account 3. Then `doctrine:migrations:migrate prev` and check `SELECT id, profile_connection_id FROM user_ai_settings WHERE profile_connection_id IS NOT NULL` gives `2 1` and `4 3`; migrate up again. Clean up the scratch databases.

- [ ] **Step 5: The frontend loses the borrowed-profile picker**

`frontend/src/app/settings/ai/ai-settings.service.ts`: delete `profileConnectionId` from `AiConfig`, `chooseProfileConnection()`, `clearProfileConnection()`, `reloadWhenGone()` (no other caller), `forgetProfileConnection()`, and in `drop()` the `.map(…)` that nulls pointers (`this.configs.set(this.configs().filter((each) => each.id !== id));`). Remove the now unused `EMPTY`, `catchError`, `throwError`, `Observable` and `HttpErrorResponse` imports if nothing else uses them.

`ai-failure.ts`: `AiFailureScope`'s last arm becomes `| { readonly action: 'row'; readonly configId: number };`.

`ai-section.component.ts`: delete `ProfilePick`, `profileCandidates`, `profilePick`, `shownProfileConnectionId()`, `chooseProfileConnection()`, `profileFailure()`; `failureFor(configId: number)` loses its `action` parameter and compares `scoped.scope.action !== 'row'`; `rowFailure(configId)` calls `failureFor(configId)`. Drop `linkedSignal` from the import if unused. `ai-section.component.html`: delete the `@if (config.capabilities.profile === 'borrowed') { <div class="profile-connection"> … </div> }` block. `ai-section.component.scss`: delete the `.profile-connection` rule.

`core/ai-availability.service.ts`, the comment above `RecommendationProfileSource`: `/** Where the engine's reader profile comes from: its own connection, or the one chosen under Settings → Profile. */`.

i18n (both files): delete `settings.ai.profileConnection`; `settings.ai.modelHint.borrowedProfile` becomes en `"takes its profile from Settings → Profile"`, de `"übernimmt das Profil aus Einstellungen → Profil"`; in `settings.ai.info.modelPicker` replace "take your reading profile from a profile connection" with "take your reading profile from Settings → Profile" (de: the matching clause). `settings.ai.guide.jevStep*` entries that tell the reader to pick a profile connection in the AI section point at Settings → Profile instead; read them and rewrite each sentence that names the old picker.

Specs: in `ai-settings.service.spec.ts` delete `profileConnectionId` from the `config()` builder and every case about choosing, clearing or dropping profile pointers (lines ~60–165); in `ai-section.component.spec.ts` delete `profileConnectionId` from its fixture and the `describe` block of profile-connection cases (lines ~1085–1360). Add one guard:

```ts
  it('offers no profile picker on a Jev connection', () => {
    const fixture = mountWith([jevActive]);

    expect((fixture.nativeElement as HTMLElement).querySelector('.profile-connection')).toBeNull();
    expect((fixture.nativeElement as HTMLElement).textContent).not.toContain('Profile connection');
  });
```

`mountWith()` and `jevActive` stand for the spec's own mount helper and Jev fixture.

Run: `docker compose exec -T frontend npm test -- settings/ai`
Expected: OK.

- [ ] **Step 6: Deletion checks**

1. In `AiProviderConfigurator::releasePointersTo()`, drop the `forgetProfileConnection()` line. `testDeletingTheProfileConnectionClearsTheProfileSettingAndKeepsTheSchedule` must fail on the in-memory assertion (the database one still passes through `ON DELETE SET NULL`). Restore.
2. In the migration, drop the `INSERT` statement. Step 4's account 2 must come out missing. Restore.

- [ ] **Step 7: Gates, live migration, commit**

```bash
php bin/phpunit
composer cs && composer stan && composer md && composer tramp
grep -rn "profileConnectionId\|getProfileConnection\|ProfileConnectionResolver\|ProfileConnectionChooser\|ProfileNotBorrowed\|findBorrowersOf" src tests ../frontend/src
docker compose exec -T frontend npm run check
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
git add -A backend frontend
git commit -m "refactor(#1351): the profile connection is the account's, so the per-connection borrowing goes"
```

The `grep` must print nothing (the migrations are outside `src`). Check `git status` before `git add -A`.

---
### Task 9: The Profile settings section

Read `docs/design-language.md` before writing the template; the section is built only from the shared primitives the recommendation card already uses (`app-settings-stack`, `-group`, `-row`, `app-field`, `app-settings-save-bar`, `app-button`, `app-error-banner`).

**Files:**
- Create: `frontend/src/app/settings/profile/profile-settings.service.ts`, `profile-settings.service.spec.ts`, `profile-section.component.{ts,html,scss,spec.ts}`, `profile-debug-log.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/settings/settings-sections.ts`, `settings.routes.ts`, `settings-api.ts` (`profileDebugLog()`), `settings.models.ts` (`DebugLogEntry.phase`)
- Modify: `frontend/src/app/settings/recommendations/recommendation-settings-card.component.{ts,html,spec.ts}`, `recommendation-settings.service.{ts,spec.ts}`, `recommendation-typed-seed.ts`
- Modify: `frontend/public/i18n/en.json`, `de.json`

**Interfaces:**
- Consumes (Task 7): the five `/api/me/ai/profile` routes and their shapes; `GET /api/recommendations/runs/debug-log/{id}` for a row's bodies.
- Produces: settings section `profile` (route `/settings/profile`, icon `psychology`, label `settings.profile.title`); `ProfileSettingsService` (`state`, `run`, `runActive`, `starting`, `startFailure`, `loadRun()`, `startRun()`, plus the `DraftSettingsService` API); `<app-profile-section>`; `<app-profile-debug-log [running] [runId]>`; `SettingsApi.profileDebugLog(): Observable<{ entries: DebugLogEntry[] }>`.

- [ ] **Step 1: Write the failing service spec**

`frontend/src/app/settings/profile/profile-settings.service.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { ProfileRun, ProfileSettingsService, ProfileSettingsState } from './profile-settings.service';

const ENDPOINT = '/api/me/ai/profile';

export function profileState(over: Partial<ProfileSettingsState> = {}): ProfileSettingsState {
  return {
    profileText: 'Likes maps and rail history.',
    generatedAt: '2026-10-03T07:15:00+00:00',
    generatedBy: { providerHost: 'llm.example.test', model: 'qwen3-14b' },
    intervalHours: 24,
    intervalChoices: [null, 6, 12, 24, 48, 168],
    connectionId: null,
    connection: { id: 3, name: 'Home LLM', baseUrl: 'https://llm.example.test/v1', model: 'qwen3-14b' },
    candidates: [
      { id: 3, name: 'Home LLM', baseUrl: 'https://llm.example.test/v1', model: 'qwen3-14b' },
      { id: 7, name: null, baseUrl: 'https://api.openai.com/v1', model: 'gpt-4o' },
    ],
    keptCap: 40,
    viewedCap: 80,
    defaults: { keptCap: 40, viewedCap: 80 },
    bounds: { keptCap: { min: 0, max: 500 }, viewedCap: { min: 0, max: 500 } },
    debugEnabled: false,
    ...over,
  };
}

export function profileRun(over: Partial<ProfileRun> = {}): ProfileRun {
  return {
    status: 'none',
    id: null,
    trigger: null,
    outcome: null,
    error: null,
    createdAt: null,
    completedAt: null,
    providerHost: null,
    model: null,
    attempts: 0,
    maxAttempts: 3,
    transportFailures: 0,
    maxTransportFailures: 3,
    streamedChars: 0,
    ...over,
  };
}

describe('ProfileSettingsService', () => {
  let service: ProfileSettingsService;
  let http: HttpTestingController;

  beforeEach(() => {
    jest.useFakeTimers();
    TestBed.configureTestingModule({
      providers: [
        ProfileSettingsService,
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: '' },
      ],
    });
    service = TestBed.inject(ProfileSettingsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    service.ngOnDestroy();
    jest.useRealTimers();
    http.verify();
  });

  it('saves an instant change over the last-saved state', () => {
    service.load();
    http.expectOne(ENDPOINT).flush(profileState({ keptCap: 15 }));

    service.saveInstant({ intervalHours: 168 });

    const request = http.expectOne((each) => each.method === 'PUT' && each.url === ENDPOINT);
    expect(request.request.body).toEqual({ intervalHours: 168, connectionId: null, keptCap: 15, viewedCap: 80 });
    request.flush(profileState({ intervalHours: 168, keptCap: 15 }));
  });

  it('polls the current run every two seconds while it is active and reloads the state when it ends', () => {
    service.load();
    http.expectOne(ENDPOINT).flush(profileState());
    service.startRun();
    http.expectOne((each) => each.method === 'POST').flush(profileRun({ status: 'pending', id: 9 }));
    expect(service.runActive()).toBe(true);

    jest.advanceTimersByTime(2000);
    http.expectOne(`${ENDPOINT}/runs/current`).flush(profileRun({ status: 'running', id: 9 }));
    jest.advanceTimersByTime(2000);
    http.expectOne(`${ENDPOINT}/runs/current`).flush(profileRun({ status: 'completed', id: 9, outcome: 'generated' }));

    http.expectOne(ENDPOINT).flush(profileState({ profileText: 'Fresh profile.' }));
    expect(service.state()?.profileText).toBe('Fresh profile.');
    jest.advanceTimersByTime(4000);
    http.expectNone(`${ENDPOINT}/runs/current`);
  });

  it('keeps a refused start as its own failure', () => {
    service.startRun();
    http.expectOne((each) => each.method === 'POST').flush(
      { type: 'profile_connection_missing', title: 'x', status: 422, detail: 'Choose a connection.' },
      { status: 422, statusText: 'Unprocessable Entity' },
    );

    expect(service.startFailure()?.detail).toBe('Choose a connection.');
    expect(service.starting()).toBe(false);
    expect(service.failure()).toBeNull();
  });
});
```

Run: `docker compose exec -T frontend npm test -- settings/profile/profile-settings.service`
Expected: FAIL, `Cannot find module './profile-settings.service'`.

- [ ] **Step 2: The service**

`frontend/src/app/settings/profile/profile-settings.service.ts`:

```ts
import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, OnDestroy, computed, signal } from '@angular/core';
import { Problem, parseProblem } from '../../core/problem';
import { DraftSettingsService } from '../../shared/settings/draft-settings.service';

const POLL_MS = 2000;

export interface ProfileConnection {
  readonly id: number;
  readonly name: string | null;
  readonly baseUrl: string;
  readonly model: string | null;
}

export interface ProfileCapBounds {
  readonly min: number;
  readonly max: number;
}

export type ProfileCapField = 'keptCap' | 'viewedCap';

/** Mirrors `ProfileSettingsJson`; `connection` is the one that builds the profile, null when none can. */
export interface ProfileSettingsState {
  readonly profileText: string | null;
  readonly generatedAt: string | null;
  readonly generatedBy: { readonly providerHost: string | null; readonly model: string } | null;
  readonly intervalHours: number | null;
  readonly intervalChoices: readonly (number | null)[];
  readonly connectionId: number | null;
  readonly connection: ProfileConnection | null;
  readonly candidates: readonly ProfileConnection[];
  readonly keptCap: number;
  readonly viewedCap: number;
  readonly defaults: Readonly<Record<ProfileCapField, number>>;
  readonly bounds: Readonly<Record<ProfileCapField, ProfileCapBounds>>;
  readonly debugEnabled: boolean;
}

export interface SaveProfileSettings {
  readonly intervalHours: number | null;
  readonly connectionId: number | null;
  readonly keptCap: number;
  readonly viewedCap: number;
}

export type TypedProfileEdits = Pick<SaveProfileSettings, ProfileCapField>;

/** Mirrors `ProfileRunJson`; `none` until the account has run one. */
export interface ProfileRun {
  readonly status: 'none' | 'pending' | 'running' | 'completed' | 'failed';
  readonly id: number | null;
  readonly trigger: 'manual' | 'scheduled' | 'recommendation' | null;
  readonly outcome: 'generated' | 'unchanged' | 'no_history' | null;
  readonly error: string | null;
  readonly createdAt: string | null;
  readonly completedAt: string | null;
  readonly providerHost: string | null;
  readonly model: string | null;
  readonly attempts: number;
  readonly maxAttempts: number;
  readonly transportFailures: number;
  readonly maxTransportFailures: number;
  readonly streamedChars: number;
}

/**
 * The profile section's state and writes on the shared draft base (schedule and connection save at once, the two
 * caps behind the save bar), plus the newest profile run, polled while it is active.
 */
@Injectable()
export class ProfileSettingsService
  extends DraftSettingsService<ProfileSettingsState, SaveProfileSettings, TypedProfileEdits>
  implements OnDestroy
{
  protected readonly endpoint = `${this.base}/api/me/ai/profile`;

  readonly run = signal<ProfileRun | null>(null);
  readonly runActive = computed(() => {
    const status = this.run()?.status;
    return status === 'pending' || status === 'running';
  });
  readonly starting = signal(false);
  readonly startFailure = signal<Problem | null>(null);

  private pollTimer: ReturnType<typeof setTimeout> | null = null;

  protected bodyFromState(state: ProfileSettingsState): SaveProfileSettings {
    return {
      intervalHours: state.intervalHours,
      connectionId: state.connectionId,
      keptCap: state.keptCap,
      viewedCap: state.viewedCap,
    };
  }

  loadRun(): void {
    this.http.get<ProfileRun>(`${this.endpoint}/runs/current`).subscribe((run) => this.adoptRun(run));
  }

  startRun(): void {
    this.starting.set(true);
    this.startFailure.set(null);
    this.http.post<ProfileRun>(`${this.endpoint}/runs`, {}).subscribe({
      next: (run) => {
        this.starting.set(false);
        this.adoptRun(run);
      },
      error: (error: HttpErrorResponse) => {
        this.starting.set(false);
        this.startFailure.set(parseProblem(error));
      },
    });
  }

  ngOnDestroy(): void {
    this.stopPolling();
  }

  /** A run that just ended may have replaced the stored profile, so the state is read again, keeping the draft. */
  private adoptRun(run: ProfileRun): void {
    const wasActive = this.runActive();
    this.run.set(run);
    if (wasActive && !this.runActive()) {
      this.http.get<ProfileSettingsState>(this.endpoint).subscribe((state) => this.state.set(state));
    }
    this.schedulePoll();
  }

  private schedulePoll(): void {
    this.stopPolling();
    if (!this.runActive()) return;
    this.pollTimer = setTimeout(() => this.loadRun(), POLL_MS);
  }

  private stopPolling(): void {
    if (this.pollTimer === null) return;
    clearTimeout(this.pollTimer);
    this.pollTimer = null;
  }
}
```

Run the Step 1 spec. Expected: PASS.

- [ ] **Step 3: Write the failing section and debug-log specs**

`frontend/src/app/settings/profile/profile-section.component.spec.ts`:

```ts
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../core/api';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { ToastService } from '../../shared/toast/toast.service';
import { ProfileSectionComponent } from './profile-section.component';
import { ProfileRun, ProfileSettingsState } from './profile-settings.service';
import { profileRun, profileState } from './profile-settings.service.spec';

const ENDPOINT = '/api/me/ai/profile';

describe('ProfileSectionComponent', () => {
  let http: HttpTestingController;

  function mount(
    state: ProfileSettingsState = profileState(),
    run: ProfileRun = profileRun(),
  ): ComponentFixture<ProfileSectionComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: '' },
        { provide: ToastService, useValue: { show: jest.fn() } },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(ProfileSectionComponent);
    fixture.detectChanges();
    http.expectOne(ENDPOINT).flush(state);
    http.expectOne(`${ENDPOINT}/runs/current`).flush(run);
    fixture.detectChanges();
    return fixture;
  }

  const element = (fixture: ComponentFixture<ProfileSectionComponent>): HTMLElement =>
    fixture.nativeElement as HTMLElement;
  const byTestId = (fixture: ComponentFixture<ProfileSectionComponent>, id: string): HTMLElement | null =>
    element(fixture).querySelector(`[data-testid="${id}"]`);

  it('shows the profile with when and by which model it was generated', () => {
    const fixture = mount();

    expect(byTestId(fixture, 'profile-text')?.textContent).toContain('Likes maps and rail history.');
    expect(byTestId(fixture, 'profile-meta')?.textContent).toContain('qwen3-14b');
    expect(byTestId(fixture, 'profile-meta')?.textContent).toContain('llm.example.test');
  });

  it('says there is no profile yet', () => {
    const fixture = mount(profileState({ profileText: null, generatedAt: null, generatedBy: null }));

    expect(byTestId(fixture, 'profile-empty')).not.toBeNull();
    expect(byTestId(fixture, 'profile-text')).toBeNull();
  });

  it('asks for a connection instead of offering Generate now when none can build the profile', () => {
    const fixture = mount(profileState({ connection: null, candidates: [] }));

    expect(byTestId(fixture, 'choose-connection')?.textContent).toContain('Choose one');
    expect(byTestId(fixture, 'generate-now')).toBeNull();
  });

  it('starts a run and says the profile is being built', () => {
    const fixture = mount();

    (byTestId(fixture, 'generate-now')?.querySelector('button') as HTMLButtonElement).click();
    http.expectOne((each) => each.method === 'POST' && each.url === `${ENDPOINT}/runs`)
      .flush(profileRun({ status: 'pending', id: 4, trigger: 'manual' }));
    fixture.detectChanges();

    expect(byTestId(fixture, 'profile-status')?.textContent).toContain('Building your profile');
    expect((byTestId(fixture, 'generate-now')?.querySelector('button') as HTMLButtonElement).disabled).toBe(true);
  });

  it('shows why the last run failed', () => {
    const fixture = mount(profileState(), profileRun({ status: 'failed', id: 5, error: 'The AI provider failed: gone' }));

    expect(element(fixture).querySelector('app-error-banner')?.textContent).toContain('The AI provider failed: gone');
  });

  it('says an unchanged scheduled run skipped the model', () => {
    const fixture = mount(profileState(), profileRun({ status: 'completed', id: 6, outcome: 'unchanged' }));

    expect(byTestId(fixture, 'profile-status')?.textContent).toContain('has not changed');
  });

  it('saves the schedule the moment it changes', () => {
    const fixture = mount();
    const select = byTestId(fixture, 'profile-schedule') as HTMLSelectElement;

    select.value = '168';
    select.dispatchEvent(new Event('change'));

    const request = http.expectOne((each) => each.method === 'PUT' && each.url === ENDPOINT);
    expect(request.request.body).toEqual({ intervalHours: 168, connectionId: null, keptCap: 40, viewedCap: 80 });
    request.flush(profileState({ intervalHours: 168 }));
  });

  it('saves the chosen connection the moment it changes', () => {
    const fixture = mount();
    const select = byTestId(fixture, 'profile-connection') as HTMLSelectElement;

    select.value = '7';
    select.dispatchEvent(new Event('change'));

    const request = http.expectOne((each) => each.method === 'PUT' && each.url === ENDPOINT);
    expect(request.request.body.connectionId).toBe(7);
    request.flush(profileState({ connectionId: 7 }));
  });

  it('holds a typed cap until Save', () => {
    const fixture = mount();
    const input = byTestId(fixture, 'profile-kept-cap') as HTMLInputElement;

    input.value = '12';
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    http.expectNone((each) => each.method === 'PUT');
    (element(fixture).querySelector('app-settings-save-bar button[type="submit"], app-settings-save-bar [data-testid="save"]') as HTMLButtonElement).click();

    const request = http.expectOne((each) => each.method === 'PUT' && each.url === ENDPOINT);
    expect(request.request.body.keptCap).toBe(12);
    request.flush(profileState({ keptCap: 12 }));
  });

  it('shows the debug log only when debug mode is on', () => {
    expect(element(mount()).querySelector('app-profile-debug-log')).toBeNull();

    const debugged = mount(profileState({ debugEnabled: true }));
    http.expectOne(`${ENDPOINT}/runs/current/log`).flush({ entries: [] });

    expect(element(debugged).querySelector('app-profile-debug-log')).not.toBeNull();
  });
});
```

The save-bar selector stands for however `SettingsSaveBarComponent` exposes its Save button; read its template and use the selector its own spec uses.

`frontend/src/app/settings/profile/profile-debug-log.component.spec.ts`:

```ts
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../core/api';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { DebugLogEntry } from '../settings.models';
import { ProfileDebugLogComponent } from './profile-debug-log.component';

const LOG = '/api/me/ai/profile/runs/current/log';

function entry(over: Partial<DebugLogEntry> = {}): DebugLogEntry {
  return {
    id: 31,
    runId: 9,
    phase: 'distill',
    batchNumber: null,
    attempt: 2,
    verdict: 'usable',
    requestBytes: 1200,
    responseBytes: 80,
    wireBytes: 90,
    streamingText: null,
    createdAt: '2026-10-03T09:00:00+00:00',
    finishedAt: '2026-10-03T09:00:04+00:00',
    errorDetail: null,
    finishReason: 'stop',
    ...over,
  } as DebugLogEntry;
}

describe('ProfileDebugLogComponent', () => {
  let http: HttpTestingController;

  function mount(running: boolean): ComponentFixture<ProfileDebugLogComponent> {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [provideHttpClient(), provideHttpClientTesting(), { provide: API_BASE_URL, useValue: '' }],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(ProfileDebugLogComponent);
    fixture.componentRef.setInput('running', running);
    fixture.componentRef.setInput('runId', 9);
    fixture.detectChanges();
    return fixture;
  }

  beforeEach(() => jest.useFakeTimers());
  afterEach(() => jest.useRealTimers());

  it('lists the newest profile run\'s calls', () => {
    const fixture = mount(false);
    http.expectOne(LOG).flush({ entries: [entry()] });
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).textContent).toContain('attempt 2');
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('usable');
  });

  it('polls while the run is active and stops once it is not', () => {
    const fixture = mount(true);
    http.expectOne(LOG).flush({ entries: [] });

    jest.advanceTimersByTime(2000);
    http.expectOne(LOG).flush({ entries: [entry({ verdict: null, streamingText: '{"prof' })] });
    fixture.componentRef.setInput('running', false);
    fixture.detectChanges();
    http.expectOne(LOG).flush({ entries: [entry()] });
    jest.advanceTimersByTime(4000);

    http.expectNone(LOG);
  });

  it('opens a call\'s request and response', () => {
    const fixture = mount(false);
    http.expectOne(LOG).flush({ entries: [entry()] });
    fixture.detectChanges();

    ((fixture.nativeElement as HTMLElement).querySelector('.profile-log__head') as HTMLButtonElement).click();
    http.expectOne('/api/recommendations/runs/debug-log/31').flush({
      id: 31, phase: 'distill', batchNumber: null, attempt: 2, verdict: 'usable',
      requestBody: '{"messages":[]}', responseText: '{"profile":"Likes maps."}', wireBytes: 90, finishReason: 'stop',
    });
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).querySelector('[data-testid="profile-log-response"]')?.textContent)
      .toContain('Likes maps.');
  });
});
```

Run: `docker compose exec -T frontend npm test -- settings/profile`
Expected: FAIL, the components are missing.

- [ ] **Step 4: The two components, the API method and the section entry**

`settings.models.ts`: `DebugLogEntry.phase` and `DebugLogDetail.phase` become `'distill' | 'batch' | 'consolidate' | 'dedup'`.

`settings-api.ts`:

```ts
  /** The newest profile run's calls, for the profile section's debug panel. */
  profileDebugLog(): Observable<{ entries: DebugLogEntry[] }> {
    return this.http.get<{ entries: DebugLogEntry[] }>(`${this.base}/api/me/ai/profile/runs/current/log`);
  }
```

(import `DebugLogEntry`).

`frontend/src/app/settings/profile/profile-debug-log.component.ts`:

```ts
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { SettingsApi } from '../settings-api';
import { DebugLogDetail, DebugLogEntry } from '../settings.models';

const POLL_MS = 2000;

/** The newest profile run's provider calls, polled while the run is active; a row opens to its bodies. */
@Component({
  selector: 'app-profile-debug-log',
  imports: [TranslocoPipe],
  templateUrl: './profile-debug-log.component.html',
  styleUrl: './profile-debug-log.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProfileDebugLogComponent {
  private readonly api = inject(SettingsApi);

  readonly running = input(false);
  readonly runId = input<number | null>(null);

  readonly entries = signal<DebugLogEntry[]>([]);
  readonly details = signal<ReadonlyMap<number, DebugLogDetail>>(new Map());

  private timer: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    effect(() => {
      this.runId();
      const running = this.running();
      untracked(() => this.fetch(running));
    });
    inject(DestroyRef).onDestroy(() => this.stop());
  }

  toggle(entry: DebugLogEntry): void {
    if (this.details().has(entry.id)) {
      const without = new Map(this.details());
      without.delete(entry.id);
      this.details.set(without);
      return;
    }
    this.api
      .debugLogEntry(entry.id)
      .subscribe((detail) => this.details.set(new Map(this.details()).set(entry.id, detail)));
  }

  private fetch(running: boolean): void {
    this.stop();
    this.api.profileDebugLog().subscribe((payload) => this.entries.set(payload.entries));
    if (running) this.timer = setTimeout(() => this.fetch(this.running()), POLL_MS);
  }

  private stop(): void {
    if (this.timer === null) return;
    clearTimeout(this.timer);
    this.timer = null;
  }
}
```

`profile-debug-log.component.html` (verdicts render the API's own words, as the recommendation panel does):

```html
@if (entries().length > 0) {
  <ol class="profile-log" data-testid="profile-log">
    @for (entry of entries(); track entry.id) {
      <li class="profile-log__entry">
        <button type="button" class="profile-log__head" (click)="toggle(entry)">
          {{ 'settings.ai.recommendations.debugAttempt' | transloco: { n: entry.attempt } }} ·
          {{ entry.verdict ?? '…' }}
        </button>
        @if (entry.streamingText; as text) {
          <pre class="profile-log__body">{{ text }}</pre>
        }
        @if (details().get(entry.id); as detail) {
          <pre class="profile-log__body" data-testid="profile-log-request">{{ detail.requestBody }}</pre>
          <pre class="profile-log__body" data-testid="profile-log-response">{{ detail.responseText }}</pre>
        }
      </li>
    }
  </ol>
}
```

`profile-debug-log.component.scss`:

```scss
.profile-log {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin: 0;
  padding: var(--space-3) var(--space-4);
  list-style: none;
}

.profile-log__head {
  padding: 0;
  border: 0;
  background: none;
  color: var(--text);
  font: inherit;
  font-size: var(--fs-sm);
  text-align: start;
  cursor: pointer;
}

.profile-log__body {
  max-height: 50vh;
  margin: var(--space-1) 0 0;
  overflow: auto;
  font-size: var(--fs-sm);
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
```

If a token named here does not exist (check `src/styles` for `--text`, `--space-*`, `--fs-sm`), use the one the recommendation debug panel's `.scss` uses for the same purpose.

`frontend/src/app/settings/profile/profile-section.component.ts`:

```ts
import {
  ChangeDetectionStrategy,
  Component,
  WritableSignal,
  computed,
  inject,
  linkedSignal,
} from '@angular/core';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { LanguageService } from '../../core/i18n/language.service';
import { formatInteger, formatLongDateTime } from '../../reader/format';
import { ButtonComponent } from '../../shared/button/button.component';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { FieldComponent } from '../../shared/field/field.component';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../shared/settings/settings-row/settings-row.component';
import { SettingsSaveBarComponent } from '../../shared/settings/save-bar/save-bar.component';
import { SettingsStackComponent } from '../../shared/settings/stack/settings-stack.component';
import { toastOnSaved } from '../../shared/toast/saved-toast';
import { ProfileDebugLogComponent } from './profile-debug-log.component';
import { ProfileCapField, ProfileConnection, ProfileSettingsService } from './profile-settings.service';

/** The interest profile the For You runs score against: what it says, how it is built, and a manual start. */
@Component({
  selector: 'app-profile-section',
  imports: [
    ButtonComponent,
    ErrorBannerComponent,
    FieldComponent,
    ProfileDebugLogComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsSaveBarComponent,
    SettingsStackComponent,
    TranslocoPipe,
  ],
  providers: [ProfileSettingsService],
  templateUrl: './profile-section.component.html',
  styleUrl: './profile-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProfileSectionComponent {
  readonly svc = inject(ProfileSettingsService);
  private readonly i18n = inject(TranslocoService);
  private readonly language = inject(LanguageService);

  readonly keptCap = linkedSignal<number>(() => this.svc.state()?.keptCap ?? 0);
  readonly viewedCap = linkedSignal<number>(() => this.svc.state()?.viewedCap ?? 0);

  readonly generatedAt = computed(() => {
    const at = this.svc.state()?.generatedAt;
    return at ? formatLongDateTime(at, this.language.lang()) : null;
  });

  readonly canGenerate = computed(() => !this.svc.runActive() && !this.svc.starting());

  /** The one line under the profile about the newest run; a failure shows as a banner instead. */
  readonly runStatusKey = computed(() => {
    const run = this.svc.run();
    if (this.svc.runActive()) return 'settings.profile.statusRunning';
    if (run?.status !== 'completed') return null;
    if (run.outcome === 'unchanged') return 'settings.profile.statusUnchanged';
    if (run.outcome === 'no_history') return 'settings.profile.statusNoHistory';
    return null;
  });

  readonly runError = computed(() => {
    const run = this.svc.run();
    return run?.status === 'failed'
      ? this.i18n.translate('settings.profile.statusFailed', { error: run.error ?? '' })
      : null;
  });

  readonly startFailureMessage = computed(() => {
    const failure = this.svc.startFailure();
    return failure ? (failure.detail ?? failure.title) : null;
  });

  constructor() {
    this.svc.load();
    this.svc.loadRun();
    toastOnSaved(this.svc, 'settings.profile.saved');
  }

  label(connection: ProfileConnection): string {
    const name = connection.name ?? new URL(connection.baseUrl).host;
    return connection.model ? `${name} · ${connection.model}` : name;
  }

  intervalKey(hours: number | null): string {
    return hours === null ? 'settings.profile.scheduleManual' : `settings.profile.schedule${hours}`;
  }

  rangeLabel(field: ProfileCapField): string {
    const bounds = this.svc.state()!.bounds[field];
    const lang = this.language.lang();
    return `${formatInteger(bounds.min, lang)}–${formatInteger(bounds.max, lang)}`;
  }

  onSchedule(event: Event): void {
    const raw = (event.target as HTMLSelectElement).value;
    this.svc.saveInstant({ intervalHours: raw === '' ? null : +raw });
  }

  onConnection(event: Event): void {
    const raw = (event.target as HTMLSelectElement).value;
    this.svc.saveInstant({ connectionId: raw === '' ? null : +raw });
  }

  /** Blank input is not zero, as in the recommendation card: a cleared field keeps its last valid value. */
  onCapInput(field: ProfileCapField, target: WritableSignal<number>, event: Event): void {
    const raw = (event.target as HTMLInputElement).value;
    if (raw === '') return;
    target.set(+raw);
    this.svc.setTypedField(field, +raw);
  }

  onReset(): void {
    this.svc.discardDraft();
    this.keptCap.set(this.svc.state()?.keptCap ?? 0);
    this.viewedCap.set(this.svc.state()?.viewedCap ?? 0);
  }

  generate(): void {
    this.svc.startRun();
  }
}
```

`frontend/src/app/settings/profile/profile-section.component.html`:

```html
@if (svc.state(); as state) {
  <app-settings-stack>
    <app-settings-group
      icon="psychology"
      [title]="'settings.profile.title' | transloco"
      [caption]="'settings.profile.caption' | transloco"
    >
      <div class="profile">
        @if (state.profileText; as text) {
          <p class="profile-text" data-testid="profile-text">{{ text }}</p>
          @if (generatedAt(); as when) {
            <p class="profile-meta" data-testid="profile-meta">
              {{
                'settings.profile.generated'
                  | transloco
                    : {
                        when: when,
                        model: state.generatedBy?.model ?? '',
                        host: state.generatedBy?.providerHost ?? '',
                      }
              }}
            </p>
          }
        } @else {
          <p class="profile-empty" data-testid="profile-empty">
            {{ 'settings.profile.empty' | transloco }}
          </p>
        }

        @if (runStatusKey(); as key) {
          <p class="profile-status" data-testid="profile-status">{{ key | transloco }}</p>
        }
        @if (runError(); as error) {
          <app-error-banner [message]="error" />
        }
        @if (startFailureMessage(); as message) {
          <app-error-banner [message]="message" />
        }

        @if (state.connection) {
          <app-button
            data-testid="generate-now"
            [disabled]="!canGenerate()"
            [loading]="svc.starting()"
            (click)="generate()"
          >
            {{ 'settings.profile.generateNow' | transloco }}
          </app-button>
        } @else {
          <p class="profile-hint" data-testid="choose-connection">
            {{ 'settings.profile.chooseConnection' | transloco }}
          </p>
        }
      </div>
    </app-settings-group>

    <app-settings-group icon="schedule" [title]="'settings.profile.settingsTitle' | transloco">
      <app-settings-row
        [stackable]="true"
        [title]="'settings.profile.schedule' | transloco"
        [description]="'settings.profile.scheduleDesc' | transloco"
      >
        <select data-testid="profile-schedule" (change)="onSchedule($event)">
          @for (hours of state.intervalChoices; track hours) {
            <option [value]="hours ?? ''" [selected]="state.intervalHours === hours">
              {{ intervalKey(hours) | transloco }}
            </option>
          }
        </select>
      </app-settings-row>

      <app-settings-row
        [stackable]="true"
        [title]="'settings.profile.connection' | transloco"
        [description]="'settings.profile.connectionDesc' | transloco"
      >
        <select data-testid="profile-connection" (change)="onConnection($event)">
          <option value="" [selected]="state.connectionId === null">
            {{ 'settings.profile.connectionActive' | transloco }}
          </option>
          @for (candidate of state.candidates; track candidate.id) {
            <option [value]="candidate.id" [selected]="state.connectionId === candidate.id">
              {{ label(candidate) }}
            </option>
          }
        </select>
      </app-settings-row>

      <div class="caps">
        <app-field
          [label]="'settings.profile.keptCap' | transloco"
          [info]="'settings.ai.info.keptCap' | transloco"
        >
          <p class="numeric-range">{{ rangeLabel('keptCap') }}</p>
          <input
            data-testid="profile-kept-cap"
            type="number"
            [min]="state.bounds.keptCap.min"
            [max]="state.bounds.keptCap.max"
            [value]="keptCap()"
            (input)="onCapInput('keptCap', keptCap, $event)"
          />
        </app-field>
        <app-field
          [label]="'settings.profile.viewedCap' | transloco"
          [info]="'settings.ai.info.viewedCap' | transloco"
        >
          <p class="numeric-range">{{ rangeLabel('viewedCap') }}</p>
          <input
            data-testid="profile-viewed-cap"
            type="number"
            [min]="state.bounds.viewedCap.min"
            [max]="state.bounds.viewedCap.max"
            [value]="viewedCap()"
            (input)="onCapInput('viewedCap', viewedCap, $event)"
          />
        </app-field>
      </div>

      @if (svc.failureMessage(); as message) {
        <div class="panel-banner">
          <app-error-banner [message]="message" />
        </div>
      }

      <app-settings-save-bar
        [dirty]="svc.dirty()"
        [saving]="svc.busy()"
        [saveLabel]="'settings.profile.save' | transloco"
        [resetLabel]="'settings.profile.reset' | transloco"
        [unsavedLabel]="'settings.profile.unsaved' | transloco"
        (save)="svc.save()"
        (reset)="onReset()"
      />
    </app-settings-group>

    @if (state.debugEnabled) {
      <app-settings-group icon="bug_report" [title]="'settings.profile.debugTitle' | transloco">
        <app-profile-debug-log [running]="svc.runActive()" [runId]="svc.run()?.id ?? null" />
      </app-settings-group>
    }
  </app-settings-stack>
}
```

`frontend/src/app/settings/profile/profile-section.component.scss` (layout glue only, tokens only):

```scss
.profile {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  align-items: flex-start;
  padding: var(--panel-inset-y) var(--panel-inset-x);
}

.profile-text {
  margin: 0;
  white-space: pre-line;
}

.profile-meta,
.profile-status,
.profile-empty,
.profile-hint {
  margin: 0;
  color: var(--text-muted);
  font-size: var(--fs-sm);
}

.caps {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr));
  gap: var(--space-3);
  padding: var(--panel-inset-y) var(--panel-inset-x);
}

.numeric-range {
  margin: 0 0 var(--space-1);
  color: var(--text-muted);
  font-size: var(--fs-sm);
}

.panel-banner {
  padding: 0 var(--panel-inset-x);
}
```

If Stylelint refuses the `14rem` literal, take the column minimum from the token the recommendation card's `.expert-grid` uses.

`settings-sections.ts`, right after the `ai` entry:

```ts
  { path: 'profile', icon: 'psychology', labelKey: 'settings.profile.title', group: 'general' },
```

`settings.routes.ts`, right after the `ai` route:

```ts
      {
        path: 'profile',
        title: sectionLabelKey('profile'),
        loadComponent: () =>
          import('./profile/profile-section.component').then(
            (module) => module.ProfileSectionComponent,
          ),
      },
```

No link from the For You view is added (out of scope).

i18n — `settings.profile` in `en.json`:

```json
    "profile": {
      "title": "Profile",
      "caption": "What the model has learned from your reading. For You recommendations are scored against it.",
      "empty": "No profile yet. Generate one now, or let the schedule build it.",
      "generated": "Generated {{when}} by {{model}} at {{host}}",
      "statusRunning": "Building your profile…",
      "statusUnchanged": "Your reading has not changed since the last profile, so the model was not asked again.",
      "statusNoHistory": "There is no reading history yet to build a profile from.",
      "statusFailed": "The last attempt failed: {{error}}",
      "generateNow": "Generate now",
      "chooseConnection": "No connection can build your profile. Choose one below, or add an LLM connection under AI.",
      "settingsTitle": "Schedule and inputs",
      "schedule": "Regenerate",
      "scheduleDesc": "How often a new profile is built. A scheduled run is skipped while your reading is unchanged.",
      "scheduleManual": "Only manually",
      "schedule6": "Every 6 hours",
      "schedule12": "Every 12 hours",
      "schedule24": "Every day",
      "schedule48": "Every 2 days",
      "schedule168": "Every week",
      "connection": "Connection",
      "connectionDesc": "The LLM connection that writes your profile.",
      "connectionActive": "The active connection",
      "keptCap": "Kept in history",
      "viewedCap": "Viewed in history",
      "save": "Save",
      "reset": "Reset",
      "unsaved": "Unsaved changes",
      "saved": "Saved.",
      "debugTitle": "Debug log"
    },
```

and in `de.json`:

```json
    "profile": {
      "title": "Profil",
      "caption": "Was das Modell aus deinem Lesen gelernt hat. „Für dich“-Empfehlungen werden daran gemessen.",
      "empty": "Noch kein Profil. Erstelle jetzt eins oder lass es nach Zeitplan erstellen.",
      "generated": "Erstellt am {{when}} mit {{model}} auf {{host}}",
      "statusRunning": "Dein Profil wird erstellt …",
      "statusUnchanged": "Dein Lesen hat sich seit dem letzten Profil nicht geändert, das Modell wurde nicht erneut gefragt.",
      "statusNoHistory": "Es gibt noch keinen Leseverlauf, aus dem sich ein Profil erstellen ließe.",
      "statusFailed": "Der letzte Versuch ist fehlgeschlagen: {{error}}",
      "generateNow": "Jetzt erstellen",
      "chooseConnection": "Keine Verbindung kann dein Profil erstellen. Wähle unten eine aus oder füge unter KI eine LLM-Verbindung hinzu.",
      "settingsTitle": "Zeitplan und Eingaben",
      "schedule": "Neu erstellen",
      "scheduleDesc": "Wie oft ein neues Profil erstellt wird. Ein geplanter Lauf entfällt, solange sich dein Lesen nicht ändert.",
      "scheduleManual": "Nur manuell",
      "schedule6": "Alle 6 Stunden",
      "schedule12": "Alle 12 Stunden",
      "schedule24": "Täglich",
      "schedule48": "Alle 2 Tage",
      "schedule168": "Wöchentlich",
      "connection": "Verbindung",
      "connectionDesc": "Die LLM-Verbindung, die dein Profil schreibt.",
      "connectionActive": "Die aktive Verbindung",
      "keptCap": "Behaltene im Verlauf",
      "viewedCap": "Angesehene im Verlauf",
      "save": "Speichern",
      "reset": "Zurücksetzen",
      "unsaved": "Ungespeicherte Änderungen",
      "saved": "Gespeichert.",
      "debugTitle": "Debug-Protokoll"
    },
```

Place each block as a sibling of `settings.ai` in its file; take the German wording of shared terms ("Behalten", "Angesehen") from the existing `settings.ai.recommendations.keptCap`/`viewedCap` strings so the two sections speak alike.

Run: `docker compose exec -T frontend npm test -- settings/profile settings/settings-sections settings/settings-nav settings/settings.routes`
Expected: PASS. `settings-nav.component.spec.ts` counts general sections from `SETTINGS_SECTIONS`, so it follows the new entry.

- [ ] **Step 5: The recommendation card loses the profile and the two caps**

`recommendation-settings.service.ts`: delete `keptCap` and `viewedCap` from `RecommendationExpertDefaults`, `RecommendationSettingsState`, `SaveRecommendationSettings` and `bodyFromState()`; `RecommendationExpertField` becomes `'favoritesCap' | 'candidatePoolSize' | 'picksLimit' | 'contextWindow'`; delete `profileText` from the state (and its comment). `recommendation-typed-seed.ts`: delete the `keptCap` and `viewedCap` lines. `recommendation-settings-card.component.ts`: delete the `keptCap`/`viewedCap` linked signals, their `reseed()` lines and `expertFieldValues()` entries; its docblock's "six caps" becomes "four caps"; `offersPrompt`'s comment drops "and a distilled profile". `recommendation-settings-card.component.html`: delete the two `app-field` blocks for `keptCap`/`viewedCap` and the `@if (state.profileText; as profile) { … }` drill-in. i18n (both files): delete `settings.ai.recommendations.profileLabel` and `settings.ai.info.profile`; keep `settings.ai.info.keptCap`/`viewedCap` (the profile section uses them); `settings.ai.recommendations.expertDesc` stays.

Specs: in `recommendation-settings-card.component.spec.ts` and `recommendation-settings.service.spec.ts` delete `keptCap`, `viewedCap` and `profileText` from the fixtures, drop the tests of the profile drill-in (`data-testid="recommendation-profile"`) and of the kept/viewed inputs, and fix any count of expert inputs (six → four). Add:

```ts
  it('no longer shows the profile or the kept and viewed caps', () => {
    const fixture = mount();

    expect(fixture.nativeElement.querySelector('[data-testid="recommendation-profile"]')).toBeNull();
    expect(fixture.nativeElement.textContent).not.toContain('Kept in history');
  });
```

Run: `docker compose exec -T frontend npm test -- settings/recommendations`
Expected: PASS.

- [ ] **Step 6: Deletion checks**

1. In `ProfileSettingsService.adoptRun()`, drop the state reload. "polls the current run … reloads the state when it ends" must fail. Restore.
2. In `schedulePoll()`, drop the `runActive()` guard. The same spec must fail on `expectNone`. Restore.
3. In `profile-section.component.html`, drop the `@else` choose-connection branch. "asks for a connection …" must fail. Restore.
4. In `ProfileDebugLogComponent.fetch()`, always reschedule. "polls while the run is active and stops once it is not" must fail. Restore.
5. In `bodyFromState()`, send `keptCap: state.defaults.keptCap`. "saves an instant change over the last-saved state" must fail. Restore.

- [ ] **Step 7: Gates, look at it, commit**

```bash
docker compose exec -T frontend npm test
docker compose exec -T frontend npm run check
```

Open `https://localhost:8443/settings/profile` in the Docker stack (light and dark, desktop and the mobile viewport): the section reads as the AI section does, Generate now shows "Building your profile…" until the run ends, the schedule and connection save with the toast, and the debug group appears only with debug mode on. Attach two screenshots to the task report.

```bash
git add frontend
git commit -m "feat(#1351): a top-level profile section shows, schedules and generates the profile"
```

---
### Task 10: Docs, the full gates on both legs, and a real run

**Files:**
- Modify: `docs/recommendations-runs.md`, `docs/for-you-scheduling.md`, `README.md` (only where it describes distillation or the profile connection)
- Modify: `backend/infection.json5` only if Step 4 says the ratchet may rise (never lower it)

- [ ] **Step 1: `docs/recommendations-runs.md`**

1. **Engines** section: the LLM engine "packs by the connection's context window, then scores the batches in waves and consolidates" — no distillation. Replace the Jev paragraph's profile sentences ("A Jev run distils first, through the profile connection picked … falls back to the last stored profile; without a profile connection the run fails …") with: `A run freezes the stored profile when it snapshots; Jev needs one and fails with a message that says so when there is none (an account without reading history). The LLM engine scores without a profile in that case.` The example model-list JSON keeps `"profile": "borrowed"`: the capability still says the engine cannot write the profile itself.
2. New section **The profile** before **The tick lock**:

   > The interest profile is generated by its own runs (`ProfileRun`, `Service/Recommendation/Profile`), not by recommendation runs. A profile run loads the reading history (favourites; kept and viewed up to the profile section's caps), fingerprints it together with the caps and the connection and model, and makes one LLM call through the profile connection (Settings → Profile; the active connection when none is chosen; a Jev connection can never be one). A usable reply replaces the stored profile (`user_recommendation_settings.profile_text` with its time, host and model); three unusable replies or three transport failures fail the run and leave the stored profile alone. No history completes the run without a call. A scheduled run whose fingerprint equals the newest completed run's completes as `unchanged` without a call; "Generate now" always calls the model.
   >
   > A recommendation run never builds a profile. At its snapshot it asks the profile module (`ProfileForRunInterface`) for one: the stored profile is frozen into the run; with none stored, a profile run is started and the run stays `pending` until it ends — completed, the run freezes whatever is stored (possibly nothing); failed, the run fails with `Profile generation failed: …`. The status JSON carries `waitingForProfile` meanwhile, and the For You toast says "Building your profile…". A run that failed before its snapshot is not resumable; a new run asks again.

3. **The tick lock**: add one sentence: `Profile runs take the same per-user lock (UserTickLock), so a profile tick and a recommendation tick never overlap; each sizes the TTL by the connection it calls.`
4. **When a run fails**: add the `Profile generation failed: …` failure and that it is not resumable.
5. **How fast it runs**: the ETA no longer counts a distillation phase; the first estimates after the deploy appear once a run on the new code has completed.

- [ ] **Step 2: `docs/for-you-scheduling.md`**

1. Intro: a second paragraph — `The interest profile has its own schedule in Settings → Profile: only manually, or every 6, 12 or 24 hours, every 2 days or weekly. A profile run is due one interval after the account's newest profile run, whatever its outcome, and a due account without a connection that can build the profile is skipped. Accounts that had auto-generate on were moved to a daily profile when this shipped (#1351).`
2. **With the background worker**: the worker also starts due profile runs every five minutes (`StartDueProfileRuns`) and ticks active ones every ten seconds (`AdvanceProfileRuns`).
3. **Without a worker**: the sweep endpoint and `/maintenance/tick` also start due profile runs and advance each active one a step; the response JSON is now `{ "startedRuns": n, "advancedRuns": m, "activeRuns": k, "startedProfileRuns": p, "advancedProfileRuns": q, "activeProfileRuns": r }` (both places it is printed).
4. **The on-demand drainer**: the terminate listener spawns it when a recommendation run *or a profile run* is active, and it drains both.

- [ ] **Step 3: README**

`grep -n -i "profile connection\|distil" README.md`; rewrite each hit to point at Settings → Profile. No new README section.

- [ ] **Step 4: Full gates, both legs**

From `backend/`:

```bash
composer check
composer md
composer test:parallel
```

From the repository root:

```bash
bash backend/bin/e2e-preflight.sh "$(git rev-parse --show-toplevel)"
docker compose exec php composer test
```

Then, from `backend/` with the branch committed (Infection reads tracked files only):

```bash
composer infection:diff
```

Expected: every gate green; Infection at or above `minMsi`. Escaped mutants on lines this branch wrote are fixed with a test that kills them, never with a lowered threshold.

- [ ] **Step 5: Migrate from empty on both dialects, validate**

The commands of Task 1 Step 13, now through all three new migrations: `[OK] Successfully migrated to version: DoctrineMigrations\Version20261003120000` and `[OK] The database schema is in sync with the mapping files.` on SQLite and MySQL. Clean up both scratch databases.

- [ ] **Step 6: A real run in the Docker stack**

With the dev stack on this checkout (the preflight above), the live migrations applied, and a real LLM connection configured on a test account:

1. Settings → Profile: "Generate now" → "Building your profile…" → the profile text with its time, host and model. Check `docker compose exec php bin/console dbal:run-sql "SELECT id, status, run_trigger, outcome, model FROM profile_run ORDER BY id DESC LIMIT 3"`.
2. Set the schedule to every 6 hours and reload: the choice persists. The due logic itself is pinned by `DueProfileRunFinderTest`; never back-date a row behind the running app.
3. On an account with no stored profile (a fresh test account with some reading history), start a For You run: the toast reads "Building your profile…", then the usual progress; the run completes with picks.
4. Scan the dev log: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'` — no deprecation or error this branch introduced.

Record what each step showed in the task report.

- [ ] **Step 7: Commit**

```bash
git add docs README.md backend/infection.json5
git commit -m "docs(#1351): the profile is generated on its own; runs freeze it"
```

---

## Self-review against the issue

1. ProfileRun entity, statuses, error, timestamps, provider/model, fingerprint, shared lock, 3 + 3 retries, failure keeps the profile — Tasks 1 and 3.
2. Stored profile text, time, model (and host) — Task 1 (`StoredProfile`), written in Task 3.
3. Schedule choices, due one interval after the newest run, fingerprint skip for scheduled runs only, "Generate now" always calls, migration of auto-generate users to 24 h — Tasks 1, 3, 4, 7.
4. Worker messages, `ForYouSweep::sweepOnce()` (so both maintenance routes), the drainer — Task 4.
5. User-level profile connection with `ON DELETE SET NULL` and in-memory clearing; active connection by default; "choose a connection" and skipped scheduled runs — Tasks 1, 3, 4, 7, 8, 9.
6. #1349's borrowing removed, carry-over of the active Jev connection's pick — Task 8.
7. Distillation removed from runs; snapshot freezes the stored profile; wait in `pending` with "Building your profile…"; profile failure fails the run; no history lets the LLM go on and fails Jev — Tasks 5 and 6.
8. Kept/viewed caps move to the profile section, favourites stay — Tasks 1, 7, 9.
9. API routes, limiter, problem+json, §6 checklist — Task 7.
10. Top-level Profile section with every listed control, polling, debug log; card loses the profile drill-in and the two caps — Task 9.
11. Run log `profile_run` reference with exactly-one owner, trimmed by `RunLogRetention`; purge leaves the profile alone — Tasks 1, 2, 3.
12. Docs — Task 10.
