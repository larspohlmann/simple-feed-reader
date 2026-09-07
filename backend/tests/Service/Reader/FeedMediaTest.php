<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Entity\Entry;
use App\Entity\EntryAttachment;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Service\Reader\FeedMedia;
use PHPUnit\Framework\TestCase;

final class FeedMediaTest extends TestCase
{
    /**
     * @param list<EntryMedium>     $media
     * @param list<EntryAttachment> $attachments
     */
    private function entryWith(array $media, array $attachments, ?string $leadUrl = null): Entry
    {
        $entry = new Entry(
            new Feed('https://feed.test/rss.xml'),
            'guid-1',
            'https://feed.test/article',
            'An article',
            new \DateTimeImmutable('2026-09-07T10:00:00Z'),
            new \DateTimeImmutable('2026-09-07T10:00:00Z'),
        );
        if ($leadUrl !== null) {
            $entry->setImage($leadUrl, 1200, 630);
        }
        $entry->setMedia($media, $attachments);

        return $entry;
    }

    public function testPosterFallbackPrefersAVideoMediumPreview(): void
    {
        $feedMedia = FeedMedia::fromEntry($this->entryWith(
            [new EntryMedium('https://feed.test/clip.mp4', 'video', null, null, 'https://feed.test/poster.jpg')],
            [],
            'https://feed.test/lead.jpg',
        ));

        self::assertSame('https://feed.test/poster.jpg', $feedMedia->posterFallback());
    }

    public function testPosterFallbackFallsBackToTheLeadImage(): void
    {
        $feedMedia = FeedMedia::fromEntry($this->entryWith([], [], 'https://feed.test/lead.jpg'));

        self::assertSame('https://feed.test/lead.jpg', $feedMedia->posterFallback());
    }

    public function testMatchesAnImageMediumAcrossACdnRendition(): void
    {
        $feedMedia = FeedMedia::fromEntry($this->entryWith(
            [new EntryMedium('https://img.test/2026/09/harbour-crane.jpg', 'image', 1600, 900)],
            [],
        ));

        $medium = $feedMedia->declaredMediumFor('https://cdn.test/w:800/harbour-crane.jpg');

        self::assertNotNull($medium);
        self::assertSame(1600, $medium->width);
        self::assertSame(900, $medium->height);
    }

    public function testDoesNotMatchADifferentImageAsset(): void
    {
        $feedMedia = FeedMedia::fromEntry($this->entryWith(
            [new EntryMedium('https://img.test/harbour-crane.jpg', 'image', 1600, 900)],
            [],
        ));

        self::assertNull($feedMedia->declaredMediumFor('https://img.test/city-skyline.jpg'));
    }

    public function testMatchesAVideoMediumByBareUrlIgnoringQuery(): void
    {
        $feedMedia = FeedMedia::fromEntry($this->entryWith(
            [new EntryMedium('https://cdn.test/clip.mp4', 'video', 1280, 720)],
            [],
        ));

        $medium = $feedMedia->declaredMediumFor('https://cdn.test/clip.mp4?token=abc123');

        self::assertNotNull($medium);
        self::assertSame('video', $medium->kind);
        self::assertSame(1280, $medium->width);
    }

    public function testMatchesAnAttachmentByBareUrl(): void
    {
        $feedMedia = FeedMedia::fromEntry($this->entryWith(
            [],
            [new EntryAttachment('https://cdn.test/ep1.mp3', 'audio/mpeg', 3723, 4200000, 'Episode one')],
        ));

        $attachment = $feedMedia->declaredAttachmentFor('https://cdn.test/ep1.mp3?utm=feed');

        self::assertNotNull($attachment);
        self::assertSame('audio/mpeg', $attachment->mimeType);
    }

    public function testReturnsNullWhenNothingMatches(): void
    {
        $feedMedia = FeedMedia::none();

        self::assertNull($feedMedia->posterFallback());
        self::assertNull($feedMedia->declaredMediumFor('https://cdn.test/x.mp4'));
        self::assertNull($feedMedia->declaredAttachmentFor('https://cdn.test/x.mp3'));
    }
}
