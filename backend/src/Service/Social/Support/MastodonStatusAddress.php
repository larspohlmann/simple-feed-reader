<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

/** Where a Mastodon status address's own instance serves the status to anyone: its two address shapes only. */
final class MastodonStatusAddress
{
    private const array STATUS_SHAPES = [
        '~^https://([a-z0-9.-]+)/users/[^/?#]+/statuses/(\d{1,32})$~iD',
        '~^https://([a-z0-9.-]+)/@[^/?#]+/(\d{1,32})$~iD',
    ];

    private function __construct()
    {
    }

    public static function statusApiUrlOrNull(string $address): ?string
    {
        foreach (self::STATUS_SHAPES as $shape) {
            if (1 === preg_match($shape, $address, $parts)) {
                return sprintf('https://%s/api/v1/statuses/%s', $parts[1], $parts[2]);
            }
        }

        return null;
    }
}
