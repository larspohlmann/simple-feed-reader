<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\Support;

use App\Service\Foundation\Url\Support\HttpsImageUrl;
use App\Service\Outbound\Fetch\Pass\PageUrls;

/**
 * For FaviconResolver: the person, community and site icons in lemmy-ui's `window.isoData` script literal.
 */
final class LemmyPageIcon
{
    // Strings are stepped over whole, so a brace in a sidebar never ends the flat record early; possessive
    // quantifiers keep a long sidebar from exhausting PCRE's JIT stack.
    private const string RECORD_BODY = '(\{(?:[^{}"]++|"(?:[^"\\\\]++|\\\\.)*+")*+\})/';

    private const string SITE_RECORD = '/"site_view":\{"site":' . self::RECORD_BODY;

    private const string COMMUNITY_RECORD = '/"community_view":\{"community":' . self::RECORD_BODY;

    private const string PERSON_RECORD = '/"person_view":\{"person":' . self::RECORD_BODY;

    private const string STATIC_BUILD = '#/static/([0-9a-z]+)/assets/#';

    public static function actorIn(string $html, PageUrls $pageUrls): ?string
    {
        $person = self::record(self::PERSON_RECORD, $html);
        if (null === $person) {
            return self::field(self::COMMUNITY_RECORD, 'icon', $html);
        }

        return self::imageUrl($person, 'avatar') ?? self::mouse($html, $pageUrls);
    }

    public static function siteIn(string $html): ?string
    {
        return self::field(self::SITE_RECORD, 'icon', $html);
    }

    private static function field(string $pattern, string $key, string $html): ?string
    {
        $record = self::record($pattern, $html);

        return null === $record ? null : self::imageUrl($record, $key);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function record(string $pattern, string $html): ?array
    {
        if (1 !== preg_match($pattern, $html, $matches)) {
            return null;
        }

        $record = json_decode($matches[1], true);

        return \is_array($record) ? $record : null;
    }

    /**
     * @param array<array-key, mixed> $record
     */
    private static function imageUrl(array $record, string $key): ?string
    {
        $value = $record[$key] ?? null;

        return HttpsImageUrl::orNull(\is_string($value) ? $value : null);
    }

    private static function mouse(string $html, PageUrls $pageUrls): ?string
    {
        if (1 !== preg_match(self::STATIC_BUILD, $html, $matches)) {
            return null;
        }

        return HttpsImageUrl::orNull($pageUrls->httpUrl('/static/' . $matches[1] . '/assets/icons/icon-96x96.png'));
    }

    private function __construct()
    {
    }
}
