<?php

declare(strict_types=1);

namespace App\Service\Transcript\Support;

use App\Service\Transcript\Exception\TranscriptUnusableException;
use App\Service\Transcript\Model\TranscriptCueModel;

/** The podcast namespace's JSON transcript: `segments[]` of `startTime`, `speaker`, `body`. */
final class JsonCues
{
    /**
     * @return list<TranscriptCueModel>
     *
     * @throws TranscriptUnusableException
     */
    public static function of(string $body): array
    {
        try {
            $document = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw TranscriptUnusableException::malformed($exception);
        }

        $segments = \is_array($document) && \is_array($document['segments'] ?? null) ? $document['segments'] : [];
        $cues = [];
        foreach ($segments as $segment) {
            $cue = \is_array($segment) ? self::cueOf($segment) : null;
            if ($cue !== null) {
                $cues[] = $cue;
            }
        }

        return $cues;
    }

    /** @param array<mixed> $segment */
    private static function cueOf(array $segment): ?TranscriptCueModel
    {
        $text = \is_string($segment['body'] ?? null) ? trim($segment['body']) : '';
        if ($text === '') {
            return null;
        }

        $start = $segment['startTime'] ?? null;
        $speaker = \is_string($segment['speaker'] ?? null) ? trim($segment['speaker']) : '';

        return new TranscriptCueModel(
            \is_int($start) || \is_float($start) ? (int) floor($start) : null,
            $speaker === '' ? null : $speaker,
            $text,
        );
    }

    private function __construct()
    {
    }
}
