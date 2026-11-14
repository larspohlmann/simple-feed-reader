<?php

declare(strict_types=1);

namespace App\Service\Foundation\Url\Support;

final class EnteredUrl
{
    /**
     * Prefixes `https://` to a bare host so "example.com" passes the Url constraint. A value with any scheme stays as
     * it is, so `ftp://` still fails the protocol check.
     */
    public static function withScheme(string $url): string
    {
        $url = trim($url);
        if ('' === $url || 1 === preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)) {
            return $url;
        }

        // Strip a protocol-relative "//" so it does not become "https:////host".
        return 'https://' . ltrim($url, '/');
    }

    private function __construct()
    {
    }
}
