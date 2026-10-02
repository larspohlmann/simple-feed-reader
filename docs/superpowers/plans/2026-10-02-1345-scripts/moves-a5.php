<?php

declare(strict_types=1);

// #1345 A5: old FQCN => new FQCN, for the #1344 move scripts (run from backend/).

return [
    'App\Service\Recommendation\Llm\Completion\Model\RateLimitedResultModel'
        => 'App\Service\Ai\Model\RateLimitedResultModel',
];
