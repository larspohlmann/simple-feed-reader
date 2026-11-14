<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Curation\Recommendation\LlmTools {
    final class FixtureTokenCounter
    {
    }
}

namespace App\Service\Curation\Recommendation {
    use App\Service\Curation\Recommendation\LlmTools\FixtureTokenCounter;

    final class FixtureBudget
    {
        public function __construct(public FixtureTokenCounter $counter)
        {
        }
    }
}

namespace App\Service\Curation\Recommendation\Llm {
    use App\Service\Curation\Recommendation\FixtureBudget;

    final class FixtureLlmEngine
    {
        public function __construct(public FixtureBudget $budget)
        {
        }
    }
}
