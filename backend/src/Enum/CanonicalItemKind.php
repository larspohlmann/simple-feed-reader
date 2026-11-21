<?php

declare(strict_types=1);

namespace App\Enum;

enum CanonicalItemKind: string
{
    case Bluesky = 'bluesky';
    case Mastodon = 'mastodon';
    case Lemmy = 'lemmy';

    public static function fromNetwork(SocialNetwork $network): self
    {
        return match ($network) {
            SocialNetwork::Bluesky => self::Bluesky,
            SocialNetwork::Mastodon => self::Mastodon,
            SocialNetwork::Lemmy => self::Lemmy,
        };
    }
}
