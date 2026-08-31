<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

final readonly class EmbedTarget
{
    public function __construct(
        public string $url,
        public ?string $posterUrl,
        public string $label,
    ) {
    }
}
