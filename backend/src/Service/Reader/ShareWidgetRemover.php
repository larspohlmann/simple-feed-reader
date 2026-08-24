<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\ClassTokenMatcher;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\XPath;

/**
 * Removes social share-button widgets from a fetched page before readability
 * parses it. These widgets carry a stable, distinctive class fingerprint per
 * plugin (Shariff by heise is the case that motivated this, #582: its bar leads
 * the hanfjournal body and renders as "teilen … merken"). Readability keeps the
 * bar because it is a list of links inside the article container.
 *
 * The match is by whole class token, so a widget's own container is removed with
 * its buttons, while an unrelated class that merely contains the fragment
 * (`sharing-hint`, `myshariff`) is left alone. Removal is position-independent:
 * these plugins print the same bar above and below the article, and both go.
 */
final readonly class ShareWidgetRemover
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

    public function removeFrom(HTMLDocument $document): void
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
     * Every element carrying a class attribute, as an array so the tree can be
     * mutated while the result is walked. An element whose ancestor was already
     * removed has a null parentNode and is skipped by the caller.
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
