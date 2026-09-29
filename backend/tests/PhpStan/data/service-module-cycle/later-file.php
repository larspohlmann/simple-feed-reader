<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Beta {
    final class OtherBetaThing
    {
        public function alpha(): string
        {
            return \App\Service\Alpha\AlphaThing::class;
        }
    }
}
