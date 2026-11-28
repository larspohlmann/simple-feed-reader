<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream;

use App\Service\Curation\YourStream\Model\EntryStemsModel;
use App\Service\Curation\YourStream\Model\StreamScoreModel;
use App\Service\Curation\YourStream\Model\StreamStrengthsModel;

final readonly class YourStreamScorer
{
    public function findMatch(
        StreamStrengthsModel $terms,
        EntryStemsModel $stems,
        float $minimumScore,
    ): ?StreamScoreModel {
        $strengths = $terms->readStrengths($stems->language);
        $contributions = [];
        foreach ($stems->positionWeights as $stem => $positionWeight) {
            $strength = $strengths[$stem] ?? null;
            if ($strength !== null) {
                $contributions[(string) $stem] = $strength * $positionWeight;
            }
        }
        $score = array_sum($contributions);
        if ($score < $minimumScore) {
            return null;
        }
        arsort($contributions);

        return new StreamScoreModel($score, array_map(strval(...), array_keys($contributions)));
    }
}
