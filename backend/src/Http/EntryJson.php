<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Entry;
use App\Entity\EntryMedia;
use App\Entity\EntryTranscript;
use App\Entity\ImageRendition;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowDiscussion;
use App\Service\Content\Chapter\Model\ServedChaptersModel;
use App\Service\Foundation\Text\Support\EntryExcerpt;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Foundation\Url\Support\FeedWebsite;
use App\Service\Foundation\Url\Support\YouTubeUrl;

/**
 * Three shapes off one row: listRow() drops contentHtml for a plain-text
 * excerpt; detail() adds contentHtml, chapters and transcript back for the single body-rendering page;
 * completed() holds only what completing a pending entry can change (#1551).
 *
 * @phpstan-import-type QuoteRow from QuotedPostJson
 * @phpstan-import-type LinkCardRow from LinkCardJson
 * @phpstan-import-type ThreadPostRow from ThreadPostJson
 * @phpstan-type EntryRow array{
 *   id: int|null, canonicalItemId: int|null, title: string, url: string|null, author: string|null,
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
 *   thread: list<ThreadPostRow>, threadLength: int,
 *   publishedAt: string|null,
 *   createdAt: string, subscriptionId: int, source: string, faviconUrl: string|null,
 *   poster: array{name: string, url: string|null, imageUrl: string|null}|null,
 *   isHidden: bool, isFavorite: bool, isKept: bool, isShared: bool, isViewed: bool,
 *   shortPlayerUrl: string|null, titleDerived: bool,
 *   share: 'link'|'media'|'quote'|null,
 *   quote: QuoteRow|null,
 *   savedSearches: list<array{id: int, slug: string, term: string}>, pending: bool,
 *   social: array{network: 'bluesky'|'mastodon', isLiked: bool, canLike: bool, isOwn: bool}|null,
 *   myVote: int|null, community: array{name: string, url: string}|null,
 *   duplicates: list<array<string, mixed>>,
 *   discussionUrl: string|null, comments: 'auto'|'manual'|null,
 *   score: int|null, commentCount: int|null, likeCount: int|null,
 * }
 * @phpstan-type CompletedEntryRow array{
 *   id: int|null, pending: bool, summary: string|null, excerpt: string,
 *   imageUrl: string|null, imageWidth: int|null, imageHeight: int|null,
 *   imageRenditions: list<array{url: string, width: int}>,
 *   media: list<array<string, string|int|true>>,
 *   linkCards: list<LinkCardRow>,
 *   thread: list<ThreadPostRow>, threadLength: int,
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
            'canonicalItemId' => $entry->getCanonicalItemId(),
            'title' => $entry->getTitle(),
            'url' => $entry->getUrl(),
        ] + self::buildDiscussionRow($row) + [
            'author' => $entry->getAuthor(),
            'imageAspectRatio' => $shortPlayerUrl === null ? null : self::SHORT_COVER_ASPECT_RATIO,
            'attachments' => EntryMedia::toJsonList($entry->getAttachments()),
            'categories' => $row->enrichment->categories,
            'publishedAt' => $entry->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $entry->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'subscriptionId' => $row->subscription->id,
            'source' => $row->subscription->title,
            'poster' => self::buildPosterRow($row),
            // The feed is fetch-joined in the row query, so this adds no N+1.
            'faviconUrl' => $row->enrichment->origin->faviconUrl ?? $entry->getFeed()->getFaviconUrl(),
            'isHidden' => $row->marks->isHidden,
            'isFavorite' => $row->marks->isFavorite,
            'isKept' => $row->marks->isKept,
            'isShared' => $row->marks->isShared(),
            'isViewed' => $row->marks->isViewed(),
            'shortPlayerUrl' => $shortPlayerUrl,
            'titleDerived' => $entry->isTitleDerived(),
            'savedSearches' => $row->enrichment->savedSearches,
            'social' => self::buildSocialRow($row),
            'myVote' => $row->enrichment->lemmy?->myVote?->value,
            'community' => self::buildCommunityRow($row),
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
            'summary' => $entry->getText()->getSummary(),
            'excerpt' => EntryExcerpt::fromSummaryOrContent(
                $entry->getText()->getSummary(),
                $entry->bodyStandsInForSummary() ? $entry->getText()->getContentHtml() : null,
            ),
            'imageUrl' => $entry->getImageUrl(),
            'imageWidth' => $entry->getImageWidth(),
            'imageHeight' => $entry->getImageHeight(),
            'imageRenditions' => ImageRendition::toJsonList($entry->getImage()->servedRenditions()),
            'media' => EntryMedia::toJsonList($entry->getMedia()),
            'linkCards' => array_map($this->linkCardJson->from(...), $row->enrichment->linkCards),
            'thread' => array_map($this->threadPostJson->from(...), $row->enrichment->threadPosts),
            'threadLength' => 1 + $entry->selectDiscussionCounts()->getSelfReplyCount(),
            'share' => $entry->getShareKind()?->value,
            'quote' => $this->quotedPostJson->fromOptional($entry->getQuotedPost()),
        ];
    }

    /**
     * @return EntryRow
     */
    public function detail(EntryListRow $row, ServedChaptersModel $served, ?EntryTranscript $transcript): array
    {
        return $this->listRow($row) + [
            'contentHtml' => $served->contentHtml,
            'chapters' => ChaptersJson::from($served->chapters),
            'transcript' => EntryTranscriptJson::from($transcript),
        ];
    }

    /** @return array{discussionUrl: string|null, comments: 'auto'|'manual'|null, score: int|null, commentCount: int|null, likeCount: int|null} */
    private static function buildDiscussionRow(EntryListRow $row): array
    {
        $discussion = $row->enrichment->discussion
            ?? EntryListRowDiscussion::fromDiscussion($row->entry->getDiscussion());

        return [
            'discussionUrl' => $discussion->url,
            'comments' => $discussion->commentsLoad?->value,
            'score' => $row->enrichment->lemmy?->score,
            'commentCount' => $row->entry->selectDiscussionCounts()->getCommentCount(),
            'likeCount' => $row->entry->getCanonicalItem()?->getLikeCount(),
        ];
    }

    /** @return array{name: string, url: string}|null */
    private static function buildCommunityRow(EntryListRow $row): ?array
    {
        $community = $row->enrichment->lemmy?->community;

        return null === $community ? null : ['name' => $community->shortName(), 'url' => $community->url];
    }

    /** @return array{network: 'bluesky'|'mastodon', isLiked: bool, canLike: bool, isOwn: bool}|null */
    private static function buildSocialRow(EntryListRow $row): ?array
    {
        $social = $row->enrichment->social;

        if (null === $social || SocialNetwork::Lemmy === $social->network) {
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
    private static function buildPosterRow(EntryListRow $row): ?array
    {
        $entry = $row->entry;
        $feed = $entry->getFeed();
        $sourceFormat = $feed->getSourceFormat();
        if (SourceFormat::isSaved($sourceFormat)) {
            return null === $feed->getNetwork() ? self::buildOriginPosterRow($row) : self::buildAuthorPosterRow($entry);
        }
        if (SourceFormat::isSocial($sourceFormat)) {
            return self::buildAuthorPosterRow($entry);
        }
        if (SocialNetwork::Lemmy === $feed->getNetwork()) {
            return self::buildAuthorPosterRow($entry);
        }
        if (null !== $feed->getNetwork()) {
            return [
                'name' => $row->subscription->title,
                'url' => AbsoluteHttpUrl::keepIfAbsolute(
                    FeedWebsite::resolveWebsite($feed->getUrl(), $feed->getSiteUrl()),
                ),
                'imageUrl' => $entry->getAuthorAvatarUrl() ?? $feed->getImageUrl(),
            ];
        }

        return null;
    }

    /** @return array{name: string, url: string|null, imageUrl: string|null}|null */
    private static function buildAuthorPosterRow(Entry $entry): ?array
    {
        $author = $entry->getAuthor();
        if (null === $author || '' === $author) {
            return null;
        }

        return ['name' => $author, 'url' => $entry->getAuthorUrl(), 'imageUrl' => $entry->getAuthorAvatarUrl()];
    }

    /** @return array{name: string, url: null, imageUrl: null}|null */
    private static function buildOriginPosterRow(EntryListRow $row): ?array
    {
        $title = $row->enrichment->origin?->title;

        return null === $title ? null : ['name' => $title, 'url' => null, 'imageUrl' => null];
    }
}
