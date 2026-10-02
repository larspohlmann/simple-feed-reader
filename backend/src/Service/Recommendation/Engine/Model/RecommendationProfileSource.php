<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Engine\Model;

enum RecommendationProfileSource: string
{
    case Own = 'own';
    case Borrowed = 'borrowed';
}
