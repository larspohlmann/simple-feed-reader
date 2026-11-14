<?php

declare(strict_types=1);

namespace App\Enum;

enum VoteScore: int
{
    case Up = 1;
    case None = 0;
    case Down = -1;

    public static function ofNetworkValue(?int $value): self
    {
        return self::tryFrom($value ?? self::None->value) ?? self::None;
    }
}
