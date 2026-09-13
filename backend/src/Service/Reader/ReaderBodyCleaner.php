<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\AuthorBio\AuthorBioSeparator;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\InBodyEmbedRewriter;
use App\Service\Reader\Media\PageMediaInserter;
use App\Service\Reader\Media\SubstackPosterLink;
use App\Service\Reader\Media\Teaser\TeaserPlayer;
use App\Service\Reader\Media\Teaser\TeaserPlayerInserter;
use App\Service\Reader\RecipeFacts\RecipeFactsCleaner;
use App\Service\Reader\Slideshow\Slideshow;
use App\Service\Reader\Slideshow\SlideshowInserter;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * Cleans readability's article HTML for the reader view through one shared
 * \Dom\HTMLDocument: parse once, rewrite in-body media, repair the page's own
 * players, drop the duplicate leading title, trim edge boilerplate, plan where
 * page-discovered media belongs, restore the lead image against that plan,
 * reconcile the media into the body, restore the lede of a media-only article,
 * serialise once — mirroring FetchedPageNormalizer's discipline of never
 * serialising and re-parsing between steps (#586, #684, #748).
 *
 * Handed on to EntrySanitizer, the XSS boundary, which stays string-in/
 * string-out since Symfony's HtmlSanitizer operates on strings, not a shared
 * DOM — the shared-document window ends here, with one serialise.
 *
 * A body too broken to parse is returned unchanged: readability output is
 * always parseable in practice, but a degenerate one falls through rather
 * than crashing the pass.
 *
 * The constructor collaborators are deliberate: this is the body-cleaning
 * pipeline's composition root, and each one is a seam the tests swap
 * independently (trimmers, restorers, inserters, …). Bagging them into a
 * parameter object would hide that coupling, not reduce it.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
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
        private SlideshowInserter $slideshowInserter,
        private RecipeFactsCleaner $recipeFactsCleaner,
        private TeaserPlayerInserter $teaserInserter,
        private MediaOnlyLede $mediaOnlyLede,
        private DuplicateBlockCollapser $duplicateCollapser,
        private AuthorBioSeparator $authorBioSeparator,
    ) {
    }

    /**
     * @param list<string|null>  $titleCandidates
     * @param list<Slideshow>    $slideshows
     * @param list<TeaserPlayer> $teasers
     */
    #[WithSpan]
    public function clean(
        string $contentHtml,
        array $titleCandidates,
        LeadImageCandidate $leadImage,
        ArticleMedia $media,
        ?string $entryAuthor = null,
        ?FeedMedia $feedMedia = null,
        array $slideshows = [],
        array $teasers = [],
        ?string $excerpt = null,
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
        // Engagement first: it strips the leading kickers and breadcrumbs that
        // otherwise sit in front of the title and hide it from the title remover.
        $this->engagementCleaner->removeFrom($document, $entryAuthor);
        $this->titleRemover->removeFrom($document, $titleCandidates);
        $this->boilerplateTrimmer->trimIn($document);

        // A recreated slideshow replaces the publisher's original carousel, which
        // extraction leaves as a broken pile of markup or an empty box. Runs after
        // the trimmers so a trimmer cannot drop the anchor, before media planning
        // so the plan sees the finished structure.
        $this->slideshowInserter->insert($document, $slideshows);

        // A publisher's recipe-fact block (servings/calories/time) lays out with
        // its own stylesheet, which the sanitizer never receives; relay it to the
        // reader's own row-of-cells marker before media planning sees the body.
        $this->recipeFactsCleaner->cleanIn($document);

        // Before planning: drop the dek and lead image a responsive page ships
        // twice (one copy hidden by CSS the scraper never runs), so the planner
        // sees one image, not a phantom second lead visual (#963).
        $this->duplicateCollapser->collapseIn($document);

        // plan() only classifies, so restore() still sees every body image and
        // can skip the hero when a lead visual will land at the top; apply()'s
        // mutation runs after, or the hero would come back (#755).
        $discoveredMedia = $recoveredInBody ? $media->withoutEmbeds() : $media;
        $plan = $this->mediaInserter->plan($document, $discoveredMedia);
        $restoredHero = $this->leadImage->restore($document, $leadImage, $plan->topPlacesLeadVisual());
        $this->mediaInserter->apply($document, $plan, $restoredHero);

        // Inline teasers the extraction reduced to a lone thumbnail: rebuild each
        // as a player where its still still sits, skipping any the media pipeline
        // already placed so the lead media is never mistaken for one (#948).
        $this->teaserInserter->insert($document, $teasers, $this->mediaUrls($media));

        // A gallery or video article whose only prose lived in a dropped header
        // now has a body of pure media; give it back the lede readability kept as
        // the excerpt. Runs last, so it judges "media-only" against the final body.
        $this->mediaOnlyLede->restore($document, $excerpt);

        // Over the settled body: set the trailing author bio and its disclosure
        // apart from the article prose they otherwise run on into (#1000).
        $this->authorBioSeparator->separateIn($document);

        // Last, over the finished body: the feed's real pixel sizes on the
        // images and players it enumerated, so none of them reflows the article.
        FeedDimensionStamper::stampInto($document, $feedMedia ?? FeedMedia::none());

        return $document->saveHtml();
    }

    /** @return list<string> the URLs the media pipeline placed, so a teaser is not rebuilt over one */
    private function mediaUrls(ArticleMedia $media): array
    {
        return array_map(static fn ($candidate): string => $candidate->url, $media->candidates);
    }
}
