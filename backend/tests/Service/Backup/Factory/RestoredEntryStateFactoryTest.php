<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Factory;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\User;
use App\Service\Backup\Dto\EntryStateLine;
use App\Service\Backup\Factory\RestoredEntryStateFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class RestoredEntryStateFactoryTest extends TestCase
{
    private const string RESTORED_AT = '2026-08-20 00:00:00';

    public function testAFavoriteKeptViewedLineRestoresAllThree(): void
    {
        $viewedAt = new \DateTimeImmutable('2026-08-10 10:00:00');

        $state = $this->factory()->create($this->user(), $this->entry(), $this->line(favorite: true, kept: true, viewedAt: $viewedAt));

        self::assertTrue($state->isFavorite());
        self::assertTrue($state->isKept());
        self::assertTrue($state->isViewed());
        self::assertSame($viewedAt, $state->getViewedAt());
    }

    public function testAPlainLineRestoresNoMark(): void
    {
        $state = $this->factory()->create($this->user(), $this->entry(), $this->line(favorite: false, kept: false, viewedAt: null, viewed: false));

        self::assertFalse($state->isFavorite());
        self::assertFalse($state->isKept());
        self::assertFalse($state->isViewed());
    }

    public function testAViewedLineWithoutItsTimeTakesTheRestoresOwn(): void
    {
        $state = $this->factory()->create($this->user(), $this->entry(), $this->line(favorite: false, kept: false, viewedAt: null));

        self::assertSame(
            (new \DateTimeImmutable(self::RESTORED_AT, new \DateTimeZone('UTC')))->format(\DATE_ATOM),
            $state->getViewedAt()?->format(\DATE_ATOM),
        );
    }

    private function factory(): RestoredEntryStateFactory
    {
        return new RestoredEntryStateFactory(new MockClock(self::RESTORED_AT, 'UTC'));
    }

    private function user(): User
    {
        return new User('state-restorer@example.test', new \DateTimeImmutable('2026-08-01'));
    }

    private function entry(): Entry
    {
        $at = new \DateTimeImmutable('2026-08-01 12:00:00');

        return new Entry(new Feed('https://restored.example/feed.xml'), 'guid-1', null, 'An entry', $at, $at);
    }

    private function line(
        bool $favorite,
        bool $kept,
        ?\DateTimeImmutable $viewedAt,
        bool $viewed = true,
    ): EntryStateLine {
        return new EntryStateLine(
            feedUrl: 'https://restored.example/feed.xml',
            guidHash: hash('sha256', 'guid-1'),
            isHidden: false,
            isFavorite: $favorite,
            isKept: $kept,
            hiddenAt: null,
            isViewed: $viewed,
            viewedAt: $viewedAt,
        );
    }
}
