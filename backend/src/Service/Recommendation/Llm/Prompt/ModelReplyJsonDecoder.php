<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Prompt;

/**
 * Decodes one reply into a JSON array, tolerating a code fence and, when the whole reply does not parse, the thinking
 * prose a reasoning model wraps around its answer. The pick, profile and consolidation parsers share it.
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
     * The complete objects of the `$key` array in a reply cut off before that array closed, in order: the object the
     * cut split never closes, so it is not among them. Empty when the reply never opened the array.
     *
     * @return list<array<mixed>>
     */
    public function completeItemsOf(string $content, string $key): array
    {
        $keyAt = strpos($content, '"' . $key . '"');
        if (false === $keyAt) {
            return [];
        }

        $arrayAt = strpos($content, '[', $keyAt);
        if (false === $arrayAt) {
            return [];
        }

        return $this->completeObjectsFrom($content, $arrayAt);
    }

    /**
     * The last complete `{...}` that decodes, the object the model settled on: LM Studio can route an answer through
     * thinking prose.
     *
     * @return array<mixed>|null
     */
    private function lastEmbeddedObject(string $text): ?array
    {
        $objects = $this->completeObjectsFrom($text, 0);

        return [] === $objects ? null : $objects[array_key_last($objects)];
    }

    /**
     * Every outermost `{...}` from $offset on that closes and decodes, in order. String literals are skipped, so a
     * brace inside a value cannot end an object early.
     *
     * @return list<array<mixed>>
     */
    private function completeObjectsFrom(string $text, int $offset): array
    {
        $objects = [];
        $depth = 0;
        $start = $offset;
        $length = \strlen($text);

        for ($index = $offset; $index < $length; ++$index) {
            $character = $text[$index];

            if ('"' === $character) {
                $index = $this->endOfString($text, $index);
            } elseif ('{' === $character) {
                if (0 === $depth) {
                    $start = $index;
                }
                ++$depth;
            } elseif ('}' === $character && $depth > 0 && 0 === --$depth) {
                array_push($objects, ...$this->decodeObject(substr($text, $start, $index - $start + 1)));
            }
        }

        return $objects;
    }

    /**
     * Where the string opening at $openIndex closes. A backslash escapes the next character; an unterminated string
     * runs to the end.
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

    /** @return list<array<mixed>> the object alone, or nothing when it does not decode */
    private function decodeObject(string $candidate): array
    {
        $decoded = json_decode($candidate, true);

        return \is_array($decoded) ? [$decoded] : [];
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
