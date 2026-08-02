<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * Normalizes a fetched page's HTML before readability parses it. Two defects
 * of block-component sites (BBC News is the canonical case, #235) are repaired:
 *
 *  - Screen-reader-only labels ("Image source, …") are styled invisible via
 *    CSS classes. The sanitizer strips classes later, so once extracted the
 *    labels would render as visible text. They are removed here, while the
 *    class names still identify them.
 *  - Every paragraph, heading and image sits in its own deep chain of
 *    single-child <div> wrappers. The depth dilutes readability's score
 *    propagation so far that subheadings, figures and even paragraphs fall
 *    under the sibling-join threshold and are dropped. Collapsing the chains
 *    lets the scores reach the real article container.
 *
 * Never throws: a page this step cannot process is returned unchanged, and
 * extraction proceeds exactly as it would have without normalization.
 */
final readonly class FetchedPageNormalizer
{
    /** Class-name fragments that mark an element as visible to screen readers only. */
    private const string HIDDEN_CLASS_PATTERN = '/(?:visually-?hidden|sr-only|screen-reader)/i';

    public function normalize(string $html): string
    {
        $document = $this->parse($html);
        if ($document === null) {
            return $html;
        }

        $this->removeScreenReaderOnlyElements($document);
        $this->unwrapSingleChildDivs($document);

        $normalized = $document->saveHTML();

        return $normalized === false ? $html : $normalized;
    }

    private function parse(string $html): ?\DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        $document = new \DOMDocument();
        $useInternalErrors = libxml_use_internal_errors(true);
        try {
            $encoded = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');

            return $document->loadHTML($encoded) ? $document : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
        }
    }

    private function removeScreenReaderOnlyElements(\DOMDocument $document): void
    {
        $xpath = new \DOMXPath($document);
        foreach (iterator_to_array($xpath->query('//*[@class]') ?: []) as $element) {
            if (
                $element instanceof \DOMElement
                && $element->parentNode !== null
                && preg_match(self::HIDDEN_CLASS_PATTERN, $element->getAttribute('class')) === 1
            ) {
                $element->parentNode->removeChild($element);
            }
        }
    }

    private function unwrapSingleChildDivs(\DOMDocument $document): void
    {
        $divs = iterator_to_array($document->getElementsByTagName('div'));
        // Reverse document order visits descendants before their ancestors, so
        // one pass collapses a whole wrapper chain from the inside out.
        foreach (array_reverse($divs) as $div) {
            $child = $this->soleDivChild($div);
            if ($child !== null && $div->parentNode !== null) {
                $div->parentNode->replaceChild($child, $div);
            }
        }
    }

    private function soleDivChild(\DOMElement $div): ?\DOMElement
    {
        $soleElement = null;
        foreach ($div->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                if ($soleElement !== null) {
                    return null;
                }
                $soleElement = $child;
            } elseif ($child instanceof \DOMText && trim($child->textContent) !== '') {
                return null;
            }
        }

        return $soleElement instanceof \DOMElement && $soleElement->nodeName === 'div' ? $soleElement : null;
    }
}
