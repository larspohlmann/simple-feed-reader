<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Exception\SocialPostRefusedException;

/** Every guid a feed may store one of the account's own thread replies under: a timeline page's and an RSS item's. */
final class OwnReplyGuids
{
    /**
     * @return list<string>
     *
     * @throws SocialPostRefusedException
     */
    public static function ofUrl(string $url, SocialAccount $account): array
    {
        return match ($account->getNetwork()) {
            SocialNetwork::Bluesky => [OwnPostIds::ofReplyUrl($url, $account)],
            SocialNetwork::Mastodon => [self::mastodonUri($url), $url],
        };
    }

    private static function mastodonUri(string $url): string
    {
        return MastodonStatusAddress::ofOrNull($url)?->uri()
            ?? throw new SocialPostRefusedException('A reply in the thread names no Mastodon status.');
    }

    private function __construct()
    {
    }
}
