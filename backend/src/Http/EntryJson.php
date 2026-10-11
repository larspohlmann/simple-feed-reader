<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EntryMedia;
use App\Entity\ImageRendition;
use App\Entity\LinkCardContent;
use App\Repository\EntryListRow;
use App\Service\Text\Support\EntryExcerpt;
use App\Service\Url\Support\YouTubeUrl;

/**
 * Three shapes off one row: listRow() drops contentHtml for a plain-text
 * excerpt; detail() adds contentHtml back for the single body-rendering page;
 * completed() holds only what completing a pending entry can change (#1551).
 *
 * @phpstan-type LinkCardRow array{
 *   url: string, title: string, description: string|null, imageUrl: string|null, siteName: string
 * }
 * @phpstan-type EntryRow array{
 *   id: int|null, title: string, url: string|null, author: string|null,
 *   summary: string|null, excerpt: string, contentHtml?: string|null,
 *   imageUrl: string|null, imageWidth: int|null, imageHeight: int|null, imageAspectRatio: float|null,
 *   imageRenditions: list<array{url: string, width: int}>,
 *   media: list<array<string, string|int>>,
 *   attachments: list<array<string, string|int>>,
 *   categories: list<string>,
 *   linkCards: list<LinkCardRow>,
 *   publishedAt: string|null,
 *   createdAt: string, subscriptionId: int, source: string, faviconUrl: string|null,
 *   isHidden: bool, isFavorite: bool, isKept: bool, isViewed: bool, shortPlayerUrl: string|null, titleDerived: bool,
 *   share: 'link'|'media'|null,
 *   savedSearches: list<array{id: int, slug: string, term: string}>, pending: bool,
 *   duplicates: list<array<string, mixed>>,
 *   discussionUrl: string|null, comments: 'auto'|'manual'|null,
 * }
 * @phpstan-type CompletedEntryRow array{
 *   id: int|null, pending: bool, summary: string|null, excerpt: string,
 *   imageUrl: string|null, imageWidth: int|null, imageHeight: int|null,
 *   imageRenditions: list<array{url: string, width: int}>,
 *   media: list<array<string, string|int>>,
 *   linkCards: list<LinkCardRow>,
 *   share: 'link'|'media'|null,
 * }
 */
final class EntryJson
{
    private const float SHORT_COVER_ASPECT_RATIO = 9 / 16;

    /**
     * @return EntryRow
     */
    public static function listRow(EntryListRow $row): array
    {
        $entry = $row->entry;
        $discussion = $entry->getDiscussion();
        $shortPlayerUrl = YouTubeUrl::shortPlayerUrl($entry->getUrl());

        return self::completed($row) + [
            'title' => $entry->getTitle(),
            'url' => $entry->getUrl(),
            'discussionUrl' => $discussion->url,
            'comments' => $discussion->commentsLoad?->value,
            'author' => $entry->getAuthor(),
            'imageAspectRatio' => $shortPlayerUrl === null ? null : self::SHORT_COVER_ASPECT_RATIO,
            'attachments' => EntryMedia::toJsonList($entry->getAttachments()),
            'categories' => $row->enrichment->categories,
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
            'shortPlayerUrl' => $shortPlayerUrl,
            'titleDerived' => $entry->isTitleDerived(),
            'savedSearches' => $row->enrichment->savedSearches,
            'duplicates' => array_map(self::listRow(...), $row->duplicates),
        ];
    }

    /**
     * @return CompletedEntryRow
     */
    public static function completed(EntryListRow $row): array
    {
        $entry = $row->entry;

        return [
            'id' => $entry->getId(),
            'pending' => $row->enrichment->pending,
            'summary' => $entry->getSummary(),
            'excerpt' => EntryExcerpt::of(
                $entry->getSummary(),
                $entry->bodyStandsInForSummary() ? $entry->getContentHtml() : null,
            ),
            'imageUrl' => $entry->getImageUrl(),
            'imageWidth' => $entry->getImageWidth(),
            'imageHeight' => $entry->getImageHeight(),
            'imageRenditions' => ImageRendition::toJsonList($entry->getImage()->servedRenditions()),
            'media' => EntryMedia::toJsonList($entry->getMedia()),
            'linkCards' => array_map(self::linkCard(...), $row->enrichment->linkCards),
            'share' => $entry->getShareKind()?->value,
        ];
    }

    /**
     * @return EntryRow
     */
    public static function detail(EntryListRow $row): array
    {
        return self::listRow($row) + ['contentHtml' => $row->entry->getContentHtml()];
    }

    /**
     * @return LinkCardRow
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
