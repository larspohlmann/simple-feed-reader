<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\Entry;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Exception\AiNotConfiguredException;
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
 * checkpoint the run as failed. Once every batch call is done, a run with
 * more than one batch enters the merge phase: one more provider call folds
 * all batches' winners into a single ranking, with the same salvage/retry/
 * fail treatment as a batch reply. A single-batch run has nothing to merge,
 * so it finalizes straight from its one winner list instead. finalize()
 * re-checks that each winning entry still exists — the pool can be pruned
 * mid-run — and writes the survivors as RecommendationItems at dense
 * positions before marking the run completed.
 *
 * The eleven constructor collaborators are deliberate: the advancer is the
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

        $settings = $this->configurator->requireConfiguration($user);
        if (!$settings->hasModel()) {
            throw new AiNotConfiguredException('No model is chosen.');
        }

        if (RecommendationRun::STATUS_PENDING === $run->getStatus()) {
            return $this->snapshotTick($run, $user);
        }

        if ($run->progress()->isMergePhase) {
            return $this->mergeTick($run, $user, $settings);
        }

        return $this->providerTick($run, $user, $settings);
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

        if ([] === $validIds) {
            // Every entry in this batch was pruned since the snapshot: there
            // is nothing to ask the model, so this is progress, not failure.
            $run->recordBatchWinners([]);
            $this->entityManager->flush();

            return RecommendationRunReport::fromRun($run);
        }

        $effectiveSettings = $this->settingsResolver->forUser($user);
        $messages = $this->batchMessagesFor($run, $userId, $ids, $linesById, $effectiveSettings);

        $content = $this->chat->complete(
            $this->configurator->credentials($settings),
            $settings->getModel() ?? '',
            $messages,
        );

        $result = $this->parser->parse($content, $validIds, $effectiveSettings->picksLimit);

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

    private function mergeTick(
        RecommendationRun $run,
        User $user,
        AiProviderSettings $settings,
    ): RecommendationRunReport {
        $userId = $this->requireUserId($user);
        $winners = $run->getWinners();
        $linesById = $this->candidateLoader->linesForIds($userId, $this->uniqueWinnerIds($winners));
        $validIds = array_keys($linesById);

        $effectiveSettings = $this->settingsResolver->forUser($user);
        $messages = $this->mergeMessagesFor($run, $winners, $linesById, $effectiveSettings);

        $content = $this->chat->complete(
            $this->configurator->credentials($settings),
            $settings->getModel() ?? '',
            $messages,
        );

        $result = $this->parser->parse($content, $validIds, $effectiveSettings->picksLimit);

        if (!$result->usable) {
            return $this->recordUnusableReply($run, $content);
        }

        return $this->finalize($run, array_map(
            static fn (RecommendationPick $pick): array => ['id' => $pick->entryId, 'reason' => $pick->reason],
            $result->picks,
        ));
    }

    /**
     * @param list<list<array{id: int, reason: string}>> $winners
     * @param array<int, PromptLine>                      $linesById
     *
     * @return list<array{role: string, content: string}>
     */
    private function mergeMessagesFor(
        RecommendationRun $run,
        array $winners,
        array $linesById,
        EffectiveRecommendationSettings $effectiveSettings,
    ): array {
        $messages = $this->promptBuilder->mergeMessages($winners, $linesById, $effectiveSettings);

        return $this->withCorrectiveTail($messages, $run);
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
     * @param list<list<array{id: int, reason: string}>> $winners
     *
     * @return list<int>
     */
    private function uniqueWinnerIds(array $winners): array
    {
        $ids = [];
        foreach ($winners as $batch) {
            foreach ($batch as $winner) {
                $ids[$winner['id']] = true;
            }
        }

        return array_keys($ids);
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

        $run->recordBatchWinners(array_map(
            static fn (RecommendationPick $pick): array => ['id' => $pick->entryId, 'reason' => $pick->reason],
            $result->picks,
        ));

        if (!$run->progress()->needsMerge) {
            return $this->finalize($run, $run->getWinners()[0]);
        }

        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }

    /**
     * Unusable batch and merge replies get the same treatment: the entity
     * does not care which phase the failed attempt belonged to.
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
     * Re-checks that each pick's entry still exists — the candidate pool can
     * be pruned mid-run — and writes the survivors as RecommendationItems at
     * dense positions in pick order before marking the run completed.
     *
     * @param list<array{id: int, reason: string}> $picks
     */
    private function finalize(RecommendationRun $run, array $picks): RecommendationRunReport
    {
        $existingIds = $this->existingEntryIds(array_map(
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

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function existingEntryIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<int> $existingIds */
        $existingIds = $this->entityManager->createQueryBuilder()
            ->select('e.id')
            ->from(Entry::class, 'e')
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getSingleColumnResult();

        return $existingIds;
    }

    private function requireUserId(User $user): int
    {
        return $user->getId() ?? throw new \LogicException('Cannot advance a run for an unsaved account.');
    }
}
