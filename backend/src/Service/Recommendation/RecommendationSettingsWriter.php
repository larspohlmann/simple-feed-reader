<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Repository\RecommendationSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The only writer of RecommendationSettings. A blank or whitespace-only
 * guidance prompt normalises to null here, not in the DTO or the controller,
 * because null is the domain meaning "use the default" that
 * RecommendationSettingsResolver already understands.
 */
final readonly class RecommendationSettingsWriter
{
    public function __construct(
        private RecommendationSettingsRepository $repository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(User $user, RecommendationSettingsValues $values): void
    {
        $normalised = $this->withNormalisedGuidance($values);
        $settings = $this->repository->findForUser($user);

        if (null === $settings) {
            $settings = new RecommendationSettings($user);
            $this->entityManager->persist($settings);
        }

        $settings->update($normalised);
        $this->entityManager->flush();
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
            keptCap: $values->keptCap,
            viewedCap: $values->viewedCap,
            candidatePoolSize: $values->candidatePoolSize,
            picksLimit: $values->picksLimit,
            contextWindow: $values->contextWindow,
            batchCount: $values->batchCount,
            debugEnabled: $values->debugEnabled,
            autoGenerateIntervalHours: $values->autoGenerateIntervalHours,
        );
    }
}
