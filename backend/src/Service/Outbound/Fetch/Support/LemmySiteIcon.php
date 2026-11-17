<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\Support;

/**
 * lemmy-ui advertises its icon only as data: URIs and has no /favicon.ico; the https original is the site record of
 * the `window.isoData` state it embeds, a script literal that is not strict JSON.
 */
final class LemmySiteIcon
{
    // JSON strings are stepped over whole, so a brace in the sidebar's markdown never ends the flat site record early.
    private const string SITE_ICON
        = '/"site_view":\{"site":\{(?:[^{}"]|"(?:[^"\\\\]|\\\\.)*")*?"icon":("(?:[^"\\\\]|\\\\.)*")/';

    public static function in(string $html): ?string
    {
        if (1 !== preg_match(self::SITE_ICON, $html, $matches)) {
            return null;
        }

        $icon = json_decode($matches[1]);

        return \is_string($icon) && str_starts_with($icon, 'https://') ? $icon : null;
    }

    private function __construct()
    {
    }
}
