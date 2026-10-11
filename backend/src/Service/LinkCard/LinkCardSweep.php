<?php

declare(strict_types=1);

namespace App\Service\LinkCard;

use App\Repository\ShareEnrichmentRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\LinkCard\Model\LinkCardBudgetModel;
use App\Service\LinkCard\Model\LinkCardSweepReportModel;

final readonly class LinkCardSweep
{
    public function __construct(
        private ShareEnrichmentRepository $enrichments,
        private ShareLinkCards $shareLinkCards,
        private NaiveUtcClock $clock,
    ) {
    }

    public function sweep(LinkCardBudgetModel $budget): LinkCardSweepReportModel
    {
        if ($budget->isEmpty()) {
            return LinkCardSweepReportModel::none();
        }

        $queued = $this->enrichments->findClaimableLinkCards($this->clock->now(), $budget->fetches);

        return new LinkCardSweepReportModel(\count($queued), $this->shareLinkCards->complete($queued, $budget));
    }
}
