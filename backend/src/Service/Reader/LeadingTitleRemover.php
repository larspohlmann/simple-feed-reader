<?php

declare(strict_types=1);

namespace App\Service\Reader;

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

        $document = $this->loadDocument($contentHtml);
        if ($document === null) {
            return $contentHtml;
        }

        $firstHeading = $this->findFirstHeading($document);
        if ($firstHeading === null || !$this->repeatsTitle($firstHeading, $normalizedTitles)) {
            return $contentHtml;
        }

        return $this->removeHeading($document, $firstHeading, $contentHtml);
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

    private function loadDocument(string $contentHtml): ?\DOMDocument
    {
        $document = new \DOMDocument();
        $useInternalErrors = libxml_use_internal_errors(true);
        try {
            $encoded = mb_encode_numericentity($contentHtml, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');

            return $document->loadHTML($encoded) ? $document : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
        }
    }

    private function findFirstHeading(\DOMDocument $document): ?\DOMElement
    {
        $matches = (new \DOMXPath($document))->query('(//h1|//h2|//h3)[1]');
        $firstNode = $matches instanceof \DOMNodeList ? $matches->item(0) : null;

        return $firstNode instanceof \DOMElement ? $firstNode : null;
    }

    /** @param list<string> $normalizedTitles */
    private function repeatsTitle(\DOMElement $heading, array $normalizedTitles): bool
    {
        if ($heading->parentNode === null) {
            return false;
        }

        return \in_array($this->normalize($heading->textContent), $normalizedTitles, true);
    }

    private function removeHeading(\DOMDocument $document, \DOMElement $heading, string $fallback): string
    {
        $heading->parentNode?->removeChild($heading);
        $withoutTitle = $document->saveHTML();

        return $withoutTitle === false ? $fallback : $withoutTitle;
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
