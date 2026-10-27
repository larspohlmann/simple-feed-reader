<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EntryTranscript;
use App\Entity\TranscriptParagraph;

final class EntryTranscriptJson
{
    /**
     * @return array{
     *     language: string|null,
     *     paragraphs: list<array{startSeconds: int|null, speaker: string|null, text: string}>,
     * }|null
     */
    public static function of(?EntryTranscript $transcript): ?array
    {
        if ($transcript === null) {
            return null;
        }

        return [
            'language' => $transcript->getLanguage(),
            'paragraphs' => array_map(
                static fn (TranscriptParagraph $paragraph): array => $paragraph->jsonSerialize(),
                $transcript->getParagraphs(),
            ),
        ];
    }

    private function __construct()
    {
    }
}
