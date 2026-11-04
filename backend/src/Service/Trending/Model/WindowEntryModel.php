<?php

declare(strict_types=1);

namespace App\Service\Trending\Model;

final readonly class WindowEntryModel
{
    public function __construct(
        public int $id,
        public int $feedId,
        public string $title,
        public bool $titleDerived,
        public ?string $summary,
        public \DateTimeImmutable $effectiveDate,
        public ?string $urlHash,
        public ?string $linkCardUrl,
    ) {
    }
}
