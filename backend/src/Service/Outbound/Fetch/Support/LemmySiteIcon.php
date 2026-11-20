<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\Support;

use App\Service\Foundation\Url\Support\HttpsImageUrl;

/**
 * For FaviconResolver: lemmy-ui advertises its icon only as data: URIs and has no /favicon.ico; the https original is
 * in the site record of the `window.isoData` state it embeds, a script literal that is not strict JSON.
 */
final class LemmySiteIcon
{
    // Strings are stepped over whole, so a brace in a sidebar never ends the flat record early; possessive
    // quantifiers keep a long sidebar from exhausting PCRE's JIT stack.
    private const string RECORD_BODY = '(\{(?:[^{}"]++|"(?:[^"\\\\]++|\\\\.)*+")*+\})/';

    private const string SITE_RECORD = '/"site_view":\{"site":' . self::RECORD_BODY;

    public static function in(string $html): ?string
    {
        $record = self::record(self::SITE_RECORD, $html);
        $icon = $record['icon'] ?? null;

        return HttpsImageUrl::parseUrl(\is_string($icon) ? $icon : null);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function record(string $pattern, string $html): array
    {
        if (1 !== preg_match($pattern, $html, $matches)) {
            return [];
        }

        $record = json_decode($matches[1], true);

        return \is_array($record) ? $record : [];
    }

    private function __construct()
    {
    }
}
