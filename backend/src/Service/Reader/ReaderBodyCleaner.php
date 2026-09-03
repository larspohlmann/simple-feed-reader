<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\InBodyEmbedRewriter;
use App\Service\Reader\Media\PageMediaInserter;
use App\Service\Reader\Media\SubstackPosterLink;

/**
 * Cleans readability's article HTML for the reader view through one shared
 * \Dom\HTMLDocument: parse once, rewrite in-body media, repair the page's own
 * players, drop the duplicate leading title, trim edge boilerplate, plan where
 * page-discovered media belongs, restore the lead image against that plan,
 * reconcile the media into the body, serialise once — mirroring
 * FetchedPageNormalizer's discipline of never serialising and re-parsing
 * between steps (#586, #684, #748).
 *
 * Handed on to EntrySanitizer, the XSS boundary, which stays string-in/
 * string-out since Symfony's HtmlSanitizer operates on strings, not a shared
 * DOM — the shared-document window ends here, with one serialise.
 *
 * A body too broken to parse is returned unchanged: readability output is
 * always parseable in practice, but a degenerate one falls through rather
 * than crashing the pass.
 */
final readonly class ReaderBodyCleaner
{
    public function __construct(
        private NavigationChromeTrimmer $navigationTrimmer,
        private LeadingTitleRemover $titleRemover,
        private LeadingEngagementCleaner $engagementCleaner,
        private EdgeBoilerplateTrimmer $boilerplateTrimmer,
        private ReaderLeadImage $leadImage,
        private InBodyEmbedRewriter $embedRewriter,
        private SubstackPosterLink $substackPoster,
        private PlayerChromeCleaner $playerChrome,
        private PageMediaInserter $mediaInserter,
    ) {
    }

    /** @param list<string|null> $titleCandidates */
    public function clean(
        string $contentHtml,
        array $titleCandidates,
        LeadImageCandidate $leadImage,
        ArticleMedia $media,
        ?string $entryAuthor = null,
    ): string {
        $document = HtmlDocumentParser::parseOrNull($contentHtml);
        if ($document === null) {
            return $contentHtml;
        }

        // Media first: a trimmer must not remove a block that now holds a
        // recovered player, and the lead-image restore must see a poster the
        // body has gained before it decides whether to add another picture.
        $recoveredInBody = $this->embedRewriter->rewriteIn($document);
        $this->substackPoster->linkIn($document);
        $this->playerChrome->cleanIn($document);

        $this->navigationTrimmer->trimIn($document);
        $this->titleRemover->removeFrom($document, $titleCandidates);
        $this->engagementCleaner->removeFrom($document, $entryAuthor);
        $this->boilerplateTrimmer->trimIn($document);

        // plan() only classifies, so restore() still sees every body image and
        // can skip the hero when a player will land at the top; apply()'s
        // mutation runs after, or the hero would come back (#755).
        $discoveredMedia = $recoveredInBody ? $media->withoutEmbeds() : $media;
        $plan = $this->mediaInserter->plan($document, $discoveredMedia);
        $this->leadImage->restore($document, $leadImage, $plan->hasTopPlaced());
        $this->mediaInserter->apply($document, $plan);

        return $document->saveHtml();
    }
}
