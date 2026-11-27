<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class EntryMarks
{
    public function __construct(
        public bool $isFavorite,
        public bool $isKept,
        public ?\DateTimeImmutable $likedAt,
    ) {
    }

    public static function none(): self
    {
        return new self(false, false, null);
    }

    #[\NoDiscard]
    public function withFavorite(bool $isFavorite): self
    {
        return clone($this, ['isFavorite' => $isFavorite]);
    }

    #[\NoDiscard]
    public function withKept(bool $isKept): self
    {
        return clone($this, ['isKept' => $isKept]);
    }

    /** Either side's flag stands, and a like keeps its earlier instant. */
    #[\NoDiscard]
    public function withMergedFrom(self $other): self
    {
        return new self(
            $this->isFavorite || $other->isFavorite,
            $this->isKept || $other->isKept,
            self::earlier($this->likedAt, $other->likedAt),
        );
    }

    private static function earlier(?\DateTimeImmutable $left, ?\DateTimeImmutable $right): ?\DateTimeImmutable
    {
        if (null === $left || null === $right) {
            return $left ?? $right;
        }

        return $left <= $right ? $left : $right;
    }
}
