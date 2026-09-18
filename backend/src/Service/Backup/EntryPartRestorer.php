<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\User;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Backup\Dto\EntryStateLine;

/**
 * The entries endpoint's whole restore: additive and idempotent, unlike
 * AccountRestorer's start() — no confirmation phrase, no wipe. Pass 1
 * (EntryPartInspector) validates against the account's actual rows while
 * writing nothing; pass 2 loads through a fresh RestoreEntryLoader built for
 * this call alone.
 */
final readonly class EntryPartRestorer
{
    public function __construct(
        private EntryPartInspector $inspector,
        private BackupReader $reader,
        private RestoreEntryLoaderFactory $loaderFactory,
    ) {
    }

    public function load(User $user, string $gzipBytes): RestoreResult
    {
        $loader = $this->loaderFactory->create($user, $this->inspector->inspect($user, $gzipBytes));
        foreach ($this->reader->read($gzipBytes) as $line) {
            match (true) {
                $line instanceof EntryLine => $loader->bufferEntry($line),
                $line instanceof EntryStateLine => $loader->loadState($line),
                default => null,
            };
        }
        $loader->finish();

        return RestoreResult::ofEntryPart($loader->entriesCreated(), $loader->entryStatesCreated());
    }
}
