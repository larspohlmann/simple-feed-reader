<?php

declare(strict_types=1);

namespace App\Service\Ingest\PlatformEntryRule;

use App\Service\Parser\ParsedEntry;

interface PlatformEntryRuleInterface
{
    public function supports(ParsedEntry $entry): bool;

    public function apply(ParsedEntry $entry): ParsedEntry;
}
