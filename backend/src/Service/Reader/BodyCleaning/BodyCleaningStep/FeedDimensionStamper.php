<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\BodyCleaningPass;
use App\Service\Reader\Model\FeedMediaModel;
use Dom\Element;

/**
 * Stamps the feed-declared pixel size onto a reader image or video the feed enumerated (#914), so the browser
 * reserves its box instead of reflowing the article. A picture the reader already sized is left untouched.
 */
final readonly class FeedDimensionStamper implements BodyCleaningStepInterface
{
    public function cleanIn(BodyCleaningPass $pass): void
    {
        foreach ($pass->document->querySelectorAll('img[src], video[src]') as $element) {
            $this->stamp($element, $pass->input->feedMedia);
        }
    }

    private function stamp(Element $element, FeedMediaModel $feedMedia): void
    {
        if ($element->hasAttribute('width') || $element->hasAttribute('height')) {
            return;
        }
        $medium = $feedMedia->declaredMediumFor($element->getAttribute('src') ?? '');
        if ($medium === null || $medium->width === null || $medium->height === null) {
            return;
        }

        $element->setAttribute('width', (string) $medium->width);
        $element->setAttribute('height', (string) $medium->height);
    }
}
