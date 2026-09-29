<?php

declare(strict_types=1);

// Fixture for CommentBlockLengthRuleTest, excluded from `composer stan`; the line numbers matter.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Tests\PhpStan\Fixtures\CommentBlockLength;

/**
 * One.
 *
 * Two.
 *
 * Three.
 */
final class ThreeProseLinesPass
{
}

/**
 * One.
 * Two.
 * Three.
 * Four.
 */
final class FourProseLinesFail
{
}

/*
 * One.
 * Two.
 * Three.
 * Four.
 * Five.
 */
final class APlainBlockCommentCounts
{
}

# One.
# Two.
# Three.
# Four.
final class HashCommentsCount
{
}
