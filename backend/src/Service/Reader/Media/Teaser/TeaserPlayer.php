<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Teaser;

use App\Service\Reader\Media\MediaKind;

/**
 * An inline media teaser the extraction reduced to a bare thumbnail: a player
 * (a video or audio clip) that carried its own still, a headline and a link to
 * a related piece. `posterUrl` is the still by which the orphan <img> the body
 * kept is matched back to this player, so the reconstruction lands in place.
 */
final readonly class TeaserPlayer
{
    public function __construct(
        public MediaKind $kind,
        public string $mediaUrl,
        public string $posterUrl,
        public ?string $caption,
        public ?string $linkUrl,
    ) {
    }
}
