<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EntryMedia;
use App\Repository\EntryListRow;

final class EntryJson
{
    /**
     * @return array{
     *   id: int|null, title: string, url: string|null, author: string|null,
     *   summary: string|null, contentHtml: string|null,
     *   imageUrl: string|null, imageWidth: int|null, imageHeight: int|null,
     *   media: list<array<string, string|int>>,
     *   attachments: list<array<string, string|int>>,
     *   publishedAt: string|null,
     *   createdAt: string, subscriptionId: int, source: string, faviconUrl: string|null,
     *   isHidden: bool, isFavorite: bool, isKept: bool, isViewed: bool
     * }
     */
    public static function one(EntryListRow $row): array
    {
        $e = $row->entry;

        return [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'url' => $e->getUrl(),
            'author' => $e->getAuthor(),
            'summary' => $e->getSummary(),
            'contentHtml' => $e->getContentHtml(),
            'imageUrl' => $e->getImageUrl(),
            'imageWidth' => $e->getImageWidth(),
            'imageHeight' => $e->getImageHeight(),
            'media' => EntryMedia::toJsonList($e->getMedia()),
            'attachments' => EntryMedia::toJsonList($e->getAttachments()),
            'publishedAt' => $e->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $e->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'subscriptionId' => $row->subscriptionId,
            'source' => $row->subscriptionTitle,
            // The feed is fetch-joined in the row query, so this adds no N+1.
            'faviconUrl' => $e->getFeed()->getFaviconUrl(),
            'isHidden' => $row->isHidden,
            'isFavorite' => $row->isFavorite,
            'isKept' => $row->isKept,
            'isViewed' => $row->isViewed,
        ];
    }
}
