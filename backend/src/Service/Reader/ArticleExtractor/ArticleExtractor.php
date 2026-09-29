<?php

declare(strict_types=1);

namespace App\Service\Reader\ArticleExtractor;

use App\Service\Html\Exception\UnparseableHtmlException;
use App\Service\Reader\ArticleContentGate;
use App\Service\Reader\ArticleReadability;
use App\Service\Reader\BodyCleaning\Model\BodyCleaningInputModel;
use App\Service\Reader\Exception\ArticleNotExtractedException;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\FetchedPageNormalizer;
use App\Service\Reader\HtmlPageFetcher;
use App\Service\Reader\Media\BodyMediaResolver;
use App\Service\Reader\Media\Model\RawPageModel;
use App\Service\Reader\Media\PageMediaScanner;
use App\Service\Reader\Media\Teaser\TeaserPlayerScanner;
use App\Service\Reader\Model\ArticlePageModel;
use App\Service\Reader\Model\EntryHintsModel;
use App\Service\Reader\Model\ExtractionFailure;
use App\Service\Reader\Model\ExtractionResultModel;
use App\Service\Reader\Model\FeedMediaModel;
use App\Service\Reader\Model\LeadFigureCaptionsModel;
use App\Service\Reader\Model\LeadImageCandidateModel;
use App\Service\Reader\Model\PageImageInventoryModel;
use App\Service\Reader\Model\PageResponseModel;
use App\Service\Reader\Paywall\PaywallSignals;
use App\Service\Reader\ReaderBodyCleaner;
use App\Service\Reader\Slideshow\Model\ContainerSignatureModel;
use App\Service\Reader\Slideshow\Model\SlideshowModel;
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
    public function extract(string $url, EntryHintsModel $hints = new EntryHintsModel()): ExtractionResultModel
    {
        try {
            return $this->extractPage($this->fetcher->fetch($url), $hints);
        } catch (PageFetchException $failure) {
            return ExtractionResultModel::failed($url, ExtractionFailure::Fetch, $failure->getMessage());
        } catch (UnparseableHtmlException) {
            return ExtractionResultModel::failed($url, ExtractionFailure::Unextractable);
        } catch (ArticleNotExtractedException $failure) {
            return ExtractionResultModel::failed($url, $failure->failure);
        }
    }

    private function extractPage(PageResponseModel $page, EntryHintsModel $hints): ExtractionResultModel
    {
        $articlePage = $this->readPage($page, $hints->feedMedia);
        $containers = $this->slideshowContainers($articlePage->slideshows);
        $article = $this->readability->richest($articlePage->normalized, $page, $containers)
            ?? throw new ArticleNotExtractedException(ExtractionFailure::Unextractable);
        $content = ArticleContentGate::contentOf($article, $articlePage->media);
        $body = $this->bodyCleaner->clean($content, $this->bodyCleaningInput($article, $articlePage, $hints));
        $clean = $this->sanitizer->sanitize($body) ?? throw new ArticleNotExtractedException(ExtractionFailure::Empty);

        return ExtractionResultModel::ok(
            url: $page->finalUrl,
            title: $article->title,
            byline: $article->byline,
            siteName: $article->siteName,
            contentHtml: $clean,
            excerpt: $article->excerpt,
            paywalled: $articlePage->paywalled,
        );
    }

    private function readPage(PageResponseModel $page, FeedMediaModel $feedMedia): ArticlePageModel
    {
        $normalized = $this->normalizer->normalize($page->html);
        $pageImages = PageImageInventoryModel::fromDocument($normalized);
        $leadCaptions = LeadFigureCaptionsModel::fromDocument($normalized);
        $rawPage = RawPageModel::parse($page->html, $page->finalUrl);

        return new ArticlePageModel(
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

    private function bodyCleaningInput(
        Article $article,
        ArticlePageModel $articlePage,
        EntryHintsModel $hints,
    ): BodyCleaningInputModel {
        return new BodyCleaningInputModel(
            titleCandidates: [$article->title, $hints->title],
            leadImage: new LeadImageCandidateModel(
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
     * @param list<SlideshowModel> $slideshows
     * @return list<ContainerSignatureModel>
     */
    private function slideshowContainers(array $slideshows): array
    {
        return array_values(array_filter(
            array_map(
                static fn (SlideshowModel $slideshow): ?ContainerSignatureModel => $slideshow->container,
                $slideshows,
            ),
        ));
    }
}
