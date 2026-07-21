<?php

declare(strict_types=1);

namespace App\Service\Parser;

final readonly class ParsedFeed
{
    /**
     * @param list<ParsedEntry> $entries
     */
    public function __construct(
        public ?string $title,
        public ?string $siteUrl,
        public ?string $description,
        public array $entries,
    ) {
    }
}
