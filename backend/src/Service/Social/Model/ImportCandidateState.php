<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

enum ImportCandidateState: string
{
    case New = 'new';
    case Subscribed = 'subscribed';
    case NoLongerFollowed = 'no_longer_followed';

    public function listingRank(): int
    {
        return match ($this) {
            self::New => 0,
            self::Subscribed => 1,
            self::NoLongerFollowed => 2,
        };
    }
}
