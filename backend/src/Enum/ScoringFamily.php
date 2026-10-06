<?php

declare(strict_types=1);

namespace App\Enum;

enum ScoringFamily: string
{
    case Decision = 'decision';
    case Reranker = 'reranker';
}
