<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\Entry;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Crypto\Exception\ApiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
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
 * batches with no provider call made. The batch phase then spends one
 * provider call per tick: a batch whose entries are all pruned records an
 * empty winner set for free, a usable reply advances the run, and an
 * unusable one is retried with a corrective message until three attempts
 * checkpoint the run as failed. Ranking is code's job, not the model's: the
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
 * The fourteen constructor collaborators are deliberate: the advancer is the
 * recommendation pipeline's composition root (lock, run persistence, AI
 * configuration, settings resolution, candidate/history loading, prompt
 * packing, the provider call and its reply parser), and each is a seam the
 * tests swap or drive independently — see RefreshRunner for the same shape.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final class RecommendationRunAdvancer
{
    private const string LOCK_NAME_PREFIX = 'ai-recommendations-';
    private const float LOCK_TTL_SECONDS = 300.0;

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
        private readonly RecommendationPickParser $parser,
        private readonly EntityManagerInterface $entityManager,
        private readonly RecommendationWinnerRanker $ranker,
        private readonly RecommendationDuplicateParser $duplicateParser,
    ) {
    }

    public function advance(User $user): RecommendationRunReport
    {
        $lock = $this->lockFactory->createLock(
            self::LOCK_NAME_PREFIX . ($user->getId() ?? 0),
            self::LOCK_TTL_SECONDS,
        );

        if (!$lock->acquire()) {
            return RecommendationRunReport::busy();
        }

        try {
            return $this->tick($user);
        } finally {
            $lock->release();
        }
    }

    private function tick(User $user): RecommendationRunReport
    {
        $run = $this->runs->findActiveForUser($user);

        if (null === $run) {
            $latest = $this->runs->findLatestForUser($user);

            return null === $latest ? RecommendationRunReport::none() : RecommendationRunReport::fromRun($latest);
        }

        try {
            return $this->tickActiveRun($run, $user);
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

    private function tickActiveRun(RecommendationRun $run, User $user): RecommendationRunReport
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

        return $this->providerTick($run, $user, $settings);
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
    ): RecommendationRunReport {
        $userId = $this->requireUserId($user);
        $ids = $run->getCandidateBatches()[$run->progress()->nextBatchIndex];
        $linesById = $this->candidateLoader->linesForIds($userId, $ids);
        $validIds = array_keys($linesById);
        $effectiveSettings = $this->settingsResolver->forUser($user);

        if ([] === $validIds) {
            // Every entry in this batch was pruned since the snapshot: there
            // is nothing to ask the model, so this is progress, not failure.
            // It takes the same ending as a usable reply, because a
            // single-batch run has no dedup phase to carry it: merely
            // checkpointing here would leave the run running with every
            // batch done, and the next tick would reach for a batch index
            // past the end of the frozen plan and wedge the run forever.
            return $this->recordBatchWinners($run, []);
        }

        $messages = $this->batchMessagesFor($run, $userId, $ids, $linesById, $effectiveSettings);

        $content = $this->callProvider($run, $settings, $messages);

        $result = $this->parser->parse($content, $validIds);

        return $this->recordReply($run, $content, $result);
    }

    /**
     * @param list<int>              $ids       the batch's entry ids, in snapshot order
     * @param array<int, PromptLine> $linesById
     *
     * @return list<array{role: string, content: string}>
     */
    private function batchMessagesFor(
        RecommendationRun $run,
        int $userId,
        array $ids,
        array $linesById,
        EffectiveRecommendationSettings $effectiveSettings,
    ): array {
        $history = $this->historyLoader->load($userId, $effectiveSettings);
        $candidateLines = $this->linesInSnapshotOrder($ids, $linesById);
        $messages = $this->promptBuilder->batchMessages($history, $candidateLines, $effectiveSettings);

        return $this->withCorrectiveTail($messages, $run);
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

        $messages = $this->withCorrectiveTail($this->promptBuilder->dedupMessages($pool, $linesById), $run);

        $content = $this->callProvider($run, $settings, $messages);

        $result = $this->duplicateParser->parse($content, array_column($pool, 'id'));

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
        $run->recordInvalidReply($content);

        if ($run->attemptsExhausted()) {
            return $this->finalize($run, $pool);
        }

        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
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
     * The shape both the entity's winner list and finalize() speak.
     *
     * @param list<RecommendationPick> $picks
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    private static function asWinners(array $picks): array
    {
        return array_map(
            static fn (RecommendationPick $pick): array => [
                'id' => $pick->entryId,
                'score' => $pick->score,
                'reason' => $pick->reason,
            ],
            $picks,
        );
    }

    /**
     * The one provider call a tick makes. A transport failure -- the provider
     * unreachable, or refusing the key -- never produced a reply for the
     * parser to judge, so it must not consume an `attempts` retry the way an
     * unusable reply does. It counts against its own ceiling instead, so a
     * provider that is persistently broken still fails the run eventually
     * (#308 final review, Important 2) rather than ticking forever; either
     * way the exception is re-thrown so the controller still maps it to its
     * problem type and the caller still sees the error on this tick.
     *
     * @param list<array{role: string, content: string}> $messages
     */
    private function callProvider(RecommendationRun $run, AiProviderSettings $settings, array $messages): string
    {
        try {
            return $this->chat->complete(
                $this->configurator->credentials($settings),
                $settings->getModel() ?? '',
                $messages,
                new NullCompletionStreamObserver(),
            );
        } catch (ProviderUnreachableException | CredentialsRejectedException $e) {
            $this->recordTransportFailure($run, $settings);

            throw $e;
        }
    }

    private function recordTransportFailure(RecommendationRun $run, AiProviderSettings $settings): void
    {
        $ceilingReached = $run->recordTransportFailure();
        if ($ceilingReached) {
            $run->fail(
                sprintf('The AI provider at %s could not be reached.', $settings->getBaseUrl()),
                $this->clock->now(),
            );
        }
        $this->entityManager->flush();
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     *
     * @return list<array{role: string, content: string}>
     */
    private function withCorrectiveTail(array $messages, RecommendationRun $run): array
    {
        $lastInvalidReply = $run->getLastInvalidReply();
        if (null === $lastInvalidReply) {
            return $messages;
        }

        return [...$messages, ...$this->promptBuilder->correctiveTail($lastInvalidReply)];
    }

    /**
     * @param list<int>              $ids       the batch's entry ids, in snapshot order
     * @param array<int, PromptLine> $linesById entries pruned since the snapshot are simply absent
     *
     * @return list<PromptLine>
     */
    private function linesInSnapshotOrder(array $ids, array $linesById): array
    {
        $present = array_filter($ids, static fn (int $id): bool => isset($linesById[$id]));

        return array_values(array_map(static fn (int $id): PromptLine => $linesById[$id], $present));
    }

    private function recordReply(
        RecommendationRun $run,
        string $content,
        PickParseResult $result,
    ): RecommendationRunReport {
        if (!$result->usable) {
            return $this->recordUnusableReply($run, $content);
        }

        return $this->recordBatchWinners($run, self::asWinners($result->picks));
    }

    /**
     * Records one batch's outcome and takes whichever ending the frozen
     * batch plan leaves. A run that still owes batch calls, or owes the one
     * dedup call, checkpoints and waits for the next tick; a single-batch run
     * has neither ahead of it, so it finalizes straight from its ranked pool.
     *
     * @param list<array{id: int, score: int, reason: string}> $winners
     */
    private function recordBatchWinners(RecommendationRun $run, array $winners): RecommendationRunReport
    {
        $run->recordBatchWinners($winners);

        if (!$run->progress()->needsDedup) {
            return $this->finalize($run, $this->ranker->ranked($run->getWinners()));
        }

        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }

    /**
     * An unusable batch reply retries with a corrective tail and fails the
     * run once attempts are exhausted; the dedup phase has its own, softer
     * ending in recordUnusableDedupReply().
     */
    private function recordUnusableReply(RecommendationRun $run, string $content): RecommendationRunReport
    {
        $run->recordInvalidReply($content);
        if ($run->attemptsExhausted()) {
            $run->fail('The model did not return a usable ranking.', $this->clock->now());
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
            $this->entityManager->persist(new RecommendationItem($run, $entryReference, $position, $pick['reason']));
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
