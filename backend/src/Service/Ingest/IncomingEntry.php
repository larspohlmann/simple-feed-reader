<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Service\Parser\ParsedEntry;

/** A parsed item with the two identities that dedup and the stored row both use, hashed once. */
final readonly class IncomingEntry
{
    public function __construct(
        public ParsedEntry $parsed,
        public string $guidHash,
        public ?string $urlHash,
    ) {
    }
}
