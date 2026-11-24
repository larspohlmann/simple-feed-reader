<?php

declare(strict_types=1);

namespace App\Service\Library\Subscription\Support;

final class SubscribableUrl
{
    /** Feed.url is VARCHAR(750): a longer URL counts as invalid instead of failing the whole batch's flush. */
    private const int MAX_FEED_URL = 750;

    private function __construct()
    {
    }

    public static function isSubscribable(string $url): bool
    {
        if (mb_strlen($url) > self::MAX_FEED_URL) {
            return false;
        }
        $scheme = parse_url($url, \PHP_URL_SCHEME);
        $host = parse_url($url, \PHP_URL_HOST);

        return \in_array($scheme, ['http', 'https'], true) && \is_string($host) && '' !== $host;
    }
}
