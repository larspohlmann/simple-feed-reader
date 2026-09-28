<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service {
    use App\Service\Alpha\AlphaThing;

    final class LooseService
    {
        public function __construct(public AlphaThing $alpha)
        {
        }
    }
}

namespace App\Service\Alpha {
    use App\Service\LooseService;

    final class AlphaThing
    {
        public function __construct(public LooseService $loose)
        {
        }
    }
}
