<?php

declare(strict_types=1);

namespace App\Service\Reader\PageRepair;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Promotes an <hr> out of the text-less <div>s that hold only it: readability removes such a <div> as empty, and
 * the rule with it.
 */
final readonly class HorizontalRuleUnwrapper implements PageRepairInterface
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
