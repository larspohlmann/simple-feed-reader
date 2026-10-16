<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Support;

use App\Entity\QuotedPostContent;
use App\Entity\QuotedPostReference;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Ingest\Support\EntryMediaAssembler;
use App\Service\LinkCard\Support\StorableQuotedPost;
use App\Service\Parser\Model\ParsedMediumModel;
use App\Service\Parser\Support\DateParser;

final class QuotedViewRecord
{
    private const string VIEW_RECORD = 'app.bsky.embed.record#viewRecord';
    private const string POST_RECORD = 'app.bsky.feed.post';
    private const string IMAGES = 'app.bsky.embed.images#view';
    private const string VIDEO = 'app.bsky.embed.video#view';
    private const string RECORD = 'app.bsky.embed.record#view';
    private const string RECORD_WITH_MEDIA = 'app.bsky.embed.recordWithMedia#view';

    public static function of(JsonNodeModel $record): ?QuotedPostContent
    {
        $value = $record->node('value');
        $url = AtPostUri::webUrl($record->string('uri') ?? '');
        $author = $record->node('author');
        $handle = $author->string('handle');
        if (!self::isPost($record, $value) || $url === null || $handle === null) {
            return null;
        }
        $embed = $record->first('embeds');

        return StorableQuotedPost::of(new QuotedPostContent(
            $url,
            $author->string('displayName') ?? '',
            $handle,
            $author->string('avatar'),
            DateParser::parse($value->string('createdAt') ?? $record->string('indexedAt')),
            $value->text('text') ?? '',
            EntryMediaAssembler::mediaOf(self::mediaOf($embed)),
            self::nestedQuoteOf($embed),
        ));
    }

    private static function isPost(JsonNodeModel $record, JsonNodeModel $value): bool
    {
        return $record->type() === self::VIEW_RECORD && $value->type() === self::POST_RECORD;
    }

    /** @return list<ParsedMediumModel> */
    private static function mediaOf(JsonNodeModel $embed): array
    {
        return match ($embed->type()) {
            self::IMAGES => BlueskyEmbedMedia::images($embed),
            self::VIDEO => array_filter([BlueskyEmbedMedia::video($embed)]),
            self::RECORD_WITH_MEDIA => self::mediaOf($embed->node('media')),
            default => [],
        };
    }

    private static function nestedQuoteOf(JsonNodeModel $embed): ?QuotedPostReference
    {
        $nested = match ($embed->type()) {
            self::RECORD => $embed->node('record'),
            self::RECORD_WITH_MEDIA => $embed->node('record')->node('record'),
            default => null,
        };
        $url = $nested === null ? null : AtPostUri::webUrl($nested->string('uri') ?? '');

        return $url === null ? null : new QuotedPostReference($url, $nested?->node('author')->string('handle'));
    }

    private function __construct()
    {
    }
}
