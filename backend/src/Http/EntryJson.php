<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EntryMedia;
use App\Entity\ImageRendition;
use App\Enum\CommentsLoad;
use App\Enum\SourceFormat;
use App\Repository\EntryListRow;
use App\Service\Text\Support\EntryExcerpt;
use App\Service\Url\Support\AbsoluteHttpUrl;
use App\Service\Url\Support\FeedWebsite;
use App\Service\Url\Support\YouTubeUrl;

/**
 * Three shapes off one row: listRow() drops contentHtml for a plain-text
 * excerpt; detail() adds contentHtml back for the single body-rendering page;
 * completed() holds only what completing a pending entry can change (#1551).
 *
 * @phpstan-import-type QuoteRow from QuotedPostJson
 * @phpstan-import-type LinkCardRow from LinkCardJson
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
 *   poster: array{name: string, url: string|null, imageUrl: string|null}|null,
 *   isHidden: bool, isFavorite: bool, isKept: bool, isViewed: bool, shortPlayerUrl: string|null, titleDerived: bool,
 *   share: 'link'|'media'|'quote'|null,
 *   quote: QuoteRow|null,
 *   savedSearches: list<array{id: int, slug: string, term: string}>, pending: bool,
 *   social: array{network: 'bluesky'|'mastodon', isLiked: bool, canLike: bool}|null,
 *   duplicates: list<array<string, mixed>>,
 *   discussionUrl: string|null, comments: 'auto'|'manual'|null,
 * }
 * @phpstan-type CompletedEntryRow array{
 *   id: int|null, pending: bool, summary: string|null, excerpt: string,
 *   imageUrl: string|null, imageWidth: int|null, imageHeight: int|null,
 *   imageRenditions: list<array{url: string, width: int}>,
 *   media: list<array<string, string|int>>,
 *   linkCards: list<LinkCardRow>,
 *   share: 'link'|'media'|'quote'|null,
 *   quote: QuoteRow|null,
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
        $shortPlayerUrl = YouTubeUrl::shortPlayerUrl($entry->getUrl());

        return self::completed($row) + [
            'title' => $entry->getTitle(),
            'url' => $entry->getUrl(),
        ] + self::discussionOf($row) + [
            'author' => $entry->getAuthor(),
            'imageAspectRatio' => $shortPlayerUrl === null ? null : self::SHORT_COVER_ASPECT_RATIO,
            'attachments' => EntryMedia::toJsonList($entry->getAttachments()),
            'categories' => $row->enrichment->categories,
            'publishedAt' => $entry->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $entry->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'subscriptionId' => $row->subscriptionId,
            'source' => $row->subscriptionTitle,
            'poster' => self::posterOf($row),
            // The feed is fetch-joined in the row query, so this adds no N+1.
            'faviconUrl' => $entry->getFeed()->getFaviconUrl(),
            'isHidden' => $row->isHidden,
            'isFavorite' => $row->isFavorite,
            'isKept' => $row->isKept,
            'isViewed' => $row->isViewed,
            'shortPlayerUrl' => $shortPlayerUrl,
            'titleDerived' => $entry->isTitleDerived(),
            'savedSearches' => $row->enrichment->savedSearches,
            'social' => self::socialOf($row),
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
            'linkCards' => array_map(LinkCardJson::of(...), $row->enrichment->linkCards),
            'share' => $entry->getShareKind()?->value,
            'quote' => QuotedPostJson::of($entry->getQuotedPost()),
        ];
    }

    /**
     * @return EntryRow
     */
    public static function detail(EntryListRow $row): array
    {
        return self::listRow($row) + ['contentHtml' => $row->entry->getContentHtml()];
    }

    /** @return array{discussionUrl: string|null, comments: 'auto'|'manual'|null} */
    private static function discussionOf(EntryListRow $row): array
    {
        $entry = $row->entry;
        if (null !== $row->enrichment->social) {
            return ['discussionUrl' => $entry->getUrl(), 'comments' => CommentsLoad::Auto->value];
        }
        $discussion = $entry->getDiscussion();

        return ['discussionUrl' => $discussion->url, 'comments' => $discussion->commentsLoad?->value];
    }

    /** @return array{network: 'bluesky'|'mastodon', isLiked: bool, canLike: bool}|null */
    private static function socialOf(EntryListRow $row): ?array
    {
        $social = $row->enrichment->social;

        if (null === $social) {
            return null;
        }

        return ['network' => $social->network->value, 'isLiked' => $social->isLiked, 'canLike' => $social->canLike];
    }

    /**
     * Who posted the entry when that is someone other than the feed. A private feed's post names its own author; every
     * post of a profile feed is that person's, so it shows their picture and profile unless the post brings an avatar
     * of its own (a repost).
     *
     * @return array{name: string, url: string|null, imageUrl: string|null}|null
     */
    private static function posterOf(EntryListRow $row): ?array
    {
        $entry = $row->entry;
        $feed = $entry->getFeed();
        $author = $entry->getAuthor();
        $isPrivateFeed = SourceFormat::isSocial($feed->getSourceFormat());
        if ($isPrivateFeed && $author !== null && $author !== '') {
            return ['name' => $author, 'url' => $entry->getAuthorUrl(), 'imageUrl' => $entry->getAuthorAvatarUrl()];
        }
        if (!$isPrivateFeed && null !== $feed->getNetwork()) {
            return [
                'name' => $row->subscriptionTitle,
                'url' => AbsoluteHttpUrl::orNull(FeedWebsite::of($feed->getUrl(), $feed->getSiteUrl())),
                'imageUrl' => $entry->getAuthorAvatarUrl() ?? $feed->getImageUrl(),
            ];
        }

        return null;
    }
}
