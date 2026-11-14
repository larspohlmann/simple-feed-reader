<?php

declare(strict_types=1);

namespace App\Service\Catalog\Model;

final readonly class BundledCatalogSummaryModel
{
    private function __construct(
        public bool $available,
        public int $categories,
        public int $feeds,
    ) {
    }

    public static function of(ParsedCatalogModel $document): self
    {
        return new self(true, \count($document->categories), $document->feedCount());
    }

    public static function unavailable(): self
    {
        return new self(false, 0, 0);
    }
}
