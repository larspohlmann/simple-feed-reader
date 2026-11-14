<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Sourcing\Preview\Model\FeedPreviewItemModel;
use App\Service\Sourcing\Preview\Model\FeedPreviewModel;

final class FeedPreviewJson
{
    /** @return array{feed: array<string, mixed>} */
    public static function one(FeedPreviewModel $preview): array
    {
        return ['feed' => self::preview($preview)];
    }

    /**
     * @return array{
     *   title: string|null,
     *   itemCount: int,
     *   content: string,
     *   hasImages: bool,
     *   items: list<array{
     *     title: string,
     *     url: string|null,
     *     author: string|null,
     *     summary: string|null,
     *     imageUrl: string|null,
     *     imageWidth: int|null,
     *     imageHeight: int|null,
     *     publishedAt: string|null,
     *   }>,
     * }
     */
    public static function preview(FeedPreviewModel $preview): array
    {
        return [
            'title' => $preview->title,
            'itemCount' => $preview->itemCount,
            'content' => $preview->content,
            'hasImages' => $preview->hasImages,
            'items' => array_map(
                static fn (FeedPreviewItemModel $item) => [
                    'title' => $item->title,
                    'url' => $item->url,
                    'author' => $item->author,
                    'summary' => $item->summary,
                    'imageUrl' => $item->imageUrl,
                    'imageWidth' => $item->imageWidth,
                    'imageHeight' => $item->imageHeight,
                    'publishedAt' => $item->publishedAt?->format(\DateTimeInterface::ATOM),
                ],
                $preview->items,
            ),
        ];
    }
}
