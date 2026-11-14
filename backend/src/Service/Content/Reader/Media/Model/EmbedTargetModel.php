<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Model;

final readonly class EmbedTargetModel
{
    public function __construct(
        public string $url,
        public ?string $posterUrl,
        public string $label,
    ) {
    }
}
