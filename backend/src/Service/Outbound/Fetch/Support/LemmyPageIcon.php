<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\Support;

use App\Service\Foundation\Url\Support\HttpsImageUrl;
use App\Service\Outbound\Fetch\Pass\PageUrls;

/**
 * For FaviconResolver: lemmy-ui advertises no https icon, but the person, community and site records of the
 * `window.isoData` state it embeds, a script literal that is not strict JSON, carry the originals.
 */
final class LemmyPageIcon
{
    private const string RECORD_BODY = '(\{(?:[^{}"]++|"(?:[^"\\\\]++|\\\\.)*+")*+\})/';

    // Strings are stepped over whole, so a brace in a sidebar never ends the flat record early; possessive
    // quantifiers keep a long sidebar from exhausting PCRE's JIT stack.
    private const string SITE_RECORD = '/"site_view":\{"site":' . self::RECORD_BODY;

    private const string COMMUNITY_RECORD = '/"community_view":\{"community":' . self::RECORD_BODY;

    private const string PERSON_RECORD = '/"person_view":\{"person":' . self::RECORD_BODY;

    private const string STATIC_BUILD = '#/static/([0-9a-z]+)/assets/#';

    public static function in(string $html, PageUrls $pageUrls): ?string
    {
        $actorIcon = self::hasRecord(self::PERSON_RECORD, $html)
            ? self::field(self::PERSON_RECORD, 'avatar', $html) ?? self::mouse($html, $pageUrls)
            : self::field(self::COMMUNITY_RECORD, 'icon', $html);

        return $actorIcon ?? self::field(self::SITE_RECORD, 'icon', $html);
    }

    private static function hasRecord(string $pattern, string $html): bool
    {
        return 1 === preg_match($pattern, $html);
    }

    private static function field(string $pattern, string $key, string $html): ?string
    {
        if (1 !== preg_match($pattern, $html, $matches)) {
            return null;
        }

        $record = json_decode($matches[1], true);
        $value = \is_array($record) ? $record[$key] ?? null : null;

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
