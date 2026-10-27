<?php

declare(strict_types=1);

namespace App\Service\Transcript;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Repository\EntryTranscriptRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Search\EntryIndexer;
use App\Service\Search\Membership\SavedSearchRematch;
use App\Service\Transcript\Model\TranscriptBudgetModel;
use App\Service\Transcript\Model\TranscriptSweepReportModel;
use Doctrine\ORM\EntityManagerInterface;

/** Fetches the newest queued transcripts that are due, then indexes the episodes that gained one and re-matches them against the saved searches. */
final readonly class TranscriptSweep
{
    public function __construct(
        private EntryTranscriptRepository $transcripts,
        private TranscriptFiller $filler,
        private EntityManagerInterface $entityManager,
        private EntryIndexer $indexer,
        private SavedSearchRematch $rematch,
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
        foreach ($claimed as $transcript) {
            $this->filler->fill($transcript, TranscriptBudgetModel::FETCH_SECONDS);
        }
        $this->entityManager->flush();

        $filled = array_values(array_filter(
            $claimed,
            static fn (EntryTranscript $transcript): bool => $transcript->isFilled(),
        ));
        $entries = array_map(static fn (EntryTranscript $transcript): Entry => $transcript->getEntry(), $filled);
        $this->indexer->index($entries);
        $this->rematch->rematch($entries);

        return new TranscriptSweepReportModel(\count($claimed), \count($filled));
    }
}
