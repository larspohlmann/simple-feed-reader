<?php

declare(strict_types=1);

namespace App\Service\Ai\Support;

/** One field of a provider's decoded reply, which is untrusted: a wrong type reads as absent. */
final class ReplyField
{
    public static function text(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * A usage counter; absent, non-integer or negative reads 0. A negative one would subtract from the per-run total
     * it is banked onto with SQL arithmetic.
     */
    public static function count(mixed $value): int
    {
        return \is_int($value) ? max(0, $value) : 0;
    }

    private function __construct()
    {
    }
}
