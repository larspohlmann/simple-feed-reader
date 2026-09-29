<?php

declare(strict_types=1);

namespace App\Service\Fetch\Support;

/**
 * The host two feed URLs share for pacing: case, a leading `www.` and a port fold away. A distinct subdomain stays a
 * distinct host; folding sibling subdomains into one family is deliberately out of scope.
 */
final class HostKey
{
    public static function forUrl(string $url): string
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            return $url;
        }

        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private function __construct()
    {
    }
}
