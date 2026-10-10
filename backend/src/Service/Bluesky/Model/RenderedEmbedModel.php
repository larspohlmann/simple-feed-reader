<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Model;

use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Parser\Model\ParsedMediumModel;

/** A post's embed as HTML to follow its body, with the picture and media it brings and its link card's URL. */
final readonly class RenderedEmbedModel
{
    /** @param list<ParsedMediumModel> $media */
    public function __construct(
        public string $html,
        public ?DeclaredImageModel $leadImage = null,
        public array $media = [],
        public ?string $linkCardUrl = null,
    ) {
    }

    public function followedBy(self $next): self
    {
        return new self(
            $this->html . $next->html,
            $this->leadImage ?? $next->leadImage,
            [...$this->media, ...$next->media],
            $this->linkCardUrl ?? $next->linkCardUrl,
        );
    }
}
