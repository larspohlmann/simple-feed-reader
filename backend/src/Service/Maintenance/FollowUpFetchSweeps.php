<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use App\Service\LinkCard\LinkCardSweep;
use App\Service\LinkCard\Model\LinkCardBudgetModel;
use App\Service\Maintenance\Model\FollowUpFetchesReportModel;
use App\Service\Transcript\Model\TranscriptBudgetModel;
use App\Service\Transcript\TranscriptSweep;
use Psr\Clock\ClockInterface;

/** Link cards first, then transcripts, each in what is left before the tick's deadline. */
final readonly class FollowUpFetchSweeps
{
    private const int LINK_CARD_MAX_FETCHES = 2;
    private const int TRANSCRIPT_MAX_FETCHES = 2;

    public function __construct(
        private LinkCardSweep $linkCardSweep,
        private TranscriptSweep $transcriptSweep,
        private ClockInterface $clock,
    ) {
    }

    public function sweep(\DateTimeImmutable $deadline): FollowUpFetchesReportModel
    {
        $linkCards = $this->linkCardSweep->sweep(
            LinkCardBudgetModel::fittingBefore($deadline, $this->clock->now(), self::LINK_CARD_MAX_FETCHES),
        );
        $transcripts = $this->transcriptSweep->sweep(
            TranscriptBudgetModel::fittingBefore($deadline, $this->clock->now(), self::TRANSCRIPT_MAX_FETCHES),
        );

        return new FollowUpFetchesReportModel($linkCards, $transcripts);
    }
}
