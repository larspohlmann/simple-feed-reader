<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\HtmlDocumentParser;

/**
 * Cleans readability's article HTML for the reader view through one shared
 * \Dom\HTMLDocument: parse once, drop the duplicate leading title and trim edge
 * boilerplate in place, serialise once. This mirrors FetchedPageNormalizer's
 * discipline of never serialising and re-parsing between steps — the two
 * removers that each used to own "parse string → mutate → serialise" now mutate
 * the same document, so the body is parsed once instead of three times (#586).
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
    ) {
    }

    /** @param list<string|null> $titleCandidates */
    public function clean(string $contentHtml, array $titleCandidates): string
    {
        $document = HtmlDocumentParser::parseOrNull($contentHtml);
        if ($document === null) {
            return $contentHtml;
        }

        $this->titleRemover->removeFrom($document, $titleCandidates);
        $this->boilerplateTrimmer->trimIn($document);

        return $document->saveHtml();
    }
}
