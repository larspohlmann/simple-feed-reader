<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Teaser\Model;

use App\Service\Reader\Media\Model\MediaKind;

/**
 * An inline media teaser the extraction reduced to a bare thumbnail: a player with its own still, a headline and a
 * link. `posterUrl` matches the orphan <img> the body kept back to this player, so the rebuild lands in place.
 */
final readonly class TeaserPlayerModel
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
