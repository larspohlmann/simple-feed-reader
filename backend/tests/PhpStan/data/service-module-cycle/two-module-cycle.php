<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixture\Alpha {
    use App\Entity\Feed;
    use App\Service\Fixture\Alpha\Parts\AlphaPart;
    use App\Service\Fixture\Beta\BetaThing;

    final class AlphaThing
    {
        public function __construct(public BetaThing $beta, public AlphaPart $part, public Feed $feed)
        {
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
