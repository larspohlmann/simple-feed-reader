<?php

declare(strict_types=1);

namespace App\Service\Content\Reader;

use App\Service\Content\Reader\Media\Model\RawPageModel;
use App\Service\Content\Reader\Media\PageMediaScanner;
use App\Service\Content\Reader\Media\Teaser\TeaserPlayerScanner;
use App\Service\Content\Reader\Model\ArticlePageModel;
use App\Service\Content\Reader\Model\FeedMediaModel;
use App\Service\Content\Reader\Model\LeadFigureCaptionsModel;
use App\Service\Content\Reader\Model\PageImageInventoryModel;
use App\Service\Content\Reader\Model\PageResponseModel;
use App\Service\Content\Reader\Paywall\PaywallSignals;
use App\Service\Content\Reader\Slideshow\SlideshowScanner;

/** Every read of a fetched page, taken before readability consumes the normalised document. */
final readonly class ArticlePageReader
{
    public function __construct(
        private FetchedPageNormalizer $normalizer,
        private PageMediaScanner $mediaScanner,
        private SlideshowScanner $slideshowScanner,
        private TeaserPlayerScanner $teaserScanner,
        private PaywallSignals $paywall,
    ) {
    }

    public function read(PageResponseModel $page, FeedMediaModel $feedMedia): ArticlePageModel
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
            paywalled: $this->paywall->isPreview($rawPage->document, $normalized),
            media: $this->mediaScanner->scan($rawPage, $feedMedia),
            slideshows: $this->slideshowScanner->scan($normalized),
            teasers: $this->teaserScanner->scan($normalized, $page->finalUrl),
        );
    }
}
