<?php

declare(strict_types=1);

namespace App\Service\Html\Model;

/**
 * One srcset entry. A width descriptor states the file's pixel width; a density only ranks the candidates of its own
 * list and measures nothing outside it.
 */
final readonly class SrcsetCandidateModel
{
    public function __construct(
        public string $url,
        public ?int $width,
        public float $density,
    ) {
    }

    /**
     * True when this candidate is the larger file. A declared width decides,
     * and a candidate without one never displaces a measured incumbent; between
     * two unmeasured candidates the denser one is the larger file.
     */
    public function outmeasures(self $incumbent): bool
    {
        if ($this->width !== null) {
            return $incumbent->width === null || $this->width > $incumbent->width;
        }

        return $incumbent->width === null && $this->density > $incumbent->density;
    }

    public function rendition(): ImageRenditionModel
    {
        return new ImageRenditionModel($this->url, $this->width);
    }
}
