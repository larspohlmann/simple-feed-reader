<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Recommendation\LlmTools {
    final class FixtureTokenCounter
    {
    }
}

namespace App\Service\Recommendation {
    use App\Service\Recommendation\LlmTools\FixtureTokenCounter;

    final class FixtureBudget
    {
        public function __construct(public FixtureTokenCounter $counter)
        {
        }
    }
}

namespace App\Service\Recommendation\Llm {
    use App\Service\Recommendation\FixtureBudget;

    final class FixtureLlmEngine
    {
        public function __construct(public FixtureBudget $budget)
        {
        }
    }
}
