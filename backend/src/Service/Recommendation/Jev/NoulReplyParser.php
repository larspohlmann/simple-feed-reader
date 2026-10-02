<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev;

use App\Service\Recommendation\Jev\Model\NoulParseResultModel;
use App\Service\Recommendation\Jev\Model\SystemOneReplyModel;
use App\Service\Recommendation\Jev\Support\NoulScore;
use App\Service\Recommendation\Jev\Support\QuestionId;

/** A batch's Nouls as scored winners without reasons; one candidate without a probability spoils the whole reply. */
final readonly class NoulReplyParser
{
    /** @param list<int> $entryIds the batch's candidates, in snapshot order */
    public function parse(SystemOneReplyModel $reply, array $entryIds): NoulParseResultModel
    {
        $winners = [];
        foreach ($entryIds as $entryId) {
            $noul = $reply->nouls[QuestionId::of($entryId)] ?? null;
            if (!self::isProbability($noul)) {
                return NoulParseResultModel::unusable();
            }
            $winners[] = ['id' => $entryId, 'score' => NoulScore::of($noul), 'reason' => ''];
        }

        return NoulParseResultModel::usable($winners);
    }

    /** @phpstan-assert-if-true float $noul */
    private static function isProbability(?float $noul): bool
    {
        return null !== $noul && $noul >= 0.0 && $noul <= 1.0;
    }
}
