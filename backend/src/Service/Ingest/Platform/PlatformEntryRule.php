<?php

declare(strict_types=1);

namespace App\Service\Ingest\Platform;

use App\Service\Parser\ParsedEntry;

interface PlatformEntryRule
{
    public function supports(ParsedEntry $entry): bool;

    public function apply(ParsedEntry $entry): ParsedEntry;
}
