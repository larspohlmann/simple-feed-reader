<?php

declare(strict_types=1);

namespace App\Service\Html\Support;

use Dom\Element;

/** Whole-token class membership: `class="myshariff"` does not carry the token `shariff`. */
final class ClassTokenMatcher
{
    /** @param list<string> $tokens */
    public static function hasAnyToken(Element $element, array $tokens): bool
    {
        /** @var list<string> $classTokens */
        $classTokens = preg_split('/\s+/', trim($element->getAttribute('class') ?? '')) ?: [];

        return array_any(
            $classTokens,
            static fn (string $classToken): bool => in_array($classToken, $tokens, true),
        );
    }

    private function __construct()
    {
    }
}
