<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * Whether a Media RSS element points at an image — the question
 * ItemImageExtractor asks when it keeps only the visual candidates. The full
 * image/audio/video/other decision lives in FeedMediaClassifier; this is the
 * image half of it, named for the one caller that needs exactly that.
 */
final class MediaImageClassifier
{
    public static function isImage(\DOMElement $element): bool
    {
        return FeedMediaClassifier::kind($element) === FeedMediaKind::Image;
    }
}
