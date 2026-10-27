<?php

declare(strict_types=1);

namespace App\Service\Transcript;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Repository\EntryTranscriptRepository;
use App\Service\Clock\NaiveUtcClock;

/** The transcript an opened entry shows. Opening fetches a due one the sweep has not reached yet, within a short budget. */
final readonly class TranscriptOnOpen
{
    private const float BUDGET_SECONDS = 3.0;

    public function __construct(
        private EntryTranscriptRepository $transcripts,
        private TranscriptCompletion $completion,
        private NaiveUtcClock $clock,
    ) {
    }

    public function serve(Entry $entry): ?EntryTranscript
    {
        $transcript = $this->transcripts->findForEntry($entry);
        if ($transcript !== null && $transcript->isPending()) {
            $claimed = $this->transcripts->claim([$transcript], $this->clock->now());
            $this->completion->complete($claimed, self::BUDGET_SECONDS);
        }

        return $transcript?->isFilled() === true ? $transcript : null;
    }
}
