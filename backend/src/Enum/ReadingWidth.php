<?php

declare(strict_types=1);

namespace App\Enum;

enum ReadingWidth: string
{
    case Standard = 'standard';
    case Wide = 'wide';
    case Full = 'full';
}
