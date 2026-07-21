<?php

declare(strict_types=1);

namespace App\Enum;

enum FeedStatus: string
{
    case Active = 'active';
    case Erroring = 'erroring';
    case Gone = 'gone';
}
