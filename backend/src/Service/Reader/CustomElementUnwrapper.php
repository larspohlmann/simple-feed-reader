<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\HTMLDocument;

/**
 * Replaces every custom element (a hyphenated tag name) with its children.
 * The sanitizer drops an unknown element with its content, so unwrapping
 * first (#789) is what lets nature's <sh-background-transition> photos through.
 */
final readonly class CustomElementUnwrapper
{
    public function unwrapIn(HTMLDocument $document): void
    {
        // Innermost first: an outer element unwrapped later still holds the
        // already-unwrapped children of its former descendants.
        foreach (array_reverse(iterator_to_array($document->querySelectorAll('*'))) as $element) {
            if ($element->parentNode !== null && str_contains($element->localName, '-')) {
                $element->replaceWith(...iterator_to_array($element->childNodes));
            }
        }
    }
}
