<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Pass;

use App\Entity\Headline;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Social\Social\Model\PlacedSelfReplyModel;
use App\Service\Social\Social\Model\TimelineSelfReplyModel;
use App\Service\Social\Social\Pass\SelfThreadPlacement;
use PHPUnit\Framework\TestCase;

final class SelfThreadPlacementTest extends TestCase
{
    public function testAChainFromTheRootTakesPositionsOneToFour(): void
    {
        $placement = new SelfThreadPlacement('r', ['r' => 0]);

        $placement->place([
            $this->reply('a', 'r'),
            $this->reply('b', 'a'),
            $this->reply('c', 'b'),
            $this->reply('d', 'c'),
        ]);

        self::assertSame([['r', 1, 'a'], ['r', 2, 'b'], ['r', 3, 'c'], ['r', 4, 'd']], $this->placedOf($placement));
        self::assertSame([], $this->unplacedOf($placement));
        self::assertSame(4, $placement->replyCount());
    }

    public function testRepliesPastTheFourthAreCountedNotPlacedNorListed(): void
    {
        $placement = new SelfThreadPlacement('r', ['r' => 0]);

        $placement->place([
            $this->reply('a', 'r'),
            $this->reply('b', 'a'),
            $this->reply('c', 'b'),
            $this->reply('d', 'c'),
            $this->reply('e', 'd'),
            $this->reply('f', 'e'),
        ]);

        self::assertCount(4, $placement->placed());
        self::assertSame([], $this->unplacedOf($placement));
        self::assertSame(6, $placement->replyCount());
    }

    public function testNoJoinedReplyCountsNone(): void
    {
        $placement = new SelfThreadPlacement('r', ['r' => 0]);

        $placement->place([$this->reply('b', 'a')]);

        self::assertSame(0, $placement->replyCount());
    }

    public function testRepliesArriveInAnyOrder(): void
    {
        $placement = new SelfThreadPlacement('r', ['r' => 0]);

        $placement->place([$this->reply('c', 'b'), $this->reply('a', 'r'), $this->reply('b', 'a')]);

        $positions = [];
        foreach ($placement->placed() as $placed) {
            $positions[(string) $placed->post->string('uri')] = $placed->position;
        }
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 3], $positions);
    }

    public function testAStoredThreadPostIsContinued(): void
    {
        $placement = new SelfThreadPlacement('r', ['r' => 0, 'a' => 1]);

        $placement->place([$this->reply('a', 'r'), $this->reply('b', 'a')]);

        self::assertSame([['r', 2, 'b']], $this->placedOf($placement));
        self::assertSame(2, $placement->replyCount());
    }

    public function testASecondBranchIsDropped(): void
    {
        $placement = new SelfThreadPlacement('r', ['r' => 0]);

        $placement->place([$this->reply('a', 'r'), $this->reply('x', 'r'), $this->reply('y', 'x')]);

        self::assertSame([['r', 1, 'a']], $this->placedOf($placement));
        self::assertSame([], $this->unplacedOf($placement));
        self::assertSame(1, $placement->replyCount());
    }

    public function testAReplyWhoseParentNeverJoinsStaysAnEntry(): void
    {
        $placement = new SelfThreadPlacement('r', ['r' => 0]);

        $placement->place([$this->reply('b', 'a')]);

        self::assertSame([], $placement->placed());
        self::assertSame(['b'], $this->unplacedOf($placement));
    }

    private function reply(string $url, string $parentUrl): TimelineSelfReplyModel
    {
        $entry = new ParsedEntryModel($url, $url, Headline::fromFeed($url), null, null, null, null);

        return new TimelineSelfReplyModel('r', $parentUrl, $entry, JsonNodeModel::fromValue(['uri' => $url]));
    }

    /** @return list<array{string, int, string}> */
    private function placedOf(SelfThreadPlacement $placement): array
    {
        return array_map(
            static fn (PlacedSelfReplyModel $placed): array => [
                $placed->rootGuid,
                $placed->position,
                (string) $placed->post->string('uri'),
            ],
            $placement->placed(),
        );
    }

    /** @return list<string> */
    private function unplacedOf(SelfThreadPlacement $placement): array
    {
        return array_map(
            static fn (ParsedEntryModel $entry): string => $entry->guid,
            $placement->unplaced(),
        );
    }
}
