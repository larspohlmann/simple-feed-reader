<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

/** Orders posts oldest first, an undated one after every dated one. */
final class OldestFirstUndatedLast
{
    public static function compare(?\DateTimeImmutable $left, ?\DateTimeImmutable $right): int
    {
        return [$left === null, $left] <=> [$right === null, $right];
    }

    private function __construct()
    {
    }
}
