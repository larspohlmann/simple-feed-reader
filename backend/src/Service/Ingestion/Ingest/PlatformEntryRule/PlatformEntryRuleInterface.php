<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest\PlatformEntryRule;

use App\Service\Ingestion\Parser\Model\ParsedEntryModel;

interface PlatformEntryRuleInterface
{
    public function supports(ParsedEntryModel $entry): bool;

    public function apply(ParsedEntryModel $entry): ParsedEntryModel;
}
