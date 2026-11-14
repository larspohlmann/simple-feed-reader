<?php

declare(strict_types=1);

namespace App\Service\Content\Reader\ArticleExtractor;

use App\Service\Foundation\Html\Exception\UnparseableHtmlException;
use App\Service\Content\Reader\ArticleContentGate;
use App\Service\Content\Reader\ArticlePageReader;
use App\Service\Content\Reader\ArticleReadability;
use App\Service\Content\Reader\BodyCleaning\Model\BodyCleaningInputModel;
use App\Service\Content\Reader\Exception\ArticleNotExtractedException;
use App\Service\Content\Reader\Exception\PageFetchException;
use App\Service\Content\Reader\HtmlPageFetcher;
use App\Service\Content\Reader\Media\BodyMediaResolver;
use App\Service\Content\Reader\Model\ArticlePageModel;
use App\Service\Content\Reader\Model\EntryHintsModel;
use App\Service\Content\Reader\Model\ExtractionFailure;
use App\Service\Content\Reader\Model\ExtractionResultModel;
use App\Service\Content\Reader\Model\LeadImageCandidateModel;
use App\Service\Content\Reader\Model\PageResponseModel;
use App\Service\Content\Reader\ReaderBodyCleaner;
use App\Service\Content\Reader\Slideshow\Model\ContainerSignatureModel;
use App\Service\Content\Reader\Slideshow\Model\SlideshowModel;
use App\Service\Foundation\Sanitize\EntrySanitizer;
use fivefilters\Readability\Article;

/**
 * Fetch, normalise, read the page, run readability, clean the body, sanitise (EntrySanitizer is the XSS barrier).
 * Every page read happens before readability consumes the normalised document. An ordinary failure is a `failed`
 * result, never a throw, so the endpoint stays 200 and the client falls back to the feed body.
 */
final readonly class ArticleExtractor implements ArticleExtractorInterface
{
    public function __construct(
        private HtmlPageFetcher $fetcher,
        private ArticlePageReader $pageReader,
        private ReaderBodyCleaner $bodyCleaner,
        private EntrySanitizer $sanitizer,
        private BodyMediaResolver $bodyMedia,
        private ArticleReadability $readability,
        private ArticleContentGate $contentGate,
    ) {
    }

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
        $articlePage = $this->pageReader->read($page, $hints->feedMedia);
        $containers = $this->slideshowContainers($articlePage->slideshows);
        $article = $this->readability->richest($articlePage->normalized, $page, $containers)
            ?? throw new ArticleNotExtractedException(ExtractionFailure::Unextractable);
        $content = $this->contentGate->contentOf($article, $articlePage->media);
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
