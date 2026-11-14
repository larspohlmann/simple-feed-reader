<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Pass;

use App\Repository\EntryListRow;
use App\Repository\EntryListRowMarks;
use App\Repository\EntryListRowSubscription;
use App\Service\Curation\Trending\Model\RankedStoryModel;
use App\Service\Curation\Trending\Pass\StoryCardSource;
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
            new EntryListRowMarks(isHidden: false, isFavorite: false, isKept: false, viewedAt: null, sharedAt: null),
            null,
        );
        $source = new StoryCardSource(
            [5 => [101, 102], 6 => [$entry->requireId()]],
            [$entry->requireId() => $row],
            [6 => 0],
        );

        $lastSeen = new \DateTimeImmutable('2031-05-14 10:00:00');
        $wider = new RankedStoryModel(6, 4, $lastSeen);

        $cards = $source->cardsOf([new RankedStoryModel(5, 3, $lastSeen), $wider]);

        self::assertCount(1, $cards);
        self::assertSame($entry, $cards[0]->lead->entry);
        self::assertSame($wider, $cards[0]->story);
        self::assertTrue($cards[0]->viewed);
    }

    public function testAStoryWithoutAnyEntryIdsGetsNoCard(): void
    {
        self::assertSame([], (new StoryCardSource([], [], []))->cardsOf([
            new RankedStoryModel(7, 3, new \DateTimeImmutable('2031-05-14 10:00:00')),
        ]));
    }
}
