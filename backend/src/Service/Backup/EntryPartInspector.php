<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\FeedRepository;
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
        private FeedRepository $feeds,
        private EntryRepository $entries,
        private int $accountEntryCeiling = BackupFitCheck::MAX_ENTRIES,
    ) {
    }

    /**
     * @return array<string, int> the id of every feed the part names, by feed url
     */
    public function inspect(User $user, string $gzipBytes): array
    {
        [$feedUrls, $guidHashesByFeedUrl] = $this->scan($gzipBytes);
        $feedIdsByUrl = $this->subscribedFeedIds($user, $feedUrls);
        $this->assertFits($user, $this->newEntryCount($guidHashesByFeedUrl, $feedIdsByUrl));

        return $feedIdsByUrl;
    }

    /**
     * @return array{array<string, true>, array<string, array<string, true>>}
     */
    private function scan(string $gzipBytes): array
    {
        $feedUrls = [];
        $guidHashesByFeedUrl = [];
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
                $guidHashesByFeedUrl[$line->feedUrl][$line->guidHash] = true;
            }
        }

        return [$feedUrls, $guidHashesByFeedUrl];
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
        $feedIdsByUrl = $this->feeds->idsByUrlsForUser($user->requireId(), array_keys($feedUrls));
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

    /**
     * Only entries this part would actually insert count against the ceiling:
     * a re-import of rows the feed already holds adds nothing (the load dedupes
     * on the same (feed, guidHash)), so counting the raw lines would refuse an
     * account near the ceiling from restoring its own export after the wipe.
     *
     * @param array<string, array<string, true>> $guidHashesByFeedUrl
     * @param array<string, int>                  $feedIdsByUrl
     */
    private function newEntryCount(array $guidHashesByFeedUrl, array $feedIdsByUrl): int
    {
        $newEntryCount = 0;
        foreach ($guidHashesByFeedUrl as $feedUrl => $guidHashes) {
            $existing = $this->entries->existingGuidHashesForFeed($feedIdsByUrl[$feedUrl], array_keys($guidHashes));
            $newEntryCount += \count($guidHashes) - \count($existing);
        }

        return $newEntryCount;
    }

    private function assertFits(User $user, int $newEntryCount): void
    {
        $current = $this->entries->countInFeedsSubscribedBy($user->requireId());
        if ($current + $newEntryCount > $this->accountEntryCeiling) {
            throw new BackupDoesNotFitException(sprintf(
                'The account holds %d entries; this part would add %d, past the %d ceiling.',
                $current,
                $newEntryCount,
                $this->accountEntryCeiling,
            ));
        }
    }
}
