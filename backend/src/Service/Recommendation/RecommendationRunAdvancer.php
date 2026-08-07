<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\AiProviderSettings;
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
 * This task implements only the snapshot phase — a pending run's candidate
 * pool is frozen into fixed-size batches, with no provider call made yet.
 * providerTick() is a stub; a later task fills it in with the per-batch
 * selection call and the merge phase.
 *
 * The nine constructor collaborators are deliberate: the advancer is the
 * recommendation pipeline's composition root (lock, run persistence, AI
 * configuration, settings resolution, candidate/history loading, prompt
 * packing), and each is a seam the tests swap or drive independently —
 * see RefreshRunner for the same shape.
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
        throw new \LogicException('not yet implemented');
    }

    private function requireUserId(User $user): int
    {
        return $user->getId() ?? throw new \LogicException('Cannot advance a run for an unsaved account.');
    }
}
