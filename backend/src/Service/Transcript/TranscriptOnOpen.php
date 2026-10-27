<?php

declare(strict_types=1);

namespace App\Service\Transcript;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Repository\EntryTranscriptRepository;
use App\Service\Search\EntryIndexer;
use Doctrine\ORM\EntityManagerInterface;

/** The transcript an opened entry shows. Opening fetches one the sweep has not reached yet, within a short budget. */
final readonly class TranscriptOnOpen
{
    private const float BUDGET_SECONDS = 3.0;

    public function __construct(
        private EntryTranscriptRepository $transcripts,
        private TranscriptFiller $filler,
        private EntityManagerInterface $entityManager,
        private EntryIndexer $indexer,
    ) {
    }

    public function serve(Entry $entry): ?EntryTranscript
    {
        $transcript = $this->transcripts->findForEntry($entry);
        if ($transcript !== null && $transcript->isPending()) {
            $this->fillNow($transcript);
        }

        return $transcript?->isFilled() === true ? $transcript : null;
    }

    private function fillNow(EntryTranscript $transcript): void
    {
        $this->filler->fill($transcript, self::BUDGET_SECONDS);
        $this->entityManager->flush();
        if ($transcript->isFilled()) {
            $this->indexer->index([$transcript->getEntry()]);
        }
    }
}
