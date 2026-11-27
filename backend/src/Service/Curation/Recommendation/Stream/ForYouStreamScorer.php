<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream;

use App\Service\Curation\Recommendation\Stream\Model\EntryStemsModel;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermsModel;
use App\Service\Curation\Recommendation\Stream\Model\StreamScoreModel;

final readonly class ForYouStreamScorer
{
    public const float MIN_SCORE = 1.5;

    public function findMatch(InterestTermsModel $terms, EntryStemsModel $stems): ?StreamScoreModel
    {
        $contributions = [];
        foreach ($stems->positionWeights as $stem => $positionWeight) {
            $strength = $terms->findStrength($stems->language, (string) $stem);
            if ($strength !== null) {
                $contributions[(string) $stem] = $strength * $positionWeight;
            }
        }
        $score = array_sum($contributions);
        if ($score < self::MIN_SCORE) {
            return null;
        }
        arsort($contributions);

        return new StreamScoreModel($score, array_map(strval(...), array_keys($contributions)));
    }
}
