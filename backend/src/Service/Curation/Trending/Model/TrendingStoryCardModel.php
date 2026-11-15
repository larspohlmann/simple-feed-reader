<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Enum\ShareKind;
use App\Repository\EntryListRow;

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
    ) {
    }

    /** @param non-empty-list<ShownRowModel> $rows the story's outlet and story-card post rows the reader can see */
    public static function fromRows(array $rows, RankedStoryModel $story, bool $viewed): self
    {
        $articles = self::newestFirst(array_map(self::articleOf(...), self::byArticle($rows)));
        $lead = self::leadOf($articles);

        return new self(
            new StoryArticlesModel($lead, array_values(array_filter(
                $articles,
                static fn (StoryArticleModel $article): bool => $article !== $lead,
            ))),
            $story,
            $viewed,
        );
    }

    /**
     * @param non-empty-list<ShownRowModel> $rows
     *
     * @return non-empty-array<string, array{list<EntryListRow>, list<EntryListRow>}> article => outlet rows, posts
     */
    private static function byArticle(array $rows): array
    {
        $byArticle = [];
        foreach ($rows as $shown) {
            $byArticle[$shown->articleKey] ??= [[], []];
            $byArticle[$shown->articleKey][$shown->row->isFromAnOutlet() ? 0 : 1][] = $shown->row;
        }

        return $byArticle;
    }

    /** @param array{list<EntryListRow>, list<EntryListRow>} $outletRowsAndPosts */
    private static function articleOf(array $outletRowsAndPosts): StoryArticleModel
    {
        [$outletRows, $posts] = $outletRowsAndPosts;
        $shares = self::oncePerPost(self::newestRowsFirst($posts));
        $outletRows = self::newestRowsFirst($outletRows);

        return new StoryArticleModel(
            $outletRows === [] ? self::representativeOf($shares) : self::picturedOrNewest($outletRows),
            $shares,
        );
    }

    /**
     * @param non-empty-list<StoryArticleModel> $articles newest first
     */
    private static function leadOf(array $articles): StoryArticleModel
    {
        $fromOutlets = array_values(array_filter(
            $articles,
            static fn (StoryArticleModel $article): bool => $article->row->isFromAnOutlet(),
        ));
        if ($fromOutlets === []) {
            return self::mostShared($articles);
        }
        foreach ($fromOutlets as $article) {
            if ($article->row->entry->getImageUrl() !== null) {
                return $article;
            }
        }

        return $fromOutlets[0];
    }

    /** @param non-empty-list<StoryArticleModel> $articles newest first */
    private static function mostShared(array $articles): StoryArticleModel
    {
        $mostShared = $articles[0];
        foreach ($articles as $article) {
            if (\count($article->shares) > \count($mostShared->shares)) {
                $mostShared = $article;
            }
        }

        return $mostShared;
    }

    /** @param non-empty-list<EntryListRow> $rows newest first */
    private static function picturedOrNewest(array $rows): EntryListRow
    {
        foreach ($rows as $row) {
            if ($row->entry->getImageUrl() !== null) {
                return $row;
            }
        }

        return $rows[0];
    }

    /**
     * The newest link share whose card has a picture, else the newest link share, else the newest post.
     *
     * @param list<EntryListRow> $posts newest first
     */
    private static function representativeOf(array $posts): EntryListRow
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
            $byPost[$post->entry->getUrlHash() ?? 'entry ' . $post->entry->requireId()] ??= $post;
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
