<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Backup\Dto\BackupHeader;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Backup\Exception\BackupDoesNotFitException;
use App\Service\Backup\Exception\InvalidBackupException;

/**
 * Pass 1 of an entry-part restore: reads the part through BackupReader (whose
 * grammar and per-part ceilings already apply), then checks the three things
 * only the database can answer — writing nothing, so a refusal here costs the
 * account nothing:
 *
 * 1. the part is an entry part, not the foundation;
 * 2. every feed it names is one this user actually subscribes to;
 * 3. loading it would not push the account's entry count past its ceiling.
 */
final readonly class EntryPartInspector
{
    public function __construct(
        private BackupReader $reader,
        private SubscriptionRepository $subscriptions,
        private EntryRepository $entries,
        private int $accountEntryCeiling = 500_000,
    ) {
    }

    public function inspect(User $user, string $gzipBytes): void
    {
        [$feedUrls, $entryCount] = $this->scan($gzipBytes);
        $this->assertEverySubscribed($user, $feedUrls);
        $this->assertFits($user, $entryCount);
    }

    /**
     * @return array{array<string, true>, int}
     */
    private function scan(string $gzipBytes): array
    {
        $feedUrls = [];
        $entryCount = 0;
        $isFirstLine = true;
        foreach ($this->reader->read($gzipBytes) as $line) {
            if ($isFirstLine) {
                $this->assertEntryPart($line);
                $isFirstLine = false;
                continue;
            }

            if ($line instanceof EntryLine) {
                $feedUrls[$line->feedUrl] = true;
                ++$entryCount;
            }
        }

        return [$feedUrls, $entryCount];
    }

    private function assertEntryPart(object $line): void
    {
        if (!$line instanceof BackupHeader || $line->isFoundation()) {
            throw new InvalidBackupException('This request takes an entry part, not the foundation.');
        }
    }

    /**
     * @param array<string, true> $feedUrls
     */
    private function assertEverySubscribed(User $user, array $feedUrls): void
    {
        $feedIds = $this->subscriptions->feedIdsByUrlForUser((int) $user->getId(), array_keys($feedUrls));
        foreach (array_keys($feedUrls) as $feedUrl) {
            if (!isset($feedIds[$feedUrl])) {
                throw new InvalidBackupException(sprintf(
                    'The backup carries rows for feed "%s", which none of its subscriptions names.',
                    $feedUrl,
                ));
            }
        }
    }

    private function assertFits(User $user, int $entryCount): void
    {
        $current = $this->entries->countInFeedsSubscribedBy((int) $user->getId());
        if ($current + $entryCount > $this->accountEntryCeiling) {
            throw new BackupDoesNotFitException(sprintf(
                'The account holds %d entries; this part would add %d, past the %d ceiling.',
                $current,
                $entryCount,
                $this->accountEntryCeiling,
            ));
        }
    }
}
