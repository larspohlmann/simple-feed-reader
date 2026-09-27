<?php

declare(strict_types=1);

namespace App\Enum;

enum DigestCadence: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
}
