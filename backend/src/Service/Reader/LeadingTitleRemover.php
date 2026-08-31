<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Drops the first heading or paragraph of extracted content when it repeats
 * the article's title. The reader view renders the title itself, so a kept headline shows
 * twice. Readability's own duplicate check misses headlines that sit in their
 * own wrapper block (it demotes the page's <h1> to <h2> and only inspects the
 * top candidate), which is exactly the block-component layout of #235.
 *
 * Takes multiple title candidates because no single source is reliable: the
 * page <title> can be an SEO variant of the headline, while the feed entry's
 * title usually matches it verbatim.
 *
 * Mutates the shared document in place (ReaderBodyCleaner parses and serialises
 * once around it). A document with no matching leading heading is left as is.
 */
final readonly class LeadingTitleRemover
{
    /** @param list<string|null> $titleCandidates */
    public function removeFrom(HTMLDocument $document, array $titleCandidates): void
    {
        $firstTextBlock = $this->findFirstTextBlock($document);
        if ($firstTextBlock === null) {
            return;
        }

        if (!$this->repeatsTitle($firstTextBlock, $this->normalizeCandidates($titleCandidates))) {
            return;
        }

        $firstTextBlock->remove();
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
     * The first h1/h2/h3/p in document order with non-empty text. An
     * element-named XPath expression would not match — the HTML5 parser puts
     * elements in the XHTML namespace, which `//h1` does not select — so this
     * reads the tree with a CSS selector.
     */
    private function findFirstTextBlock(HTMLDocument $document): ?Element
    {
        foreach ($document->querySelectorAll('h1, h2, h3, p') as $block) {
            if (trim((string) $block->textContent) !== '') {
                return $block;
            }
        }

        return null;
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
