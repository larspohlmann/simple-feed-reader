<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\Exception\UnparseableHtmlException;
use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Repair\PageRepair;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Text;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * Parses a fetched page once and runs the PageRepair pipeline over it, in the order services.yaml wires, before
 * readability scores it. <script>/<style> are cut from the raw source first, bounded by the real close tag the
 * tokenizer would use, to stay byte-identical to the pipeline this replaced.
 */
final readonly class FetchedPageNormalizer
{
    private const string SCRIPT_OR_STYLE_PATTERN = '#<(script|style)\b[^>]*>.*?</\1\s*>#is';

    /** @param iterable<PageRepair> $repairs */
    public function __construct(private iterable $repairs)
    {
    }

    /** @throws UnparseableHtmlException when the page is blank or cannot be parsed */
    #[WithSpan]
    public function normalize(string $html): HTMLDocument
    {
        return $this->repair($html);
    }

    /**
     * The page with single-child <div> wrapper chains collapsed (#235), or null when there is none. A fresh
     * parse, since readability consumes each document it reads and the collapse breaks some pages (#476).
     * @throws UnparseableHtmlException when the page is blank or cannot be parsed
     */
    public function collapseWrapperChains(string $html): ?HTMLDocument
    {
        $document = $this->repair($html);
        if ($this->unwrapSingleChildDivs($document) === 0) {
            return null;
        }

        return $document;
    }

    private function repair(string $html): HTMLDocument
    {
        $document = HtmlDocumentParser::parse($this->removeScriptAndStyleBlocks($html));
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
