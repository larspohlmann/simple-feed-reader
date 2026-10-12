<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Support\BlueskyWeb;

/** The AT URI of a Bluesky list or feed generator, `at://<did>/<collection>/<rkey>`. */
final class BlueskyRecordUri
{
    private const string LIST = 'app.bsky.graph.list';
    private const string FEED_GENERATOR = 'app.bsky.feed.generator';
    private const string RECORD_KEY = '[A-Za-z0-9._~:-]{1,512}';

    public static function isList(string $uri): bool
    {
        return self::LIST === self::partsOf($uri)['collection'];
    }

    public static function isFeedGenerator(string $uri): bool
    {
        return self::FEED_GENERATOR === self::partsOf($uri)['collection'];
    }

    /** @return string|null the record's page on the Bluesky web app; null when $uri is no list or feed generator */
    public static function webUrl(string $uri): ?string
    {
        $parts = self::partsOf($uri);
        if (null === $parts['collection']) {
            return null;
        }
        $section = self::LIST === $parts['collection'] ? 'lists' : 'feed';

        return BlueskyWeb::profileUrl($parts['did']) . '/' . $section . '/' . $parts['recordKey'];
    }

    /** @return array{did: string, collection: string|null, recordKey: string} */
    private static function partsOf(string $uri): array
    {
        $pattern = '#^at://(?<did>' . BlueskyActor::DID_SYNTAX . ')/(?<collection>'
            . preg_quote(self::LIST, '#') . '|' . preg_quote(self::FEED_GENERATOR, '#')
            . ')/(?<recordKey>' . self::RECORD_KEY . ')$#D';
        if (1 !== preg_match($pattern, $uri, $match)) {
            return ['did' => '', 'collection' => null, 'recordKey' => ''];
        }

        return ['did' => $match['did'], 'collection' => $match['collection'], 'recordKey' => $match['recordKey']];
    }

    private function __construct()
    {
    }
}
