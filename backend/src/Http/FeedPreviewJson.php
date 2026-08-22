<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Preview\FeedPreview;
use App\Service\Preview\FeedPreviewItem;

final class FeedPreviewJson
{
    /**
     * @return array{feed: array{
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
     * }}
     */
    public static function one(FeedPreview $preview): array
    {
        return ['feed' => [
            'title' => $preview->title,
            'itemCount' => $preview->itemCount,
            'content' => $preview->content,
            'hasImages' => $preview->hasImages,
            'items' => array_map(
                static fn (FeedPreviewItem $i) => [
                    'title' => $i->title,
                    'url' => $i->url,
                    'author' => $i->author,
                    'summary' => $i->summary,
                    'imageUrl' => $i->imageUrl,
                    'imageWidth' => $i->imageWidth,
                    'imageHeight' => $i->imageHeight,
                    'publishedAt' => $i->publishedAt?->format(\DateTimeInterface::ATOM),
                ],
                $preview->items,
            ),
        ]];
    }
}
