<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring;

use App\Service\Recommendation\Scoring\Model\ScoreParseResultModel;
use App\Service\Recommendation\Scoring\Model\ScoringReplyModel;
use App\Service\Recommendation\Scoring\Support\ProbabilityScore;
use App\Service\Recommendation\Scoring\Support\QuestionId;

/** A batch's Nouls as scored winners without reasons; one candidate without a probability spoils the whole reply. */
final readonly class ScoreParser
{
    /** @param list<int> $entryIds the batch's candidates, in snapshot order */
    public function parse(ScoringReplyModel $reply, array $entryIds): ScoreParseResultModel
    {
        $winners = [];
        foreach ($entryIds as $entryId) {
            $noul = $reply->nouls[QuestionId::of($entryId)] ?? null;
            if (!self::isProbability($noul)) {
                return ScoreParseResultModel::unusable();
            }
            $winners[] = ['id' => $entryId, 'score' => ProbabilityScore::of($noul), 'reason' => ''];
        }

        return ScoreParseResultModel::usable($winners);
    }

    /** @phpstan-assert-if-true float $noul */
    private static function isProbability(?float $noul): bool
    {
        return null !== $noul && $noul >= 0.0 && $noul <= 1.0;
    }
}
