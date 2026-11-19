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

    /** @return array{string, string}|null the name and host of `name@host`; null for any other shape */
    public static function parseNameAndHost(string $identity): ?array
    {
        $nameAndHost = explode('@', $identity);

        return 2 === \count($nameAndHost) ? [$nameAndHost[0], $nameAndHost[1]] : null;
    }

    /** Whether the identity a built feed URL reads back as is the one it was built from, case aside. */
    public static function isRoundTrip(?string $parsedIdentity, string $identity): bool
    {
        return null !== $parsedIdentity && 0 === strcasecmp($parsedIdentity, $identity);
    }

    private static function validBlueskyActor(?string $identity): ?string
    {
        return null !== $identity && BlueskyActor::isValid($identity) ? $identity : null;
    }

    private function __construct()
    {
    }
}
