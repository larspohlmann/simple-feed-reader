<?php

declare(strict_types=1);

namespace App\Enum;

enum FeedPlatform: string
{
    case Substack = 'substack';

    public static function fromGenerator(?string $generator): ?self
    {
        return null !== $generator && 0 === strcasecmp(trim($generator), 'Substack') ? self::Substack : null;
    }
}
