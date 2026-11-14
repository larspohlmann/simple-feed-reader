<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit\Model;

/** One entry the audit will run the reader pipeline over. */
final readonly class SampledEntryModel
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
