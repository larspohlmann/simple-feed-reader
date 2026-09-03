<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Decodes one raw assistant reply into a JSON array, tolerating the code fence
 * some models wrap around JSON, and — when the whole reply does not parse —
 * the thinking prose a reasoning model wraps around its answer (#323). Shared
 * by the pick and duplicate parsers so the tolerance exists once.
 */
final readonly class ModelReplyJsonDecoder
{
    /**
     * @return array<mixed>|null null when the reply is not a JSON object or array
     */
    public function decode(string $content): ?array
    {
        $decoded = json_decode($this->stripCodeFence($content), true);

        if (\is_array($decoded)) {
            return $decoded;
        }

        return $this->lastEmbeddedObject($content);
    }

    /**
     * The last complete `{...}` in the reply that decodes to an array — the
     * object the model settled on last. LM Studio can route a reasoning answer
     * through the reasoning channel, wrapped in thinking prose (#323). String
     * literals are skipped so a brace inside a value cannot end an object early.
     *
     * @return array<mixed>|null
     */
    private function lastEmbeddedObject(string $text): ?array
    {
        $found = null;
        $depth = 0;
        $start = 0;
        $length = \strlen($text);

        for ($index = 0; $index < $length; ++$index) {
            $character = $text[$index];

            if ('"' === $character) {
                $index = $this->endOfString($text, $index);
            } elseif ('{' === $character) {
                if (0 === $depth) {
                    $start = $index;
                }
                ++$depth;
            } elseif ('}' === $character && $depth > 0 && 0 === --$depth) {
                $found = $this->decodeObject(substr($text, $start, $index - $start + 1)) ?? $found;
            }
        }

        return $found;
    }

    /**
     * The index of the closing quote of the string that opens at $openIndex, so
     * the scan can jump past a value that may itself contain braces. A
     * backslash escapes the next character, so an escaped quote does not close
     * the string; an unterminated string runs to the end.
     */
    private function endOfString(string $text, int $openIndex): int
    {
        $length = \strlen($text);

        for ($index = $openIndex + 1; $index < $length; ++$index) {
            $character = $text[$index];

            if ('\\' === $character) {
                ++$index;
            } elseif ('"' === $character) {
                return $index;
            }
        }

        return $length;
    }

    /** @return array<mixed>|null */
    private function decodeObject(string $candidate): ?array
    {
        $decoded = json_decode($candidate, true);

        return \is_array($decoded) ? $decoded : null;
    }

    private function stripCodeFence(string $content): string
    {
        $trimmed = trim($content);

        if (!str_starts_with($trimmed, '```') || !str_ends_with($trimmed, '```')) {
            return $trimmed;
        }

        $withoutClosingFence = substr($trimmed, 0, -3);
        $firstLineEnd = strpos($withoutClosingFence, "\n");

        if (false === $firstLineEnd) {
            return $withoutClosingFence;
        }

        return substr($withoutClosingFence, $firstLineEnd + 1);
    }
}
