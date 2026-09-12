<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Reader\Media\Sibling\SiblingMediaExtender;

/**
 * Turns the media a page declares into the media the body cleaner inserts:
 * resolve each item's real stream location, then append the siblings that only
 * a verified item earns (#800). The two steps always run as one, so they live
 * behind one collaborator rather than two the extractor threads by hand.
 */
final readonly class BodyMediaResolver
{
    public function __construct(
        private StreamLocationResolver $streamLocations,
        private SiblingMediaExtender $siblings,
    ) {
    }

    public function resolveForBody(ArticleMedia $declared, string $pageHtml): ArticleMedia
    {
        return $this->siblings->extend($declared, $this->streamLocations->resolve($declared), $pageHtml);
    }
}
