<?php

declare(strict_types=1);

namespace App\Service\Transcript\Model;

use App\Entity\TranscriptParagraph;

final readonly class ReadTranscriptModel
{
    /** @param list<TranscriptParagraph> $paragraphs */
    public function __construct(public array $paragraphs, public string $searchText)
    {
    }
}
