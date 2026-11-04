<?php

declare(strict_types=1);

namespace App\Tests\Service\Trending\Pass;

use App\Repository\EntryListRow;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
use App\Service\Trending\Model\RankedStoryModel;
use App\Service\Trending\Pass\StoryCardSource;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;

final class StoryCardSourceTest extends DbTestCase
{
    public function testAStoryWhoseRowsVanishedBetweenTheQueriesGetsNoCardAndTheNextOneStillDoes(): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $entry = $fixtures->entry($fixtures->feed('alpha'), 'Alpha', '2031-05-14 08:00:00');
        $fixtures->flush();
        $row = new EntryListRow(
            $entry,
            new EntryListRowSubscription(1, 'Alpha'),
            false,
            false,
            false,
            new EntryListRowViewState(false, null, null),
            null,
        );
        $source = new StoryCardSource(
            [5 => [101, 102], 6 => [$entry->requireId()]],
            [$entry->requireId() => $row],
            [6 => 0],
            [],
        );

        $cards = $source->cardsOf([new RankedStoryModel(5, 3), new RankedStoryModel(6, 4)]);

        self::assertCount(1, $cards);
        self::assertSame($entry, $cards[0]->lead->entry);
        self::assertSame(4, $cards[0]->outletCount);
        self::assertTrue($cards[0]->viewed);
    }

    public function testAStoryWithoutAnyEntryIdsGetsNoCard(): void
    {
        self::assertSame([], (new StoryCardSource([], [], [], []))->cardsOf([new RankedStoryModel(7, 3)]));
    }
}
