<?php

declare(strict_types=1);

namespace App\Service\Reader\Repair;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Readability reads a `<div>` whose only child is an `<hr>` as empty and removes
 * it, taking the rule with it; Substack wraps every section break that way.
 * Promote the rule out of each such wrapper so it survives as a direct child
 * that scoring leaves alone.
 */
final readonly class HorizontalRuleUnwrapper implements PageRepair
{
    public function repairIn(HTMLDocument $document): void
    {
        foreach (iterator_to_array($document->getElementsByTagName('hr')) as $rule) {
            $wrapper = $rule->parentNode;
            while ($wrapper instanceof Element && $this->isSoleRuleWrapper($wrapper)) {
                $host = $wrapper->parentNode;
                if ($host === null) {
                    break;
                }
                $host->replaceChild($rule, $wrapper);
                $wrapper = $host;
            }
        }
    }

    private function isSoleRuleWrapper(Element $element): bool
    {
        return $element->localName === 'div'
            && $element->childElementCount === 1
            && trim((string) $element->textContent) === '';
    }
}
