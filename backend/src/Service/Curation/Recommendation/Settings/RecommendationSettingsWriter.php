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
