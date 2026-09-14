<?php

declare(strict_types=1);

namespace App\Service\Reader\Repair;

use Dom\HTMLDocument;

/**
 * Screen-reader-only labels ("Image source, …") are hidden by a CSS class. The
 * sanitizer strips classes later, so once extracted they would render as visible
 * text; they are removed here, while the class still identifies them.
 */
final readonly class ScreenReaderOnlyElementRemover implements PageRepair
{
    /** Class-name fragments that mark an element as visible to screen readers only. */
    private const string HIDDEN_CLASS_PATTERN = '/visually-?hidden|sr-only|screen-reader/i';

    public function repairIn(HTMLDocument $document): void
    {
        foreach (iterator_to_array($document->querySelectorAll('[class]')) as $element) {
            if (
                $element->parentNode !== null
                && preg_match(self::HIDDEN_CLASS_PATTERN, $element->getAttribute('class') ?? '') === 1
            ) {
                $element->parentNode->removeChild($element);
            }
        }
    }
}
