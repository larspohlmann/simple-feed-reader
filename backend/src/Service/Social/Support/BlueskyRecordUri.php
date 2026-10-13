<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Support\BlueskyWeb;

/** The AT URI of a Bluesky list, feed generator or like, `at://<did>/<collection>/<rkey>`. */
final class BlueskyRecordUri
{
    private const string LIST = 'app.bsky.graph.list';
    private const string FEED_GENERATOR = 'app.bsky.feed.generator';
    private const string LIKE = 'app.bsky.feed.like';
    private const string RECORD_KEY = '[A-Za-z0-9._~:-]{1,512}';
    private const int MAX_LIKE_LENGTH = 512;

    public static function isList(string $uri): bool
    {
        return self::LIST === (self::partsOf($uri)['collection'] ?? null);
    }

    public static function isFeedGenerator(string $uri): bool
    {
        return self::FEED_GENERATOR === (self::partsOf($uri)['collection'] ?? null);
    }

    /** @return string|null $uri when it names a like record the remote id column can hold */
    public static function likeOrNull(?string $uri): ?string
    {
        if (null === $uri || \strlen($uri) > self::MAX_LIKE_LENGTH) {
            return null;
        }

        return self::LIKE === (self::partsOf($uri)['collection'] ?? null) ? $uri : null;
    }

    public static function recordKeyOf(string $uri): ?string
    {
        return self::partsOf($uri)['recordKey'] ?? null;
    }

    /** @return string|null the record's page on the Bluesky web app; null when $uri is no list or feed generator */
    public static function webUrl(string $uri): ?string
    {
        $parts = self::partsOf($uri);
        if (null === $parts || self::LIKE === $parts['collection']) {
            return null;
        }
        $section = self::LIST === $parts['collection'] ? 'lists' : 'feed';

        return BlueskyWeb::profileUrl($parts['did']) . '/' . $section . '/' . $parts['recordKey'];
    }

    /** @return array{did: string, collection: string, recordKey: string}|null */
    private static function partsOf(string $uri): ?array
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
        return \in_array($collection, [self::LIST, self::FEED_GENERATOR, self::LIKE], true);
    }

    private function __construct()
    {
    }
}
