<?php

declare(strict_types=1);

namespace App\Service\Transcript\Support;

use App\Service\Transcript\Exception\TranscriptUnusableException;
use App\Service\Transcript\Model\TranscriptCueModel;

/** The podcast namespace's JSON transcript: `segments[]` of `startTime`, `speaker`, `body`. */
final class JsonCues
{
    private const int MAX_START_SECONDS = 1000 * 3600;

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
            self::startOf($start),
            $speaker === '' ? null : $speaker,
            $text,
        );
    }

    private static function startOf(mixed $start): ?int
    {
        $isUsable = (\is_int($start) || \is_float($start)) && is_finite($start)
            && $start >= 0 && $start < self::MAX_START_SECONDS;

        return $isUsable ? (int) floor($start) : null;
    }

    private function __construct()
    {
    }
}
