<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\Entry;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Repository\EntryRepository;
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
 * The driver-agnostic tick: #311's worker will call this exact method with no
 * HTTP request in sight, and the poll endpoint calls it too. One user's tick
 * runs at a time behind a per-user lock, so a slow provider call from one
 * poll can never overlap a second one racing in from another tab or the
 * worker sweep.
 *
 * The snapshot phase freezes a pending run's candidate pool into fixed-size
 * batches with no provider call made. The batch phase then sends a wave of
 * concurrent provider calls per tick (#344), bounded by the connection's
 * batchConcurrency and the driver's regime: a batch whose entries are all
 * pruned records an empty winner set for free, a usable reply banks the
 * batch's winners, and an unusable one is retried in-tick with a corrective
 * message -- built from that batch's own last invalid reply -- until
 * MAX_ATTEMPTS rounds drop it as an empty winner set (#329). A transport
 * failure anywhere in a wave banks nothing, records one ceiling increment,
 * and re-runs the whole wave next tick from the unmoved cursor. Ranking is
 * code's job, not the model's: the
 * batches score every candidate against a shared rubric, and the ranker
 * orders the pooled scores globally. Once every batch call is done, a run
 * with more than one batch enters the dedup phase: one more provider call
 * receives the score-ordered cut of the pool and names the entries that
 * duplicate a better-ranked story. That reply retries with a corrective
 * message too, but a dedup reply that stays unusable degrades instead of
 * failing — the run completes with the undeduped top list. A single-batch
 * run has nothing to dedup, so it finalizes straight from its ranked pool
 * instead. finalize() re-checks that each winning entry still exists — the
 * pool can be pruned mid-run — and writes the survivors as
 * RecommendationItems at dense positions before marking the run completed.
 *
 * The many constructor collaborators are deliberate: the advancer is the
 * recommendation pipeline's composition root (lock, run persistence, AI
 * configuration, settings resolution, candidate/history loading, prompt
 * packing, the batch wave and the dedup provider call), and each is a seam the
 * tests swap or drive independently — see RefreshRunner for the same shape.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final class RecommendationRunAdvancer
{
    private const string LOCK_NAME_PREFIX = 'ai-recommendations-';

    /**
     * Headroom over the longest silence a live holder can produce, for the
     * stretches of a tick that stream nothing and so beat nothing: candidate
     * loading and prompt assembly before the first request, and the ranking,
     * banking and recording between calls and waves.
     *
     * It also has to clear Strato's 240 s cap on a web request. A poll tick
     * killed at that cap may have died before its first chunk, so it never
     * beat at all and this margin is the whole of its floor.
     */
    private const float LOCK_TTL_MARGIN_SECONDS = 300.0;

    /**
     * The most concurrent batch calls a poll tick may fan out. A poll tick is
     * a web request, so its wave stays small however high the connection's
     * batchConcurrency is set; the worker, which owns its process, sends the
     * full configured cap (#344).
     */
    public const int POLL_MAX_CONCURRENCY = 2;

    public function __construct(
        private readonly RecommendationRunRepository $runs,
        private readonly EntryRepository $entries,
        private readonly LockFactory $lockFactory,
        private readonly AiProviderConfigurator $configurator,
        private readonly ProviderConnectionFactory $connections,
        private readonly ClockInterface $clock,
        private readonly RecommendationSettingsResolver $settingsResolver,
        private readonly RecommendationCandidateLoader $candidateLoader,
        private readonly RecommendationHistoryLoader $historyLoader,
        private readonly RecommendationPromptBuilder $promptBuilder,
        private readonly ChatCompletionClient $chat,
        private readonly EntityManagerInterface $entityManager,
        private readonly RecommendationWinnerRanker $ranker,
        private readonly RecommendationDuplicateParser $duplicateParser,
        private readonly RecommendationCallRecorder $callRecorder,
        private readonly RecommendationCancellationCheckpoint $cancellation,
        private readonly RecommendationBatchWave $batchWave,
        private readonly RecommendationCompletionRequestFactory $requestFactory,
        private readonly TickLockKeepalive $keepalive,
    ) {
    }

    public function advance(User $user, TickDriver $driver = TickDriver::Poll): RecommendationRunReport
    {
        $lockName = self::LOCK_NAME_PREFIX . ($user->getId() ?? 0);
        $lock = $this->lockFactory->createLock($lockName, $this->lockTtlFor($user));

        if (!$lock->acquire()) {
            return RecommendationRunReport::busy();
        }

        // A hard request-time kill -- Strato caps a web request at 240s, below
        // the lock TTL -- never reaches the finally below, so without this the
        // lock would sit held for its full TTL (8 min on the standard profile,
        // 20 on the slow one) and freeze the run until it expired. A shutdown
        // hook releases it on that path.
        // The store's delete is token-scoped, so on the normal path (the
        // finally has already released) or once another process has re-acquired
        // the same name, the hook is a harmless no-op -- it can never free a
        // lock this request no longer owns. A true hard kill (SIGKILL) skips
        // shutdown hooks too, so the TTL stays as the ultimate backstop.
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
     * The lock must outlive every silence a *live* holder can produce, or it
     * becomes stealable mid-tick: a second process (the worker sweep or
     * another poll) would start a concurrent tick for the same run, and
     * RecommendationRun carries no optimistic-version guard, so the two could
     * double-bank winners and double-bill provider spend.
     *
     * Invariant since #444: a live holder refreshes the lock at least every
     * TickLockKeepalive::MINIMUM_INTERVAL_SECONDS, so the TTL only has to
     * clear the longest gap between two refreshes -- not the longest tick.
     * That gap is one first-byte wait: a beat rides on a streamed chunk, and
     * a provider that has not started answering yields none until the stream
     * idle timeout fires. Sizing it from the throttle instead would let a
     * live holder's lock lapse mid-call.
     *
     * Read from the connection rather than from a constant since #433: a
     * connection the account marked slow waits a quarter of an hour for a
     * first byte where the standard profile waits three minutes, and a TTL
     * sized for the standard profile would expire under one of its calls.
     * Only that connection pays the longer TTL -- pinning every account to
     * the slow ceiling would stretch every stall behind a dead holder.
     *
     * The read happens just outside the lock, so an account that flips the
     * setting in the same instant a tick starts gets one tick sized to the
     * previous profile. The window is a single statement wide and the next
     * tick corrects it; both racers read the same row, so they agree.
     *
     * What this replaced was MAX_ATTEMPTS x the wall clock of the longest
     * call the tick could legally make: 2100 s here, 11 100 s on the slow
     * profile. Nothing refreshed the lock then, so it had to cover the whole
     * tick -- and a worker that died mid-tick stranded the run for that full
     * span while its replacement logged "already acquired" against a holder
     * that no longer existed, twice in production (#439).
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
            // Stopped mid-call, for one of the two reasons a tick may not
            // keep writing: the user cancelled the run, or another process
            // took the per-user lock this tick was working under (#444).
            // refresh() throws away everything this tick computed and
            // re-reads the row whoever owns the run now wrote, so the caller
            // is told the run's real state rather than handed the progress of
            // a run nobody is waiting for -- or of one somebody else is
            // advancing. Nothing is flushed: the guard fires before any run
            // mutation, so there is no half-written checkpoint to undo.
            $this->entityManager->refresh($run);

            return RecommendationRunReport::fromRun($run);
        } catch (AiNotConfiguredException | ApiKeyUnreadableException $e) {
            // Shared by both drivers (#311 fix): an account that loses its
            // provider, model, or a readable key can never advance again on
            // any driver, so the run is failed right here rather than only
            // when the worker sweep happens to be the one ticking it. Before
            // this, a poll-only install left such a run stuck retried
            // forever, because only AdvanceRecommendationRunsHandler applied
            // this classification. The exception still propagates: the
            // controller's HTTP mapping and the worker's own fault-isolation
            // floor are unchanged.
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

        if ($run->progress()->isDedupPhase) {
            return $this->dedupTick($run, $user, $settings);
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
        $userId = $this->requireUserId($user);
        $effectiveSettings = $this->settingsResolver->forUser($user);
        $waveSize = $this->waveSize($run, $settings, $driver);

        $winnersPerBatch = $this->resolveWave($run, $settings, $effectiveSettings, $userId, $waveSize);

        return $this->pickEndingAfterWave($run, $winnersPerBatch);
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
     * How many batches this tick sends at once: the connection's configured
     * concurrency, clamped to the driver's regime and to the batches the plan
     * has left. A poll tick is a web request, so it never sends more than
     * POLL_MAX_CONCURRENCY however high the connection is set; the worker owns
     * its process and sends the full configured cap. The hard
     * MAX_BATCH_CONCURRENCY ceiling protects both, and a floor of 1 protects
     * against a stored `batchConcurrency` of 0 or less -- unreachable through
     * the API's `Range(1..4)` validation, but a direct-DB value that low would
     * otherwise wedge the run in a zero-progress tick forever (#344).
     */
    private function waveSize(RecommendationRun $run, AiProviderSettings $settings, TickDriver $driver): int
    {
        $batchesRemaining = \count($run->getCandidateBatches()) - $run->progress()->nextBatchIndex;

        return min($this->effectiveCap($settings, $driver), $batchesRemaining);
    }

    private function effectiveCap(AiProviderSettings $settings, TickDriver $driver): int
    {
        $cap = TickDriver::Poll === $driver
            ? min($settings->batchConcurrency(), self::POLL_MAX_CONCURRENCY)
            : $settings->batchConcurrency();

        return max(1, min($cap, AiProviderSettings::MAX_BATCH_CONCURRENCY));
    }

    /**
     * Banks every resolved batch of the wave in plan order -- so batchesDone
     * advances by the wave size and the winner list stays batch-ordered -- then
     * takes the plan's ending once: a single-batch run finalizes straight from
     * its ranked pool, a multi-batch run checkpoints (its next tick runs the
     * remaining batches, or the dedup barrier once every batch is done).
     *
     * @param list<list<array{id: int, score: int, reason: string}>> $winnersPerBatch
     */
    private function pickEndingAfterWave(RecommendationRun $run, array $winnersPerBatch): RecommendationRunReport
    {
        foreach ($winnersPerBatch as $winners) {
            $run->recordBatchWinners($winners);
        }

        if (!$run->progress()->needsDedup) {
            return $this->finalize($run, $this->ranker->ranked($run->getWinners()));
        }

        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }

    private function dedupTick(
        RecommendationRun $run,
        User $user,
        AiProviderSettings $settings,
    ): RecommendationRunReport {
        $userId = $this->requireUserId($user);
        $picksLimit = $this->settingsResolver->forUser($user)->picksLimit;
        $pool = $this->ranker->cutForDedup($this->ranker->ranked($run->getWinners()), $picksLimit);
        $linesById = $this->candidateLoader->linesForIds($userId, array_column($pool, 'id'));
        $pool = self::stillPresent($pool, $linesById);

        if ([] === $pool) {
            // Every ranked entry was pruned since its batch ran: there is
            // nothing left to dedup, so this is progress, not failure --
            // mirrors providerTick's own all-pruned short-circuit.
            return $this->finalize($run, []);
        }

        $messages = $this->promptBuilder->messagesWithCorrectiveTail(
            $this->promptBuilder->dedupMessages($pool, $linesById),
            $run->getLastInvalidReply(),
            RecommendationPromptText::DEDUP_CORRECTIVE,
        );

        $recordedCall = $this->callRecorder->begin(
            $run,
            RecommendationRunLog::PHASE_DEDUP,
            null,
            $messages,
            $settings->getModel() ?? '',
        );

        $content = $this->callProvider(
            $run,
            $settings,
            $this->requestFactory->create(
                $settings,
                $messages,
                \count($pool),
                RecommendationResponseSchema::Duplicates,
            ),
            $recordedCall,
        );

        $result = $this->duplicateParser->parse($content, array_column($pool, 'id'));
        $recordedCall->settle($content, $result->usable);
        $this->cancellation->guard($run);

        if (!$result->usable) {
            return $this->recordUnusableDedupReply($run, $content, $pool);
        }

        return $this->finalize($run, $this->withoutDuplicates($pool, $result->duplicateIds));
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $pool
     * @param array<int, PromptLine>                           $linesById entries pruned since their batch are absent
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    private static function stillPresent(array $pool, array $linesById): array
    {
        return array_values(array_filter(
            $pool,
            static fn (array $winner): bool => isset($linesById[$winner['id']]),
        ));
    }

    /**
     * A dedup reply that stays unusable after every retry degrades instead
     * of failing: the batch calls' ranking work is already done, and an
     * undeduped top list beats throwing the whole run away over a cosmetic
     * cleanup. Transport failures keep failing the run -- an unreachable
     * provider is not a degraded answer.
     *
     * @param list<array{id: int, score: int, reason: string}> $pool
     */
    private function recordUnusableDedupReply(
        RecommendationRun $run,
        string $content,
        array $pool,
    ): RecommendationRunReport {
        return $this->retryOrDegrade(
            $run,
            $content,
            fn (): RecommendationRunReport => $this->finalize($run, $pool),
        );
    }

    /**
     * Never reaches below the dedup cut for backfill: entries beyond it were
     * never shown to the dedup call, so pulling them in could reintroduce
     * unchecked duplicates. A final list shorter than the picks limit is the
     * accepted cost.
     *
     * How much shorter is bounded at the parser, not here: a reply naming
     * more than half of the entries it was shown never reaches this method,
     * so at least half the pool always survives and a completed run always
     * has recommendations in it. This used to be guarded here instead, by
     * exempting the best-ranked entry -- it cannot duplicate a better-ranked
     * one -- which kept the list off zero but let it be gutted down to one
     * (#396).
     *
     * @param non-empty-list<array{id: int, score: int, reason: string}> $pool
     * @param list<int>                                                  $duplicateIds
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    private function withoutDuplicates(array $pool, array $duplicateIds): array
    {
        return array_values(array_filter(
            $pool,
            static fn (array $winner): bool => !\in_array($winner['id'], $duplicateIds, true),
        ));
    }

    /**
     * The dedup phase's single provider call, recorded for the debug view from
     * the moment the request goes out (#309). A transport failure -- the
     * provider unreachable, or refusing the key -- never produced a reply for
     * the parser to judge, so it must not consume an `attempts` retry the way
     * an unusable reply does. It counts against its own ceiling instead, so a
     * provider that is persistently broken still fails the run eventually
     * (#308 final review, Important 2) rather than ticking forever; either
     * way the exception is re-thrown so the controller still maps it to its
     * problem type and the caller still sees the error on this tick. The batch
     * phase reads its wave through RecommendationBatchWave::resolve() instead
     * (#344), which delegates to completeMany -- that folds a per-call
     * transport failure into that call's outcome rather than throwing.
     *
     * The generic \Throwable catch below exists only to settle the log row:
     * begin() has already persisted it, and a verdict that stays null reads
     * to the debug panel as "still streaming" forever (its one other
     * producer, streamingTextForUser(), has no way to tell a genuinely
     * abandoned call from a live one). credentials() -- decrypting the
     * stored key -- runs inside this same try on purpose, because an
     * unreadable key (ApiKeyUnreadableException, e.g. after a master-secret
     * rotation) never produced a reply either; it is not classified as a
     * transport failure and does not touch the ceiling, it just must not
     * leave the row stuck. The exception is always re-thrown unchanged, so
     * which exception reaches tick() -- and how the run ends -- is exactly
     * as before.
     */
    private function callProvider(
        RecommendationRun $run,
        AiProviderSettings $settings,
        CompletionRequest $request,
        RecordedCall $recordedCall,
    ): string {
        try {
            return $this->chat->complete(
                $this->connections->forSettings($settings),
                $request,
                $recordedCall,
            );
        } catch (ProviderUnreachableException | CredentialsRejectedException $e) {
            $recordedCall->abortAfterTransportFailure($e->getMessage());
            $this->recordTransportFailure($run, $settings, $e->getMessage());

            throw $e;
        } catch (\Throwable $e) {
            $recordedCall->abortAfterTransportFailure($e->getMessage());

            throw $e;
        }
    }

    private function recordTransportFailure(
        RecommendationRun $run,
        AiProviderSettings $settings,
        string $failureDetail,
    ): void {
        $ceilingReached = $run->recordTransportFailure();
        if ($ceilingReached) {
            // The real per-call detail, not a hardcoded "could not be reached":
            // most transport failures are the provider refusing or truncating a
            // call it plainly received, and flattening them all into one
            // unreachable message hid a fixable 400 behind a network story
            // (#329). The base URL stays for context -- which endpoint failed.
            $run->fail(
                sprintf('The AI provider at %s failed: %s', $settings->getBaseUrl(), $failureDetail),
                $this->clock->now(),
            );
        }
        $this->entityManager->flush();
    }

    /**
     * The retry envelope the dedup phase uses: record the invalid reply, and
     * once attempts are exhausted hand off to the degraded ending -- an
     * undeduped list -- rather than failing the run; otherwise checkpoint and
     * wait for the corrective retry on the next tick. The batch phase no longer
     * comes here: its retries happen in-tick within the wave, so it never
     * writes the run's cross-tick attempt state (#344).
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

    /**
     * Cuts the ranked list to the reader's picks limit, re-checks that each
     * surviving pick's entry still exists — the candidate pool can be pruned
     * mid-run — and writes the survivors as RecommendationItems at dense
     * positions in pick order before marking the run completed.
     *
     * Every ending funnels through here, so the cut lives here too: a new
     * ending cannot ship an over-long list by forgetting to slice.
     *
     * @param list<array{id: int, score: int, reason: string}> $ranked
     */
    private function finalize(RecommendationRun $run, array $ranked): RecommendationRunReport
    {
        $picks = \array_slice($ranked, 0, $this->settingsResolver->forUser($run->getUser())->picksLimit);
        $existingIds = $this->entries->findExistingIds(array_map(
            static fn (array $pick): int => $pick['id'],
            $picks,
        ));

        $position = 0;
        foreach ($picks as $pick) {
            if (!\in_array($pick['id'], $existingIds, true)) {
                continue;
            }

            $position++;
            $entryReference = $this->entityManager->getReference(Entry::class, $pick['id'])
                ?? throw new \LogicException('Entry ' . $pick['id'] . ' was confirmed to exist a moment ago.');
            $this->entityManager->persist(
                new RecommendationItem($run, $entryReference, $position, $pick['reason'], $pick['score']),
            );
        }

        $run->complete($this->clock->now());
        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }

    private function requireUserId(User $user): int
    {
        return $user->getId() ?? throw new \LogicException('Cannot advance a run for an unsaved account.');
    }
}
