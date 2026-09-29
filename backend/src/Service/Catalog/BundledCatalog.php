<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Service\Catalog\Exception\InvalidCatalogDocumentException;
use App\Service\Catalog\Model\BundledCatalogSummaryModel;
use App\Service\Catalog\Model\ParsedCatalogModel;

/**
 * The catalog document this release ships, shared by the admin's one-click import and the console command. Once
 * imported, the database is authoritative.
 */
final readonly class BundledCatalog
{
    public function __construct(
        private CatalogDocument $parser,
        private string $projectDir,
    ) {
    }

    public function path(): string
    {
        return $this->projectDir . '/resources/catalog/catalog.opml';
    }

    public function isAvailable(): bool
    {
        return is_file($this->path()) && is_readable($this->path());
    }

    public function document(): ParsedCatalogModel
    {
        if (!$this->isAvailable()) {
            throw new InvalidCatalogDocumentException(
                \sprintf('No readable catalog document at %s.', $this->path()),
            );
        }

        return $this->parser->parse((string) file_get_contents($this->path()));
    }

    public function summary(): BundledCatalogSummaryModel
    {
        try {
            return BundledCatalogSummaryModel::of($this->document());
        } catch (InvalidCatalogDocumentException) {
            // Missing or corrupt reads as unavailable, not a 500: the admin can still upload a file.
            return BundledCatalogSummaryModel::unavailable();
        }
    }
}
