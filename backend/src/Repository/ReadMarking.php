<?php

declare(strict_types=1);

namespace App\Repository;

/** Who marks entries read, and the instant stamped as their hiddenAt. */
final readonly class ReadMarking
{
    public function __construct(
        public int $userId,
        public \DateTimeImmutable $at,
    ) {
    }
}
