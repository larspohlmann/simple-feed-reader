<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning;

use App\Service\Reader\Media\ArticleMedia;
use Dom\HTMLDocument;

/**
 * One clean of one body: the document every step mutates, what the extraction knows about the article, and the
 * one fact a step records for a later one.
 */
final class BodyCleaningPass
{
    private bool $embedsRecoveredInBody = false;

    public function __construct(
        public readonly HTMLDocument $document,
        public readonly BodyCleaningInput $input,
    ) {
    }

    public function recordEmbedsRecoveredInBody(): void
    {
        $this->embedsRecoveredInBody = true;
    }

    /** The page's media to place: no embed once the body recovered its own, so a video never shows twice. */
    public function discoveredMedia(): ArticleMedia
    {
        return $this->embedsRecoveredInBody ? $this->input->media->withoutEmbeds() : $this->input->media;
    }
}
