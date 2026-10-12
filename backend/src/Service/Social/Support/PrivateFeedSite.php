<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Social\Model\PrivateFeedModel;

/** The web page a private feed stands for, so its favicon and "visit site" link never see `social:`. */
final class PrivateFeedSite
{
    private const string BLUESKY_RECORD
        = '#^at://([^/]+)/(app\.bsky\.graph\.list|app\.bsky\.feed\.generator)/([^/]+)$#';
    private const string BLUESKY_LIST = 'app.bsky.graph.list';

    public static function of(SocialAccount $account, PrivateFeedModel $privateFeed): string
    {
        return match ($account->getNetwork()) {
            SocialNetwork::Bluesky => self::bluesky($account, $privateFeed),
            SocialNetwork::Mastodon => self::mastodon($account, $privateFeed),
        };
    }

    private static function bluesky(SocialAccount $account, PrivateFeedModel $privateFeed): string
    {
        $remoteId = $privateFeed->remoteId ?? '';
        if (1 !== preg_match(self::BLUESKY_RECORD, $remoteId, $parts)) {
            return AtPostUri::profileUrl($account->getIdentity());
        }
        $section = self::BLUESKY_LIST === $parts[2] ? 'lists' : 'feed';

        return AtPostUri::profileUrl($parts[1]) . '/' . $section . '/' . $parts[3];
    }

    private static function mastodon(SocialAccount $account, PrivateFeedModel $privateFeed): string
    {
        $instance = rtrim($account->getServiceUrl(), '/');
        if (SocialFeedKind::UserList !== $privateFeed->kind) {
            return $instance;
        }

        return $instance . '/lists/' . rawurlencode($privateFeed->remoteId ?? '');
    }

    private function __construct()
    {
    }
}
