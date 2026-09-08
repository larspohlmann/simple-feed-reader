<?php

declare(strict_types=1);

namespace App\Service\Fetch;

/**
 * The key that decides whether two feed URLs share a host for pacing purposes.
 *
 * Folds the incidental differences that still name the same origin — case, a
 * leading `www.`, an explicit port — so that a burst against one publisher is
 * recognised as one host. Family folding (all `*.youtube.com` into one) is
 * deliberately out of scope; a distinct subdomain stays a distinct host.
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
}
