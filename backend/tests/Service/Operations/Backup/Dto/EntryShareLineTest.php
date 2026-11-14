<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Backup\Dto;

use App\Service\Operations\Backup\Dto\EntryShareLine;
use App\Tests\Support\BackupLineRecords;
use PHPUnit\Framework\TestCase;

final class EntryShareLineTest extends TestCase
{
    public function testItWritesTheShareInstantInUtc(): void
    {
        $entry = BackupLineRecords::entry();
        $sharedAt = new \DateTimeImmutable('2026-08-05T09:15:00+02:00');

        self::assertSame(
            sprintf(
                '{"kind":"entryShare","feedUrl":"https://one.example/rss","guidHash":"%s",'
                . '"sharedAt":"2026-08-05T07:15:00+00:00"}',
                $entry->getGuidHash(),
            ),
            EntryShareLine::of($entry, $sharedAt, 'https://one.example/rss')->toBackupLine(),
        );
    }
}
