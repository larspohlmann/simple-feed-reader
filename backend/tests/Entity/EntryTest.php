<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Enum\CommentsLoad;
use App\Service\Discussion\Discussion;
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

    public function testTheFeedBodyIsTheArticleContentByDefault(): void
    {
        $entry = $this->entry();
        $entry->setContentHtml('<p>The article.</p>');

        self::assertSame('<p>The article.</p>', $entry->getArticleContentHtml());
    }

    public function testAnOpeningPostBodyIsNoArticleContent(): void
    {
        $entry = $this->entry();
        $entry->setContentHtml('<p>My take on the linked article.</p>');
        $entry->setDiscussion(Discussion::of('https://t.example/1', null, CommentsLoad::Auto)->withOpeningPostBody());

        self::assertNull($entry->getArticleContentHtml());
        self::assertSame('<p>My take on the linked article.</p>', $entry->getContentHtml());
    }

    private function entry(): Entry
    {
        $feed = new Feed('https://example.test/feed.xml');

        return new Entry(
            $feed,
            'guid-1',
            'https://example.test/1',
            'Title',
            new \DateTimeImmutable('2026-09-21 06:00:00'),
            new \DateTimeImmutable('2026-09-21 05:00:00'),
        );
    }
}
