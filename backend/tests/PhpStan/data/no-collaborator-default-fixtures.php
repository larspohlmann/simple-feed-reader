<?php

declare(strict_types=1);

// Fixtures for NoCollaboratorDefaultRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixtures {
    final readonly class Helper
    {
    }

    final readonly class DefaultsItsHelper
    {
        public function __construct(
            private Helper $helper = new Helper(),
            private int $limit = 3,
        ) {
        }
    }

    final readonly class TakesItsHelper
    {
        public function __construct(private Helper $helper)
        {
        }
    }
}

namespace App\Service\Fixtures\Model {
    final readonly class DefaultsAValue
    {
        public function __construct(public \App\Service\Fixtures\Helper $part = new \App\Service\Fixtures\Helper())
        {
        }
    }
}

namespace App\Controller\Fixtures {
    final readonly class ControllerDefaults
    {
        public function __construct(private \App\Service\Fixtures\Helper $helper = new \App\Service\Fixtures\Helper())
        {
        }
    }
}

namespace App\Entity\Fixtures {
    final class EntityDefaults
    {
        public function __construct(private \App\Service\Fixtures\Helper $helper = new \App\Service\Fixtures\Helper())
        {
        }
    }
}

namespace App\Service\Fixtures\Dto {
    final readonly class DtoDefaultsAValue
    {
        public function __construct(public \App\Service\Fixtures\Helper $part = new \App\Service\Fixtures\Helper())
        {
        }
    }
}

namespace App\Service\Fixtures\Pass {
    final readonly class PassDefaultsAValue
    {
        public function __construct(public \App\Service\Fixtures\Helper $part = new \App\Service\Fixtures\Helper())
        {
        }
    }
}

namespace App\Service\Fixtures\Message {
    final readonly class MessageDefaultsAValue
    {
        public function __construct(public \App\Service\Fixtures\Helper $part = new \App\Service\Fixtures\Helper())
        {
        }
    }
}
