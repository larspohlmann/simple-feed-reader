<?php

declare(strict_types=1);

namespace App\Service\Transcript;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Repository\EntryTranscriptRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Search\EntryIndexer;
use App\Service\Search\Membership\SavedSearchRematch;
use Doctrine\ORM\EntityManagerInterface;

/** The transcript an opened entry shows. Opening fetches a due one the sweep has not reached yet, within a short budget. */
final readonly class TranscriptOnOpen
{
    private const float BUDGET_SECONDS = 3.0;

    public function __construct(
        private EntryTranscriptRepository $transcripts,
        private TranscriptFiller $filler,
        private EntityManagerInterface $entityManager,
        private EntryIndexer $indexer,
        private SavedSearchRematch $rematch,
        private NaiveUtcClock $clock,
    ) {
    }

    public function serve(Entry $entry): ?EntryTranscript
    {
        $transcript = $this->transcripts->findForEntry($entry);
        if ($transcript !== null && $transcript->isPending()) {
            $this->fillWhenClaimed($transcript);
        }

        return $transcript?->isFilled() === true ? $transcript : null;
    }

    private function fillWhenClaimed(EntryTranscript $transcript): void
    {
        if ($this->transcripts->claim([$transcript], $this->clock->now()) === []) {
            return;
        }

        $this->filler->fill($transcript, self::BUDGET_SECONDS);
        $this->entityManager->flush();
        if ($transcript->isFilled()) {
            $this->indexer->index([$transcript->getEntry()]);
            $this->rematch->rematch([$transcript->getEntry()]);
        }
    }
}
