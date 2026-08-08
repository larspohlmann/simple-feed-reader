<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Decodes one raw assistant reply into a JSON array, tolerating the code
 * fence some models wrap around JSON output. Shared by the pick and
 * duplicate parsers so the fence handling exists exactly once.
 */
final readonly class ModelReplyJsonDecoder
{
    /**
     * @return array<mixed>|null null when the reply is not a JSON object or array
     */
    public function decode(string $content): ?array
    {
        $decoded = json_decode($this->stripCodeFence($content), true);

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
