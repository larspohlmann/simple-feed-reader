<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream;

use App\Service\Curation\YourStream\Model\YourStreamWordSweepReportModel;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;

/** Refreshes nothing until the local word lists are stored (#2601). */
final readonly class YourStreamWordSweep
{
    public const int UNHURRIED_BUDGET_SECONDS = 3600;

    public function sweep(SweepBudgetModel $budget): YourStreamWordSweepReportModel
    {
        return YourStreamWordSweepReportModel::none();
    }
}
