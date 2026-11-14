<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Backup\Dto;

use App\Service\Operations\Backup\Dto\FooterLine;
use App\Service\Operations\Backup\Support\BackupSchema;
use PHPUnit\Framework\TestCase;

final class FooterLineTest extends TestCase
{
    public function testAnAbsentKindCountsZeroAndEveryKindKeepsItsPlace(): void
    {
        self::assertSame(
            '{"kind":"footer","counts":{"tag":0,"savedSearch":0,"feed":0,"subscription":0,"entry":4,'
            . '"entryState":2,"entryShare":0}}',
            FooterLine::of([BackupSchema::KIND_ENTRY_STATE => 2, BackupSchema::KIND_ENTRY => 4])->toBackupLine(),
        );
    }
}
