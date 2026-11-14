<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Curation\Ai {
    final class FixtureConnection
    {
    }
}

namespace App\Service\Curation\Recommendation {
    use App\Service\Curation\Ai\FixtureConnection;

    final class FixtureTickPhases
    {
        public function __construct(public FixtureConnection $connection)
        {
        }

        public function engine(): string
        {
            return \App\Service\Curation\Recommendation\Llm\FixtureLlmEngine::class;
        }
    }
}

namespace App\Service\Curation\Recommendation\Llm {
    use App\Service\Curation\Recommendation\FixtureTickPhases;

    final class FixtureLlmEngine
    {
        public function __construct(public FixtureTickPhases $phases)
        {
        }
    }
}
