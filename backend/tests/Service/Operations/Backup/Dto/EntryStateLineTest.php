<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Backup\Dto;

use App\Entity\EntryState;
use App\Service\Operations\Backup\Dto\EntryStateLine;
use App\Service\Operations\Backup\Exception\InvalidBackupException;
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
                . '"viewedAt":"2026-08-04T08:30:00+00:00","likedAt":null,"labels":["Read later","Work"]}',
                $state->getEntry()->getGuidHash(),
            ),
            EntryStateLine::fromState($state, 'https://one.example/rss', ['Read later', 'Work'])->toBackupLine(),
        );
    }

    public function testAKeptStateWritesNoInstants(): void
    {
        $state = new EntryState(BackupLineRecords::user(), BackupLineRecords::entry());
        $state->markKept();

        self::assertSame(
            sprintf(
                '{"kind":"entryState","feedUrl":"https://one.example/rss","guidHash":"%s","isHidden":false,'
                . '"isFavorite":false,"isKept":true,"hiddenAt":null,"isViewed":false,"viewedAt":null,'
                . '"likedAt":null,"labels":[]}',
                $state->getEntry()->getGuidHash(),
            ),
            EntryStateLine::fromState($state, 'https://one.example/rss', [])->toBackupLine(),
        );
    }

    public function testALikedStateRoundTripsItsInstant(): void
    {
        $state = new EntryState(BackupLineRecords::user(), BackupLineRecords::entry());
        $state->markLiked(new \DateTimeImmutable('2026-11-27T10:00:00+01:00'));

        $written = EntryStateLine::fromState($state, 'https://one.example/rss', [])->toBackupLine();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($written, true, flags: JSON_THROW_ON_ERROR);
        $line = EntryStateLine::fromLine($decoded);

        self::assertStringContainsString('"likedAt":"2026-11-27T09:00:00+00:00"', $written);
        self::assertEquals(new \DateTimeImmutable('2026-11-27T09:00:00Z'), $line->likedAt);
    }

    public function testALineWrittenBeforeLikesHaveNoLikedAt(): void
    {
        self::assertNull(EntryStateLine::fromLine(self::lineWith([]))->likedAt);
    }

    public function testLineWithoutLabelsRestores(): void
    {
        $line = EntryStateLine::fromLine(self::lineWith([]));

        self::assertSame([], $line->labels);
        self::assertTrue($line->isFavorite);
    }

    public function testALineReadsItsLabelNames(): void
    {
        self::assertSame(['Work', 'Read later'], EntryStateLine::fromLine(self::lineWith([
            'labels' => ['Work', 'Read later'],
        ]))->labels);
    }

    public function testALabelNameARestoreCannotHoldIsDropped(): void
    {
        self::assertSame(['Work'], EntryStateLine::fromLine(self::lineWith([
            'labels' => ['  Work  ', '   ', str_repeat('x', 61)],
        ]))->labels);
    }

    public function testALabelListThatIsNotAListOfNamesIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);

        EntryStateLine::fromLine(self::lineWith(['labels' => ['Work', 7]]));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function lineWith(array $extra): array
    {
        return [
            'kind' => 'entryState',
            'feedUrl' => 'https://one.example/rss',
            'guidHash' => 'hash',
            'isHidden' => false,
            'isFavorite' => true,
            'isKept' => false,
            'hiddenAt' => null,
            'isViewed' => false,
            'viewedAt' => null,
            ...$extra,
        ];
    }
}
