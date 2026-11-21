<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Enum\ShareKind;
use App\Repository\EntryListRow;
use App\Service\Curation\Trending\Support\MostShared;

/**
 * One Trending card, one row per article (#2229): an outlet's row for an article the reader's feeds carry, else the
 * newest sharing post with a pictured card. Posts are shares of the article their card links, never rows of their own.
 */
final readonly class TrendingStoryCardModel
{
    public function __construct(
        public StoryArticlesModel $articles,
        public RankedStoryModel $story,
        public bool $viewed,
        public bool $followed,
    ) {
    }

    public function face(): StoryFaceModel
    {
        return new StoryFaceModel(
            $this->story->storyId,
            $this->story->heading ?? new StoryHeadingModel($this->articles->lead->row->entry->getTitle(), null),
            new StorySourcesModel($this->story->outletCount, $this->articles->shareCount()),
            $this->followed,
            $this->story->lifespan,
        );
    }

    /** @param non-empty-list<ShownRowModel> $rows the story's outlet and story-card post rows the reader can see */
    public static function fromRows(array $rows, RankedStoryModel $story, bool $viewed, bool $followed): self
    {
        $articles = self::newestFirst(array_map(self::buildArticle(...), self::byArticle($rows)));
        $lead = self::pickLead($articles);

        return new self(
            new StoryArticlesModel($lead, array_values(array_filter(
                $articles,
                static fn (StoryArticleModel $article): bool => $article !== $lead,
            ))),
            $story,
            $viewed,
            $followed,
        );
    }

    /**
     * @param non-empty-list<ShownRowModel> $rows
     *
     * @return non-empty-array<string, non-empty-list<EntryListRow>> article key => the rows about it
     */
    private static function byArticle(array $rows): array
    {
        $byArticle = [];
        foreach ($rows as $shown) {
            $byArticle[$shown->articleKey][] = $shown->row;
        }

        return $byArticle;
    }

    /** @param non-empty-list<EntryListRow> $rows one article's outlet rows and the posts sharing it */
    private static function buildArticle(array $rows): StoryArticleModel
    {
        $rows = self::newestRowsFirst($rows);
        $outletRows = array_values(array_filter($rows, static fn (EntryListRow $row): bool => $row->isFromAnOutlet()));
        $shares = self::oncePerPost(array_values(array_filter(
            $rows,
            static fn (EntryListRow $row): bool => !$row->isFromAnOutlet(),
        )));

        return new StoryArticleModel(
            $outletRows === [] ? self::pickRepresentative($shares) : self::picturedOrNewest($outletRows),
            $shares,
        );
    }

    /** @param non-empty-list<StoryArticleModel> $articles newest first */
    private static function pickLead(array $articles): StoryArticleModel
    {
        $fromOutlets = array_values(array_filter(
            $articles,
            static fn (StoryArticleModel $article): bool => $article->row->isFromAnOutlet(),
        ));
        if ($fromOutlets === []) {
            return MostShared::pick($articles, static fn (StoryArticleModel $article): int => \count($article->shares));
        }

        return array_find($fromOutlets, static fn (StoryArticleModel $article): bool => self::isPictured($article->row))
            ?? $fromOutlets[0];
    }

    /** @param non-empty-list<EntryListRow> $rows newest first */
    private static function picturedOrNewest(array $rows): EntryListRow
    {
        return array_find($rows, self::isPictured(...)) ?? $rows[0];
    }

    private static function isPictured(EntryListRow $row): bool
    {
        return $row->entry->getImageUrl() !== null;
    }

    /**
     * The newest link share whose card has a picture, else the newest link share, else the newest post.
     *
     * @param list<EntryListRow> $posts newest first
     */
    private static function pickRepresentative(array $posts): EntryListRow
    {
        $linkShares = array_values(array_filter(
            $posts,
            static fn (EntryListRow $post): bool => $post->entry->getShareKind() === ShareKind::Link,
        ));

        return array_find(
            $linkShares,
            static fn (EntryListRow $share): bool => ($share->enrichment->linkCards[0]->imageUrl ?? null) !== null,
        ) ?? $linkShares[0] ?? $posts[0];
    }

    /**
     * A post copied into several timelines is one share: its copies carry one URL hash.
     *
     * @param list<EntryListRow> $posts newest first
     *
     * @return list<EntryListRow>
     */
    private static function oncePerPost(array $posts): array
    {
        $byPost = [];
        foreach ($posts as $post) {
            $byPost[$post->keyBy($post->entry->getUrlHash())] ??= $post;
        }

        return array_values($byPost);
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<EntryListRow>
     */
    private static function newestRowsFirst(array $rows): array
    {
        usort(
            $rows,
            static fn (EntryListRow $left, EntryListRow $right): int => self::newness($right) <=> self::newness($left),
        );

        return $rows;
    }

    /**
     * @param non-empty-array<StoryArticleModel> $articles
     *
     * @return non-empty-list<StoryArticleModel>
     */
    private static function newestFirst(array $articles): array
    {
        usort(
            $articles,
            static fn (StoryArticleModel $left, StoryArticleModel $right): int
                => self::newness($right->row) <=> self::newness($left->row),
        );

        return $articles;
    }

    /** @return array{\DateTimeImmutable, int} */
    private static function newness(EntryListRow $row): array
    {
        return [$row->entry->getEffectiveDate(), $row->entry->requireId()];
    }
}
