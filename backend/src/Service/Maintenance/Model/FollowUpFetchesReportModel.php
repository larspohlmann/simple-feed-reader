<?php

declare(strict_types=1);

namespace App\Service\Maintenance\Model;

use App\Service\Image\Model\ImageVerificationReportModel;
use App\Service\LinkCard\Model\LinkCardSweepReportModel;
use App\Service\Transcript\Model\TranscriptSweepReportModel;

/** The tick's sweeps that fetch what ingest left to come: image sizes, link cards and transcripts. */
final readonly class FollowUpFetchesReportModel
{
    public function __construct(
        public ImageVerificationReportModel $imageVerification,
        public LinkCardSweepReportModel $linkCards,
        public TranscriptSweepReportModel $transcripts,
    ) {
    }

    public static function none(): self
    {
        return new self(
            new ImageVerificationReportModel(0, 0, 0, 0),
            LinkCardSweepReportModel::none(),
            TranscriptSweepReportModel::none(),
        );
    }
}
