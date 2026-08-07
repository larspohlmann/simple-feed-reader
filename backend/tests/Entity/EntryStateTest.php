<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class EntryStateTest extends TestCase
{
    public function testAFreshStateIsNotViewed(): void
    {
        $state = $this->makeState();

        self::assertFalse($state->isViewed());
        self::assertNull($state->getViewedAt());
    }

    public function testMarkViewedSetsFlagAndTimestamp(): void
    {
        $state = $this->makeState();
        $firstOpen = new \DateTimeImmutable('2026-08-07T10:00:00Z');

        $state->markViewed($firstOpen);

        self::assertTrue($state->isViewed());
        self::assertSame($firstOpen, $state->getViewedAt());
    }

    public function testMarkViewedKeepsTheFirstTimestamp(): void
    {
        $state = $this->makeState();
        $firstOpen = new \DateTimeImmutable('2026-08-07T10:00:00Z');
        $laterOpen = new \DateTimeImmutable('2026-08-07T11:00:00Z');

        $state->markViewed($firstOpen);
        $state->markViewed($laterOpen);

        self::assertTrue($state->isViewed());
        self::assertSame($firstOpen, $state->getViewedAt());
    }

    private function makeState(): EntryState
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $feed = new Feed('https://example.com/feed.xml');
        $entry = new Entry(
            $feed,
            'guid-1',
            'https://example.com/1',
            'Post',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );

        return new EntryState($user, $entry);
    }
}
