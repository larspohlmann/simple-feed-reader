<?php

declare(strict_types=1);

namespace App\Service\Html;

/**
 * One entry of a srcset list: a URL and the descriptor that sizes it. A width
 * descriptor states the file's pixel width. A density descriptor states only
 * the ratio to the layout width, so it ranks the candidates of one list but
 * measures nothing against the world outside that list.
 */
final readonly class SrcsetCandidate
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

    public function rendition(): ImageRendition
    {
        return new ImageRendition($this->url, $this->width);
    }
}
