<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy\Model;

use App\Enum\SocialFeedKind;

enum LemmyListing: string
{
    case Subscribed = 'Subscribed';
    case Local = 'Local';
    case All = 'All';

    public const int PAGE_SIZE = 50;

    public static function fromFeedKind(SocialFeedKind $kind): ?self
    {
        return match ($kind) {
            SocialFeedKind::Home => self::Subscribed,
            SocialFeedKind::Local => self::Local,
            SocialFeedKind::All => self::All,
            default => null,
        };
    }
}
