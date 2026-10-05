<?php

declare(strict_types=1);

// #1395 Task 1: old FQCN => new FQCN, for move-classes.php, compare-moves.php and compare-import-lines.php.
// Both classes move down because an entity takes or asks them (docs/architecture.md §8).

return [
    'App\Service\Ai\Model\ModelDescriptorModel' => 'App\Entity\ModelDescriptor',
    'App\Service\Recommendation\Engine\Model\RecommendationProfileSource' => 'App\Enum\RecommendationProfileSource',
];
