<?php

declare(strict_types=1);

namespace App\Service\Backup\Model;

/** What a backup file holds, without holding the file: its source and a count for every repeatable line kind. */
final readonly class BackupInventoryModel
{
    public function __construct(
        public RestoreSourceModel $source,
        public int $tags,
        public int $savedSearches,
        public int $feeds,
        public int $subscriptions,
        public int $entries,
        public int $entryStates,
    ) {
    }
}
