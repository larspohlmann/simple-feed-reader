<?php

declare(strict_types=1);

namespace App\Enum;

enum LinkResolution: string
{
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Unchanged = 'unchanged';
    case Unresolved = 'unresolved';
}
