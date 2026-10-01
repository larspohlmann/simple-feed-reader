<?php

declare(strict_types=1);

namespace App\Service\Image\Model;

use App\Entity\ImageRendition;

/**
 * An image URL with the width and height its source declared, each independently nullable (most feeds declare
 * neither; the Guardian declares width only). Null means unknown: reserve no space rather than guess.
 */
final readonly class DeclaredImageModel
{
    /** @param list<ImageRendition> $renditions the same picture at each declared width */
    public function __construct(
        public string $url,
        public ?int $width = null,
        public ?int $height = null,
        public array $renditions = [],
    ) {
    }

    public function declaresBeacon(): bool
    {
        return $this->declaredDimensions()?->isBeacon() ?? false;
    }

    /** This image, adding the renditions of each other image that shows the same picture in the same crop. */
    public function joinedWith(self ...$others): self
    {
        $renditions = $this->renditions;
        foreach ($others as $other) {
            if ($other !== $this && $this->showsSamePictureAs($other)) {
                $renditions = [...$renditions, ...$other->renditions];
            }
        }

        return new self($this->url, $this->width, $this->height, $renditions);
    }

    private function showsSamePictureAs(self $other): bool
    {
        return !$this->declaresAnotherCropThan($other)
            && ImageIdentityModel::fromUrl($this->url)->isRenditionOf(ImageIdentityModel::fromUrl($other->url));
    }

    private function declaresAnotherCropThan(self $other): bool
    {
        $dimensions = $this->declaredDimensions();
        $otherDimensions = $other->declaredDimensions();

        return $dimensions !== null && $otherDimensions !== null && $dimensions->isAnotherCropThan($otherDimensions);
    }

    private function declaredDimensions(): ?ImageDimensionsModel
    {
        if ($this->width === null || $this->height === null) {
            return null;
        }

        return new ImageDimensionsModel($this->width, $this->height);
    }
}
