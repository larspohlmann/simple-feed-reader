<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Text\Support\Whitespace;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Drops the first heading or paragraph when it repeats a title candidate: the reader renders the title itself, and
 * readability misses a headline in its own wrapper block. Several candidates, because the page <title> can be an SEO
 * variant of the headline the feed title matches.
 */
final readonly class LeadingTitleRemover implements BodyCleaningStepInterface
{
    public function cleanIn(BodyCleaningPass $pass): void
    {
        $this->removeFrom($pass->document, $pass->input->titleCandidates);
    }

    /** @param list<string|null> $titleCandidates */
    private function removeFrom(HTMLDocument $document, array $titleCandidates): void
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
     * The first h1/h2/h3/p with text, by CSS selector: an XPath `//h1` misses the HTML5 parser's XHTML-namespaced
     * elements.
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
    private function repeatsTitle(Element $firstTextBlock, array $normalizedTitles): bool
    {
        return \in_array($this->normalize((string) $firstTextBlock->textContent), $normalizedTitles, true);
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(Whitespace::collapse($text));
    }
}
