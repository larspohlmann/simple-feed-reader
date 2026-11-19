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
        return self::validActor(self::parseFollowedIdentity($feedUrl));
    }

    /** @return string|null the identity a profile feed or a DID author feed follows, unvalidated; null for any other */
    public static function parseFollowedIdentity(string $feedUrl): ?string
    {
        return BlueskyProfileFeed::parseIdentity($feedUrl) ?? self::parseAuthorDid($feedUrl);
    }

    /** @return string|null the DID an author feed names; null for a handle author or any other feed */
    public static function parseAuthorDid(string $feedUrl): ?string
    {
        $author = SocialFeedUrl::parseAuthor(SocialNetwork::Bluesky, $feedUrl);

        return null !== $author && BlueskyActor::isDid($author) ? $author : null;
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
