<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Service\Backup\Dto\BackupHeader;
use App\Service\Backup\Dto\BackupTotals;
use App\Service\Backup\Exception\InvalidBackupException;
use PHPUnit\Framework\TestCase;

final class BackupHeaderTest extends TestCase
{
    public function testANegativePartIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('Header part -1 is negative.');

        self::header(part: -1, parts: null)->requireCoherent();
    }

    public function testAnEntryPartThatDeclaresNeitherIsCoherent(): void
    {
        $header = self::header(part: 2, parts: null);

        self::assertSame($header, $header->requireCoherent());
    }

    public function testAnEntryPartDeclaringAPartsCountIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('Entry part 2 must not declare parts or totals.');

        self::header(part: 2, parts: 3)->requireCoherent();
    }

    public function testAnEntryPartDeclaringOnlyTotalsIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('Entry part 2 must not declare parts or totals.');

        self::header(part: 2, parts: null, totals: new BackupTotals(1, 1))->requireCoherent();
    }

    public function testAFoundationWithoutItsPartsCountIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('The foundation must declare its parts count and totals.');

        self::header(part: 0, parts: null)->requireCoherent();
    }

    public function testAFoundationWithAZeroPartsCountIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('The foundation must declare its parts count and totals.');

        self::header(part: 0, parts: 0, totals: new BackupTotals(1, 1))->requireCoherent();
    }

    public function testAFoundationWithoutTotalsIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('The foundation must declare its parts count and totals.');

        self::header(part: 0, parts: 2)->requireCoherent();
    }

    public function testACoherentFoundationIsCoherent(): void
    {
        $header = self::header(part: 0, parts: 2, totals: new BackupTotals(1, 1));

        self::assertSame($header, $header->requireCoherent());
    }

    public function testItMapsToTheSourceARestoreShows(): void
    {
        $createdAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $header = new BackupHeader(
            schemaVersion: 3,
            createdAt: $createdAt,
            sourceUrl: 'https://reader.example',
            sourceEmail: 'owner@example.test',
            backupId: 'backup-1202',
            part: 0,
            parts: 4,
            totals: new BackupTotals(1, 1),
        );

        $source = $header->toSource();

        self::assertSame('backup-1202', $source->backupId);
        self::assertSame(4, $source->parts);
        self::assertSame($createdAt, $source->createdAt);
        self::assertSame('https://reader.example', $source->sourceUrl);
        self::assertSame('owner@example.test', $source->sourceEmail);
    }

    private static function header(int $part, ?int $parts, ?BackupTotals $totals = null): BackupHeader
    {
        return new BackupHeader(
            schemaVersion: 3,
            createdAt: new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            sourceUrl: null,
            sourceEmail: null,
            backupId: 'backup-1167',
            part: $part,
            parts: $parts,
            totals: $totals,
        );
    }
}
