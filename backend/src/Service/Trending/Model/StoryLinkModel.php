<?php

declare(strict_types=1);

namespace App\Service\Trending\Model;

final readonly class StoryLinkModel
{
    private const float URL_STRENGTH = \PHP_FLOAT_MAX;

    public int $firstEntryId;
    public int $secondEntryId;

    public function __construct(int $oneEntryId, int $otherEntryId, public float $strength)
    {
        $this->firstEntryId = min($oneEntryId, $otherEntryId);
        $this->secondEntryId = max($oneEntryId, $otherEntryId);
    }

    public static function sameUrl(int $oneEntryId, int $otherEntryId): self
    {
        return new self($oneEntryId, $otherEntryId, self::URL_STRENGTH);
    }

    public function pairKey(): string
    {
        return $this->firstEntryId . ':' . $this->secondEntryId;
    }

    /** Strongest first, ties by entry id, so grouping never depends on the order links arrive in (#2017). */
    public static function compare(self $left, self $right): int
    {
        return [$right->strength, $left->firstEntryId, $left->secondEntryId]
            <=> [$left->strength, $right->firstEntryId, $right->secondEntryId];
    }
}
