<?php

declare(strict_types=1);

namespace App\Enum;

enum ProfileRunOutcome: string
{
    case Generated = 'generated';
    case Unchanged = 'unchanged';
    case NoHistory = 'no_history';
}
