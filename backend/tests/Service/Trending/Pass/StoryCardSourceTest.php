<?php

declare(strict_types=1);

namespace App\Tests\Service\Trending\Pass;

use App\Service\Trending\Model\RankedStoryModel;
use App\Service\Trending\Pass\StoryCardSource;
use PHPUnit\Framework\TestCase;

final class StoryCardSourceTest extends TestCase
{
    public function testAStoryWhoseRowsVanishedBetweenTheQueriesGetsNoCard(): void
    {
        $source = new StoryCardSource([5 => [101, 102]], [], [5 => 0]);

        self::assertSame([], $source->cardsOf([new RankedStoryModel(5, 3)]));
    }

    public function testAStoryWithoutAnyEntryIdsGetsNoCard(): void
    {
        $source = new StoryCardSource([], [], []);

        self::assertSame([], $source->cardsOf([new RankedStoryModel(7, 3)]));
    }
}
