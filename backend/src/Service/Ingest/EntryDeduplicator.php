<?php

declare(strict_types=1);

namespace App\Service\Ingest;

/**
 * Decides whether an incoming feed item is one the feed already gave us, on
 * either of two independent identities: the stable URL (which survives BBC's
 * volatile revision-counter GUID) or the raw GUID (the fallback for items with
 * no URL). Seeded from the rows a feed already holds, then updated as a batch
 * is walked so a duplicate that appears twice within one fetch is caught too.
 */
final class EntryDeduplicator
{
    /** @var array<string, true> */
    private array $seenGuidHashes;

    /** @var array<string, true> */
    private array $seenUrlHashes;

    /**
     * @param list<string> $existingGuidHashes
     * @param list<string> $existingUrlHashes
     */
    public function __construct(array $existingGuidHashes, array $existingUrlHashes)
    {
        $this->seenGuidHashes = array_fill_keys($existingGuidHashes, true);
        $this->seenUrlHashes = array_fill_keys($existingUrlHashes, true);
    }

    public function isDuplicate(string $guidHash, ?string $urlHash): bool
    {
        if (isset($this->seenGuidHashes[$guidHash])) {
            return true;
        }

        return $urlHash !== null && isset($this->seenUrlHashes[$urlHash]);
    }

    public function remember(string $guidHash, ?string $urlHash): void
    {
        $this->seenGuidHashes[$guidHash] = true;
        if ($urlHash !== null) {
            $this->seenUrlHashes[$urlHash] = true;
        }
    }
}
