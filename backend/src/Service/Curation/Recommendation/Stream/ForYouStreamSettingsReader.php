<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream;

use App\Entity\ForYouStreamTuning;
use App\Entity\User;
use App\Repository\InterestTermSetRepository;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Curation\Recommendation\Stream\Model\ForYouStreamStateModel;

final readonly class ForYouStreamSettingsReader
{
    public function __construct(
        private RecommendationSettingsRepository $settings,
        private InterestTermSetRepository $termSets,
    ) {
    }

    public function readState(User $user): ForYouStreamStateModel
    {
        return new ForYouStreamStateModel(
            $this->settings->findForUser($user)?->getForYouStreamTuning() ?? ForYouStreamTuning::defaults(),
            $this->termSets->findForUser($user)?->getPhrases() ?? [],
        );
    }
}
