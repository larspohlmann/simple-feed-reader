<?php

declare(strict_types=1);

namespace App\Service\Html;

use Dom\Element;

/**
 * Tests whether an element carries any of a set of whole class tokens.
 *
 * A `class` attribute is a whitespace-separated token list, so membership is by
 * whole token: an element with `class="myshariff"` does not match the token
 * `shariff`. Several reader-cleaning steps identify a block by a curated set of
 * plugin fingerprints this way (#582), so the whole-token match lives here once
 * rather than being copied into each of them.
 */
final class ClassTokenMatcher
{
    /**
     * @param list<string> $tokens the whole class tokens to look for
     */
    public static function hasAnyToken(Element $element, array $tokens): bool
    {
        /** @var list<string> $classTokens */
        $classTokens = preg_split('/\s+/', trim($element->getAttribute('class') ?? '')) ?: [];

        return array_any(
            $classTokens,
            static fn (string $classToken): bool => in_array($classToken, $tokens, true),
        );
    }
}
