<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Alpha {
    use App\Service\Alpha\Parts\AlphaPart;
    use App\Service\Beta\BetaThing;
    use App\Service\Gamma\GammaThing;

    final class AlphaThing
    {
        public function __construct(public AlphaPart $part, public BetaThing $beta, public GammaThing $gamma)
        {
        }
    }
}

namespace App\Service\Beta {
    use App\Service\Gamma\GammaThing;

    final class BetaThing
    {
        public function __construct(public GammaThing $gamma)
        {
        }
    }
}

namespace App\Service\Gamma {
    final class GammaThing
    {
    }
}

namespace App\Command\Gamma {
    use App\Service\Alpha\AlphaThing;

    final class GammaCommand
    {
        public function __construct(public AlphaThing $alpha)
        {
        }
    }
}
