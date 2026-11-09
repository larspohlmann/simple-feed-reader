<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Entity\SavedSearch;
use App\Service\Backup\Dto\SavedSearchLine;
use App\Tests\Support\BackupLineRecords;
use PHPUnit\Framework\TestCase;

final class SavedSearchLineTest extends TestCase
{
    public function testItWritesItsLineInTheBackupFormat(): void
    {
        $savedSearch = new SavedSearch(BackupLineRecords::user(), 'climate', true, false);
        $savedSearch->setPosition(4);

        self::assertSame(
            '{"kind":"savedSearch","term":"climate","wholeWord":true,"phrase":false,"position":4}',
            SavedSearchLine::of($savedSearch)->toBackupLine(),
        );
    }
}
