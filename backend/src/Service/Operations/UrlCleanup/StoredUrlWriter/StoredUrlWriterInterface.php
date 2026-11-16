<?php

declare(strict_types=1);

namespace App\Service\Operations\UrlCleanup\StoredUrlWriter;

use App\Entity\Entry;

/** What the cleanup writes; the dry run writes nothing through the same calls. */
interface StoredUrlWriterInterface
{
    public function relocateEntry(Entry $entry, string $url, ?string $urlHash): void;

    public function relocateLink(int $linkId, string $url, string $urlHash): void;

    public function mergeLink(int $mergedLinkId, int $holderLinkId): void;
}
