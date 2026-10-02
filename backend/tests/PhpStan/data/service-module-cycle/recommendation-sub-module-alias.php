<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Schedule {
    use App\Service\Recommendation\Llm as LlmModule;

    final class FixtureNightlyRun
    {
        public function engine(): string
        {
            return LlmModule\FixtureLlmEngine::class;
        }
    }
}

namespace App\Service\Recommendation\Llm {
    use App\Service\Schedule\FixtureNightlyRun;

    final class FixtureLlmEngine
    {
        public function __construct(public FixtureNightlyRun $nightlyRun)
        {
        }
    }
}
