<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Search\TranscriptText\TranscriptTextInterface;

final readonly class NoTranscriptTexts implements TranscriptTextInterface
{
    public function searchTextsFor(array $entryIds): array
    {
        return [];
    }
}
