<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Settings;

use App\Entity\RecommendationSettings;
use App\Entity\RecommendationSettingsValues;
use App\Entity\User;
use App\Repository\RecommendationSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The only writer of RecommendationSettings. Blank guidance normalises to null here, the resolver's "use the default".
 * save() keeps the stored profile whatever the form sends; only storeProfile(), the distiller's entry point, sets it.
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
        $settings = $this->loadOrCreate($user);
        $requested = $this->withNormalisedGuidance($values);
        $settings->update($this->withReplacedProfileText($requested, $settings->values()->profileText));
        $this->entityManager->flush();
    }

    public function storeProfile(User $user, ?string $profileText): void
    {
        $settings = $this->loadOrCreate($user);
        $settings->update($this->withReplacedProfileText($settings->values(), $profileText));
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
            historyCaps: $values->historyCaps,
            poolLimits: $values->poolLimits,
            contextWindow: $values->contextWindow,
            batchSize: $values->batchSize,
            debugEnabled: $values->debugEnabled,
            autoGenerateIntervalHours: $values->autoGenerateIntervalHours,
            showScoreAndReasons: $values->showScoreAndReasons,
        );
    }

    private function withReplacedProfileText(
        RecommendationSettingsValues $values,
        ?string $profileText,
    ): RecommendationSettingsValues {
        return new RecommendationSettingsValues(
            guidancePrompt: $values->guidancePrompt,
            historyCaps: $values->historyCaps,
            poolLimits: $values->poolLimits,
            contextWindow: $values->contextWindow,
            batchSize: $values->batchSize,
            debugEnabled: $values->debugEnabled,
            autoGenerateIntervalHours: $values->autoGenerateIntervalHours,
            profileText: $profileText,
            showScoreAndReasons: $values->showScoreAndReasons,
        );
    }
}
