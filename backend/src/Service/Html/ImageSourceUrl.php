<?php

declare(strict_types=1);

namespace App\Service\Html;

/**
 * Decides whether a URL can stand as an <img> src the reader promotes. A blank
 * value carries nothing, and a scheme that is neither http nor https is a
 * `data:` placeholder or a `javascript:` payload the sanitizer would strip, so
 * neither is a candidate. A relative URL stays usable: readability resolves it
 * against the page's final URL right after promotion.
 */
final class ImageSourceUrl
{
    /** A URL carrying a scheme that is neither http nor https — never promoted. */
    private const string FOREIGN_SCHEME = '#^(?!https?://)[a-z][a-z0-9+.\-]*:#i';

    public static function isUsable(?string $url): bool
    {
        return $url !== null && $url !== '' && preg_match(self::FOREIGN_SCHEME, $url) !== 1;
    }
}
