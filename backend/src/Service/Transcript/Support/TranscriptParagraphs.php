<?php

declare(strict_types=1);

namespace App\Service\Transcript\Support;

use App\Entity\TranscriptParagraph;
use App\Service\Transcript\Model\TranscriptCueModel;

final class TranscriptParagraphs
{
    /**
     * A speaker's consecutive cues are one paragraph, starting at the first; cues without a speaker stay apart.
     *
     * @param list<TranscriptCueModel> $cues
     *
     * @return list<TranscriptParagraph>
     */
    public static function from(array $cues): array
    {
        $paragraphs = [];
        $open = null;
        foreach ($cues as $cue) {
            if ($open !== null && $cue->speaker !== null && $cue->speaker === $open->speaker) {
                $open = new TranscriptParagraph($open->startSeconds, $open->speaker, $open->text . ' ' . $cue->text);
                continue;
            }
            if ($open !== null) {
                $paragraphs[] = $open;
            }
            $open = new TranscriptParagraph($cue->startSeconds, $cue->speaker, $cue->text);
        }
        if ($open !== null) {
            $paragraphs[] = $open;
        }

        return $paragraphs;
    }

    /** @param list<TranscriptParagraph> $paragraphs */
    public static function searchTextOf(array $paragraphs): string
    {
        return implode("\n", array_map(
            static fn (TranscriptParagraph $paragraph): string => $paragraph->speaker === null
                ? $paragraph->text
                : $paragraph->speaker . ': ' . $paragraph->text,
            $paragraphs,
        ));
    }

    private function __construct()
    {
    }
}
