<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

/** A batch's reply judged: its winners, or a retry; either way the transcript its run-log row keeps. */
final readonly class BatchReplyVerdictModel
{
    /** @param list<array{id: int, score: int, reason: string}> $winners */
    private function __construct(
        public bool $usable,
        public array $winners,
        public string $transcript,
    ) {
    }

    /** @param list<array{id: int, score: int, reason: string}> $winners */
    public static function usable(array $winners, string $transcript): self
    {
        return new self(true, $winners, $transcript);
    }

    public static function unusable(string $transcript): self
    {
        return new self(false, [], $transcript);
    }
}
