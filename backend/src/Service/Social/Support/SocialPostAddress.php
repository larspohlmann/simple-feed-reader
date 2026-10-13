<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\Entry;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Url\Support\AbsoluteHttpUrl;

/** Which network post an entry is: the one rule every interaction with a post (a like, later a reply) starts from. */
final class SocialPostAddress
{
    public static function of(Entry $entry): ?SocialPostAddressModel
    {
        return self::onFeedNetwork($entry, SocialFeedClassifier::networkOf($entry->getFeed()));
    }

    /** For a caller that classified the entry's feed already, so a page of entries classifies each feed once. */
    public static function onFeedNetwork(Entry $entry, ?SocialNetwork $feedNetwork): ?SocialPostAddressModel
    {
        return match ($feedNetwork) {
            SocialNetwork::Bluesky => self::blueskyPost($entry->getGuid()),
            SocialNetwork::Mastodon => self::mastodonPost($entry),
            null => null,
        };
    }

    private static function blueskyPost(string $guid): ?SocialPostAddressModel
    {
        return AtPostUri::matches($guid) ? new SocialPostAddressModel(SocialNetwork::Bluesky, $guid) : null;
    }

    private static function mastodonPost(Entry $entry): ?SocialPostAddressModel
    {
        $statusAddress = AbsoluteHttpUrl::orNull($entry->getGuid()) ?? AbsoluteHttpUrl::orNull($entry->getUrl());

        return null === $statusAddress ? null : new SocialPostAddressModel(SocialNetwork::Mastodon, $statusAddress);
    }

    private function __construct()
    {
    }
}
