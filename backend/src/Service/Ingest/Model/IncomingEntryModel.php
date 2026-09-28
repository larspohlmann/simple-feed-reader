<?php

declare(strict_types=1);

namespace App\Service\Ingest\Model;

use App\Service\Parser\Model\ParsedEntryModel;

/** A parsed item with the two identities that dedup and the stored row both use, hashed once. */
final readonly class IncomingEntryModel
{
    public function __construct(
        public ParsedEntryModel $parsed,
        public string $guidHash,
        public ?string $urlHash,
    ) {
    }
}
