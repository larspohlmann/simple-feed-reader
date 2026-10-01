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
        return $this->width !== null
            && $this->height !== null
            && (new ImageDimensionsModel($this->width, $this->height))->isBeacon();
    }
}
