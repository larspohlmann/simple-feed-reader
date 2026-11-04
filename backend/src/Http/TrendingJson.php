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
     * @return array{
     *   window: string,
     *   builtAt: string|null,
     *   stories: list<array{
     *     lead: array<string, mixed>, others: list<array<string, mixed>>, outletCount: int, viewed: bool
     *   }>
     * }
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
     * @return array{lead: array<string, mixed>, others: list<array<string, mixed>>, outletCount: int, viewed: bool}
     */
    private function story(TrendingStoryCardModel $story): array
    {
        return [
            'lead' => $this->entryJson->listRow($story->lead),
            'others' => array_map($this->entryJson->listRow(...), $story->others),
            'outletCount' => $story->outletCount,
            'viewed' => $story->viewed,
        ];
    }
}
