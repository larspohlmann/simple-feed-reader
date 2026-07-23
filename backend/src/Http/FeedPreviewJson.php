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
     *     publishedAt: string|null,
     *     author: string|null,
     *     hasImage: bool,
     *     textLength: int,
     *     snippet: string,
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
                    'publishedAt' => $i->publishedAt?->format(\DateTimeInterface::ATOM),
                    'author' => $i->author,
                    'hasImage' => $i->hasImage,
                    'textLength' => $i->textLength,
                    'snippet' => $i->snippet,
                ],
                $preview->items,
            ),
        ]];
    }
}
