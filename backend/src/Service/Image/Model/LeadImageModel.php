<?php

declare(strict_types=1);

namespace App\Service\Image\Model;

use App\Entity\ImageRendition;

final readonly class LeadImageModel
{
    /** @param list<ImageRendition> $known */
    public function __construct(
        public string $url,
        public ImageDimensionsModel $dimensions,
        public array $known,
    ) {
    }

    /** @return list<int> */
    public function knownWidths(): array
    {
        return [
            $this->dimensions->width,
            ...array_map(static fn (ImageRendition $rendition): int => $rendition->width, $this->known),
        ];
    }
}
