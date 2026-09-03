<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Crypto\Exception\ApiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\ProviderConnectionFactory;
use App\Service\Ai\ProviderTimeouts;
use App\Service\Recommendation\Exception\RecommendationRunCancelledException;
use App\Service\Recommendation\Exception\RecommendationTickLockLostException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The driver-agnostic tick: #311's worker calls this exact method with no HTTP
 * request, and the poll endpoint calls it too. One user's tick runs at a time
 * behind a per-user lock, so a slow provider call can never overlap another from
 * a tab or the worker sweep.
 *
 * The snapshot phase freezes a pending run's candidate pool into fixed-size
 * batches with no provider call. The batch phase then sends a wave of concurrent
 * calls per tick (#344), bounded by batchConcurrency and the driver's regime: an
 * all-pruned batch records an empty winner set for free, a usable reply banks its
 * winners, and an unusable one retries in-tick with a corrective message from that
 * batch's own last invalid reply until MAX_ATTEMPTS drops it (#329). A transport
 * failure in a wave banks nothing, records one ceiling increment, and re-runs the
 * whole wave next tick from the unmoved cursor. Ranking is code's job, not the
 * model's: batches score every candidate against a shared rubric, and the ranker
 * orders the pooled scores globally. Before any batch call, the distillation phase
 * spends one call turning the reader's weighted history into a short preference
 * profile every later phase reads instead (#493); an unusable reply retries, then
 * degrades to no profile rather than failing. Once every batch call is done, the
 * consolidation phase sends one more call that re-scores, re-reasons and dedupes
 * the score-ordered cut in a single pass; it retries too, but a still-unusable
 * reply degrades — the run completes with the undeduped batch-score list. Every
 * ending funnels through RecommendationRunFinalizer, which re-checks each winning
 * entry still exists (the pool can be pruned mid-run) and writes the survivors as
 * RecommendationItems at dense positions before marking the run completed.
 *
 * The many constructor collaborators are deliberate: the advancer is the
 * pipeline's composition root, and each is a seam the tests swap or drive
 * independently — see RefreshRunner for the same shape.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final class RecommendationRunAdvancer
{
    private const string LOCK_NAME_PREFIX = 'ai-recommendations-';

    /**
     * Headroom over the longest silence a live holder can produce, for the tick
     * stretches that stream nothing and so beat nothing: candidate loading and
     * prompt assembly before the first request, and ranking, banking and recording
     * between calls and waves.
     *
     * The stretch to re-tune against is the snapshot tick, which makes no provider
     * call and so never beats — the TTL is its whole budget. That budget fell from
     * 2100 s to 480 s with #444. It holds because the work is database- and CPU-bound
     * over a pool candidatePoolSize caps, and because the poll driver on Strato is
     * killed at 240 s before the lock expires; an unbounded snapshot phase would
     * break that assumption. The margin also clears Strato's 240 s request cap,
     * which on the standard profile is the only reason the TTL does: 180 s of
     * first-byte wait alone would fall short, and a poll tick killed at that cap may
     * never have beaten, so the TTL is its floor.
     *
     * Public so RecommendationRunAdvancerTest can pin the exact TTL against its two
     * inputs rather than a re-derivation of the formula's shape.
     */
    public const float LOCK_TTL_MARGIN_SECONDS = 300.0;

    /**
     * The most concurrent batch calls a poll tick may fan out. A poll tick is
     * a web request, so its wave stays small however high the connection's
     * batchConcurrency is set; the worker, which owns its process, sends the
     * full configured cap (#344).
     */
    public const int POLL_MAX_CONCURRENCY = 2;

    public function __construct(
        private readonly RecommendationRunRepository $runs,
        private readonly LockFactory $lockFactory,
        private readonly AiProviderConfigurator $configurator,
        private readonly ProviderConnectionFactory $connections,
        private readonly ClockInterface $clock,
        private readonly RecommendationSettingsResolver $settingsResolver,
        private readonly RecommendationCandidateLoader $candidateLoader,
        private readonly RecommendationHistoryLoader $historyLoader,
        private readonly RecommendationPromptBuilder $promptBuilder,
        private readonly EntityManagerInterface $entityManager,
        private readonly RecommendationTickCheckpoint $checkpoint,
        private readonly RecommendationProfileDistiller $distiller,
        private readonly RecommendationBatchWave $batchWave,
        private readonly RecommendationConsolidationResolver $consolidationResolver,
        private readonly RecommendationRunFinalizer $finalizer,
        private readonly TickLockKeepalive $keepalive,
    ) {
    }

    /**
     * The one place the per-user lock's name is formed, so a caller that has
     * to name it in a log line -- RecommendationPollDriver, reporting a lock
     * held with nobody behind it (#439) -- names the very lock this method
     * takes rather than a second copy of the convention.
     */
    public static function lockNameFor(User $user): string
    {
        return self::LOCK_NAME_PREFIX . ($user->getId() ?? 0);
    }

    public function advance(User $user, TickDriver $driver = TickDriver::Poll): RecommendationRunReport
    {
        $lockName = self::lockNameFor($user);
        $lock = $this->lockFactory->createLock($lockName, $this->lockTtlFor($user));

        if (!$lock->acquire()) {
            // Silent: a failed acquire is the healthy, frequent case — somebody
            // else is advancing this run. Only the caller can tell that apart
            // from a stall, since only it knows whether a driver claims alive
            // (#439); RecommendationPollDriver logs the warning there.
            return RecommendationRunReport::busy();
        }

        // A hard request-time kill (Strato caps a request at 240s, below the
        // lock TTL) never reaches the finally below, so without this the lock
        // sits held its full TTL (8 min standard, 20 slow) and freezes the run.
        // The store's delete is token-scoped, so on the normal path or once
        // another process re-acquired the name the hook is a harmless no-op. A
        // true hard kill (SIGKILL) skips shutdown hooks too, so the TTL backstops.
        register_shutdown_function(static function () use ($lock): void {
            try {
                $lock->release();
            } catch (\Throwable) {
                // Best-effort: a failure to release during shutdown must not
                // raise a second fatal. The TTL still bounds the stall.
            }
        });

        // From here the lock is refreshed for as long as the tick keeps
        // streaming, which is what lets lockTtlFor() size the TTL against one
        // silence instead of the whole tick (#444).
        $this->keepalive->hold($lock, $lockName);

        try {
            return $this->tick($user, $driver);
        } finally {
            // Disarmed before the release, never after: a beat between the
            // two would refresh a lock that is on its way out, and once the
            // name is free another process may already hold it.
            $this->keepalive->release();
            $lock->release();
        }
    }

    /**
     * How long this account's tick may hold its lock.
     *
     * The lock must outlive every silence a *live* holder can produce, or it becomes
     * stealable mid-tick: a second process would start a concurrent tick for the same
     * run, and RecommendationRun carries no optimistic-version guard, so the two could
     * double-bank winners and double-bill provider spend.
     *
     * Invariant since #444: a live holder refreshes the lock no more often than
     * TickLockKeepalive::MINIMUM_INTERVAL_SECONDS — a throttle ceiling, not a promise
     * of a refresh — and goes without one only while its stream is silent. So the TTL
     * must clear the longest silence, not the longest tick, and that silence is one
     * first-byte wait: a beat rides a streamed chunk, and a provider that has not
     * started answering yields none until the idle timeout fires. Sizing the TTL from
     * the throttle would read the ceiling as a floor and let a live holder's lock lapse.
     *
     * Read from the connection rather than a constant since #433: a slow-marked
     * connection waits a quarter of an hour for a first byte where the standard profile
     * waits three minutes, and a TTL sized for standard would expire under one of its
     * calls. Only that connection pays the longer TTL — pinning every account to the
     * slow ceiling would stretch every stall behind a dead holder. The read happens
     * just outside the lock, so an account that flips the setting as a tick starts gets
     * one tick sized to the previous profile; the window is one statement wide, both
     * racers read the same row, and the next tick corrects it.
     *
     * This replaced MAX_ATTEMPTS x the wall clock of the longest legal call (2100 s
     * here, 11 100 s slow). Nothing refreshed the lock then, so it had to cover the
     * whole tick — a worker that died mid-tick stranded the run for that full span
     * while its replacement logged "already acquired" against a holder that no longer
     * existed, twice in production (#439).
     */
    private function lockTtlFor(User $user): float
    {
        $settings = $this->configurator->settingsFor($user);
        $timeouts = null === $settings
            ? ProviderTimeouts::standard()
            : $this->connections->timeoutsFor($settings);

        return $timeouts->firstByteSeconds + self::LOCK_TTL_MARGIN_SECONDS;
    }

    private function tick(User $user, TickDriver $driver): RecommendationRunReport
    {
        $run = $this->runs->findActiveForUser($user);

        if (null === $run) {
            $latest = $this->runs->findLatestForUser($user);

            return null === $latest ? RecommendationRunReport::none() : RecommendationRunReport::fromRun($latest);
        }

        try {
            return $this->tickActiveRun($run, $user, $driver);
        } catch (RecommendationRunCancelledException | RecommendationTickLockLostException) {
            // Stopped mid-call for one of the two reasons a tick may stop
            // writing: the user cancelled, or another process took the per-user
            // lock (#444). refresh() discards what this tick computed and re-reads
            // the row the current owner wrote, so the caller gets the real state.
            // Nothing is flushed: the guard fires before any run mutation.
            $this->entityManager->refresh($run);

            return RecommendationRunReport::fromRun($run);
        } catch (AiNotConfiguredException | ApiKeyUnreadableException $e) {
            // Shared by both drivers (#311 fix): an account that loses its
            // provider, model, or readable key can never advance again, so the
            // run is failed here rather than only when the worker sweep ticks it.
            // Before this, a poll-only install retried such a run forever, since
            // only AdvanceRecommendationRunsHandler classified it. The exception
            // still propagates: the HTTP mapping and worker fault floor are unchanged.
            $this->failPermanently($run, self::failureMessageFor($e));

            throw $e;
        }
    }

    private function tickActiveRun(RecommendationRun $run, User $user, TickDriver $driver): RecommendationRunReport
    {
        $settings = $this->configurator->requireConfiguration($user);
        if (!$settings->hasModel()) {
            throw new AiNotConfiguredException('No model is chosen.');
        }

        if (RecommendationRun::STATUS_PENDING === $run->getStatus()) {
            return $this->snapshotTick($run, $user);
        }

        if ($run->progress()->distillPending) {
            return $this->distillTick($run, $user, $settings);
        }

        if ($run->progress()->isConsolidationPhase) {
            return $this->consolidateTick($run, $user, $settings);
        }

        return $this->providerTick($run, $user, $settings, $driver);
    }

    private function failPermanently(RecommendationRun $run, string $message): void
    {
        $run->fail($message, $this->clock->now());
        $this->entityManager->flush();
    }

    private static function failureMessageFor(AiNotConfiguredException | ApiKeyUnreadableException $e): string
    {
        return $e instanceof ApiKeyUnreadableException
            ? 'The stored API key can no longer be read.'
            : 'The AI provider is no longer configured.';
    }

    private function snapshotTick(RecommendationRun $run, User $user): RecommendationRunReport
    {
        $userId = $this->requireUserId($user);
        $effectiveSettings = $this->settingsResolver->forUser($user);
        $now = $this->clock->now();
        $candidates = $this->candidateLoader->load($userId, new CandidatePoolRequest(
            // P<N>D is calendar-day arithmetic; it only equals N x 24h because
            // Kernel.php pins the process timezone to UTC, where every day is
            // exactly 24h (no DST shift to absorb).
            since: $now->sub(new \DateInterval(\sprintf('P%dD', $effectiveSettings->lookbackDays))),
            poolSize: $effectiveSettings->candidatePoolSize,
            orderSeed: (int) $now->getTimestamp(),
        ));

        if ([] === $candidates) {
            // An empty feed is not an error: freeze an empty batch plan (the
            // only legal way to leave PENDING) and complete immediately.
            $run->snapshot([]);
            $run->complete($this->clock->now());
            $this->entityManager->flush();

            return RecommendationRunReport::fromRun($run);
        }

        $history = $this->historyLoader->load($userId, $effectiveSettings);
        $batches = $this->promptBuilder->packBatches($candidates, $history, $effectiveSettings);
        $run->snapshot($batches);
        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }

    private function providerTick(
        RecommendationRun $run,
        User $user,
        AiProviderSettings $settings,
        TickDriver $driver,
    ): RecommendationRunReport {
        $this->markFirstBatchBeforeCallingProvider($run);
        $userId = $this->requireUserId($user);
        $effectiveSettings = $this->settingsResolver->forUser($user);
        $waveSize = $this->waveSize($run, $settings, $driver);

        $winnersPerBatch = $this->resolveWave($run, $settings, $effectiveSettings, $userId, $waveSize);

        return $this->pickEndingAfterWave($run, $winnersPerBatch);
    }

    /** Commit the start signal before the blocking provider request. A status
     * poll during that request must see that the ETA can now count down. */
    private function markFirstBatchBeforeCallingProvider(RecommendationRun $run): void
    {
        if ($run->hasFirstBatchStarted()) {
            return;
        }

        $run->markFirstBatchStarted();
        $this->entityManager->flush();
    }

    /**
     * Delegates the wave to RecommendationBatchWave and turns its atomic-wave
     * transport failure into the run's own accounting: one ceiling increment
     * for the whole wave -- the wave threw once, whatever its size, so a wave
     * of four cannot exhaust a ceiling of three at once -- failing the run if
     * that increment reached the ceiling, then re-throwing so the caller's
     * mapping is unchanged and the next tick re-runs the wave from the unmoved
     * cursor (#344). An unreadable key is not a transport failure -- the wave
     * settles its log rows and lets it propagate untouched to tick(), which
     * fails the run permanently.
     *
     * @return list<list<array{id: int, score: int, reason: string}>>
     */
    private function resolveWave(
        RecommendationRun $run,
        AiProviderSettings $settings,
        EffectiveRecommendationSettings $effectiveSettings,
        int $userId,
        int $waveSize,
    ): array {
        try {
            return $this->batchWave->resolve($run, $settings, $effectiveSettings, $userId, $waveSize);
        } catch (ProviderUnreachableException | CredentialsRejectedException $e) {
            $this->recordTransportFailure($run, $settings, $e->getMessage());

            throw $e;
        }
    }

    /**
     * How many batches this tick sends at once: the configured concurrency,
     * clamped to the driver's regime and to the batches the plan has left. A poll
     * tick never sends more than POLL_MAX_CONCURRENCY; the worker sends the full
     * configured cap. The hard MAX_BATCH_CONCURRENCY ceiling caps both, and a floor
     * of 1 guards a stored `batchConcurrency` of 0 or less — unreachable through the
     * API's `Range(1..4)`, but a direct-DB value that low would wedge the run in a
     * zero-progress tick forever (#344).
     */
    private function waveSize(RecommendationRun $run, AiProviderSettings $settings, TickDriver $driver): int
    {
        // The run's first batch wave goes out as a single call, whatever the
        // configured concurrency, so it warms the provider's prompt-prefix cache
        // before the fan-out races for it (#495). Every batch shares a byte-identical
        // prefix (system role, profile, favourites, pool frame), so warming it once
        // is nearly free on cache-capable providers. A resumed run never re-warms.
        if (0 === $run->progress()->nextBatchIndex) {
            return 1;
        }

        $batchesRemaining = \count($run->getCandidateBatches()) - $run->progress()->nextBatchIndex;

        return min($this->effectiveCap($settings, $driver), $batchesRemaining);
    }

    /**
     * Worker is the one driver that owns its process rather than a bounded web
     * request, so it is the branch named explicitly here; every other driver (Poll,
     * and Sweep's cron-triggered web request) takes the POLL_MAX_CONCURRENCY clamp by
     * falling through the `else`. See TickDriver's docblock for why Sweep belongs here.
     */
    private function effectiveCap(AiProviderSettings $settings, TickDriver $driver): int
    {
        $cap = TickDriver::Worker === $driver
            ? $settings->batchConcurrency()
            : min($settings->batchConcurrency(), self::POLL_MAX_CONCURRENCY);

        return max(1, min($cap, AiProviderSettings::MAX_BATCH_CONCURRENCY));
    }

    /**
     * Banks every resolved batch of the wave in plan order — so batchesDone advances
     * by the wave size and the winner list stays batch-ordered — then checkpoints: the
     * next tick runs the remaining batches, or once every batch is done, the
     * consolidation phase that now follows every plan regardless of batch count (#493).
     *
     * @param list<list<array{id: int, score: int, reason: string}>> $winnersPerBatch
     */
    private function pickEndingAfterWave(RecommendationRun $run, array $winnersPerBatch): RecommendationRunReport
    {
        foreach ($winnersPerBatch as $winners) {
            $run->recordBatchWinners($winners);
        }

        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }

    /**
     * Delegates the distillation phase's single provider call to
     * RecommendationProfileDistiller and writes what it returns. A transport failure
     * throws out of distill(), folded into the run's accounting here (one ceiling
     * increment, then re-thrown) — the same envelope resolveWave() gives the batch
     * phase. An unusable reply is retried across ticks or degraded to no profile:
     * distillation has no pool to fall back to, unlike consolidation's undeduped list.
     * Either ending records the profile (possibly null) and checkpoints, so the next
     * tick reads distillPending false and moves on to the batches.
     */
    private function distillTick(
        RecommendationRun $run,
        User $user,
        AiProviderSettings $settings,
    ): RecommendationRunReport {
        $userId = $this->requireUserId($user);
        $effectiveSettings = $this->settingsResolver->forUser($user);

        try {
            $outcome = $this->distiller->distill($run, $settings, $userId, $effectiveSettings);
        } catch (ProviderUnreachableException | CredentialsRejectedException $e) {
            $this->recordTransportFailure($run, $settings, $e->getMessage());

            throw $e;
        }

        if (!$outcome->usable) {
            return $this->retryOrDegrade(
                $run,
                $outcome->requireUnusableReply(),
                fn (): RecommendationRunReport => $this->recordProfileAndCheckpoint($run, null),
            );
        }

        return $this->recordProfileAndCheckpoint($run, $outcome->profileText);
    }

    /**
     * The write both distillTick endings share: freeze the run's profile --
     * a usable outcome's text or a degraded null -- and checkpoint, so the
     * caller and every future tick see distillPending false.
     */
    private function recordProfileAndCheckpoint(RecommendationRun $run, ?string $profileText): RecommendationRunReport
    {
        $run->recordProfile($profileText);
        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }

    /**
     * Delegates the consolidation phase's single provider call to
     * RecommendationConsolidationResolver and writes what it returns. A transport
     * failure throws out of resolve(), folded into the run's accounting here (one
     * ceiling increment, then re-thrown) — the same envelope resolveWave() gives the
     * batch phase. An unusable reply is retried across ticks or degraded to the
     * undeduped, unreasoned batch-score list; a usable one, or an all-pruned pool,
     * finalizes. Every plan reaches this phase now (#493) — no single-batch shortcut.
     */
    private function consolidateTick(
        RecommendationRun $run,
        User $user,
        AiProviderSettings $settings,
    ): RecommendationRunReport {
        $userId = $this->requireUserId($user);
        $effectiveSettings = $this->settingsResolver->forUser($user);
        $picksLimit = $effectiveSettings->picksLimit;

        try {
            $outcome = $this->consolidationResolver->resolve(
                $run,
                $settings,
                $userId,
                $picksLimit,
                $effectiveSettings,
            );
        } catch (ProviderUnreachableException | CredentialsRejectedException $e) {
            $this->recordTransportFailure($run, $settings, $e->getMessage());

            throw $e;
        }

        if (!$outcome->usable) {
            return $this->retryOrDegrade(
                $run,
                $outcome->requireUnusableReply(),
                fn (): RecommendationRunReport => $this->finalizer->finalize($run, $outcome->requireFallbackPool()),
            );
        }

        return $this->finalizer->finalize($run, $outcome->ranked);
    }

    /**
     * Guarded like every banking write, for the same reason: the counter and the
     * fail() the ceiling triggers are the run's own state, and a tick that may no
     * longer write must write none of it (#439). The entity cannot refuse it —
     * RecommendationRun::recordTransportFailure() judges the status this tick read
     * before the call, so a run another process has since completed is failed over it.
     *
     * Nothing is swallowed while the lock is held and the run is live — every ordinary
     * transport failure: the guard cannot throw there, and the caller's re-throw
     * carries the provider's error out. When it does throw, this tick has stopped
     * owning the run, and tick() answers with the state its real owner wrote.
     */
    private function recordTransportFailure(
        RecommendationRun $run,
        AiProviderSettings $settings,
        string $failureDetail,
    ): void {
        $this->checkpoint->guard($run);

        $ceilingReached = $run->recordTransportFailure();
        if ($ceilingReached) {
            // The real per-call detail, not a hardcoded "could not be reached":
            // most transport failures are the provider refusing or truncating a
            // call it received, and one flat unreachable message hid a fixable
            // 400 behind a network story (#329). Base URL stays: which endpoint failed.
            $run->fail(
                sprintf('The AI provider at %s failed: %s', $settings->getBaseUrl(), $failureDetail),
                $this->clock->now(),
            );
        }
        $this->entityManager->flush();
    }

    /**
     * The retry envelope the distillation and consolidation phases share: record the
     * invalid reply, and once attempts are exhausted hand off to the degraded ending
     * (no profile, or an undeduped list) rather than failing the run; otherwise
     * checkpoint and wait for the corrective retry next tick. The batch phase no longer
     * comes here: its retries happen in-tick within the wave, so it never writes the
     * run's cross-tick attempt state (#344).
     *
     * @param callable(): RecommendationRunReport $onAttemptsExhausted
     */
    private function retryOrDegrade(
        RecommendationRun $run,
        string $content,
        callable $onAttemptsExhausted,
    ): RecommendationRunReport {
        $run->recordInvalidReply($content);
        if ($run->progress()->attemptsExhausted) {
            return $onAttemptsExhausted();
        }
        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }

    private function requireUserId(User $user): int
    {
        return $user->getId() ?? throw new \LogicException('Cannot advance a run for an unsaved account.');
    }
}
