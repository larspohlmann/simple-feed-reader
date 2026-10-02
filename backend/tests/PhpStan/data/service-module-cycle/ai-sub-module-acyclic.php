<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Ai {
    final class FixtureConnection
    {
    }
}

namespace App\Service\Recommendation {
    use App\Service\Ai\FixtureConnection;

    interface FixtureEngineInterface
    {
        public function advance(FixtureConnection $connection): void;
    }
}

namespace App\Service\Ai\Llm {
    use App\Service\Ai\FixtureConnection;
    use App\Service\Recommendation\FixtureEngineInterface;

    final class FixtureLlmEngine implements FixtureEngineInterface
    {
        public function advance(FixtureConnection $connection): void
        {
        }
    }
}
