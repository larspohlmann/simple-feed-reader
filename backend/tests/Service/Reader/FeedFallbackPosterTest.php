<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Service\Reader\FeedFallbackPoster;
use PHPUnit\Framework\TestCase;

final class FeedFallbackPosterTest extends TestCase
{
    private function entry(): Entry
    {
        return new Entry(
            new Feed('https://feed.test/rss.xml'),
            'guid-1',
            'https://feed.test/article',
            'An article',
            new \DateTimeImmutable('2026-09-07T10:00:00Z'),
            new \DateTimeImmutable('2026-09-07T10:00:00Z'),
        );
    }

    public function testPrefersAVideoMediumPoster(): void
    {
        $entry = $this->entry();
        $entry->setImage('https://feed.test/lead.jpg', 1200, 630);
        $entry->setMedia(
            [
                new EntryMedium('https://feed.test/lead.jpg', 'image', 1200, 630),
                new EntryMedium('https://feed.test/clip.mp4', 'video', null, null, 'https://feed.test/poster.jpg'),
            ],
            [],
        );

        self::assertSame('https://feed.test/poster.jpg', (new FeedFallbackPoster())->forEntry($entry));
    }

    public function testFallsBackToTheLeadImageWhenNoVideoPosterExists(): void
    {
        $entry = $this->entry();
        $entry->setImage('https://feed.test/lead.jpg', 1200, 630);
        $entry->setMedia([new EntryMedium('https://feed.test/lead.jpg', 'image', 1200, 630)], []);

        self::assertSame('https://feed.test/lead.jpg', (new FeedFallbackPoster())->forEntry($entry));
    }

    public function testFallsBackToTheLeadImageWhenAVideoDeclaresNoPoster(): void
    {
        $entry = $this->entry();
        $entry->setImage('https://feed.test/lead.jpg', 1200, 630);
        $entry->setMedia([new EntryMedium('https://feed.test/clip.mp4', 'video')], []);

        self::assertSame('https://feed.test/lead.jpg', (new FeedFallbackPoster())->forEntry($entry));
    }

    public function testIsNullWhenNeitherAVideoPosterNorALeadImageExists(): void
    {
        self::assertNull((new FeedFallbackPoster())->forEntry($this->entry()));
    }
}
