<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\Media\PageMediaInserter;
use App\Service\Reader\ReaderLeadImage;

/**
 * Restores the lead image between planning and applying the page's media: plan() only classifies, so the restore
 * still sees every body image. A top-placed video or embed takes the lead position and gets no hero above it; a
 * narration audio player leaves the hero its place (#907).
 */
final readonly class PageMediaPlacement implements BodyCleaningStepInterface
{
    public function __construct(
        private PageMediaInserter $mediaInserter,
        private ReaderLeadImage $leadImage,
    ) {
    }

    public function cleanIn(BodyCleaningPass $pass): void
    {
        $plan = $this->mediaInserter->plan($pass->document, $pass->discoveredMedia());
        $restoredHero = $plan->topPlacesLeadVisual()
            ? null
            : $this->leadImage->restore($pass->document, $pass->input->leadImage);
        $this->mediaInserter->apply($pass->document, $plan, $restoredHero);
    }
}
