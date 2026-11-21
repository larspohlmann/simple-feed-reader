<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest\Model;

final readonly class CrossPostModel
{
    public function __construct(
        public ?string $authorUrl,
        public ?string $normalizedLinkedUrl,
        public string $title,
        public ?string $bodyHtml,
    ) {
    }
}
