<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class StoredPostModel
{
    public function __construct(
        public int $entryId,
        public ?string $contentHtml,
        public ?string $authorUrl,
    ) {
    }
}
