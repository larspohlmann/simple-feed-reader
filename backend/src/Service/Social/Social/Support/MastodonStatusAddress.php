<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Social\Model\MastodonStatusModel;

/** Where a Mastodon status address's own instance serves the status to anyone: its two address shapes only. */
final class MastodonStatusAddress
{
    private const array STATUS_SHAPES = [
        '~^(?<prefix>https://(?<host>[a-z0-9.-]+)/users/(?<account>[^/?#]+))/statuses/(?<id>\d{1,32})$~iD',
        '~^(?<prefix>https://(?<host>[a-z0-9.-]+)/@(?<account>[^/?#]+))/(?<id>\d{1,32})$~iD',
    ];

    private function __construct()
    {
    }

    public static function ofOrNull(string $address): ?MastodonStatusModel
    {
        foreach (self::STATUS_SHAPES as $shape) {
            if (1 === preg_match($shape, $address, $match)) {
                return new MastodonStatusModel($match['host'], $match['id'], $match['prefix'], $match['account']);
            }
        }

        return null;
    }
}
