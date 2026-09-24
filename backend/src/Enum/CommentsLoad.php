<?php

declare(strict_types=1);

namespace App\Enum;

enum CommentsLoad: string
{
    case Auto = 'auto';
    case Manual = 'manual';
}
