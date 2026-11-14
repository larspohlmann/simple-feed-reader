<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription\Pass;

use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\Pass\BulkSubscribePositions;
use PHPUnit\Framework\TestCase;

final class BulkSubscribePositionsTest extends TestCase
{
    public function testHandsOutConsecutivePositionsFromTheSeeds(): void
    {
        $positions = new BulkSubscribePositions(5, 3);

        self::assertSame(5, $positions->takeSubscriptionPosition());
        self::assertSame(6, $positions->takeSubscriptionPosition());
        self::assertSame(3, $positions->takeTagPosition());
        self::assertSame(4, $positions->takeTagPosition());
    }

    public function testAsksForATagsFirstFreePositionOnlyOnce(): void
    {
        $positions = new BulkSubscribePositions(0, 0);
        $tag = $this->tag('Tag');
        $asked = 0;
        $firstFree = static function () use (&$asked): int {
            ++$asked;

            return 7;
        };

        self::assertSame(7, $positions->takeFeedPositionIn($tag, $firstFree));
        self::assertSame(8, $positions->takeFeedPositionIn($tag, $firstFree));
        self::assertSame(1, $asked);
    }

    public function testNumbersEachTagOnItsOwn(): void
    {
        $positions = new BulkSubscribePositions(0, 0);
        $first = $this->tag('First');
        $second = $this->tag('Second');

        self::assertSame(0, $positions->takeFeedPositionIn($first, static fn (): int => 0));
        self::assertSame(4, $positions->takeFeedPositionIn($second, static fn (): int => 4));
        self::assertSame(1, $positions->takeFeedPositionIn($first, static fn (): int => 0));
    }

    private function tag(string $name): Tag
    {
        return new Tag(new User('positions@example.com', new \DateTimeImmutable('2026-07-01 00:00:00')), $name);
    }
}
