<?php

declare(strict_types=1);

namespace App\Service\Operations\UrlCleanup\StoredUrlWriter;

use App\Entity\Entry;

final readonly class DryRunStoredUrlWriter implements StoredUrlWriterInterface
{
    public function relocateEntry(Entry $entry, string $url, ?string $urlHash): void
    {
    }

    public function relocateLink(int $linkId, string $url, string $urlHash): void
    {
    }

    public function mergeLink(int $mergedLinkId, int $holderLinkId): void
    {
    }
}
