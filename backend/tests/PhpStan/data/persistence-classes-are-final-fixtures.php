<?php

declare(strict_types=1);

// Fixtures for PersistenceClassesAreFinalRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Entity\Fixtures {
    class OpenEntity
    {
    }

    final class ClosedEntity
    {
    }

    interface EntityContract
    {
    }

    enum EntityKind
    {
        case One;
    }
}

namespace App\Repository\Fixtures {
    abstract class AbstractBaseRepository
    {
    }

    final readonly class ClosedRepository
    {
    }
}

namespace App\Service\Fixtures {
    class OpenService
    {
    }
}

namespace App\Entity\Fixtures\Documented {
    /** @final */
    class DocblockFinalEntity
    {
    }
}
