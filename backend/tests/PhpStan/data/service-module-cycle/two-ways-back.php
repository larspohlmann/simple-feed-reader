<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixture\Alpha {
    final class AlphaThing
    {
        /** @return list<string> */
        public function neighbours(): array
        {
            return [\App\Service\Fixture\Gamma\GammaThing::class, \App\Service\Fixture\Beta\BetaThing::class];
        }
    }
}

namespace App\Service\Fixture\Beta {
    final class BetaThing
    {
        public function alpha(): string
        {
            return \App\Service\Fixture\Alpha\AlphaThing::class;
        }
    }
}

namespace App\Service\Fixture\Gamma {
    final class GammaThing
    {
        public function alpha(): string
        {
            return \App\Service\Fixture\Alpha\AlphaThing::class;
        }
    }
}
