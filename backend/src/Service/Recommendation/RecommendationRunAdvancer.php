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
use App\Service\Recommendation\Exception\RecommendationRunCancelledException;
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
    private const float LOCK_TTL_SECONDS = 300.0;

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
    ) {
    }

    public function advance(User $user, TickDriver $driver = TickDriver::Poll): RecommendationRunReport
    {
        $lock = $this->lockFactory->createLock(
            self::LOCK_NAME_PREFIX . ($user->getId() ?? 0),
            self::LOCK_TTL_SECONDS,
        );

        if (!$lock->acquire()) {
            return RecommendationRunReport::busy();
        }

        try {
            return $this->tick($user, $driver);
        } finally {
            $lock->release();
        }
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
        } catch (RecommendationRunCancelledException) {
            // Stopped mid-call. refresh() throws away everything this tick
            // computed and re-reads the row the canceller wrote, so the
            // caller is told the run is over rather than handed the progress
            // of a run nobody is waiting for. Nothing is flushed: the guard
            // fires before any run mutation, so there is no half-written
            // checkpoint to undo.
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
        $candidates = $this->candidateLoader->load($userId, $effectiveSettings->candidatePoolSize);

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
        $this->settleVerdict($recordedCall, $content, $result->usable);
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
     * The best-ranked entry is exempt from the filter because it cannot, by
     * definition, duplicate a better-ranked entry. Without that exemption a
     * reply naming every id it was shown -- which the duplicate parser
     * rightly reads as usable -- would complete the run with an empty list
     * and no error.
     *
     * @param non-empty-list<array{id: int, score: int, reason: string}> $pool
     * @param list<int>                                                  $duplicateIds
     *
     * @return non-empty-list<array{id: int, score: int, reason: string}>
     */
    private function withoutDuplicates(array $pool, array $duplicateIds): array
    {
        $bestRanked = $pool[0];
        $survivors = array_values(array_filter(
            \array_slice($pool, 1),
            static fn (array $winner): bool => !\in_array($winner['id'], $duplicateIds, true),
        ));

        return [$bestRanked, ...$survivors];
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
                $this->configurator->credentials($settings),
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

    private function settleVerdict(RecordedCall $recordedCall, string $content, bool $usable): void
    {
        if ($usable) {
            $recordedCall->finishUsable($content);

            return;
        }

        $recordedCall->finishUnusable($content);
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
