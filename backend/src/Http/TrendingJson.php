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

    /** @return array<string, mixed> the lead article's list row; its duplicates are the story's other articles */
    private function story(TrendingStoryCardModel $card): array
    {
        $articles = $card->articles;

        return [
            ...$this->article($articles->lead),
            'duplicates' => array_map($this->article(...), $articles->others),
            'story' => [
                'outletCount' => $card->story->outletCount,
                'viewed' => $card->viewed,
                'shareCount' => $articles->shareCount(),
            ],
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
