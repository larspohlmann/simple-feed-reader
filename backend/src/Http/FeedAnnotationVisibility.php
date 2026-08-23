<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Which per-entry recommendation annotations a for-you feed page carries.
 *
 * One switch, because the reason and its score are one explanation: the score
 * is rendered beside the reason it belongs to, so a reader who asked not to be
 * told why an article was picked has not asked to be told how strongly (#576).
 *
 * #342 gated both behind the debug setting. #541 split them onto independent
 * axes so the reason could follow the reader's own preference while the score
 * stayed with debug. That left debug as a second, unrelated way to reveal half
 * the explanation, which is what this supersedes — debug now keeps the per-run
 * call logs and nothing else.
 */
final readonly class FeedAnnotationVisibility
{
    public function __construct(
        public bool $showExplanation,
    ) {
    }
}
