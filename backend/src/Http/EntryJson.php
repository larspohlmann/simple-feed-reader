<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Entry;
use App\Entity\EntryMedia;
use App\Entity\EntryTranscript;
use App\Entity\ImageRendition;
use App\Enum\SourceFormat;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowDiscussion;
use App\Service\Chapter\Model\ServedChaptersModel;
use App\Service\Text\Support\EntryExcerpt;
use App\Service\Url\Support\AbsoluteHttpUrl;
use App\Service\Url\Support\FeedWebsite;
use App\Service\Url\Support\YouTubeUrl;

/**
 * Three shapes off one row: listRow() drops contentHtml for a plain-text
 * excerpt; detail() adds contentHtml, chapters and transcript back for the single body-rendering page;
 * completed() holds only what completing a pending entry can change (#1551).
 *
 * @phpstan-import-type QuoteRow from QuotedPostJson
 * @phpstan-import-type LinkCardRow from LinkCardJson
 * @phpstan-import-type ThreadPostRow from ThreadPostJson
 * @phpstan-type EntryRow array{
 *   id: int|null, title: string, url: string|null, author: string|null,
 *   summary: string|null, excerpt: string, contentHtml?: string|null,
 *   chapters?: list<array{startSeconds: int, title: string}>|null,
 *   transcript?: array{
 *     language: string|null,
 *     paragraphs: list<array{startSeconds: int|null, speaker: string|null, text: string}>,
 *   }|null,
 *   imageUrl: string|null, imageWidth: int|null, imageHeight: int|null, imageAspectRatio: float|null,
 *   imageRenditions: list<array{url: string, width: int}>,
 *   media: list<array<string, string|int|true>>,
 *   attachments: list<array<string, string|int|true>>,
 *   categories: list<string>,
 *   linkCards: list<LinkCardRow>,
 *   thread: list<ThreadPostRow>,
 *   publishedAt: string|null,
 *   createdAt: string, subscriptionId: int, source: string, faviconUrl: string|null,
 *   poster: array{name: string, url: string|null, imageUrl: string|null}|null,
 *   isHidden: bool, isFavorite: bool, isKept: bool, isViewed: bool, shortPlayerUrl: string|null, titleDerived: bool,
 *   share: 'link'|'media'|'quote'|null,
 *   quote: QuoteRow|null,
 *   savedSearches: list<array{id: int, slug: string, term: string}>, pending: bool,
 *   social: array{network: 'bluesky'|'mastodon', isLiked: bool, canLike: bool, isOwn: bool}|null,
 *   duplicates: list<array<string, mixed>>,
 *   discussionUrl: string|null, comments: 'auto'|'manual'|null,
 * }
 * @phpstan-type CompletedEntryRow array{
 *   id: int|null, pending: bool, summary: string|null, excerpt: string,
 *   imageUrl: string|null, imageWidth: int|null, imageHeight: int|null,
 *   imageRenditions: list<array{url: string, width: int}>,
 *   media: list<array<string, string|int|true>>,
 *   linkCards: list<LinkCardRow>,
 *   thread: list<ThreadPostRow>,
 *   share: 'link'|'media'|'quote'|null,
 *   quote: QuoteRow|null,
 * }
 */
final readonly class EntryJson
{
    private const float SHORT_COVER_ASPECT_RATIO = 9 / 16;

    public function __construct(
        private LinkCardJson $linkCardJson,
        private ThreadPostJson $threadPostJson,
        private QuotedPostJson $quotedPostJson,
    ) {
    }

    /**
     * @return EntryRow
     */
    public function listRow(EntryListRow $row): array
    {
        $entry = $row->entry;
        $shortPlayerUrl = YouTubeUrl::shortPlayerUrl($entry->getUrl());

        return $this->completed($row) + [
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
            'faviconUrl' => $row->enrichment->origin->faviconUrl ?? $entry->getFeed()->getFaviconUrl(),
            'isHidden' => $row->isHidden,
            'isFavorite' => $row->isFavorite,
            'isKept' => $row->isKept,
            'isViewed' => $row->isViewed,
            'shortPlayerUrl' => $shortPlayerUrl,
            'titleDerived' => $entry->isTitleDerived(),
            'savedSearches' => $row->enrichment->savedSearches,
            'social' => self::socialOf($row),
            'duplicates' => array_map($this->listRow(...), $row->duplicates),
        ];
    }

    /**
     * @return CompletedEntryRow
     */
    public function completed(EntryListRow $row): array
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
            'linkCards' => array_map($this->linkCardJson->of(...), $row->enrichment->linkCards),
            'thread' => array_map($this->threadPostJson->of(...), $row->enrichment->threadPosts),
            'share' => $entry->getShareKind()?->value,
            'quote' => $this->quotedPostJson->ofOrNull($entry->getQuotedPost()),
        ];
    }

    /**
     * @return EntryRow
     */
    public function detail(EntryListRow $row, ServedChaptersModel $served, ?EntryTranscript $transcript): array
    {
        return $this->listRow($row) + [
            'contentHtml' => $served->contentHtml,
            'chapters' => ChaptersJson::of($served->chapters),
            'transcript' => EntryTranscriptJson::of($transcript),
        ];
    }

    /** @return array{discussionUrl: string|null, comments: 'auto'|'manual'|null} */
    private static function discussionOf(EntryListRow $row): array
    {
        $discussion = $row->enrichment->discussion ?? EntryListRowDiscussion::storedOf($row->entry->getDiscussion());

        return ['discussionUrl' => $discussion->url, 'comments' => $discussion->commentsLoad?->value];
    }

    /** @return array{network: 'bluesky'|'mastodon', isLiked: bool, canLike: bool, isOwn: bool}|null */
    private static function socialOf(EntryListRow $row): ?array
    {
        $social = $row->enrichment->social;

        if (null === $social) {
            return null;
        }

        return [
            'network' => $social->network->value,
            'isLiked' => $social->isLiked,
            'canLike' => $social->canLike,
            'isOwn' => $social->isOwn,
        ];
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
        $sourceFormat = $feed->getSourceFormat();
        if (SourceFormat::isSaved($sourceFormat)) {
            return null === $feed->getNetwork() ? self::originPosterOf($row) : self::authorPosterOf($entry);
        }
        if (SourceFormat::isSocial($sourceFormat)) {
            return self::authorPosterOf($entry);
        }
        if (null !== $feed->getNetwork()) {
            return [
                'name' => $row->subscriptionTitle,
                'url' => AbsoluteHttpUrl::orNull(FeedWebsite::of($feed->getUrl(), $feed->getSiteUrl())),
                'imageUrl' => $entry->getAuthorAvatarUrl() ?? $feed->getImageUrl(),
            ];
        }

        return null;
    }

    /** @return array{name: string, url: string|null, imageUrl: string|null}|null */
    private static function authorPosterOf(Entry $entry): ?array
    {
        $author = $entry->getAuthor();
        if (null === $author || '' === $author) {
            return null;
        }

        return ['name' => $author, 'url' => $entry->getAuthorUrl(), 'imageUrl' => $entry->getAuthorAvatarUrl()];
    }

    /** @return array{name: string, url: null, imageUrl: null}|null */
    private static function originPosterOf(EntryListRow $row): ?array
    {
        $title = $row->enrichment->origin?->title;

        return null === $title ? null : ['name' => $title, 'url' => null, 'imageUrl' => null];
    }
}
