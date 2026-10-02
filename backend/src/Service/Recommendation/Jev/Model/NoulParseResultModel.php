<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev\Model;

final readonly class NoulParseResultModel
{
    /** @param list<array{id: int, score: int, reason: string}> $winners */
    private function __construct(
        public bool $usable,
        public array $winners,
    ) {
    }

    /** @param list<array{id: int, score: int, reason: string}> $winners */
    public static function usable(array $winners): self
    {
        return new self(true, $winners);
    }

    public static function unusable(): self
    {
        return new self(false, []);
    }
}
