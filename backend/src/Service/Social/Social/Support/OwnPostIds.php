<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\Exception\NetworkWithoutFeedsException;
use App\Service\Social\Bluesky\Support\AtPostUri;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Model\SocialPostAddressModel;

/** What a delete names one of the account's own posts by: a Bluesky AT URI, or the status id on its own server. */
final class OwnPostIds
{
    /** @throws SocialPostRefusedException */
    public static function ofAddress(SocialPostAddressModel $post, SocialAccount $account): string
    {
        return match ($post->network) {
            SocialNetwork::Bluesky => $post->address,
            SocialNetwork::Mastodon => self::mastodonStatusId($post->address, $account),
            SocialNetwork::Lemmy => throw NetworkWithoutFeedsException::for(SocialNetwork::Lemmy),
        };
    }

    /** @throws SocialPostRefusedException */
    public static function ofReplyUrl(string $url, SocialAccount $account): string
    {
        return match ($account->getNetwork()) {
            SocialNetwork::Bluesky => self::blueskyPost($url, $account),
            SocialNetwork::Mastodon => self::mastodonStatusId($url, $account),
            SocialNetwork::Lemmy => throw NetworkWithoutFeedsException::for(SocialNetwork::Lemmy),
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
        $ownHost = $account->getServiceHost();
        if (null === $status || null === $ownHost || 0 !== strcasecmp($status->host, $ownHost)) {
            throw new SocialPostRefusedException('The post is not on this account\'s own Mastodon server.');
        }

        return $status->id;
    }

    private function __construct()
    {
    }
}
