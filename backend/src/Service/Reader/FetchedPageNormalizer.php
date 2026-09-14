<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Repair\PageRepair;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Text;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * Normalizes a fetched page's HTML before readability parses it. The document is
 * parsed once, with the same HTML5 parser readability uses (`\Dom\HTMLDocument`),
 * and handed on as an object — no serialize-and-re-parse round-trip. A pipeline
 * of PageRepair strategies then repairs the defects of real-world sites (BBC
 * News is the canonical block-component case, #235) that would otherwise cost
 * the extraction a figure, a heading or a whole article; the wiring fixes their
 * order, and each mutates the document in place.
 *
 * <script> and <style> blocks are stripped from the raw source, bounded by the
 * real close tag, before the parse — so a JSON-LD block never reaches readability
 * either. Kept as a raw-source strip rather than a DOM `querySelectorAll('script,
 * style')` removal to stay byte-identical to the pipeline this replaced;
 * script/style content is raw-text, so the regex matches the same close-tag
 * boundary the tokenizer would.
 *
 * The wrapper collapse is a separate public method, not a repair in the pipeline:
 * normalize() is the score-neutral pass, and callers decide whether to also run
 * collapseWrapperChains() (#235), which rescues block-component pages but breaks
 * some well-structured ones (#476) — ArticleExtractor extracts with and without
 * it and keeps the richer result.
 */
final readonly class FetchedPageNormalizer
{
    /** Whole <script>/<style> blocks — matched to the first real close tag, the
     *  same boundary a browser uses, so an HTML string inside the code goes too. */
    private const string SCRIPT_OR_STYLE_PATTERN = '#<(script|style)\b[^>]*>.*?</\1\s*>#is';

    /** @param iterable<PageRepair> $repairs */
    public function __construct(private iterable $repairs)
    {
    }

    /**
     * The score-neutral document, ready to hand to readability, or null when the
     * page is empty or cannot be parsed — the caller then extracts nothing.
     */
    #[WithSpan]
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
     * two variants must be independent objects since readability consumes
     * (mutates) each one it parses.
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

        foreach ($this->repairs as $repair) {
            $repair->repairIn($document);
        }

        return $document;
    }

    private function removeScriptAndStyleBlocks(string $html): string
    {
        return preg_replace(self::SCRIPT_OR_STYLE_PATTERN, '', $html) ?? $html;
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
}
