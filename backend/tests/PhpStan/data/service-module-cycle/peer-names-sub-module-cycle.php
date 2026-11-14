<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Briefing\Digest {
    use App\Service\Curation\Recommendation\Llm\FixtureLlmEngine;

    final class FixtureDigestSchedule
    {
        public function __construct(public FixtureLlmEngine $engine)
        {
        }
    }
}

namespace App\Service\Curation\Recommendation\Llm {
    use App\Service\Briefing\Digest\FixtureDigestSchedule;

    final class FixtureLlmEngine
    {
        public function schedule(): string
        {
            return FixtureDigestSchedule::class;
        }
    }
}
