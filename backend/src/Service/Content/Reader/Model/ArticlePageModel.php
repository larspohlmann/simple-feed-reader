<?php

declare(strict_types=1);

namespace App\Service\Content\Reader\Model;

use App\Service\Content\Reader\Media\Model\ArticleMediaModel;
use App\Service\Content\Reader\Media\Teaser\Model\TeaserPlayerModel;
use App\Service\Content\Reader\Slideshow\Model\SlideshowModel;
use Dom\HTMLDocument;

/** What the extractor reads off the fetched page before readability consumes the normalised document. */
final readonly class ArticlePageModel
{
    /**
     * @param list<SlideshowModel>    $slideshows
     * @param list<TeaserPlayerModel> $teasers
     */
    public function __construct(
        public PageResponseModel $page,
        public HTMLDocument $normalized,
        public PageImageInventoryModel $pageImages,
        public LeadFigureCaptionsModel $leadCaptions,
        public bool $paywalled,
        public ArticleMediaModel $media,
        public array $slideshows,
        public array $teasers,
    ) {
    }
}
