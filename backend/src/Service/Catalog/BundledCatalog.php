<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Service\Catalog\Exception\InvalidCatalogDocumentException;

/**
 * The catalog document this release ships. One owner for the path, shared by the
 * admin's one-click import and the console command.
 *
 * It is a starting point, not a source of truth: once imported, the database is
 * authoritative and the admin may edit it freely.
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

    public function document(): ParsedCatalog
    {
        if (!$this->isAvailable()) {
            throw new InvalidCatalogDocumentException(
                \sprintf('No readable catalog document at %s.', $this->path()),
            );
        }

        return $this->parser->parse((string) file_get_contents($this->path()));
    }
}
