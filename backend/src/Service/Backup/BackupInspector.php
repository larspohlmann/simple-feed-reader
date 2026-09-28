<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Backup\Model\BackupInventoryModel;

/**
 * Pass 1 of a restore start: reads the foundation through BackupReader, counts
 * it, and checks that every subscription's feed and tags resolve inside it —
 * so a rejected file never costs the account anything.
 */
final readonly class BackupInspector
{
    public function __construct(private BackupReader $reader)
    {
    }

    public function inspect(string $gzipBytes): BackupInventoryModel
    {
        $tally = new BackupTally();
        foreach ($this->reader->read($gzipBytes) as $line) {
            $tally->accept($line);
        }

        return $tally->inventory();
    }
}
