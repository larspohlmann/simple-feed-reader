<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Which per-entry recommendation annotations a for-you feed page carries.
 *
 * The two annotations vary on independent axes (#541): the human-readable
 * `recommendationReason` follows the user's own "show reasons" preference,
 * while the numeric `recommendationScore` stays behind the debug setting.
 * This supersedes #342, where a single debug flag gated both together.
 */
final readonly class FeedAnnotationVisibility
{
    public function __construct(
        public bool $showReasons,
        public bool $showScores,
    ) {
    }
}
