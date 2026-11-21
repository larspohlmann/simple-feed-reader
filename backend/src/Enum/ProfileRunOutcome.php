<?php

declare(strict_types=1);

namespace App\Enum;

enum ProfileRunOutcome: string
{
    case Generated = 'generated';
    case Unchanged = 'unchanged';
    case NoHistory = 'no_history';

    /** @return list<self> the outcomes a run reaches without sending a prompt */
    public static function withoutCall(): array
    {
        return [self::Unchanged, self::NoHistory];
    }
}
