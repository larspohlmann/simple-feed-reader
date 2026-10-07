<?php

declare(strict_types=1);

namespace App\Service\Parser\Model;

use App\Service\Image\Model\DeclaredImageModel;

final readonly class ParsedEntryMediaModel
{
    public function __construct(
        public ?DeclaredImageModel $image = null,
        public ?ParsedMediaBundleModel $mediaBundle = null,
    ) {
    }

    /** An episode without artwork of its own shows the show's; any other item stays without an image. */
    public function withShowArtwork(?DeclaredImageModel $showArtwork): self
    {
        if ($this->image !== null || !($this->mediaBundle?->isEpisode() ?? false)) {
            return $this;
        }

        return new self($showArtwork, $this->mediaBundle);
    }
}
