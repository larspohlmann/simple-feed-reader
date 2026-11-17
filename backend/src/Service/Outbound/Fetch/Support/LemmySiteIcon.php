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
    // Strings are stepped over whole, so a brace in the sidebar never ends the flat record early; possessive
    // quantifiers keep a long sidebar from exhausting PCRE's JIT stack.
    private const string SITE_RECORD = '/"site_view":\{"site":(\{(?:[^{}"]++|"(?:[^"\\\\]++|\\\\.)*+")*+\})/';

    public static function in(string $html): ?string
    {
        if (1 !== preg_match(self::SITE_RECORD, $html, $matches)) {
            return null;
        }

        $record = json_decode($matches[1], true);
        $icon = \is_array($record) ? $record['icon'] ?? null : null;

        return HttpsImageUrl::orNull(\is_string($icon) ? $icon : null);
    }

    private function __construct()
    {
    }
}
