<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Model\SocialPostAddressModel;

/** What a delete names one of the account's own posts by: a Bluesky AT URI, or the status id on its own server. */
final class OwnPostIds
{
    /** @throws SocialPostRefusedException */
    public static function ofAddress(SocialPostAddressModel $post, SocialAccount $account): string
    {
        return match ($post->network) {
            SocialNetwork::Bluesky => $post->address,
            SocialNetwork::Mastodon => self::mastodonStatusId($post->address, $account),
        };
    }

    /** @throws SocialPostRefusedException */
    public static function ofReplyUrl(string $url, SocialAccount $account): string
    {
        return match ($account->getNetwork()) {
            SocialNetwork::Bluesky => self::blueskyPost($url, $account),
            SocialNetwork::Mastodon => self::mastodonStatusId($url, $account),
        };
    }

    private static function blueskyPost(string $url, SocialAccount $account): string
    {
        $post = AtPostUri::ofWebUrl($url)
            ?? throw new SocialPostRefusedException('A reply in the thread names no Bluesky post.');

        return AtPostUri::withAuthority($post, $account->getIdentity());
    }

    private static function mastodonStatusId(string $address, SocialAccount $account): string
    {
        $status = MastodonStatusAddress::ofOrNull($address);
        $ownHost = parse_url($account->getServiceUrl(), \PHP_URL_HOST);
        if (null === $status || !\is_string($ownHost) || 0 !== strcasecmp($status->host, $ownHost)) {
            throw new SocialPostRefusedException('The post is not on this account\'s own Mastodon server.');
        }

        return $status->id;
    }

    private function __construct()
    {
    }
}
