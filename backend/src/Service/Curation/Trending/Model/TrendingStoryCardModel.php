<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Enum\ShareKind;
use App\Repository\EntryListRow;

/**
 * One Trending card: the lead article, newest with a picture, whose duplicates are the other outlets (#2017); a post
 * sharing the story leads only when the reader follows no article feed in it (#2229).
 */
final readonly class TrendingStoryCardModel
{
    public function __construct(
        public EntryListRow $lead,
        public RankedStoryModel $story,
        public bool $viewed,
    ) {
    }

    /** @param non-empty-list<EntryListRow> $rows the story's article and story-card post rows the reader can see */
    public static function fromRows(array $rows, RankedStoryModel $story, bool $viewed): self
    {
        $onePerFeed = self::newestPerFeed($rows);
        $lead = self::leadOf($onePerFeed);

        return new self(
            $lead->withDuplicates(self::otherFeedsThan($lead, $onePerFeed)),
            $story,
            $viewed,
        );
    }

    /**
     * The lead's own copies elsewhere join its outlets, one per feed, the lead's feed never; a copy is no new source.
     *
     * @param list<EntryListRow> $copies
     */
    #[\NoDiscard]
    public function withCopies(array $copies): self
    {
        return clone($this, [
            'lead' => $this->lead->withDuplicates(self::otherFeedsThan(
                $this->lead,
                self::newestPerFeed([...$this->lead->duplicates, ...$copies]),
            )),
        ]);
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return ($rows is non-empty-list ? non-empty-list<EntryListRow> : list<EntryListRow>) newest first
     */
    private static function newestPerFeed(array $rows): array
    {
        usort($rows, static fn (EntryListRow $left, EntryListRow $right): int => [
            $right->entry->getEffectiveDate(),
            $right->entry->requireId(),
        ] <=> [$left->entry->getEffectiveDate(), $left->entry->requireId()]);

        $byFeed = [];
        foreach ($rows as $row) {
            $byFeed[$row->entry->getFeed()->requireId()] ??= $row;
        }

        return array_values($byFeed);
    }

    /**
     * @param list<EntryListRow> $onePerFeed
     *
     * @return list<EntryListRow>
     */
    private static function otherFeedsThan(EntryListRow $lead, array $onePerFeed): array
    {
        $leadFeedId = $lead->entry->getFeed()->requireId();

        return array_values(array_filter(
            $onePerFeed,
            static fn (EntryListRow $row): bool => $row->entry->getFeed()->requireId() !== $leadFeedId,
        ));
    }

    /** @param non-empty-list<EntryListRow> $rows newest first */
    private static function leadOf(array $rows): EntryListRow
    {
        $articles = array_values(array_filter(
            $rows,
            static fn (EntryListRow $row): bool => $row->entry->getFeed()->getNetwork() === null,
        ));
        if ($articles === []) {
            return self::postLeadOf($rows);
        }
        foreach ($articles as $article) {
            if ($article->entry->getImageUrl() !== null) {
                return $article;
            }
        }

        return $articles[0];
    }

    /** @param non-empty-list<EntryListRow> $posts newest first */
    private static function postLeadOf(array $posts): EntryListRow
    {
        $linkShares = array_values(array_filter(
            $posts,
            static fn (EntryListRow $post): bool => $post->entry->getShareKind() === ShareKind::Link,
        ));
        foreach ($linkShares as $linkShare) {
            if (($linkShare->enrichment->linkCards[0]->imageUrl ?? null) !== null) {
                return $linkShare;
            }
        }

        return $linkShares[0] ?? $posts[0];
    }
}
