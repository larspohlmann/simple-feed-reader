<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Discussion;
use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Entity\Headline;
use PHPUnit\Framework\TestCase;

final class EntryTest extends TestCase
{
    public function testDroppingTheImageRemovesItFromTheMediaListAndSettlesTheRow(): void
    {
        $entry = $this->entry();
        $entry->getImage()->storePending('https://i/lead.jpg', null, null);
        $entry->setMedia([
            new EntryMedium('https://i/lead.jpg', 'image'),
            new EntryMedium('https://i/other.jpg', 'image'),
        ], []);
        $at = new \DateTimeImmutable('2026-09-21 12:00:00');

        $entry->dropImage($at);

        self::assertNull($entry->getImageUrl());
        self::assertEquals($at, $entry->getImage()->getCheckedAt());
        $media = $entry->getMedia();
        self::assertCount(1, $media);
        self::assertSame('https://i/other.jpg', $media[0]->url);
    }

    public function testDroppingAnImageAbsentFromMediaLeavesTheListUntouched(): void
    {
        $entry = $this->entry();
        $entry->getImage()->storePending('https://i/lead.jpg', null, null);
        $entry->setMedia([new EntryMedium('https://i/other.jpg', 'image')], []);

        $entry->dropImage(new \DateTimeImmutable('2026-09-21 12:00:00'));

        self::assertCount(1, $entry->getMedia());
    }

    public function testANewEntryKeepsTheTitleTheFeedGave(): void
    {
        self::assertFalse($this->entry()->isTitleDerived());
    }

    public function testAnEntryRemembersItsTitleWasDerived(): void
    {
        $entry = $this->entry();

        $entry->setHeadline(Headline::derived('Title'));

        self::assertTrue($entry->isTitleDerived());
    }

    public function testAnEntryWithoutUrlAndWithADiscussionPageIsADiscussionThread(): void
    {
        $entry = $this->entry(null);
        $entry->setDiscussion(Discussion::platformThread('https://lemmy.world/post/1'));

        self::assertTrue($entry->isDiscussionThread());
    }

    public function testAnEntryWithAUrlIsNotADiscussionThread(): void
    {
        $entry = $this->entry('https://example.test/1');
        $entry->setDiscussion(Discussion::platformThread('https://lemmy.world/post/1'));

        self::assertFalse($entry->isDiscussionThread());
    }

    public function testAnEntryWithoutADiscussionPageIsNotADiscussionThread(): void
    {
        self::assertFalse($this->entry(null)->isDiscussionThread());
    }

    private function entry(?string $url = 'https://example.test/1'): Entry
    {
        $feed = new Feed('https://example.test/feed.xml');

        return new Entry(
            $feed,
            'guid-1',
            $url,
            'Title',
            new \DateTimeImmutable('2026-09-21 06:00:00'),
            new \DateTimeImmutable('2026-09-21 05:00:00'),
        );
    }
}
