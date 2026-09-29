<?php

declare(strict_types=1);

// Fixtures for AbbreviatedNameRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixtures {
    use Symfony\Component\Security\Core\Exception\AuthenticationException;

    final class Abbreviations
    {
        private int $idx = 0;

        public function __construct(private readonly string $cfg)
        {
        }

        public function spelledOut(string $document, int $x, int $y): int
        {
            $total = $x + $y + $this->idx;
            foreach ([1, 2] as $i) {
                $total += $i + \strlen($document . $this->cfg);
            }
            try {
                return $total;
            } catch (\Throwable $e) {
                return \count(array_map(static fn (int $n): int => $n, [1]));
            }
        }

        public function numbered(): int
        {
            $s1 = 1;
            $data = 2;

            return $data;
        }
    }

    final class InheritedNames extends AuthenticationException
    {
        public function __construct(string $msg)
        {
            parent::__construct($msg);
        }

        public function __unserialize(array $data): void
        {
            parent::__unserialize($data);
        }

        public function own(array $data): int
        {
            return \count($data);
        }
    }
}

namespace App\Service\Fixtures\Wire {
    final readonly class WireRequest
    {
        public function __construct(public string $q)
        {
        }
    }

    final readonly class OtherRequest
    {
        public function __construct(public string $q)
        {
        }
    }
}

namespace App\Service\Fixtures\Anonymous {
    function counter(): object
    {
        return new class {
            private int $idx = 0;
        };
    }
}
