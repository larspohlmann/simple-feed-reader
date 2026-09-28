<?php

declare(strict_types=1);

namespace App\Enum;

enum CallVerdict: string
{
    case Usable = 'usable';
    case Unusable = 'unusable';
    case TransportFailed = 'transport-failed';
}
