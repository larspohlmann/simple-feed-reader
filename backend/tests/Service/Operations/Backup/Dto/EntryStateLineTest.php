<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Backup\Dto;

use App\Entity\EntryState;
use App\Service\Operations\Backup\Dto\EntryStateLine;
use App\Tests\Support\BackupLineRecords;
use PHPUnit\Framework\TestCase;

final class EntryStateLineTest extends TestCase
{
    public function testItWritesAMarkedStateWithItsInstantsInUtc(): void
    {
        $state = new EntryState(BackupLineRecords::user(), BackupLineRecords::entry());
        $state->hide(new \DateTimeImmutable('2026-08-04T10:00:00+02:00'));
        $state->markFavorite();
        $state->markViewed(new \DateTimeImmutable('2026-08-04T08:30:00Z'));

        self::assertSame(
            sprintf(
                '{"kind":"entryState","feedUrl":"https://one.example/rss","guidHash":"%s","isHidden":true,'
                . '"isFavorite":true,"isKept":false,"hiddenAt":"2026-08-04T08:00:00+00:00","isViewed":true,'
                . '"viewedAt":"2026-08-04T08:30:00+00:00"}',
                $state->getEntry()->getGuidHash(),
            ),
            EntryStateLine::of($state, 'https://one.example/rss')->toBackupLine(),
        );
    }

    public function testAKeptStateWritesNoInstants(): void
    {
        $state = new EntryState(BackupLineRecords::user(), BackupLineRecords::entry());
        $state->markKept();

        self::assertSame(
            sprintf(
                '{"kind":"entryState","feedUrl":"https://one.example/rss","guidHash":"%s","isHidden":false,'
                . '"isFavorite":false,"isKept":true,"hiddenAt":null,"isViewed":false,"viewedAt":null}',
                $state->getEntry()->getGuidHash(),
            ),
            EntryStateLine::of($state, 'https://one.example/rss')->toBackupLine(),
        );
    }
}
