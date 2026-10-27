<?php

declare(strict_types=1);

namespace App\Service\Transcript;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Service\Search\EntryIndexer;
use App\Service\Search\Membership\SavedSearchRematch;
use Doctrine\ORM\EntityManagerInterface;

/** Fills claimed transcripts, then indexes the episodes that gained one and re-matches them against the saved searches. */
final readonly class TranscriptCompletion
{
    public function __construct(
        private TranscriptFiller $filler,
        private EntityManagerInterface $entityManager,
        private EntryIndexer $indexer,
        private SavedSearchRematch $rematch,
    ) {
    }

    /**
     * @param list<EntryTranscript> $claimed
     *
     * @return list<EntryTranscript> the ones now filled
     */
    public function complete(array $claimed, float $secondsEach): array
    {
        if ($claimed === []) {
            return [];
        }

        foreach ($claimed as $transcript) {
            $this->filler->fill($transcript, $secondsEach);
        }
        $this->entityManager->flush();

        $filled = array_values(array_filter(
            $claimed,
            static fn (EntryTranscript $transcript): bool => $transcript->isFilled(),
        ));
        $entries = array_map(static fn (EntryTranscript $transcript): Entry => $transcript->getEntry(), $filled);
        $this->indexer->index($entries);
        $this->rematch->rematch($entries);

        return $filled;
    }
}
