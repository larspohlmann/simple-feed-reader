<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Alpha {
    final class AlphaThing
    {
        public function beta(): string
        {
            return \App\Service\Beta\BetaThing::class;
        }
    }
}

namespace App\Service\Beta {
    final class BetaThing
    {
        public function alpha(): string
        {
            return \App\Service\Alpha\AlphaThing::class;
        }
    }
}
