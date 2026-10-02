<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Engine\Model;

/** Where an engine's reader profile comes from: its own connection distils it, or it borrows the profile connection. */
enum RecommendationProfileSource: string
{
    case Own = 'own';
    case Borrowed = 'borrowed';
}
