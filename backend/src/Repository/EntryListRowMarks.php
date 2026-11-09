<?php

declare(strict_types=1);

namespace App\Repository;

/** The reader's own marks on a row's entry; isHidden already has the subscription's watermark folded in. */
final readonly class EntryListRowMarks
{
    public function __construct(
        public bool $isHidden = false,
        public bool $isFavorite = false,
        public bool $isKept = false,
        public ?\DateTimeImmutable $viewedAt = null,
        public ?\DateTimeImmutable $sharedAt = null,
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
