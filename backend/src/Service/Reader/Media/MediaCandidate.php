<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

/**
 * One piece of media the source page offers for this article. `posterUrl` is the
 * still a video shows before playback; `label` is the link text an embed falls
 * back to when the provider has no cheap poster.
 */
final readonly class MediaCandidate
{
    public function __construct(
        public MediaKind $kind,
        public string $url,
        public ?string $posterUrl = null,
        public ?string $label = null,
    ) {
    }
}
