<?php

declare(strict_types=1);

namespace App\Service\Search\TranscriptText;

interface TranscriptTextInterface
{
    /**
     * @param list<int> $entryIds
     *
     * @return array<int, string> the filled transcripts' search text, by entry id
     */
    public function searchTextsFor(array $entryIds): array;
}
