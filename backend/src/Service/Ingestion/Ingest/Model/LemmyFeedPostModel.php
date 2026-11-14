<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest\Model;

final readonly class LemmyFeedPostModel
{
    public function __construct(
        public string $authorName,
        public string $profileUrl,
        public string $bodyHtml,
    ) {
    }
}
