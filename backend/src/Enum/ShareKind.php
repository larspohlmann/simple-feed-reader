<?php

declare(strict_types=1);

namespace App\Enum;

enum ShareKind: string
{
    case Link = 'link';
    case Media = 'media';
}
