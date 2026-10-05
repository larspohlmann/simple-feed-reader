<?php

declare(strict_types=1);

namespace App\Enum;

enum RecommendationProfileSource: string
{
    case Own = 'own';
    case Borrowed = 'borrowed';
}
