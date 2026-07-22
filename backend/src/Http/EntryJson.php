<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\EntryListRow;

final class EntryJson
{
    /**
     * @return array{
     *   id: int|null, title: string, url: string|null, author: string|null,
     *   summary: string|null, contentHtml: string|null, publishedAt: string|null,
     *   createdAt: string, subscriptionId: int, source: string,
     *   isRead: bool, isFavorite: bool, isKept: bool
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
            'publishedAt' => $e->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $e->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'subscriptionId' => $row->subscriptionId,
            'source' => $row->subscriptionTitle,
            'isRead' => $row->isRead,
            'isFavorite' => $row->isFavorite,
            'isKept' => $row->isKept,
        ];
    }
}
