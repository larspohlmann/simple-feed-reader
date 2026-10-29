<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use App\Service\Image\ImageVerificationSweep;
use App\Service\Image\Model\ImageVerificationBudgetModel;
use App\Service\LinkCard\LinkCardSweep;
use App\Service\LinkCard\Model\LinkCardBudgetModel;
use App\Service\Maintenance\Model\FollowUpFetchesReportModel;
use App\Service\Transcript\Model\TranscriptBudgetModel;
use App\Service\Transcript\TranscriptSweep;
use Psr\Clock\ClockInterface;

/** Images, then link cards, then transcripts, each in what is left before the tick's deadline. */
final readonly class FollowUpFetchSweeps
{
    private const int IMAGE_VERIFICATION_MAX_SECONDS = 45;
    private const int LINK_CARD_MAX_FETCHES = 2;
    private const int TRANSCRIPT_MAX_FETCHES = 10;

    public function __construct(
        private ImageVerificationSweep $imageVerificationSweep,
        private LinkCardSweep $linkCardSweep,
        private TranscriptSweep $transcriptSweep,
        private ClockInterface $clock,
    ) {
    }

    public function sweep(\DateTimeImmutable $deadline): FollowUpFetchesReportModel
    {
        $imageVerification = $this->imageVerificationSweep->verifyDue(
            ImageVerificationBudgetModel::halfOfWhatIsLeft(
                $deadline,
                $this->clock->now(),
                self::IMAGE_VERIFICATION_MAX_SECONDS,
            ),
        );
        $linkCards = $this->linkCardSweep->sweep(
            LinkCardBudgetModel::fittingBefore($deadline, $this->clock->now(), self::LINK_CARD_MAX_FETCHES),
        );
        $transcripts = $this->transcriptSweep->sweep(
            TranscriptBudgetModel::fittingBefore($deadline, $this->clock->now(), self::TRANSCRIPT_MAX_FETCHES),
        );

        return new FollowUpFetchesReportModel($imageVerification, $linkCards, $transcripts);
    }
}
