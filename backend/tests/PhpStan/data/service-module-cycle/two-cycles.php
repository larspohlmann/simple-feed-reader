<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixture\Alpha {
    final class AlphaThing
    {
        public function beta(): string
        {
            return \App\Service\Fixture\Beta\BetaThing::class;
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
        public function delta(): string
        {
            return \App\Service\Fixture\Delta\DeltaThing::class;
        }
    }
}

namespace App\Service\Fixture\Delta {
    final class DeltaThing
    {
        public function gamma(): string
        {
            return \App\Service\Fixture\Gamma\GammaThing::class;
        }
    }
}
