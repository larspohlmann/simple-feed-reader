<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Backup\Dto\BackupHeader;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Backup\Dto\EntryStateLine;
use App\Service\Backup\Exception\BackupDoesNotFitException;
use App\Service\Backup\Exception\InvalidBackupException;

/**
 * Pass 1 of an entry-part restore, writing nothing: the part is an entry part,
 * every feed it names is one this user subscribes to, and loading it keeps
 * the account under its entry ceiling.
 */
final readonly class EntryPartInspector
{
    public function __construct(
        private BackupReader $reader,
        private SubscriptionRepository $subscriptions,
        private EntryRepository $entries,
        private int $accountEntryCeiling = BackupFitCheck::MAX_ENTRIES,
    ) {
    }

    /**
     * @return array<string, int> the id of every feed the part names, by feed url
     */
    public function inspect(User $user, string $gzipBytes): array
    {
        [$feedUrls, $entryCount] = $this->scan($gzipBytes);
        $feedIdsByUrl = $this->subscribedFeedIds($user, $feedUrls);
        $this->assertFits($user, $entryCount);

        return $feedIdsByUrl;
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

            if ($line instanceof EntryLine || $line instanceof EntryStateLine) {
                $feedUrls[$line->feedUrl] = true;
            }

            if ($line instanceof EntryLine) {
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
     *
     * @return array<string, int>
     */
    private function subscribedFeedIds(User $user, array $feedUrls): array
    {
        $feedIdsByUrl = $this->subscriptions->feedIdsByUrlForUser((int) $user->getId(), array_keys($feedUrls));
        foreach (array_keys($feedUrls) as $feedUrl) {
            if (!isset($feedIdsByUrl[$feedUrl])) {
                throw new InvalidBackupException(sprintf(
                    'The backup carries rows for feed "%s", which none of its subscriptions names.',
                    $feedUrl,
                ));
            }
        }

        return $feedIdsByUrl;
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
