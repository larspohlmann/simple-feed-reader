<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Service\Parser\Model\FeedMediaKind;

/** The image half of FeedMediaClassifier's decision, for ItemImageExtractor's visual candidates. */
final class MediaImageClassifier
{
    public static function isImage(\DOMElement $element): bool
    {
        return FeedMediaClassifier::kind($element) === FeedMediaKind::Image;
    }

    private function __construct()
    {
    }
}
