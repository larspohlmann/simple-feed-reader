<?php

declare(strict_types=1);

namespace App\Service\Fetch\AnswerSettler;

use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\SettlementModel;

/**
 * For callers that fetch feeds without a pass: every request goes out, and every outcome is yielded as it is.
 *
 * @implements AnswerSettlerInterface<FetchOutcomeModel>
 */
final readonly class PassThroughSettler implements AnswerSettlerInterface
{
    public function admits(int|string $key): bool
    {
        return true;
    }

    /** @return SettlementModel<FetchOutcomeModel> */
    public function settle(int|string $key, FetchOutcomeModel $outcome): SettlementModel
    {
        return SettlementModel::outcome($outcome);
    }
}
