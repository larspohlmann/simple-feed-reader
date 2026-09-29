<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\User;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Backup\Dto\EntryStateLine;
use App\Service\Backup\Factory\RestoreEntryLoaderFactory;
use App\Service\Backup\Model\RestoreResultModel;

/**
 * The entries endpoint's restore: additive and idempotent, with no confirmation phrase and no wipe. EntryPartInspector
 * validates while writing nothing, then a fresh RestoreEntryLoader, built for this call alone, loads.
 */
final readonly class EntryPartRestorer
{
    public function __construct(
        private EntryPartInspector $inspector,
        private BackupReader $reader,
        private RestoreEntryLoaderFactory $loaderFactory,
    ) {
    }

    public function load(User $user, string $gzipBytes): RestoreResultModel
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

        return RestoreResultModel::ofEntryPart($loader->entriesCreated(), $loader->entryStatesCreated());
    }
}
