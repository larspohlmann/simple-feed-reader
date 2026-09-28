<?php

declare(strict_types=1);

namespace App\Service\Reader\ArticleExtractor;

use App\Service\Html\Exception\UnparseableHtmlException;
use App\Service\Reader\ArticleContentGate;
use App\Service\Reader\ArticlePage;
use App\Service\Reader\ArticleReadability;
use App\Service\Reader\BodyCleaning\BodyCleaningInput;
use App\Service\Reader\EntryHints;
use App\Service\Reader\Exception\ArticleNotExtractedException;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\ExtractionFailure;
use App\Service\Reader\ExtractionResult;
use App\Service\Reader\FeedMedia;
use App\Service\Reader\FetchedPageNormalizer;
use App\Service\Reader\HtmlPageFetcher;
use App\Service\Reader\LeadFigureCaptions;
use App\Service\Reader\LeadImageCandidate;
use App\Service\Reader\Media\BodyMediaResolver;
use App\Service\Reader\Media\PageMediaScanner;
use App\Service\Reader\Media\RawPage;
use App\Service\Reader\Media\Teaser\TeaserPlayerScanner;
use App\Service\Reader\PageImageInventory;
use App\Service\Reader\PageResponse;
use App\Service\Reader\Paywall\PaywallSignals;
use App\Service\Reader\ReaderBodyCleaner;
use App\Service\Reader\Slideshow\ContainerSignature;
use App\Service\Reader\Slideshow\Slideshow;
use App\Service\Reader\Slideshow\SlideshowScanner;
use App\Service\Sanitize\EntrySanitizer;
use fivefilters\Readability\Article;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * Fetch, normalise, read the page, run readability, clean the body, sanitise (EntrySanitizer is the XSS barrier).
 * Every page read happens before readability consumes the normalised document (#684, #748). An ordinary failure is
 * a `failed` result, never a throw, so the endpoint stays 200 and the client falls back to the feed body.
 */
final readonly class ArticleExtractor implements ArticleExtractorInterface
{
    public function __construct(
        private HtmlPageFetcher $fetcher,
        private FetchedPageNormalizer $normalizer,
        private ReaderBodyCleaner $bodyCleaner,
        private EntrySanitizer $sanitizer,
        private PageMediaScanner $mediaScanner,
        private BodyMediaResolver $bodyMedia,
        private SlideshowScanner $slideshowScanner,
        private TeaserPlayerScanner $teaserScanner,
        private ArticleReadability $readability,
    ) {
    }

    #[WithSpan]
    public function extract(string $url, EntryHints $hints = new EntryHints()): ExtractionResult
    {
        try {
            return $this->extractPage($this->fetcher->fetch($url), $hints);
        } catch (PageFetchException $failure) {
            return ExtractionResult::failed($url, ExtractionFailure::Fetch, $failure->getMessage());
        } catch (UnparseableHtmlException) {
            return ExtractionResult::failed($url, ExtractionFailure::Unextractable);
        } catch (ArticleNotExtractedException $failure) {
            return ExtractionResult::failed($url, $failure->failure);
        }
    }

    private function extractPage(PageResponse $page, EntryHints $hints): ExtractionResult
    {
        $articlePage = $this->readPage($page, $hints->feedMedia);
        $containers = $this->slideshowContainers($articlePage->slideshows);
        $article = $this->readability->richest($articlePage->normalized, $page, $containers)
            ?? throw new ArticleNotExtractedException(ExtractionFailure::Unextractable);
        $content = ArticleContentGate::contentOf($article, $articlePage->media);
        $body = $this->bodyCleaner->clean($content, $this->bodyCleaningInput($article, $articlePage, $hints));
        $clean = $this->sanitizer->sanitize($body) ?? throw new ArticleNotExtractedException(ExtractionFailure::Empty);

        return ExtractionResult::ok(
            url: $page->finalUrl,
            title: $article->title,
            byline: $article->byline,
            siteName: $article->siteName,
            contentHtml: $clean,
            excerpt: $article->excerpt,
            paywalled: $articlePage->paywalled,
        );
    }

    private function readPage(PageResponse $page, FeedMedia $feedMedia): ArticlePage
    {
        $normalized = $this->normalizer->normalize($page->html);
        $pageImages = PageImageInventory::fromDocument($normalized);
        $leadCaptions = LeadFigureCaptions::fromDocument($normalized);
        $rawPage = RawPage::parse($page->html, $page->finalUrl);

        return new ArticlePage(
            page: $page,
            normalized: $normalized,
            pageImages: $pageImages,
            leadCaptions: $leadCaptions,
            paywalled: PaywallSignals::isPreview($rawPage->document, $normalized),
            media: $this->mediaScanner->scan($rawPage, $feedMedia),
            slideshows: $this->slideshowScanner->scan($normalized),
            teasers: $this->teaserScanner->scan($normalized, $page->finalUrl),
        );
    }

    private function bodyCleaningInput(Article $article, ArticlePage $articlePage, EntryHints $hints): BodyCleaningInput
    {
        return new BodyCleaningInput(
            titleCandidates: [$article->title, $hints->title],
            leadImage: new LeadImageCandidate(
                $article->image,
                $articlePage->pageImages,
                $articlePage->leadCaptions->captionFor($article->image),
            ),
            media: $this->bodyMedia->resolveForBody($articlePage->media, $articlePage->page->html),
            feedMedia: $hints->feedMedia,
            entryAuthor: $hints->author,
            slideshows: $articlePage->slideshows,
            teasers: $articlePage->teasers,
            excerpt: $article->excerpt,
        );
    }

    /**
     * @param list<Slideshow> $slideshows
     * @return list<ContainerSignature>
     */
    private function slideshowContainers(array $slideshows): array
    {
        return array_values(array_filter(
            array_map(static fn (Slideshow $slideshow): ?ContainerSignature => $slideshow->container, $slideshows),
        ));
    }
}
