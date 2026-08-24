<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Text;
use Dom\XPath;

/**
 * Normalizes a fetched page's HTML before readability parses it. The document
 * is parsed once, with the same HTML5 parser readability uses
 * (`\Dom\HTMLDocument`), and handed on as an object — no serialize-and-re-parse
 * round-trip. Two defects of block-component sites (BBC News is the canonical
 * case, #235) are repaired:
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
 *  - Social share-button widgets (Shariff, Sharedaddy, AddToAny, ShareThis)
 *    sit inside the article container as a plain list of links, so readability
 *    keeps them and their "teilen"/"share" labels lead the extracted text.
 *    ShareWidgetRemover strips them here, by class fingerprint, before scoring
 *    (#582).
 *  - <script> and <style> blocks are stripped from the raw source, bounded by
 *    the real close tag. This keeps their text out of the extraction — the
 *    same content readability's own script removal drops — and does it before
 *    the parse, so a JSON-LD block never reaches readability either. It stays a
 *    raw-source strip, not a DOM `querySelectorAll('script, style')` removal,
 *    to keep the output byte-identical to the pipeline this migration replaced;
 *    a <script>/<style> element is raw-text, so the regex matches the same
 *    close-tag boundary the tokenizer would. Moving it into the DOM pass is a
 *    reasonable follow-up.
 *
 * The wrapper collapse is a separate public method, not a step of normalize():
 * normalize() is the score-neutral pass, and callers decide whether to also
 * run collapseWrapperChains().
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

    public function __construct(
        private LazyImageSources $lazyImages,
        private ShareWidgetRemover $shareWidgets,
    ) {
    }

    /**
     * The score-neutral document, ready to hand to readability, or null when the
     * page is empty or cannot be parsed — the caller then extracts nothing.
     */
    public function normalize(string $html): ?HTMLDocument
    {
        return $this->repair($html);
    }

    /**
     * The document with single-child <div> wrapper chains collapsed (#235), or
     * null when there is no chain to collapse — the caller then skips the second
     * extraction. Kept separate from normalize() because the same collapse can
     * flip a well-structured page to the wrong block (#476): ArticleExtractor
     * extracts with and without it and keeps the richer result.
     *
     * Parses the raw HTML afresh rather than sharing normalize()'s document: the
     * conservative and collapsed variants must be independent objects because
     * readability consumes (mutates) each document it parses. A clone of the
     * normalized document would save this parse, but the parse is a few ms
     * against a readability pass of tens of ms, so the plain re-parse is kept.
     */
    public function collapseWrapperChains(string $html): ?HTMLDocument
    {
        $document = $this->repair($html);
        if ($document === null || $this->unwrapSingleChildDivs($document) === 0) {
            return null;
        }

        return $document;
    }

    private function repair(string $html): ?HTMLDocument
    {
        $document = HtmlDocumentParser::parseOrNull($this->removeScriptAndStyleBlocks($html));
        if ($document === null) {
            return null;
        }

        $this->lazyImages->resolveIn($document);
        $this->shareWidgets->removeFrom($document);
        $this->removeScreenReaderOnlyElements($document);
        $this->removeOrphanIconGlyphs($document);

        return $document;
    }

    private function removeScriptAndStyleBlocks(string $html): string
    {
        return preg_replace(self::SCRIPT_OR_STYLE_PATTERN, '', $html) ?? $html;
    }

    private function removeScreenReaderOnlyElements(HTMLDocument $document): void
    {
        foreach ($this->query($document, '//*[@class]') as $element) {
            if (
                $element instanceof Element
                && $element->parentNode !== null
                && preg_match(self::HIDDEN_CLASS_PATTERN, $element->getAttribute('class') ?? '') === 1
            ) {
                $element->parentNode->removeChild($element);
            }
        }
    }

    private function removeOrphanIconGlyphs(HTMLDocument $document): void
    {
        $emptiedHolders = [];
        foreach ($this->query($document, '//text()') as $node) {
            if (!$node instanceof Text) {
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
            if ($node->parentNode instanceof Element) {
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
    private function pruneWhileEmpty(Element $element): void
    {
        while (
            $element->parentNode !== null
            && trim((string) $element->textContent) === ''
            && !$this->holdsEmbeddedContent($element)
        ) {
            $parent = $element->parentNode;
            $parent->removeChild($element);
            if (!$parent instanceof Element) {
                return;
            }
            $element = $parent;
        }
    }

    private function holdsEmbeddedContent(Element $element): bool
    {
        foreach ($element->getElementsByTagName('*') as $descendant) {
            if (in_array($descendant->localName, self::EMBEDDED_TAGS, true)) {
                return true;
            }
        }

        return false;
    }

    private function unwrapSingleChildDivs(HTMLDocument $document): int
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

    private function soleDivChild(Element $div): ?Element
    {
        $soleElement = null;
        foreach ($div->childNodes as $child) {
            if ($child instanceof Element) {
                if ($soleElement !== null) {
                    return null;
                }
                $soleElement = $child;
            } elseif ($child instanceof Text && trim((string) $child->textContent) !== '') {
                return null;
            }
        }

        return $soleElement instanceof Element && $soleElement->localName === 'div' ? $soleElement : null;
    }

    /**
     * The nodes an XPath expression selects, as an array so the tree can be
     * mutated while the result is walked.
     *
     * @return list<\Dom\Node>
     */
    private function query(HTMLDocument $document, string $expression): array
    {
        return iterator_to_array((new XPath($document))->query($expression), false);
    }
}
