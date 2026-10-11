<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EntryMedia;
use App\Entity\ImageRendition;
use App\Entity\LinkCardContent;
use App\Repository\EntryListRow;
use App\Service\Reader\Media\Support\CoverAspectRatio;
use App\Service\Reader\Media\Support\YouTubeShortUrl;
use App\Service\Text\Support\EntryExcerpt;

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
     *   imageUrl: string|null, imageWidth: int|null, imageHeight: int|null, imageAspectRatio: float|null,
     *   imageRenditions: list<array{url: string, width: int}>,
     *   media: list<array<string, string|int>>,
     *   attachments: list<array<string, string|int>>,
     *   categories: list<string>,
     *   linkCards: list<array{
     *     url: string, title: string, description: string|null, imageUrl: string|null, siteName: string
     *   }>,
     *   publishedAt: string|null,
     *   createdAt: string, subscriptionId: int, source: string, faviconUrl: string|null,
     *   isHidden: bool, isFavorite: bool, isKept: bool, isViewed: bool, isShort: bool, titleDerived: bool,
     *   savedSearches: list<array{id: int, slug: string, term: string}>, pending: bool,
     *   duplicates: list<array<string, mixed>>,
     *   discussionUrl: string|null, comments: 'auto'|'manual'|null,
     * }
     */
    public static function listRow(EntryListRow $row): array
    {
        return self::commonFields($row) + [
            'excerpt' => EntryExcerpt::of(
                $row->entry->getSummary(),
                $row->entry->isUntitledPost() ? null : $row->entry->getContentHtml(),
            ),
            'duplicates' => array_map(self::listRow(...), $row->duplicates),
        ];
    }

    /**
     * @return array{
     *   id: int|null, title: string, url: string|null, author: string|null,
     *   summary: string|null, excerpt: string, contentHtml: string|null,
     *   imageUrl: string|null, imageWidth: int|null, imageHeight: int|null, imageAspectRatio: float|null,
     *   imageRenditions: list<array{url: string, width: int}>,
     *   media: list<array<string, string|int>>,
     *   attachments: list<array<string, string|int>>,
     *   categories: list<string>,
     *   linkCards: list<array{
     *     url: string, title: string, description: string|null, imageUrl: string|null, siteName: string
     *   }>,
     *   publishedAt: string|null,
     *   createdAt: string, subscriptionId: int, source: string, faviconUrl: string|null,
     *   isHidden: bool, isFavorite: bool, isKept: bool, isViewed: bool, isShort: bool, titleDerived: bool,
     *   savedSearches: list<array{id: int, slug: string, term: string}>, pending: bool,
     *   duplicates: list<array<string, mixed>>,
     *   discussionUrl: string|null, comments: 'auto'|'manual'|null,
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
     *   imageUrl: string|null, imageWidth: int|null, imageHeight: int|null, imageAspectRatio: float|null,
     *   imageRenditions: list<array{url: string, width: int}>,
     *   media: list<array<string, string|int>>,
     *   attachments: list<array<string, string|int>>,
     *   categories: list<string>,
     *   linkCards: list<array{
     *     url: string, title: string, description: string|null, imageUrl: string|null, siteName: string
     *   }>,
     *   publishedAt: string|null,
     *   createdAt: string, subscriptionId: int, source: string, faviconUrl: string|null,
     *   isHidden: bool, isFavorite: bool, isKept: bool, isViewed: bool, isShort: bool, titleDerived: bool,
     *   savedSearches: list<array{id: int, slug: string, term: string}>, pending: bool,
     *   discussionUrl: string|null, comments: 'auto'|'manual'|null,
     * }
     */
    private static function commonFields(EntryListRow $row): array
    {
        $entry = $row->entry;
        $discussion = $entry->getDiscussion();

        return [
            'id' => $entry->getId(),
            'title' => $entry->getTitle(),
            'url' => $entry->getUrl(),
            'discussionUrl' => $discussion->url,
            'comments' => $discussion->commentsLoad?->value,
            'author' => $entry->getAuthor(),
            'summary' => $entry->getSummary(),
            'imageUrl' => $entry->getImageUrl(),
            'imageWidth' => $entry->getImageWidth(),
            'imageHeight' => $entry->getImageHeight(),
            'imageAspectRatio' => CoverAspectRatio::of($entry->getUrl()),
            'imageRenditions' => ImageRendition::toJsonList($entry->getImage()->servedRenditions()),
            'media' => EntryMedia::toJsonList($entry->getMedia()),
            'attachments' => EntryMedia::toJsonList($entry->getAttachments()),
            'categories' => $row->categories,
            'linkCards' => array_map(self::linkCard(...), $row->linkCards),
            'publishedAt' => $entry->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $entry->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'subscriptionId' => $row->subscriptionId,
            'source' => $row->subscriptionTitle,
            // The feed is fetch-joined in the row query, so this adds no N+1.
            'faviconUrl' => $entry->getFeed()->getFaviconUrl(),
            'isHidden' => $row->isHidden,
            'isFavorite' => $row->isFavorite,
            'isKept' => $row->isKept,
            'isViewed' => $row->isViewed,
            'isShort' => YouTubeShortUrl::is($entry->getUrl()),
            'titleDerived' => $entry->isTitleDerived(),
            'savedSearches' => $row->savedSearches,
            'pending' => $row->pending,
        ];
    }

    /**
     * @return array{url: string, title: string, description: string|null, imageUrl: string|null, siteName: string}
     */
    private static function linkCard(LinkCardContent $card): array
    {
        return [
            'url' => $card->url,
            'title' => $card->title,
            'description' => $card->description,
            'imageUrl' => $card->imageUrl,
            'siteName' => $card->siteName,
        ];
    }
}
