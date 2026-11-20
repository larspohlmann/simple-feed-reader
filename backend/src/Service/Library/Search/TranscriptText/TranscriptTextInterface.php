<?php

declare(strict_types=1);

namespace App\Service\Library\Search\TranscriptText;

interface TranscriptTextInterface
{
    /**
     * @param list<int> $entryIds
     *
     * @return array<int, string> the filled transcripts' search text, by entry id
     */
    public function findSearchTexts(array $entryIds): array;
}
