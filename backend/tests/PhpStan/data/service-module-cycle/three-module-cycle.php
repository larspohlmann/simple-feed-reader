<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Alpha {
    use App\Service\Beta\BetaThing;
    use App\Service\Epsilon\EpsilonThing;

    final class AlphaThing
    {
        public function __construct(public BetaThing $beta, public EpsilonThing $epsilon)
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
        public function alpha(): string
        {
            return 'App\Service\Alpha\AlphaThing';
        }
    }
}

namespace App\Service\Delta {
    use App\Service\Alpha\AlphaThing;

    final class DependsOnTheCycle
    {
        public function __construct(public AlphaThing $alpha)
        {
        }
    }
}

namespace App\Service\Epsilon {
    final class EpsilonThing
    {
    }
}
