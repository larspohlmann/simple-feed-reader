<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Trending\Model\TrendingPageModel;
use App\Service\Trending\Model\TrendingStoryCardModel;

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

    /** @return array<string, mixed> the lead's list row; its duplicates are the story's other outlets */
    private function story(TrendingStoryCardModel $story): array
    {
        return [
            ...$this->entryJson->listRow($story->lead),
            'story' => ['outletCount' => $story->outletCount, 'viewed' => $story->viewed],
        ];
    }
}
