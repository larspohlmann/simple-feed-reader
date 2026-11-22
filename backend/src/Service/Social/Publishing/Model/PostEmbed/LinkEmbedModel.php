<?php

declare(strict_types=1);

namespace App\Service\Social\Publishing\Model\PostEmbed;

final readonly class LinkEmbedModel implements PostEmbedModelInterface
{
    public function __construct(
        public string $url,
        public string $title,
    ) {
    }
}
