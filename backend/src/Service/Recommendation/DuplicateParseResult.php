<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The outcome of parsing one dedup reply. Unlike PickParseResult, an empty
 * id list is usable: "no duplicates" is a legitimate answer, while zero
 * picks would mean the model recommended nothing.
 */
final readonly class DuplicateParseResult
{
    /** @param list<int> $duplicateIds */
    private function __construct(
        public array $duplicateIds,
        public bool $usable,
    ) {
    }

    /** @param list<int> $duplicateIds */
    public static function usable(array $duplicateIds): self
    {
        return new self($duplicateIds, true);
    }

    public static function unusable(): self
    {
        return new self([], false);
    }
}
