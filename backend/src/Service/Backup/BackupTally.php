<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Backup\Dto\BackupHeader;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Backup\Dto\EntryStateLine;
use App\Service\Backup\Dto\FeedLine;
use App\Service\Backup\Dto\SavedSearchLine;
use App\Service\Backup\Dto\SubscriptionLine;
use App\Service\Backup\Dto\TagLine;
use App\Service\Backup\Exception\InvalidBackupException;

/**
 * One inspection's working state: the header, a count per repeatable line
 * kind, and the two name sets that make the foundation's own cross-references
 * checkable while it streams past. Built per inspect() call and thrown away
 * with it, which is why it does not live on the shared BackupInspector.
 *
 * Entry and entry-state lines are counted only — an entry part carries no
 * subscriptions to check them against, so that verification is Task 5's, run
 * against the account's actual rows rather than the file's own claims.
 */
final class BackupTally
{
    private ?BackupHeader $header = null;

    /** @var array<string, true> declared tag names, as a set */
    private array $tagNames = [];

    /** @var array<string, true> declared feed urls, as a set */
    private array $feedUrls = [];

    /** @var array<string, true> the feed urls a subscription actually names */
    private array $subscribedFeedUrls = [];

    /** @var array<string, int> */
    private array $counts = [
        'tags' => 0,
        'savedSearches' => 0,
        'feeds' => 0,
        'subscriptions' => 0,
        'entries' => 0,
        'entryStates' => 0,
    ];

    public function accept(object $line): void
    {
        match (true) {
            $line instanceof BackupHeader => $this->header = $line,
            $line instanceof TagLine => $this->acceptTag($line),
            $line instanceof SavedSearchLine => ++$this->counts['savedSearches'],
            $line instanceof FeedLine => $this->acceptFeed($line),
            $line instanceof SubscriptionLine => $this->acceptSubscription($line),
            $line instanceof EntryLine => ++$this->counts['entries'],
            $line instanceof EntryStateLine => ++$this->counts['entryStates'],
            // The account line is validated by its own dto and loaded in pass 2.
            default => null,
        };
    }

    public function inventory(): BackupInventory
    {
        $header = $this->header ?? throw new InvalidBackupException('The backup is missing its header line.');
        [$entries, $entryStates] = $this->entryCounts($header);

        return new BackupInventory(
            header: $header,
            tags: $this->counts['tags'],
            savedSearches: $this->counts['savedSearches'],
            feeds: $this->counts['feeds'],
            subscriptions: $this->counts['subscriptions'],
            entries: $entries,
            entryStates: $entryStates,
        );
    }

    /**
     * A foundation's own counted entries/entryStates are always zero; use the
     * header's claimed totals instead. An entry part counts its own lines.
     *
     * @return array{int, int}
     */
    private function entryCounts(BackupHeader $header): array
    {
        if (!$header->isFoundation()) {
            return [$this->counts['entries'], $this->counts['entryStates']];
        }

        $totals = $header->totals ?? throw new \LogicException(
            'BackupReader must refuse a foundation with no totals before it reaches the tally.',
        );

        return [$totals->entries, $totals->entryStates];
    }

    /**
     * A repeated tag name is refused here rather than left to the unique
     * (user_id, name) index, which would only speak up after the wipe.
     */
    private function acceptTag(TagLine $line): void
    {
        if (isset($this->tagNames[$line->name])) {
            throw new InvalidBackupException(sprintf('The backup declares tag "%s" twice.', $line->name));
        }

        $this->tagNames[$line->name] = true;
        ++$this->counts['tags'];
    }

    /**
     * A repeated feed url is refused here rather than left to the unique
     * feed.url index, which would only speak up after the wipe.
     */
    private function acceptFeed(FeedLine $line): void
    {
        if (isset($this->feedUrls[$line->url])) {
            throw new InvalidBackupException(sprintf('The backup declares feed "%s" twice.', $line->url));
        }

        $this->feedUrls[$line->url] = true;
        ++$this->counts['feeds'];
    }

    /**
     * A repeated subscription is refused here rather than left to the unique
     * (user_id, feed_id) index, which would only speak up after the wipe.
     */
    private function acceptSubscription(SubscriptionLine $line): void
    {
        if (!isset($this->feedUrls[$line->feedUrl])) {
            throw new InvalidBackupException(sprintf(
                'Subscription to "%s" has no matching feed line.',
                $line->feedUrl,
            ));
        }

        if (isset($this->subscribedFeedUrls[$line->feedUrl])) {
            throw new InvalidBackupException(sprintf(
                'The backup subscribes to feed "%s" twice.',
                $line->feedUrl,
            ));
        }

        foreach ($line->tags as $ref) {
            if (!isset($this->tagNames[$ref->name])) {
                throw new InvalidBackupException(sprintf(
                    'A subscription names tag "%s", which the backup never declares.',
                    $ref->name,
                ));
            }
        }

        $this->subscribedFeedUrls[$line->feedUrl] = true;
        ++$this->counts['subscriptions'];
    }
}
