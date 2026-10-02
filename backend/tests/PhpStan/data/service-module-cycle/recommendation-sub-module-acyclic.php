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

namespace App\Service\Digest {
    use App\Service\Recommendation\FixtureEngineInterface;

    final class FixtureDigestSchedule
    {
        public function __construct(public FixtureEngineInterface $engine)
        {
        }
    }
}

namespace App\Service\Recommendation\Llm {
    use App\Service\Ai\FixtureConnection;
    use App\Service\Digest\FixtureDigestSchedule;
    use App\Service\Recommendation\FixtureEngineInterface;

    final class FixtureLlmEngine implements FixtureEngineInterface
    {
        public function __construct(public FixtureDigestSchedule $schedule)
        {
        }

        public function advance(FixtureConnection $connection): void
        {
        }
    }
}
