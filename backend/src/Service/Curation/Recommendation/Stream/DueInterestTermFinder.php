<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream;

use App\Entity\RecommendationSettings;
use App\Enum\RunStatus;
use App\Repository\InterestTermRunRepository;
use App\Repository\InterestTermSetRepository;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Curation\Recommendation\Profile\ProfileConnections;
use App\Service\Curation\Recommendation\Stream\Model\DueInterestTermsModel;
use App\Service\Foundation\Clock\NaiveUtcClock;

/**
 * The accounts whose stored profile is newer than their interest terms, with a connection that can read it, unless
 * the last attempt on this very profile failed: a new profile is what lifts that.
 */
final readonly class DueInterestTermFinder
{
    public function __construct(
        private RecommendationSettingsRepository $settings,
        private InterestTermSetRepository $termSets,
        private InterestTermRunRepository $termRuns,
        private ProfileConnections $profileConnections,
        private NaiveUtcClock $clock,
    ) {
    }

    /** @return list<DueInterestTermsModel> */
    public function findDue(): array
    {
        $due = [];
        foreach ($this->settings->findWithStoredProfile($this->clock->now()) as $row) {
            $model = $this->buildDue($row);
            if (null !== $model) {
                $due[] = $model;
            }
        }

        return $due;
    }

    private function buildDue(RecommendationSettings $row): ?DueInterestTermsModel
    {
        $profile = $row->getStoredProfile();
        $text = $profile->getText();
        $generatedAt = $profile->getGeneratedAt();
        if (null === $text || null === $generatedAt || $this->isUpToDate($row, $generatedAt)) {
            return null;
        }

        $connection = $this->profileConnections->findUsableConnectionGiven($row->getUser(), $row);

        if (null === $connection) {
            return null;
        }

        return new DueInterestTermsModel($row->getUser(), $connection, $text, $generatedAt);
    }

    private function isUpToDate(RecommendationSettings $row, \DateTimeImmutable $generatedAt): bool
    {
        $user = $row->getUser();
        $set = $this->termSets->findForUser($user);
        if (null !== $set && $set->getProfileGeneratedAt() >= $generatedAt) {
            return true;
        }
        $latest = $this->termRuns->findLatestForUser($user);

        return RunStatus::Failed === $latest?->getStatus() && $latest->isForProfile($generatedAt);
    }
}
