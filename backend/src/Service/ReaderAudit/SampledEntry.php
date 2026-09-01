<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/** One entry the audit will run the reader pipeline over. */
final readonly class SampledEntry
{
    public function __construct(
        public int $entryId,
        public int $subscriptionId,
        public int $feedId,
        public string $feedTitle,
        public string $title,
        public string $url,
        public ?string $feedContentHtml,
        public bool $hasFeedImage,
        public ?string $author = null,
    ) {
    }
}
