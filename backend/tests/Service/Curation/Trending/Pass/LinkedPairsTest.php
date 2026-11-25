<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Pass;

use App\Service\Curation\Trending\Model\StoryLinkModel;
use App\Service\Curation\Trending\Pass\LinkedPairs;
use PHPUnit\Framework\TestCase;

final class LinkedPairsTest extends TestCase
{
    public function testALinkCountsInBothDirections(): void
    {
        $linkedPairs = LinkedPairs::fromLinks([$this->link(1, 2)]);

        $this->assertSame(1, $linkedPairs->countBetween([1], [2]));
        $this->assertSame(1, $linkedPairs->countBetween([2], [1]));
    }

    public function testUnlinkedEntriesCountNothing(): void
    {
        $linkedPairs = LinkedPairs::fromLinks([$this->link(1, 2)]);

        $this->assertSame(0, $linkedPairs->countBetween([1], [3]));
        $this->assertSame(0, $linkedPairs->countBetween([4], [2]));
    }

    public function testEveryLinkedPairBetweenTheListsCounts(): void
    {
        $linkedPairs = LinkedPairs::fromLinks([$this->link(1, 3), $this->link(1, 4), $this->link(2, 4)]);

        $this->assertSame(3, $linkedPairs->countBetween([1, 2], [3, 4]));
    }

    public function testADuplicateLinkCountsOnce(): void
    {
        $linkedPairs = LinkedPairs::fromLinks([$this->link(1, 2), $this->link(1, 2)]);

        $this->assertSame(1, $linkedPairs->countBetween([1], [2]));
    }

    private function link(int $oneEntryId, int $otherEntryId): StoryLinkModel
    {
        return new StoryLinkModel($oneEntryId, $otherEntryId, 1.0);
    }
}
