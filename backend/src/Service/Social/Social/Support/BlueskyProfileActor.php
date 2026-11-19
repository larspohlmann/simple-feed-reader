<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Enum\SocialNetwork;

final class BlueskyProfileActor
{
    private function __construct()
    {
    }

    /** @return string|null the valid Bluesky actor a profile feed or an author feed follows; null for any other feed */
    public static function parseFollowedActor(string $feedUrl): ?string
    {
        $author = SocialFeedUrl::parseAuthor(SocialNetwork::Bluesky, $feedUrl);
        $did = null !== $author && BlueskyActor::isDid($author) ? $author : null;

        return self::validActor(BlueskyProfileFeed::parseIdentity($feedUrl) ?? $did);
    }

    /** @return string|null the valid Bluesky actor a profile's RSS feed follows; null for an author feed or any other */
    public static function parseRssActor(string $feedUrl): ?string
    {
        return self::validActor(BlueskyProfileFeed::parseIdentity($feedUrl));
    }

    private static function validActor(?string $identity): ?string
    {
        return null !== $identity && BlueskyActor::isValid($identity) ? $identity : null;
    }
}
