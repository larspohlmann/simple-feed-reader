<?php

declare(strict_types=1);

namespace App\Repository;

/** The reader's own marks on a row's entry; isHidden already has the subscription's watermark folded in. */
final readonly class EntryListRowMarks
{
    public function __construct(
        public bool $isHidden,
        public bool $isFavorite,
        public bool $isKept,
        public ?\DateTimeImmutable $viewedAt,
        public ?\DateTimeImmutable $sharedAt,
    ) {
    }

    public function isViewed(): bool
    {
        return null !== $this->viewedAt;
    }

    public function isShared(): bool
    {
        return null !== $this->sharedAt;
    }
}
