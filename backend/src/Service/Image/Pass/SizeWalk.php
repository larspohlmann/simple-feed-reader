<?php

declare(strict_types=1);

namespace App\Service\Image\Pass;

use App\Entity\ImageRendition;
use App\Service\Image\Model\ImageDimensionsModel;
use App\Service\Image\Model\LeadImageModel;
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

    public static function overLead(LeadImageModel $lead): self
    {
        return new self($lead->url, $lead->dimensions, $lead->knownWidths());
    }

    public function candidateFor(int $width): ?string
    {
        if ($width >= $this->basis->width || !$this->bandIsUncovered($width)) {
            return null;
        }
        $height = (int) round($this->basis->height * $width / $this->basis->width);
        $candidate = SizedImageUrl::atSize($this->basisUrl, $width, $height);

        return $candidate === $this->basisUrl ? null : $candidate;
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

    private function bandIsUncovered(int $width): bool
    {
        return \in_array(RenditionTargets::bandOf($width), RenditionTargets::uncovered($this->knownWidths), true);
    }
}
