<?php

declare(strict_types=1);

namespace App\Service\Image\Pass;

use App\Entity\ImageRendition;
use App\Service\Image\Model\ImageDimensionsModel;
use App\Service\Image\Support\RenditionTargets;
use App\Service\Image\Support\SizedImageUrl;

/** One lead's walk through the guessed widths: the picture sizes are named after, and the bands found so far. */
final class SizeWalk
{
    /** @var list<ImageRendition> */
    private array $found = [];

    /** @param list<int> $knownWidths */
    public function __construct(
        private readonly string $basisUrl,
        private readonly ImageDimensionsModel $basis,
        private array $knownWidths,
    ) {
    }

    public function wants(int $width): bool
    {
        return $width < $this->basis->width
            && \in_array(RenditionTargets::bandOf($width), RenditionTargets::uncovered($this->knownWidths), true);
    }

    public function candidateAt(int $width): string
    {
        $height = (int) round($this->basis->height * $width / $this->basis->width);

        return SizedImageUrl::atSize($this->basisUrl, $width, $height);
    }

    public function keep(ImageRendition $rung): void
    {
        $this->found[] = $rung;
        $this->knownWidths[] = $rung->width;
    }

    /** @return list<ImageRendition> */
    public function found(): array
    {
        return $this->found;
    }
}
