<?php

declare(strict_types=1);

namespace App\Enum;

enum AiRunKind: string
{
    case ForYou = 'for_you';
    case Profile = 'profile';
    case Story = 'story';

    /** Among runs created in the same second, the higher rank comes first. */
    public function tieRank(): int
    {
        return match ($this) {
            self::ForYou => 2,
            self::Profile => 1,
            self::Story => 0,
        };
    }
}
