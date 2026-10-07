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
    public static function withShowArtworkFallback(
        ?DeclaredImageModel $image,
        ParsedMediaBundleModel $mediaBundle,
        ?DeclaredImageModel $showArtwork,
    ): self {
        if ($image === null && $mediaBundle->isEpisode()) {
            return new self($showArtwork, $mediaBundle);
        }

        return new self($image, $mediaBundle);
    }
}
