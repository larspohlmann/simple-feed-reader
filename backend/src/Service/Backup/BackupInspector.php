<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Backup\Dto\AccountLine;
use App\Service\Backup\Dto\BackupHeader;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Backup\Dto\EntryStateLine;
use App\Service\Backup\Dto\FeedLine;
use App\Service\Backup\Dto\SubscriptionLine;
use App\Service\Backup\Dto\TagLine;
use App\Service\Backup\Exception\InvalidBackupException;

/**
 * Pass 1 of the restore: reads the whole file through BackupReader and counts
 * it. Retains nothing but the header, the account line and the five tallies —
 * never the stream itself — so a half-million-entry file costs this pass no
 * more memory than a ten-line one. Any grammar or type violation BackupReader
 * raises propagates unchanged: a file that cannot be fully read must never
 * report a count.
 */
final readonly class BackupInspector
{
    public function inspect(string $gzipBytes): BackupInventory
    {
        $header = null;
        $account = null;
        $tags = 0;
        $feeds = 0;
        $subscriptions = 0;
        $entries = 0;
        $entryStates = 0;

        foreach ((new BackupReader())->read($gzipBytes) as $line) {
            switch (true) {
                case $line instanceof BackupHeader:
                    $header = $line;
                    break;
                case $line instanceof AccountLine:
                    $account = $line;
                    break;
                case $line instanceof TagLine:
                    ++$tags;
                    break;
                case $line instanceof FeedLine:
                    ++$feeds;
                    break;
                case $line instanceof SubscriptionLine:
                    ++$subscriptions;
                    break;
                case $line instanceof EntryLine:
                    ++$entries;
                    break;
                case $line instanceof EntryStateLine:
                    ++$entryStates;
                    break;
            }
        }

        return new BackupInventory(
            header: $this->requireHeader($header),
            account: $this->requireAccount($account),
            tags: $tags,
            feeds: $feeds,
            subscriptions: $subscriptions,
            entries: $entries,
            entryStates: $entryStates,
        );
    }

    /**
     * BackupReader guarantees a header and an account line precede everything
     * else and throws before yielding anything if either is missing, so these
     * two never actually fire — they exist to keep the property typed instead
     * of nullable.
     */
    private function requireHeader(?BackupHeader $header): BackupHeader
    {
        return $header ?? throw new InvalidBackupException('The backup is missing its header line.');
    }

    private function requireAccount(?AccountLine $account): AccountLine
    {
        return $account ?? throw new InvalidBackupException('The backup is missing its account line.');
    }
}
