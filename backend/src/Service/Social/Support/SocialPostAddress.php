<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\Entry;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Url\Support\AbsoluteHttpUrl;

/** Which network post an entry is: the one rule every interaction with a post (a like, later a reply) starts from. */
final class SocialPostAddress
{
    public static function of(Entry $entry): ?SocialPostAddressModel
    {
        return match ($entry->getFeed()->getNetwork()) {
            SocialNetwork::Bluesky => self::blueskyPost($entry->getGuid()),
            SocialNetwork::Mastodon => self::mastodonPost($entry),
            null => null,
        };
    }

    /** @throws NotASocialPostException */
    public static function ofOrRefuse(Entry $entry): SocialPostAddressModel
    {
        return self::of($entry) ?? throw new NotASocialPostException('This entry is not a Bluesky or Mastodon post.');
    }

    /**
     * A reply in the entry's thread, named by the address its comment carries.
     *
     * @throws NotASocialPostException
     */
    public static function replyOrRefuse(Entry $entry, string $address): SocialPostAddressModel
    {
        $reply = match (self::ofOrRefuse($entry)->network) {
            SocialNetwork::Bluesky => self::blueskyPost($address),
            SocialNetwork::Mastodon => self::mastodonStatus(AbsoluteHttpUrl::orNull($address)),
        };

        return $reply ?? throw new NotASocialPostException('The reply is no post on the entry\'s network.');
    }

    private static function blueskyPost(string $guid): ?SocialPostAddressModel
    {
        return AtPostUri::matches($guid) ? new SocialPostAddressModel(SocialNetwork::Bluesky, $guid) : null;
    }

    private static function mastodonPost(Entry $entry): ?SocialPostAddressModel
    {
        $statusAddress = AbsoluteHttpUrl::orNull($entry->getGuid()) ?? AbsoluteHttpUrl::orNull($entry->getUrl());

        return self::mastodonStatus($statusAddress);
    }

    private static function mastodonStatus(?string $statusAddress): ?SocialPostAddressModel
    {
        return null === $statusAddress ? null : new SocialPostAddressModel(SocialNetwork::Mastodon, $statusAddress);
    }

    private function __construct()
    {
    }
}
