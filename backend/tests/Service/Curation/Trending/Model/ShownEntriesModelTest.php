<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Model;

use App\Service\Curation\Trending\Model\ShownEntriesModel;
use PHPUnit\Framework\TestCase;

final class ShownEntriesModelTest extends TestCase
{
    public function testItListsTheEntriesOfEachStoryAndAllOfThem(): void
    {
        $shown = new ShownEntriesModel([1 => [10 => 'a', 11 => null], 2 => [20 => 'b']]);

        self::assertSame([1 => [10, 11], 2 => [20]], $shown->entryIdsByStory());
        self::assertSame([10, 11, 20], $shown->entryIds());
    }

    public function testAListedEntryKeepsItsOwnLinkHashEvenWhenItHasNone(): void
    {
        $shown = new ShownEntriesModel([1 => [10 => 'a', 11 => null]]);

        self::assertSame('a', $shown->resolveLinkHash(10, 10));
        self::assertNull($shown->resolveLinkHash(11, 10));
    }

    public function testAnUnlistedCopyTakesTheLinkHashOfTheRowItWasFoldedInto(): void
    {
        $shown = new ShownEntriesModel([1 => [10 => 'a']]);

        self::assertSame('a', $shown->resolveLinkHash(99, 10));
    }

    public function testNoStoriesShowNothing(): void
    {
        $shown = new ShownEntriesModel([]);

        self::assertSame([], $shown->entryIdsByStory());
        self::assertSame([], $shown->entryIds());
    }
}
