<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\Teaser\TeaserPlayer;
use App\Service\Reader\Slideshow\Slideshow;
use Dom\HTMLDocument;

/** What the extractor reads off the fetched page before readability consumes the normalised document (#684). */
final readonly class ArticlePage
{
    /**
     * @param list<Slideshow>    $slideshows
     * @param list<TeaserPlayer> $teasers
     */
    public function __construct(
        public PageResponse $page,
        public HTMLDocument $normalized,
        public PageImageInventory $pageImages,
        public LeadFigureCaptions $leadCaptions,
        public bool $paywalled,
        public ArticleMedia $media,
        public array $slideshows,
        public array $teasers,
    ) {
    }
}
