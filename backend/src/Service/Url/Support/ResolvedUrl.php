<?php

declare(strict_types=1);

namespace App\Service\Url\Support;

/** A URL found on a page, made absolute against the page's own URL. */
final class ResolvedUrl
{
    private const string HAS_SCHEME = '#^[a-z][a-z0-9+.\-]*:#i';

    public static function against(string $pageUrl, string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '' || preg_match(self::HAS_SCHEME, $reference) === 1) {
            return $reference;
        }

        $scheme = (string) parse_url($pageUrl, \PHP_URL_SCHEME);
        if (str_starts_with($reference, '//')) {
            return $scheme . ':' . $reference;
        }

        $origin = UrlOrigin::of($pageUrl);
        if ($origin === null) {
            return $reference;
        }
        if (str_starts_with($reference, '/')) {
            return $origin . $reference;
        }

        return $origin . self::directoryOf($pageUrl) . $reference;
    }

    private static function directoryOf(string $pageUrl): string
    {
        $path = (string) parse_url($pageUrl, \PHP_URL_PATH);

        $lastSlash = strrpos($path, '/');

        return $lastSlash === false ? '/' : substr($path, 0, $lastSlash + 1);
    }

    private function __construct()
    {
    }
}
