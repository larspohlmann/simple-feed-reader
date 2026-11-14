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

    interface FixtureEngineInterface
    {
        public function advance(FixtureConnection $connection): void;
    }
}

namespace App\Service\Fixture\Digest {
    use App\Service\Curation\Recommendation\FixtureEngineInterface;

    final class FixtureDigestSchedule
    {
        public function __construct(public FixtureEngineInterface $engine)
        {
        }
    }
}

namespace App\Service\Curation\Recommendation\Llm {
    use App\Service\Curation\Ai\FixtureConnection;
    use App\Service\Fixture\Digest\FixtureDigestSchedule;
    use App\Service\Curation\Recommendation\FixtureEngineInterface;

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
