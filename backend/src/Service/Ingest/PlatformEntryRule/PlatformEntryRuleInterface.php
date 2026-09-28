<?php

declare(strict_types=1);

namespace App\Service\Ingest\PlatformEntryRule;

use App\Service\Parser\Model\ParsedEntryModel;

interface PlatformEntryRuleInterface
{
    public function supports(ParsedEntryModel $entry): bool;

    public function apply(ParsedEntryModel $entry): ParsedEntryModel;
}
