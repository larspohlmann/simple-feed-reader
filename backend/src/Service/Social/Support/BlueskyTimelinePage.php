<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Bluesky\Support\BlueskyWeb;
use App\Service\Bluesky\Support\BlueskyRichText;
use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Support\DateParser;
use App\Service\Parser\Support\EntryTitle;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Url\Support\AbsoluteHttpUrl;

/** A getTimeline / getListFeed / getFeed answer as entries; each post view rides along for its embed. */
final class BlueskyTimelinePage
{
    private const string REPOST = 'app.bsky.feed.defs#reasonRepost';

    /** @param list<JsonNodeModel> $items the answer's `feed` */
    public static function of(array $items, string $accountDid): TimelinePageModel
    {
        $entries = [];
        $posts = [];
        foreach ($items as $item) {
            $entry = self::isShown($item, $accountDid) ? self::entryOf($item) : null;
            if (null === $entry) {
                continue;
            }
            $entries[] = $entry;
            $posts[$entry->guid] = $item->node('post');
        }

        return TimelinePageModel::of($entries, KnownSharesModel::ofBlueskyPosts($posts), null);
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
            author: $author->string('displayName') ?? $author->string('handle'),
            summary: null,
            contentHtml: self::withRepostAttribution($text, $item->node('reason')),
            publishedAt: DateParser::parse(self::postedAt($item)),
            authorUrl: self::profileOf($author),
            authorAvatarUrl: AbsoluteHttpUrl::orNull($author->string('avatar')),
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
        $name = $reposter->string('displayName') ?? $reposter->string('handle');

        return ($html ?? '') . ShareAttribution::line('Reposted by', $name, self::profileOf($reposter));
    }

    private static function profileOf(JsonNodeModel $actor): ?string
    {
        $handleOrDid = $actor->string('handle') ?? $actor->string('did');

        return null === $handleOrDid ? null : BlueskyWeb::profileUrl($handleOrDid);
    }

    private function __construct()
    {
    }
}
