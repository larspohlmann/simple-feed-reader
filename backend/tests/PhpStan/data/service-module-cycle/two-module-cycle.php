<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Alpha {
    use App\Entity\Feed;
    use App\Service\Alpha\Parts\AlphaPart;
    use App\Service\Beta\BetaThing;

    final class AlphaThing
    {
        public function __construct(public BetaThing $beta, public AlphaPart $part, public Feed $feed)
        {
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
