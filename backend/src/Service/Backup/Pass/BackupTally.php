<?php

declare(strict_types=1);

namespace App\Service\Backup\Pass;

use App\Entity\Tag;
use App\Service\Backup\Dto\BackupHeader;
use App\Service\Backup\Dto\FeedLine;
use App\Service\Backup\Dto\SavedSearchLine;
use App\Service\Backup\Dto\SubscriptionLine;
use App\Service\Backup\Dto\TagLine;
use App\Service\Backup\Exception\InvalidBackupException;
use App\Service\Backup\Model\BackupInventoryModel;
use App\Service\SavedPosts\Support\SavedFeedUrl;

/**
 * One foundation inspection's working state: the header, a count per line
 * kind, and the name sets that make its cross-references checkable while it
 * streams past. Built per inspect() call and thrown away with it.
 */
final class BackupTally
{
    private ?BackupHeader $header = null;

    /** @var array<string, int> each declared tag's depth below the top, by name */
    private array $tagDepths = [];

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
    ];

    public function accept(object $line): void
    {
        match (true) {
            $line instanceof BackupHeader => $this->header = $line,
            $line instanceof TagLine => $this->acceptTag($line),
            $line instanceof SavedSearchLine => ++$this->counts['savedSearches'],
            $line instanceof FeedLine => $this->acceptFeed($line),
            $line instanceof SubscriptionLine => $this->acceptSubscription($line),
            // The account line is validated by its own dto and loaded in pass 2.
            default => null,
        };
    }

    /**
     * A foundation holds no entry lines, so the entry counts are its header's claimed totals.
     */
    public function inventory(): BackupInventoryModel
    {
        $header = $this->header ?? throw new InvalidBackupException('The backup is missing its header line.');
        $totals = $header->totals
            ?? throw new InvalidBackupException('The restore starts with part 0, the foundation.');

        return new BackupInventoryModel(
            source: $header->toSource(),
            tags: $this->counts['tags'],
            savedSearches: $this->counts['savedSearches'],
            feeds: $this->counts['feeds'],
            subscriptions: $this->counts['subscriptions'],
            entries: $totals->entries,
            entryStates: $totals->entryStates,
        );
    }

    /**
     * A repeated tag name is refused here rather than left to the unique
     * (user_id, name) index, which would only speak up after the wipe.
     */
    private function acceptTag(TagLine $line): void
    {
        if (isset($this->tagDepths[$line->name])) {
            throw new InvalidBackupException(sprintf('The backup declares tag "%s" twice.', $line->name));
        }

        $this->tagDepths[$line->name] = $line->parent === null ? 0 : $this->depthUnder($line->parent, $line->name);
        ++$this->counts['tags'];
    }

    private function depthUnder(string $parent, string $child): int
    {
        if (!isset($this->tagDepths[$parent])) {
            throw new InvalidBackupException(sprintf('The backup names parent tag "%s" before declaring it.', $parent));
        }

        $depth = $this->tagDepths[$parent] + 1;
        if ($depth > Tag::MAX_DEPTH) {
            throw new InvalidBackupException(
                sprintf('The backup nests tag "%s" more than %d levels below the top.', $child, Tag::MAX_DEPTH),
            );
        }

        return $depth;
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

        foreach ($line->tags as $tagReference) {
            if (!isset($this->tagDepths[$tagReference->name])) {
                throw new InvalidBackupException(sprintf(
                    'A subscription names tag "%s", which the backup never declares.',
                    $tagReference->name,
                ));
            }
        }

        $this->subscribedFeedUrls[$line->feedUrl] = true;
        if (!SavedFeedUrl::isSaved($line->feedUrl)) {
            ++$this->counts['subscriptions'];
        }
    }
}
