<?php

declare(strict_types=1);

// Fixture for CommentBlockLengthRuleTest, excluded from `composer stan`; the line numbers matter.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Tests\PhpStan\Fixtures\CommentBlockLength;

final class VarOneLiners
{
    /** @return list<int> */
    public function values(): array
    {
        // One.
        // Two.
        // Three.
        /** @var list<int> $values */
        $values = [1, 2, 3];

        return $values;
    }

    public function describedOneLiner(): int
    {
        // One.
        // Two.
        // Three.
        /** @var int $count Four: a description is prose. */
        $count = \count($this->values());

        return $count;
    }
}
