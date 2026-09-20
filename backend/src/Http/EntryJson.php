<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EntryMedia;
use App\Repository\EntryListRow;
use App\Service\Text\EntryExcerpt;

/**
 * Two shapes off one row: listRow() drops contentHtml for a plain-text
 * excerpt; detail() adds contentHtml back for the single body-rendering page.
 */
final class EntryJson
{
    /**
     * @return array{
     *   id: int|null, title: string, url: string|null, author: string|null,
     *   summary: string|null, excerpt: string,
     *   imageUrl: string|null, imageWidth: int|null, imageHeight: int|null,
     *   media: list<array<string, string|int>>,
     *   attachments: list<array<string, string|int>>,
     *   categories: list<string>,
     *   publishedAt: string|null,
     *   createdAt: string, subscriptionId: int, source: string, faviconUrl: string|null,
     *   isHidden: bool, isFavorite: bool, isKept: bool, isViewed: bool,
     *   duplicates: list<array<string, mixed>>
     * }
     */
    public static function listRow(EntryListRow $row): array
    {
        return self::commonFields($row) + [
            'excerpt' => EntryExcerpt::of($row->entry->getSummary(), $row->entry->getContentHtml()),
            'duplicates' => array_map(self::listRow(...), $row->duplicates),
        ];
    }

    /**
     * @return array{
     *   id: int|null, title: string, url: string|null, author: string|null,
     *   summary: string|null, excerpt: string, contentHtml: string|null,
     *   imageUrl: string|null, imageWidth: int|null, imageHeight: int|null,
     *   media: list<array<string, string|int>>,
     *   attachments: list<array<string, string|int>>,
     *   categories: list<string>,
     *   publishedAt: string|null,
     *   createdAt: string, subscriptionId: int, source: string, faviconUrl: string|null,
     *   isHidden: bool, isFavorite: bool, isKept: bool, isViewed: bool,
     *   duplicates: list<array<string, mixed>>
     * }
     */
    public static function detail(EntryListRow $row): array
    {
        return self::listRow($row) + ['contentHtml' => $row->entry->getContentHtml()];
    }

    /**
     * @return array{
     *   id: int|null, title: string, url: string|null, author: string|null,
     *   summary: string|null,
     *   imageUrl: string|null, imageWidth: int|null, imageHeight: int|null,
     *   media: list<array<string, string|int>>,
     *   attachments: list<array<string, string|int>>,
     *   categories: list<string>,
     *   publishedAt: string|null,
     *   createdAt: string, subscriptionId: int, source: string, faviconUrl: string|null,
     *   isHidden: bool, isFavorite: bool, isKept: bool, isViewed: bool,
     * }
     */
    private static function commonFields(EntryListRow $row): array
    {
        $e = $row->entry;

        return [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'url' => $e->getUrl(),
            'author' => $e->getAuthor(),
            'summary' => $e->getSummary(),
            'imageUrl' => $e->getImageUrl(),
            'imageWidth' => $e->getImageWidth(),
            'imageHeight' => $e->getImageHeight(),
            'media' => EntryMedia::toJsonList($e->getMedia()),
            'attachments' => EntryMedia::toJsonList($e->getAttachments()),
            'categories' => $row->categories,
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
