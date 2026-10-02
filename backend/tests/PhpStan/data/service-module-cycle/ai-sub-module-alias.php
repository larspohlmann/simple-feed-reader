<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Recommendation {
    use App\Service\Ai\Llm as LlmModule;

    final class FixtureTickPhases
    {
        public function engine(): string
        {
            return LlmModule\FixtureLlmEngine::class;
        }
    }
}

namespace App\Service\Ai\Llm {
    use App\Service\Recommendation\FixtureTickPhases;

    final class FixtureLlmEngine
    {
        public function __construct(public FixtureTickPhases $phases)
        {
        }
    }
}
