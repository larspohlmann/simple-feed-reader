<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * Pass 1 of the restore: reads the whole file through BackupReader, counts it
 * and checks that every reference it makes resolves inside the same file — a
 * subscription's feed and tags, an entry's and a state's feed. Doing that here
 * rather than during the load is what makes InvalidBackupException's promise
 * true: a file that cannot be fully accepted never costs the account anything.
 *
 * Retains nothing but the header, the five tallies and the bounded name sets
 * BackupTally holds — never the stream itself — so a half-million-entry file
 * costs this pass no more memory than a ten-line one. Any grammar or type
 * violation BackupReader raises propagates unchanged: a file that cannot be
 * fully read must never report a count.
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
