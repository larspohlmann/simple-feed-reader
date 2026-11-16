<?php

declare(strict_types=1);

namespace App\Service\Operations\UrlCleanup\StoredUrlWriter;

use App\Entity\Entry;
use App\Repository\SharedLinkRepository;
use App\Repository\SharedLinkResolutionRepository;

final readonly class StoredUrlWriter implements StoredUrlWriterInterface
{
    public function __construct(
        private SharedLinkRepository $sharedLinks,
        private SharedLinkResolutionRepository $resolutions,
    ) {
    }

    public function relocateEntry(Entry $entry, string $url, ?string $urlHash): void
    {
        $entry->relocate($url, $urlHash);
    }

    public function relocateLink(int $linkId, string $url, string $urlHash): void
    {
        $this->sharedLinks->relocate($linkId, $url, $urlHash);
    }

    public function mergeLink(int $mergedLinkId, int $holderLinkId): void
    {
        $this->resolutions->moveUnlessHeld($mergedLinkId, $holderLinkId);
        $this->sharedLinks->mergeInto($mergedLinkId, $holderLinkId);
    }
}
