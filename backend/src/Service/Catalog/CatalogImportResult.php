<?php

declare(strict_types=1);

namespace App\Service\Catalog;

final readonly class CatalogImportResult
{
    public function __construct(
        public int $categoriesCreated = 0,
        public int $categoriesUpdated = 0,
        public int $categoriesRemoved = 0,
        public int $feedsCreated = 0,
        public int $feedsUpdated = 0,
        public int $feedsRemoved = 0,
        /** Locked rows the import left alone — reported so the admin can see the lock did something. */
        public int $lockedSkipped = 0,
    ) {
    }

    public function with(
        int $categoriesCreated = 0,
        int $categoriesUpdated = 0,
        int $categoriesRemoved = 0,
        int $feedsCreated = 0,
        int $feedsUpdated = 0,
        int $feedsRemoved = 0,
        int $lockedSkipped = 0,
    ): self {
        return new self(
            $this->categoriesCreated + $categoriesCreated,
            $this->categoriesUpdated + $categoriesUpdated,
            $this->categoriesRemoved + $categoriesRemoved,
            $this->feedsCreated + $feedsCreated,
            $this->feedsUpdated + $feedsUpdated,
            $this->feedsRemoved + $feedsRemoved,
            $this->lockedSkipped + $lockedSkipped,
        );
    }
}
