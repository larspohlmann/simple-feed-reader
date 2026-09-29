<?php

declare(strict_types=1);

namespace App\Service\Html\Support;

/**
 * Whether a URL can stand as a promoted <img> src: not blank, and http(s) or relative, since readability resolves a
 * relative one against the final page URL right after promotion. `data:` and `javascript:` never qualify.
 */
final class ImageSourceUrl
{
    private const string FOREIGN_SCHEME = '#^(?!https?://)[a-z][a-z0-9+.\-]*:#i';

    public static function isUsable(?string $url): bool
    {
        return $url !== null && $url !== '' && preg_match(self::FOREIGN_SCHEME, $url) !== 1;
    }

    private function __construct()
    {
    }
}
