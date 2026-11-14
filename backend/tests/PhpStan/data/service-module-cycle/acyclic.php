<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixture\Alpha {
    use App\Service\Fixture\Alpha\Parts\AlphaPart;
    use App\Service\Fixture\Beta\BetaThing;
    use App\Service\Fixture\Gamma\GammaThing;

    final class AlphaThing
    {
        public function __construct(public AlphaPart $part, public BetaThing $beta, public GammaThing $gamma)
        {
        }
    }
}

namespace App\Service\Fixture\Beta {
    use App\Service\Fixture\Gamma\GammaThing;

    final class BetaThing
    {
        public function __construct(public GammaThing $gamma)
        {
        }
    }
}

namespace App\Service\Fixture\Gamma {
    final class GammaThing
    {
    }
}

namespace App\Command\Gamma {
    use App\Service\Fixture\Alpha\AlphaThing;

    final class GammaCommand
    {
        public function __construct(public AlphaThing $alpha)
        {
        }
    }
}
