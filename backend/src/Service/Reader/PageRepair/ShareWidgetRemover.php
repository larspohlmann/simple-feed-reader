<?php

declare(strict_types=1);

namespace App\Service\Reader\PageRepair;

use App\Service\Html\Support\ClassTokenMatcher;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\XPath;

/**
 * Removes share-button widgets, found by a plugin's whole class token, before readability keeps them as a list of
 * links. A class merely containing a token (`myshariff`) stays; every copy of a bar goes, wherever it sits.
 */
final readonly class ShareWidgetRemover implements PageRepairInterface
{
    /**
     * Whole class tokens that identify a share-widget container. Each is a
     * plugin fingerprint, not a generic word — `social-share` and bare `share`
     * are deliberately excluded as too loose.
     */
    private const array SHARE_WIDGET_CLASS_TOKENS = [
        'shariff',                          // Shariff (heise)
        'sharedaddy',                       // Jetpack Sharedaddy container
        'sd-sharing',                       // Jetpack Sharedaddy inner block
        'addtoany_share_save_container',    // AddToAny
        'a2a_kit',                          // AddToAny inline kit
        'sharethis-inline-share-buttons',   // ShareThis
    ];

    public function repairIn(HTMLDocument $document): void
    {
        foreach ($this->elementsWithClass($document) as $element) {
            if ($element->parentNode !== null && $this->isShareWidget($element)) {
                $element->parentNode->removeChild($element);
            }
        }
    }

    private function isShareWidget(Element $element): bool
    {
        return ClassTokenMatcher::hasAnyToken($element, self::SHARE_WIDGET_CLASS_TOKENS);
    }

    /**
     * Every element carrying a class attribute, collected before any removal so the tree can change during the walk.
     *
     * @return list<Element>
     */
    private function elementsWithClass(HTMLDocument $document): array
    {
        $elements = [];
        foreach ((new XPath($document))->query('//*[@class]') as $node) {
            if ($node instanceof Element) {
                $elements[] = $node;
            }
        }

        return $elements;
    }
}
