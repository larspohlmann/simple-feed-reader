<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Enum\FeedLanguage;

final readonly class WindowEntryModel
{
    public function __construct(
        public int $id,
        public int $feedId,
        public ?FeedLanguage $language,
        public string $title,
        public bool $titleDerived,
        public ?string $summary,
        public \DateTimeImmutable $effectiveDate,
        public EntryLinksModel $links,
        public ?int $canonicalItemId = null,
    ) {
    }
}
