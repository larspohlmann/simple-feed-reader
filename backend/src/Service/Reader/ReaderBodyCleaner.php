<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\HtmlDocumentParser;

/**
 * Cleans readability's article HTML for the reader view through one shared
 * \Dom\HTMLDocument: parse once, drop the duplicate leading title, trim edge
 * boilerplate and restore the lead image in place, serialise once. This mirrors
 * FetchedPageNormalizer's discipline of never serialising and re-parsing between
 * steps — the two removers and the lead-image restore all mutate the same
 * document, so the body is parsed once instead of four times (#586, #684).
 *
 * The result is handed on to EntrySanitizer, the XSS boundary, which stays
 * string-in/string-out because Symfony's HtmlSanitizer operates on strings, not
 * a shared DOM. So the shared-document window ends here, with one serialise.
 *
 * A body too broken to parse is returned unchanged: readability output is
 * always parseable in practice, but a degenerate one falls through rather than
 * crashing the pass.
 */
final readonly class ReaderBodyCleaner
{
    public function __construct(
        private LeadingTitleRemover $titleRemover,
        private EdgeBoilerplateTrimmer $boilerplateTrimmer,
        private ReaderLeadImage $leadImage,
    ) {
    }

    /** @param list<string|null> $titleCandidates */
    public function clean(string $contentHtml, array $titleCandidates, LeadImageCandidate $leadImage): string
    {
        $document = HtmlDocumentParser::parseOrNull($contentHtml);
        if ($document === null) {
            return $contentHtml;
        }

        $this->titleRemover->removeFrom($document, $titleCandidates);
        $this->boilerplateTrimmer->trimIn($document);
        $this->leadImage->restore($document, $leadImage);

        return $document->saveHtml();
    }
}
