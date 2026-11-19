<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Curation\Trending\Model\StoryArticleModel;
use App\Service\Curation\Trending\Model\TrendingPageModel;
use App\Service\Curation\Trending\Model\TrendingStoryCardModel;

final readonly class TrendingJson
{
    public function __construct(private EntryJson $entryJson)
    {
    }

    /**
     * @return array{window: string, builtAt: string|null, stories: list<array<string, mixed>>}
     */
    public function page(TrendingPageModel $page): array
    {
        return [
            'window' => $page->window->value,
            'builtAt' => $page->builtAt?->format(\DateTimeInterface::ATOM),
            'stories' => array_map($this->story(...), $page->stories),
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     title: string,
     *     synopsis: string|null,
     *     outletCount: int,
     *     viewed: bool,
     *     shareCount: int,
     *     followed: bool,
     *     leadEntryId: int,
     *     articles: list<array<string, mixed>>,
     * }
     */
    private function story(TrendingStoryCardModel $card): array
    {
        $articles = $card->articles;

        return [
            'id' => $card->story->storyId,
            'title' => $card->story->title ?? $articles->lead->row->entry->getTitle(),
            'synopsis' => $card->story->synopsis,
            'outletCount' => $card->story->outletCount,
            'viewed' => $card->viewed,
            'shareCount' => $articles->shareCount(),
            'followed' => $card->followed,
            'leadEntryId' => $articles->lead->row->entry->requireId(),
            'articles' => array_map($this->article(...), [$articles->lead, ...$articles->others]),
        ];
    }

    /** @return array<string, mixed> the article's row and the posts sharing it */
    private function article(StoryArticleModel $article): array
    {
        return [
            ...$this->entryJson->listRow($article->row),
            'shares' => array_map($this->entryJson->listRow(...), $article->shares),
        ];
    }
}
