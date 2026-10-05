<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\SavedSearch;
use App\Repository\SavedSearchRepository;
use App\Service\Recommendation\Pool\RecommendationHistoryLoader;
use App\Service\Recommendation\Profile\Model\ProfileInputsModel;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;

final readonly class ProfileInputsLoader
{
    public function __construct(
        private RecommendationHistoryLoader $historyLoader,
        private SavedSearchRepository $savedSearches,
    ) {
    }

    public function load(int $userId, EffectiveRecommendationSettingsModel $settings): ProfileInputsModel
    {
        return new ProfileInputsModel(
            $this->historyLoader->load($userId, $settings),
            array_values(array_unique(array_map(self::termAsTyped(...), $this->savedSearches->findForUser($userId)))),
        );
    }

    private static function termAsTyped(SavedSearch $savedSearch): string
    {
        return $savedSearch->isPhrase() ? '"' . $savedSearch->getTerm() . '"' : $savedSearch->getTerm();
    }
}
