<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Enum\SocialNetwork;

final class ProfileIdentity
{
    /** @return string|null the valid Bluesky actor a profile feed or an author feed follows; null for any other feed */
    public static function validBlueskyActorOf(string $feedUrl): ?string
    {
        return self::validBlueskyActor(
            BlueskyProfileFeed::parseIdentity($feedUrl) ?? SocialFeedUrl::authorOf(SocialNetwork::Bluesky, $feedUrl),
        );
    }

    /** @return string|null the valid Bluesky actor a profile's RSS feed follows; null for an author feed or any other */
    public static function validBlueskyActorOfRssFeed(string $feedUrl): ?string
    {
        return self::validBlueskyActor(BlueskyProfileFeed::parseIdentity($feedUrl));
    }

    private static function validBlueskyActor(?string $identity): ?string
    {
        return null !== $identity && BlueskyActor::isValid($identity) ? $identity : null;
    }

    private function __construct()
    {
    }
}
