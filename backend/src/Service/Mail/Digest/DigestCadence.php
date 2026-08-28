<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

enum DigestCadence: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
}
