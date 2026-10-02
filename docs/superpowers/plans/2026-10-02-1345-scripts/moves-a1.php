<?php

declare(strict_types=1);

// #1345 A1: old FQCN => new FQCN, for the #1344 move scripts (run from backend/).

return [
    'App\Service\Recommendation\Engine\Model\RecommendationEngineKind' => 'App\Enum\RecommendationEngineKind',
];
