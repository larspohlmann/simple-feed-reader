<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky\Support;

use App\Service\Social\BlueskyPost\Support\BlueskyWeb;

/** The AT URI of a Bluesky list, feed generator, like or repost, `at://<did>/<collection>/<rkey>`. */
final class BlueskyRecordUri
{
    private const string LIST = 'app.bsky.graph.list';
    private const string FEED_GENERATOR = 'app.bsky.feed.generator';
    public const string LIKE = 'app.bsky.feed.like';
    public const string REPOST = 'app.bsky.feed.repost';
    public const string POST = 'app.bsky.feed.post';
    private const string RECORD_KEY = '[A-Za-z0-9._~:-]{1,512}';
    private const int MAX_RECORD_LENGTH = 512;

    public static function isList(string $uri): bool
    {
        return self::LIST === (self::parseParts($uri)['collection'] ?? null);
    }

    public static function isFeedGenerator(string $uri): bool
    {
        return self::FEED_GENERATOR === (self::parseParts($uri)['collection'] ?? null);
    }

    /** @return string|null $uri when it names a like record the remote id column can hold */
    public static function keepIfLike(?string $uri): ?string
    {
        return self::keepIfInCollection($uri, self::LIKE);
    }

    public static function keepIfRepost(?string $uri): ?string
    {
        return self::keepIfInCollection($uri, self::REPOST);
    }

    public static function keepIfPost(?string $uri): ?string
    {
        return self::keepIfInCollection($uri, self::POST);
    }

    public static function parseRecordKey(string $uri): ?string
    {
        return self::parseParts($uri)['recordKey'] ?? null;
    }

    /** @return string|null the record's page on the Bluesky web app; null when $uri is no list or feed generator */
    public static function webUrl(string $uri): ?string
    {
        $parts = self::parseParts($uri);
        if (null === $parts || !\in_array($parts['collection'], [self::LIST, self::FEED_GENERATOR], true)) {
            return null;
        }
        $section = self::LIST === $parts['collection'] ? 'lists' : 'feed';

        return BlueskyWeb::profileUrl($parts['did']) . '/' . $section . '/' . $parts['recordKey'];
    }

    public static function keepIfInCollection(?string $uri, string $collection): ?string
    {
        if (null === $uri || \strlen($uri) > self::MAX_RECORD_LENGTH) {
            return null;
        }

        return $collection === (self::parseParts($uri)['collection'] ?? null) ? $uri : null;
    }

    /** @return array{did: string, collection: string, recordKey: string}|null */
    private static function parseParts(string $uri): ?array
    {
        $pattern = '#^at://(?<did>' . BlueskyActor::DID_SYNTAX . ')/(?<collection>[a-z.]+)/(?<recordKey>'
            . self::RECORD_KEY . ')$#D';
        if (1 !== preg_match($pattern, $uri, $match) || !self::isKnownCollection($match['collection'])) {
            return null;
        }

        return ['did' => $match['did'], 'collection' => $match['collection'], 'recordKey' => $match['recordKey']];
    }

    private static function isKnownCollection(string $collection): bool
    {
        return \in_array($collection, [self::LIST, self::FEED_GENERATOR, self::LIKE, self::REPOST, self::POST], true);
    }

    private function __construct()
    {
    }
}
