<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Which per-entry recommendation annotations a for-you feed page carries.
 *
 * One switch, because the reason and its score are one explanation: the score
 * renders beside the reason it belongs to, so a reader who opted out of why an
 * article was picked hasn't opted out of how strongly (#576).
 *
 * #342 gated both behind the debug setting; #541 split them onto independent
 * axes so the reason follows the reader's preference while the score stayed
 * with debug. That left debug as a second, unrelated way to reveal half the
 * explanation, which this supersedes — debug now keeps only the per-run call
 * logs.
 */
final readonly class FeedAnnotationVisibility
{
    public function __construct(
        public bool $showExplanation,
    ) {
    }
}
