<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed\Model;

/**
 * Which recommendation annotations a for-you page carries. One switch: the score renders beside its reason, so a reader
 * who hides why an article was picked hides how strongly too. Debug mode reaches neither.
 */
final readonly class FeedAnnotationVisibilityModel
{
    public function __construct(
        public bool $showExplanation,
    ) {
    }
}
