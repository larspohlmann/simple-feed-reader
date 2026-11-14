<?php

declare(strict_types=1);

// A fixture for ServiceRoleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixture {
    /** @noinspection AutoloadingIssuesInspection */
    final readonly class LooseInGroup
    {
        public function name(): string
        {
            return 'group';
        }
    }
}

namespace App\Service {
    /** @noinspection AutoloadingIssuesInspection */
    final readonly class LooseInRoot
    {
        public function name(): string
        {
            return 'root';
        }
    }
}

namespace App\Service\Fixture\Shelf {
    /** @noinspection AutoloadingIssuesInspection */
    final readonly class InItsModule
    {
        public function name(): string
        {
            return 'module';
        }
    }
}

namespace App\Service\Widget\Shelf {
    /** @noinspection AutoloadingIssuesInspection */
    final readonly class InAnUnknownGroup
    {
        public function name(): string
        {
            return 'unknown group';
        }
    }
}
