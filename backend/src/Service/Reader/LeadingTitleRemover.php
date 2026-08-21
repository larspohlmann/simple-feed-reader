<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Drops the first heading of extracted content when it repeats the article's
 * title. The reader view renders the title itself, so a kept headline shows
 * twice. Readability's own duplicate check misses headlines that sit in their
 * own wrapper block (it demotes the page's <h1> to <h2> and only inspects the
 * top candidate), which is exactly the block-component layout of #235.
 *
 * Takes multiple title candidates because no single source is reliable: the
 * page <title> can be an SEO variant of the headline, while the feed entry's
 * title usually matches it verbatim.
 *
 * Never throws: content this step cannot process is returned unchanged.
 */
final readonly class LeadingTitleRemover
{
    /** @param list<string|null> $titleCandidates */
    public function remove(string $contentHtml, array $titleCandidates): string
    {
        $normalizedTitles = $this->normalizeCandidates($titleCandidates);
        if ($normalizedTitles === []) {
            return $contentHtml;
        }

        $document = HtmlDocumentParser::parseOrNull($contentHtml);
        if ($document === null) {
            return $contentHtml;
        }

        $firstHeading = $this->findFirstHeading($document);
        if ($firstHeading === null || !$this->repeatsTitle($firstHeading, $normalizedTitles)) {
            return $contentHtml;
        }

        $firstHeading->remove();

        return $document->saveHtml();
    }

    /**
     * @param list<string|null> $titleCandidates
     * @return list<string>
     */
    private function normalizeCandidates(array $titleCandidates): array
    {
        $nonEmptyCandidates = array_filter(
            $titleCandidates,
            static fn (?string $candidate): bool => $candidate !== null && trim($candidate) !== '',
        );

        return array_values(array_map($this->normalize(...), $nonEmptyCandidates));
    }

    /**
     * The first h1/h2/h3 in document order. An element-named XPath expression
     * would not match — the HTML5 parser puts elements in the XHTML namespace,
     * which `//h1` does not select — so this reads the tree with a CSS selector.
     */
    private function findFirstHeading(HTMLDocument $document): ?Element
    {
        return $document->querySelector('h1, h2, h3');
    }

    /** @param list<string> $normalizedTitles */
    private function repeatsTitle(Element $heading, array $normalizedTitles): bool
    {
        return \in_array($this->normalize((string) $heading->textContent), $normalizedTitles, true);
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
