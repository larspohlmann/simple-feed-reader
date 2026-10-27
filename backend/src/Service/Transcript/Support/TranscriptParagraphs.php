<?php

declare(strict_types=1);

namespace App\Service\Transcript\Support;

use App\Entity\TranscriptParagraph;
use App\Service\Transcript\Model\TranscriptCueModel;
use App\Service\Transcript\Pass\ParagraphRun;

final class TranscriptParagraphs
{
    /**
     * A speakerless cue joins the open paragraph, a speaker's cue joins it when the speaker is the same, and a
     * paragraph closes at a sentence end once it is long enough.
     *
     * @param list<TranscriptCueModel> $cues
     *
     * @return list<TranscriptParagraph>
     */
    public static function from(array $cues): array
    {
        $run = new ParagraphRun();
        foreach ($cues as $cue) {
            $run->add($cue);
        }

        return $run->finish();
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
