<?php

declare(strict_types=1);

// Fixtures for PersistenceKnowsNoServiceRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Repository\Fixtures;

use App\Service\Backup\Dto\EntryLine;

final readonly class EntryBatchInserter
{
    public function line(): string
    {
        return EntryLine::class;
    }
}
