<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Bluesky\Support\BlueskyRichText;
use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Support\DateParser;
use App\Service\Parser\Support\EntryTitle;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\Model\TimelineSelfReplyModel;

/** A timeline, list, feed or author-feed answer as entries and self-replies, post views kept for embeds. */
final class BlueskyTimelinePage
{
    private const string REPOST = 'app.bsky.feed.defs#reasonRepost';

    /** @param list<JsonNodeModel> $items the answer's `feed` */
    public static function of(array $items, string $accountDid): TimelinePageModel
    {
        return self::pageOf($items, static fn (JsonNodeModel $item): bool => self::isShown($item, $accountDid));
    }

    /**
     * What the profile's public RSS lists: its own posts and its replies to itself, never a repost.
     *
     * @param list<JsonNodeModel> $items the answer's `feed`
     */
    public static function ofAuthor(array $items, string $authorDid): TimelinePageModel
    {
        return self::pageOf($items, static fn (JsonNodeModel $item): bool => self::isAuthorsOwn($item, $authorDid));
    }

    /**
     * @param list<JsonNodeModel>          $items
     * @param \Closure(JsonNodeModel): bool $isShown
     */
    private static function pageOf(array $items, \Closure $isShown): TimelinePageModel
    {
        $entries = [];
        $selfReplies = [];
        $posts = [];
        foreach ($items as $item) {
            $entry = $isShown($item) ? self::entryOf($item) : null;
            if (null === $entry) {
                continue;
            }
            $posts[$entry->guid] = $item->node('post');
            $selfReply = self::selfReplyOf($item, $entry);
            if (null === $selfReply) {
                $entries[] = $entry;
                continue;
            }
            $selfReplies[] = $selfReply;
        }

        return TimelinePageModel::of($entries, KnownSharesModel::ofBlueskyPosts($posts), null)
            ->withSelfReplies($selfReplies);
    }

    private static function selfReplyOf(JsonNodeModel $item, ParsedEntryModel $entry): ?TimelineSelfReplyModel
    {
        $reply = $item->node('reply');
        $rootUri = $reply->node('root')->string('uri');
        $parentUrl = AtPostUri::webUrl($reply->node('parent')->string('uri') ?? '');
        if (null === $rootUri || null === $parentUrl || !self::continuesOwnThread($item)) {
            return null;
        }

        return new TimelineSelfReplyModel($rootUri, $parentUrl, $entry, $item->node('post'));
    }

    /** A reply to its author's own post, under a root they wrote, and not a repost of one. */
    private static function continuesOwnThread(JsonNodeModel $item): bool
    {
        $author = $item->node('post')->node('author')->string('did');
        $reply = $item->node('reply');

        return null !== $author
            && self::REPOST !== $item->node('reason')->type()
            && $author === $reply->node('parent')->node('author')->string('did')
            && $author === $reply->node('root')->node('author')->string('did');
    }

    private static function isAuthorsOwn(JsonNodeModel $item, string $authorDid): bool
    {
        if (self::REPOST === $item->node('reason')->type()) {
            return false;
        }

        return !$item->has('reply')
            || $authorDid === $item->node('reply')->node('parent')->node('author')->string('did');
    }

    /** As the official app: a reply shows when it answers the account itself or someone the account follows. */
    private static function isShown(JsonNodeModel $item, string $accountDid): bool
    {
        if (!$item->has('reply')) {
            return true;
        }
        $parentAuthor = $item->node('reply')->node('parent')->node('author');

        return $accountDid === $parentAuthor->string('did')
            || null !== $parentAuthor->node('viewer')->string('following');
    }

    private static function entryOf(JsonNodeModel $item): ?ParsedEntryModel
    {
        $post = $item->node('post');
        $uri = $post->string('uri');
        $url = AtPostUri::webUrl($uri ?? '');
        if (null === $uri || null === $url) {
            return null;
        }
        $record = $post->node('record');
        $author = $post->node('author');
        $text = BlueskyRichText::asHtml($record->text('text') ?? '', $record->nodes('facets'));

        return new ParsedEntryModel(
            guid: $uri,
            url: $url,
            headline: EntryTitle::of(null, $text),
            author: BlueskyAuthor::name($author),
            summary: null,
            contentHtml: self::withRepostAttribution($text, $item->node('reason')),
            publishedAt: DateParser::parse(self::postedAt($item)),
            authorUrl: BlueskyAuthor::profile($author),
            authorAvatarUrl: BlueskyAuthor::avatar($author),
        );
    }

    /** A repost lands in the timeline when it was reposted, not when the original was written. */
    private static function postedAt(JsonNodeModel $item): ?string
    {
        $reason = $item->node('reason');
        if (self::REPOST === $reason->type()) {
            return $reason->string('indexedAt');
        }
        $post = $item->node('post');

        return $post->node('record')->string('createdAt') ?? $post->string('indexedAt');
    }

    private static function withRepostAttribution(?string $html, JsonNodeModel $reason): ?string
    {
        if (self::REPOST !== $reason->type()) {
            return $html;
        }
        $reposter = $reason->node('by');

        $attribution = ShareAttribution::line(
            'Reposted by',
            BlueskyAuthor::name($reposter),
            BlueskyAuthor::profile($reposter),
        );

        return ($html ?? '') . $attribution;
    }

    private function __construct()
    {
    }
}
