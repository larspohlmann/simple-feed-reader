<?php

declare(strict_types=1);

namespace App\Service\Content\Transcript\Support;

use App\Service\Content\Transcript\Model\TranscriptCueModel;
use App\Service\Foundation\Text\Support\PlainText;

/** WebVTT and SubRip cues: a block's timing line starts it, the lines after it are its text. */
final class TimedCues
{
    private const string TIMING = '/^(?:(\d{1,3}):)?(\d{1,2}):(\d{2})(?:[.,]\d{1,3})?\s*-->/';
    private const string INLINE_LABEL = '/^(\p{Lu}[\p{Lu}\p{N} .\'’-]{0,39}):\s+/u';
    private const string VOICE = '/^<v(?:\.[^\s>]*)?\s+([^>]+)>/';

    /** @return list<TranscriptCueModel> */
    public static function parseCues(string $body): array
    {
        $cues = [];
        foreach (self::blocks($body) as $lines) {
            $cue = self::parseCue($lines);
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
    private static function parseCue(array $lines): ?TranscriptCueModel
    {
        foreach ($lines as $index => $line) {
            $start = self::parseStart($line);
            if ($start !== null) {
                return self::parseCueText($start, \array_slice($lines, $index + 1));
            }
        }

        return null;
    }

    private static function parseStart(string $line): ?int
    {
        if (preg_match(self::TIMING, trim($line), $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 3600 + (int) $match[2] * 60 + (int) $match[3];
    }

    /** @param list<string> $textLines */
    private static function parseCueText(int $start, array $textLines): ?TranscriptCueModel
    {
        $raw = trim(implode(' ', $textLines));
        $voice = preg_match(self::VOICE, $raw, $match) === 1 ? trim($match[1]) : '';
        $text = PlainText::from($raw) ?? '';
        if ($voice === '' && preg_match(self::INLINE_LABEL, $text, $match) === 1) {
            $voice = trim($match[1]);
            $text = substr($text, \strlen($match[0]));
        }

        return $text === '' ? null : new TranscriptCueModel($start, $voice === '' ? null : $voice, $text);
    }

    private function __construct()
    {
    }
}
