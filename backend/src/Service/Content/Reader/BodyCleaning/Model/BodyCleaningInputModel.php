<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning\Model;

use App\Service\Reader\Media\Model\ArticleMediaModel;
use App\Service\Reader\Media\Teaser\Model\TeaserPlayerModel;
use App\Service\Reader\Model\FeedMediaModel;
use App\Service\Reader\Model\LeadImageCandidateModel;
use App\Service\Reader\Slideshow\Model\SlideshowModel;

/** What the extraction knows about the article besides its body, for the body-cleaning steps to read. */
final readonly class BodyCleaningInputModel
{
    /**
     * @param list<string|null>       $titleCandidates readability's title and the feed entry's, either possibly absent
     * @param list<SlideshowModel>    $slideshows
     * @param list<TeaserPlayerModel> $teasers
     */
    public function __construct(
        public array $titleCandidates,
        public LeadImageCandidateModel $leadImage,
        public ArticleMediaModel $media,
        public FeedMediaModel $feedMedia,
        public ?string $entryAuthor = null,
        public array $slideshows = [],
        public array $teasers = [],
        public ?string $excerpt = null,
    ) {
    }
}
