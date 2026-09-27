<?php

declare(strict_types=1);

// Fixtures for NoToArrayInServicesRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixtures {
    final class ShapesItsOwnJson implements \JsonSerializable
    {
        public function toArray(): array
        {
            return [];
        }

        public function jsonSerialize(): array
        {
            return [];
        }
    }
}

namespace App\Service\Fixtures\Nested {
    final class ShoutsItsJson
    {
        public function TOARRAY(): array
        {
            return [];
        }
    }
}

namespace App\Service\Fixtures\Clean {
    final class NamesItsStore
    {
        public function toCacheEntry(): array
        {
            return [];
        }

        public function toLogContext(): array
        {
            return [];
        }
    }
}

namespace App\Http\Fixtures {
    final class MapsTheWire
    {
        public function toArray(): array
        {
            return [];
        }
    }
}

namespace App\ServiceLocator\Fixtures {
    final class LooksLikeAServiceButIsNot
    {
        public function toArray(): array
        {
            return [];
        }
    }
}
