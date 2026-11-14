<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixture\Alpha {
    use App\Service\Fixture\Beta\BetaThing;
    use App\Service\Fixture\Epsilon\EpsilonThing;

    final class AlphaThing
    {
        public function __construct(public BetaThing $beta, public EpsilonThing $epsilon)
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
        public function alpha(): string
        {
            return 'App\Service\Fixture\Alpha\AlphaThing';
        }
    }
}

namespace App\Service\Fixture\Delta {
    use App\Service\Fixture\Alpha\AlphaThing;

    final class DependsOnTheCycle
    {
        public function __construct(public AlphaThing $alpha)
        {
        }
    }
}

namespace App\Service\Fixture\Epsilon {
    final class EpsilonThing
    {
    }
}
