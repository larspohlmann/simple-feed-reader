<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon\Support;

use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Social\Mastodon\Model\MastodonStatusModel;

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

    public static function parseAddress(string $address): ?MastodonStatusModel
    {
        foreach (self::STATUS_SHAPES as $shape) {
            if (1 === preg_match($shape, $address, $match)) {
                return new MastodonStatusModel($match['host'], $match['id'], $match['prefix'], $match['account']);
            }
        }

        return null;
    }

    public static function isStatusAddress(?string $address): bool
    {
        return null !== $address && null !== self::parseAddress($address);
    }

    public static function pickEntryAddress(?string $guid, ?string $url): ?string
    {
        if (self::isStatusAddress($guid)) {
            return $guid;
        }
        if (self::isStatusAddress($url)) {
            return $url;
        }

        return AbsoluteHttpUrl::keepIfAbsolute($guid) ?? AbsoluteHttpUrl::keepIfAbsolute($url);
    }
}
