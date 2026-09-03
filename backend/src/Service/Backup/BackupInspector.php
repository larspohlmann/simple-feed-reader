<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * Pass 1 of the restore: reads the file through BackupReader, counts it, and
 * checks that every reference resolves inside the same file (a subscription's
 * feed/tags, an entry's/state's feed) — the check that makes
 * InvalidBackupException's promise true: a rejected file never costs the
 * account anything.
 *
 * Retains only the header, the five tallies, and BackupTally's bounded name
 * sets, never the stream, so cost stays flat regardless of file size. Grammar
 * or type violations from BackupReader propagate unchanged.
 */
final readonly class BackupInspector
{
    public function __construct(private BackupReader $reader)
    {
    }

    public function inspect(string $gzipBytes): BackupInventory
    {
        $tally = new BackupTally();
        foreach ($this->reader->read($gzipBytes) as $line) {
            $tally->accept($line);
        }

        return $tally->inventory();
    }
}
