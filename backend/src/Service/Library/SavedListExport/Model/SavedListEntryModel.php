<?php

declare(strict_types=1);

namespace App\Service\Library\SavedListExport\Model;

final readonly class SavedListEntryModel
{
    /**
     * @param list<string> $labelNames alphabetical
     */
    public function __construct(
        public string $guid,
        public ?string $url,
        public string $title,
        public ?string $author,
        public string $contentHtml,
        public \DateTimeImmutable $effectiveDate,
        public SavedListOriginModel $origin,
        public array $labelNames,
    ) {
    }
}
