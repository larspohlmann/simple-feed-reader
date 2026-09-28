<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning;

use App\Service\Reader\FeedMedia;
use App\Service\Reader\LeadImageCandidate;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\Teaser\TeaserPlayer;
use App\Service\Reader\Slideshow\Slideshow;

/** What the extraction knows about the article besides its body, for the body-cleaning steps to read. */
final readonly class BodyCleaningInput
{
    /**
     * @param list<string|null>  $titleCandidates readability's title and the feed entry's, either possibly absent
     * @param list<Slideshow>    $slideshows
     * @param list<TeaserPlayer> $teasers
     */
    public function __construct(
        public array $titleCandidates,
        public LeadImageCandidate $leadImage,
        public ArticleMedia $media,
        public FeedMedia $feedMedia,
        public ?string $entryAuthor = null,
        public array $slideshows = [],
        public array $teasers = [],
        public ?string $excerpt = null,
    ) {
    }
}
