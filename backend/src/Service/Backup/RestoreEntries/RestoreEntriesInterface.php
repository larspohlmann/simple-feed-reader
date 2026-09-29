<?php

declare(strict_types=1);

namespace App\Service\Backup\RestoreEntries;

use App\Entity\Entry;

interface RestoreEntriesInterface
{
    /** @return list<Entry> */
    public function entriesAfterId(int $lastId, int $limit): array;

    /** @return array<string, int> */
    public function guidHashToIdMapForFeed(int $feedId): array;

    /**
     * @param list<string> $guidHashes
     *
     * @return array<string, int>
     */
    public function entryIdsByGuidHash(int $feedId, array $guidHashes): array;
}
