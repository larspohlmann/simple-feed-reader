<?php

declare(strict_types=1);

namespace App\Service\Reader\PageRepair;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Removes the elements a page hides with a utility class (`hidden`, `is-hidden`, `sc-hidden`) until its script shows
 * them, such as a no-JS fallback's timeout error. A variant class (`md:block`) may show it after all, so it stays.
 */
final readonly class HiddenClassElementRemover implements PageRepairInterface
{
    private const string HIDING_CLASS_PATTERN = '/(?:^|\s)(?:[a-z]{1,3}-)?hidden(?:\s|$)/';

    public function repairIn(HTMLDocument $document): void
    {
        foreach ($document->querySelectorAll('[class]') as $element) {
            if ($this->isHidden($element)) {
                $element->remove();
            }
        }
    }

    private function isHidden(Element $element): bool
    {
        $classes = $element->getAttribute('class') ?? '';

        return preg_match(self::HIDING_CLASS_PATTERN, $classes) === 1 && !str_contains($classes, ':');
    }
}
