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
 *
 * `storeProfile()` is a second, narrower entry point (#493): the distiller
 * that produces the reader's preference profile calls it directly, without
 * routing through the settings form's full `RecommendationSettingsValues`,
 * so it can never carry a stale copy of fields it knows nothing about.
 *
 * The settings form is read-only on profileText (#493), so the
 * RecommendationSettingsValues a `save()` caller supplies always carries
 * `profileText: null` -- it has no way to know the current value. `save()`
 * therefore never trusts that field on the incoming values; it re-reads the
 * persisted profile off the row itself and carries that forward untouched,
 * so a settings-form save can never undo what storeProfile() wrote.
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
        $settings = $this->repository->findForUser($user);

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
            keptCap: $values->keptCap,
            viewedCap: $values->viewedCap,
            candidatePoolSize: $values->candidatePoolSize,
            lookbackDays: $values->lookbackDays,
            picksLimit: $values->picksLimit,
            contextWindow: $values->contextWindow,
            batchCount: $values->batchCount,
            debugEnabled: $values->debugEnabled,
            autoGenerateIntervalHours: $values->autoGenerateIntervalHours,
            showReasons: $values->showReasons,
        );
    }

    private function withReplacedProfileText(
        RecommendationSettingsValues $values,
        ?string $profileText,
    ): RecommendationSettingsValues {
        return new RecommendationSettingsValues(
            guidancePrompt: $values->guidancePrompt,
            favoritesCap: $values->favoritesCap,
            keptCap: $values->keptCap,
            viewedCap: $values->viewedCap,
            candidatePoolSize: $values->candidatePoolSize,
            lookbackDays: $values->lookbackDays,
            picksLimit: $values->picksLimit,
            contextWindow: $values->contextWindow,
            batchCount: $values->batchCount,
            debugEnabled: $values->debugEnabled,
            autoGenerateIntervalHours: $values->autoGenerateIntervalHours,
            profileText: $profileText,
            showReasons: $values->showReasons,
        );
    }
}
