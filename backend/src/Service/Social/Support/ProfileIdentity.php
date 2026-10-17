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

    private function __construct()
    {
    }
}
