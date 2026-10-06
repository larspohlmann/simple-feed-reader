<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring;

use App\Service\Recommendation\Scoring\Model\ScoreParseResultModel;
use App\Service\Recommendation\Scoring\Model\ScoringReplyModel;
use App\Service\Recommendation\Scoring\Support\ScaledScore;

/** A batch's scores as winners without reasons; one candidate without a value in [0, 1] spoils the whole reply. */
final readonly class ScoreParser
{
    /** @param list<int> $entryIds the batch's candidates, in snapshot order */
    public function parse(ScoringReplyModel $reply, array $entryIds): ScoreParseResultModel
    {
        $winners = [];
        foreach ($entryIds as $entryId) {
            $score = $reply->scores[$entryId] ?? null;
            if (!self::isWithinUnitInterval($score)) {
                return ScoreParseResultModel::unusable();
            }
            $winners[] = ['id' => $entryId, 'score' => ScaledScore::of($score), 'reason' => ''];
        }

        return ScoreParseResultModel::usable($winners);
    }

    /** @phpstan-assert-if-true float $score */
    private static function isWithinUnitInterval(?float $score): bool
    {
        return null !== $score && $score >= 0.0 && $score <= 1.0;
    }
}
