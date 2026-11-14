<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Enum\SocialNetwork;

final class ProfileIdentity
{
    /** @return string|null the DID, handle or user@host; null when the feed follows no person on $network */
    public static function of(SocialNetwork $network, string $feedUrl): ?string
    {
        return ProfileFeedUrl::identityOf($network, $feedUrl) ?? SocialFeedUrl::authorOf($network, $feedUrl);
    }

    /** @return string|null the valid Bluesky actor a profile feed or an author feed follows; null for any other feed */
    public static function validBlueskyActorOf(string $feedUrl): ?string
    {
        return self::validBlueskyActor(self::of(SocialNetwork::Bluesky, $feedUrl));
    }

    /** @return string|null the valid Bluesky actor a profile's RSS feed follows; null for an author feed or any other */
    public static function validBlueskyActorOfRssFeed(string $feedUrl): ?string
    {
        return self::validBlueskyActor(ProfileFeedUrl::identityOf(SocialNetwork::Bluesky, $feedUrl));
    }

    private static function validBlueskyActor(?string $identity): ?string
    {
        return null !== $identity && BlueskyActor::isValid($identity) ? $identity : null;
    }

    private function __construct()
    {
    }
}
