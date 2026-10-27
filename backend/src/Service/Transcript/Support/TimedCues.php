<?php

declare(strict_types=1);

namespace App\Service\Transcript\Support;

use App\Service\Transcript\Model\TranscriptCueModel;

/** WebVTT and SubRip cues: a block's timing line starts it, the lines after it are its text. */
final class TimedCues
{
    private const string TIMING = '/^(?:(\d+):)?(\d{1,2}):(\d{2})[.,]\d{1,3}\s+-->/';
    private const string VOICE = '/^<v(?:\.[^\s>]*)?\s+([^>]+)>/';

    /** @return list<TranscriptCueModel> */
    public static function of(string $body): array
    {
        $cues = [];
        foreach (self::blocks($body) as $lines) {
            $cue = self::cueOf($lines);
            if ($cue !== null) {
                $cues[] = $cue;
            }
        }

        return $cues;
    }

    /** @return list<list<string>> */
    private static function blocks(string $body): array
    {
        $normalized = str_replace(["\u{FEFF}", "\r\n", "\r"], ['', "\n", "\n"], $body);
        $blocks = preg_split('/\n{2,}/', trim($normalized)) ?: [];

        return array_map(static fn (string $block): array => explode("\n", $block), $blocks);
    }

    /** @param list<string> $lines */
    private static function cueOf(array $lines): ?TranscriptCueModel
    {
        foreach ($lines as $index => $line) {
            $start = self::startOf($line);
            if ($start !== null) {
                return self::cueAt($start, \array_slice($lines, $index + 1));
            }
        }

        return null;
    }

    private static function startOf(string $line): ?int
    {
        if (preg_match(self::TIMING, trim($line), $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 3600 + (int) $match[2] * 60 + (int) $match[3];
    }

    /** @param list<string> $textLines */
    private static function cueAt(int $start, array $textLines): ?TranscriptCueModel
    {
        $raw = trim(implode(' ', $textLines));
        $speaker = preg_match(self::VOICE, $raw, $match) === 1 ? trim($match[1]) : null;
        $text = self::plain($raw);

        return $text === '' ? null : new TranscriptCueModel($start, $speaker === '' ? null : $speaker, $text);
    }

    private static function plain(string $markup): string
    {
        $text = html_entity_decode(strip_tags($markup), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function __construct()
    {
    }
}
