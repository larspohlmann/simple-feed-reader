<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Content\Transcript\Model\TranscriptBudgetModel;
use App\Service\Content\Transcript\Model\TranscriptSweepReportModel;
use App\Service\Content\Transcript\TranscriptSweep;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'transcripts', priority: 60)]
final readonly class TranscriptsTickSweep implements MaintenanceSweepInterface
{
    private const int MAX_FETCHES = 10;

    public function __construct(
        private TranscriptSweep $transcriptSweep,
        private ClockInterface $clock,
    ) {
    }

    public function run(\DateTimeImmutable $deadline): TranscriptSweepReportModel
    {
        return $this->transcriptSweep->sweep(
            TranscriptBudgetModel::fittingBefore($deadline, $this->clock->now(), self::MAX_FETCHES),
        );
    }

    public function idleReport(): TranscriptSweepReportModel
    {
        return TranscriptSweepReportModel::none();
    }
}
