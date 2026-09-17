<?php

declare(strict_types=1);

function allowedChain(?string $first, ?string $second, string $fallback): string
{
    return $first ?? $second ?? $fallback;
}

function rejectedChain(?string $first, ?string $second, ?string $third, string $fallback): string
{
    return $first ?? $second ?? $third ?? $fallback;
}

function longerRejectedChain(
    ?string $first,
    ?string $second,
    ?string $third,
    ?string $fourth,
    string $fallback,
): string {
    return $first ?? $second ?? $third ?? $fourth ?? $fallback;
}

function syntaxWithSparseChildren(): void
{
    [$first, , $third] = ['first', 'second', 'third'];
}
