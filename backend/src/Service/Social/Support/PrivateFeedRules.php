<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\PrivateFeedModel;

final class PrivateFeedRules
{
    public const string MASTODON_LIST = '/^\d{1,32}$/';
    private const string AT_URI_REPOSITORY = '#^at://did:[a-z0-9]+:[A-Za-z0-9._:%-]+/';
    private const string RECORD_KEY = '/[A-Za-z0-9._~:-]{1,512}$#';
    private const string BLUESKY_LIST = self::AT_URI_REPOSITORY . 'app\.bsky\.graph\.list' . self::RECORD_KEY;
    private const string BLUESKY_FEED = self::AT_URI_REPOSITORY . 'app\.bsky\.feed\.generator' . self::RECORD_KEY;

    public static function allows(SocialNetwork $network, PrivateFeedModel $privateFeed): bool
    {
        return match ($privateFeed->kind) {
            SocialFeedKind::Home => true,
            SocialFeedKind::UserList => self::matches(
                SocialNetwork::Bluesky === $network ? self::BLUESKY_LIST : self::MASTODON_LIST,
                $privateFeed->remoteId,
            ),
            SocialFeedKind::CustomFeed => SocialNetwork::Bluesky === $network
                && self::matches(self::BLUESKY_FEED, $privateFeed->remoteId),
        };
    }

    private static function matches(string $pattern, ?string $remoteId): bool
    {
        return null !== $remoteId && 1 === preg_match($pattern, $remoteId);
    }

    private function __construct()
    {
    }
}
