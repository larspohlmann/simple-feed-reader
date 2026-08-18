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
 *  - Lazy-loaded images carry a blank `data:` placeholder in `src` and the
 *    real URL in a `data-*` attribute. Both are gone by the time the sanitizer
 *    is done, so the reader showed an empty frame (#467). LazyImageSources
 *    restores the source while the data attribute is still there.
 *  - Every paragraph, heading and image sits in its own deep chain of
 *    single-child <div> wrappers. The depth dilutes readability's score
 *    propagation so far that subheadings, figures and even paragraphs fall
 *    under the sibling-join threshold and are dropped. collapseWrapperChains()
 *    collapses the chains so the scores reach the real article container.
 *  - Icon-font glyphs sit in a Private Use Area code point selected by a CSS
 *    class. The sanitizer strips the class, so the glyph loses its font and the
 *    browser paints a tofu box (taz's pull-quote mark is the case, U+E80F).
 *    The dead code points are removed here, along with the now-empty element
 *    that held them.
 *  - An inline <script> whose JavaScript builds an HTML string ('<div>…</div>',
 *    a paywall or banner injector) breaks libxml's HTML parser: it ends the
 *    script at the embedded tag and spills the rest of the code into the body
 *    as text, past the reach of the sanitizer's element-level script removal.
 *    <script> and <style> blocks are stripped from the raw source first, where
 *    the real </script> still bounds them.
 *
 * The wrapper collapse is a separate public method, not a step of normalize():
 * normalize() is the score-neutral pass, and callers decide whether to also
 * run collapseWrapperChains().
 *
 * Never throws: a page this step cannot process is returned unchanged, and
 * extraction proceeds exactly as it would have without normalization.
 */
final readonly class FetchedPageNormalizer
{
    /** Class-name fragments that mark an element as visible to screen readers only. */
    private const string HIDDEN_CLASS_PATTERN = '/visually-?hidden|sr-only|screen-reader/i';

    /** Whole <script>/<style> blocks — matched to the first real close tag, the
     *  same boundary a browser uses, so an HTML string inside the code goes too. */
    private const string SCRIPT_OR_STYLE_PATTERN = '#<(script|style)\b[^>]*>.*?</\1\s*>#is';

    /**
     * Private Use Area code points across the three planes — icon-font glyphs
     * with no meaning of their own once the selecting class is gone.
     */
    private const string PRIVATE_USE_PATTERN = '/[\x{E000}-\x{F8FF}\x{F0000}-\x{FFFFD}\x{100000}-\x{10FFFD}]/u';

    /** Elements that carry content without text, so an empty one still counts. */
    private const array EMBEDDED_TAGS = [
        'img', 'picture', 'source', 'svg', 'video', 'audio', 'iframe', 'br', 'hr', 'input',
    ];

    public function __construct(private LazyImageSources $lazyImages)
    {
    }

    public function normalize(string $html): string
    {
        $html = $this->removeScriptAndStyleBlocks($html);
        $document = $this->parse($html);
        if ($document === null) {
            return $html;
        }

        $this->lazyImages->resolveIn($document);
        $this->removeScreenReaderOnlyElements($document);
        $this->removeOrphanIconGlyphs($document);

        $normalized = $document->saveHTML();

        return $normalized === false ? $html : $normalized;
    }

    /**
     * Collapse chains of single-child <div> wrappers so readability's score
     * propagation reaches the real article container (#235). Kept separate from
     * normalize() because the same collapse can flip a well-structured page to
     * the wrong block (#476): ArticleExtractor extracts with and without it and
     * keeps the richer result. The input is returned unchanged when there is no
     * chain to collapse, so an unaffected page costs no second extraction.
     */
    public function collapseWrapperChains(string $html): string
    {
        $document = $this->parse($html);
        if ($document === null) {
            return $html;
        }

        if ($this->unwrapSingleChildDivs($document) === 0) {
            return $html;
        }

        $collapsed = $document->saveHTML();

        return $collapsed === false ? $html : $collapsed;
    }

    private function removeScriptAndStyleBlocks(string $html): string
    {
        return preg_replace(self::SCRIPT_OR_STYLE_PATTERN, '', $html) ?? $html;
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

    private function removeOrphanIconGlyphs(\DOMDocument $document): void
    {
        $xpath = new \DOMXPath($document);
        $emptiedHolders = [];
        foreach (iterator_to_array($xpath->query('//text()') ?: []) as $node) {
            if (!$node instanceof \DOMText) {
                continue;
            }
            $text = $node->nodeValue;
            if ($text === null) {
                continue;
            }
            $withoutGlyphs = preg_replace(self::PRIVATE_USE_PATTERN, '', $text);
            if ($withoutGlyphs === null || $withoutGlyphs === $text) {
                continue;
            }
            $node->nodeValue = $withoutGlyphs;
            if ($node->parentNode instanceof \DOMElement) {
                $emptiedHolders[] = $node->parentNode;
            }
        }
        foreach ($emptiedHolders as $holder) {
            $this->pruneWhileEmpty($holder);
        }
    }

    /**
     * Drop an element the glyph strip left empty, then walk up dropping each
     * ancestor the removal in turn empties — a pull-quote's icon <span> and the
     * <p> that held nothing else both go.
     */
    private function pruneWhileEmpty(\DOMElement $element): void
    {
        while (
            $element->parentNode !== null
            && trim($element->textContent) === ''
            && !$this->holdsEmbeddedContent($element)
        ) {
            $parent = $element->parentNode;
            $parent->removeChild($element);
            if (!$parent instanceof \DOMElement) {
                return;
            }
            $element = $parent;
        }
    }

    private function holdsEmbeddedContent(\DOMElement $element): bool
    {
        foreach ($element->getElementsByTagName('*') as $descendant) {
            if (in_array($descendant->nodeName, self::EMBEDDED_TAGS, true)) {
                return true;
            }
        }

        return false;
    }

    private function unwrapSingleChildDivs(\DOMDocument $document): int
    {
        $divs = iterator_to_array($document->getElementsByTagName('div'));
        // Reverse document order visits descendants before their ancestors, so
        // one pass collapses a whole wrapper chain from the inside out.
        $collapsed = 0;
        foreach (array_reverse($divs) as $div) {
            $child = $this->soleDivChild($div);
            if ($child !== null && $div->parentNode !== null) {
                $div->parentNode->replaceChild($child, $div);
                ++$collapsed;
            }
        }

        return $collapsed;
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
