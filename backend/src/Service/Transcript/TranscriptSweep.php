<?php

declare(strict_types=1);

namespace App\Service\Transcript;

use App\Repository\EntryTranscriptRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Transcript\Model\TranscriptBudgetModel;
use App\Service\Transcript\Model\TranscriptSweepReportModel;

/** Completes the newest queued transcripts that are due. */
final readonly class TranscriptSweep
{
    public function __construct(
        private EntryTranscriptRepository $transcripts,
        private TranscriptCompletion $completion,
        private NaiveUtcClock $clock,
    ) {
    }

    public function sweep(TranscriptBudgetModel $budget): TranscriptSweepReportModel
    {
        if ($budget->isEmpty()) {
            return TranscriptSweepReportModel::none();
        }

        $now = $this->clock->now();
        $claimed = $this->transcripts->claim($this->transcripts->findClaimable($now, $budget->fetches), $now);
        $filled = $this->completion->complete($claimed, TranscriptBudgetModel::FETCH_SECONDS);

        return new TranscriptSweepReportModel(\count($claimed), \count($filled));
    }
}
