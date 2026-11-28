<?php

declare(strict_types=1);

namespace App\Enum;

/** How selective Your stream is, narrow to wide: the lowest article score it still accepts. */
enum YourStreamPrecision: string
{
    case Narrowest = 'narrowest';
    case Narrow = 'narrow';
    case Balanced = 'balanced';
    case Wide = 'wide';
    case Widest = 'widest';

    public const self DEFAULT = self::Wide;

    public function minimumScore(): float
    {
        return match ($this) {
            self::Narrowest => 4.0,
            self::Narrow => 3.0,
            self::Balanced => 2.0,
            self::Wide => 1.5,
            self::Widest => 1.0,
        };
    }
}
