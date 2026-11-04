<?php

declare(strict_types=1);

namespace App\Tests\Service\Trending\Model;

use App\Service\Trending\Model\StoryLinkModel;
use PHPUnit\Framework\TestCase;

final class StoryLinkModelTest extends TestCase
{
    public function testTheLowerIdComesFirstWhicheverWayRoundTheLinkWasMade(): void
    {
        $link = new StoryLinkModel(9, 4, 15.5);

        self::assertSame(4, $link->firstEntryId);
        self::assertSame(9, $link->secondEntryId);
        self::assertSame('4:9', $link->pairKey());
    }

    public function testLinksSortStrongestFirstThenByEntryIds(): void
    {
        $links = [
            new StoryLinkModel(5, 6, 14.0),
            new StoryLinkModel(1, 3, 20.0),
            new StoryLinkModel(1, 2, 20.0),
            StoryLinkModel::sameUrl(7, 8),
            new StoryLinkModel(2, 4, 20.0),
        ];

        usort($links, StoryLinkModel::compare(...));

        self::assertSame(
            ['7:8', '1:2', '1:3', '2:4', '5:6'],
            array_map(static fn (StoryLinkModel $link): string => $link->pairKey(), $links),
        );
    }
}
