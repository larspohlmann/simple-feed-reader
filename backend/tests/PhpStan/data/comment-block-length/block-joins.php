<?php

declare(strict_types=1);

// Fixture for CommentBlockLengthRuleTest, excluded from `composer stan`; the line numbers matter.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Tests\PhpStan\Fixtures\CommentBlockLength;

final class BlockJoins
{
    public function consecutiveLineCommentsAreOneBlock(): int
    {
        // One.
        // Two.
        // Three.
        // Four.
        return 1;
    }

    public function aBlankLineDoesNotSplitABlock(): int
    {
        // One.
        // Two.

        // Three.
        // Four.
        return 2;
    }

    public function aTrailingCommentJoinsTheCommentsBelowIt(): int
    {
        $value = 3; // One.
        // Two.
        // Three.
        // Four.
        return $value;
    }

    public function aLineCommentJoinsTheDocBlockBelowIt(): int
    {
        // One.
        /**
         * Two.
         * Three.
         * Four.
         */
        $value = 4;

        return $value;
    }

    public function codeBetweenCommentsSplitsThem(): int
    {
        // One.
        // Two.
        $value = 5;
        // Three.
        // Four.
        return $value;
    }

    public function trailingCommentsOnConsecutiveCodeLinesStaySeparate(): int
    {
        $one = 1; // One.
        $two = 2; // Two.
        $three = 3; // Three.
        $four = 4; // Four.

        return $one + $two + $three + $four;
    }
}
