<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\EntryAttachment;
use App\Entity\EntryMedia;
use App\Entity\EntryMedium;
use App\Entity\Exception\IncompleteStoredMediaException;
use PHPUnit\Framework\TestCase;

final class EntryMediaTest extends TestCase
{
    public function testEmptyByDefault(): void
    {
        $media = new EntryMedia();

        self::assertSame([], $media->getMedia());
        self::assertSame([], $media->getAttachments());
    }

    public function testRoundTripsMediaAndAttachments(): void
    {
        $media = new EntryMedia();
        $media->set(
            [
                new EntryMedium('https://i/one.jpg', 'image', 800, 600),
                new EntryMedium('https://v/clip.mp4', 'video', null, null, 'https://v/p.jpg'),
            ],
            [new EntryAttachment('https://cdn/ep.mp3', 'audio/mpeg', 3723, 4200000, 'Chapter two')],
        );

        $visuals = $media->getMedia();
        self::assertCount(2, $visuals);
        self::assertSame('https://i/one.jpg', $visuals[0]->url);
        self::assertSame('image', $visuals[0]->kind);
        self::assertSame(800, $visuals[0]->width);
        self::assertSame('https://v/p.jpg', $visuals[1]->previewImageUrl);

        $attachments = $media->getAttachments();
        self::assertCount(1, $attachments);
        self::assertSame('audio/mpeg', $attachments[0]->mimeType);
        self::assertSame(3723, $attachments[0]->durationInSeconds);
        self::assertSame('Chapter two', $attachments[0]->title);
    }

    public function testRemovingAUrlDropsOnlyTheMatchingMediumAndReindexes(): void
    {
        $media = new EntryMedia();
        $media->set(
            [
                new EntryMedium('https://i/one.jpg', 'image'),
                new EntryMedium('https://i/two.jpg', 'image'),
            ],
            [new EntryAttachment('https://cdn/ep.mp3', 'audio/mpeg')],
        );

        $media->removeUrl('https://i/one.jpg');

        $visuals = $media->getMedia();
        self::assertSame([0], array_keys($visuals));
        self::assertSame('https://i/two.jpg', $visuals[0]->url);
        self::assertCount(1, $media->getAttachments());
    }

    public function testRemovingAnAbsentUrlLeavesTheListUntouched(): void
    {
        $media = new EntryMedia();
        $media->set([new EntryMedium('https://i/one.jpg', 'image')], []);

        $media->removeUrl('https://i/missing.jpg');

        self::assertCount(1, $media->getMedia());
    }

    public function testMediumJsonOmitsUnknownFields(): void
    {
        self::assertSame(
            ['url' => 'https://i/x.jpg', 'kind' => 'image'],
            (new EntryMedium('https://i/x.jpg', 'image'))->jsonSerialize(),
        );
    }

    public function testAttachmentJsonOmitsUnknownFields(): void
    {
        self::assertSame(
            ['url' => 'https://cdn/x.mp3'],
            (new EntryAttachment('https://cdn/x.mp3'))->jsonSerialize(),
        );
    }

    public function testAStoredMediumNeedsAUrlAndAKind(): void
    {
        self::assertTrue(EntryMedium::isComplete(['url' => 'https://i/x.jpg', 'kind' => 'image']));
        self::assertFalse(EntryMedium::isComplete(['kind' => 'image']));
        self::assertFalse(EntryMedium::isComplete(['url' => 'https://i/x.jpg']));
        self::assertFalse(EntryMedium::isComplete(['url' => 42, 'kind' => 'image']));
    }

    public function testAStoredAttachmentNeedsAUrl(): void
    {
        self::assertTrue(EntryAttachment::isComplete(['url' => 'https://cdn/x.mp3']));
        self::assertFalse(EntryAttachment::isComplete(['mimeType' => 'audio/mpeg']));
    }

    public function testACompleteStoredMediumRoundTripsItsDeclaredFields(): void
    {
        $medium = EntryMedium::fromStored([
            'url' => 'https://v/clip.mp4',
            'kind' => 'video',
            'width' => 1280,
            'height' => '720',
            'previewImageUrl' => 'https://v/p.jpg',
        ]);

        self::assertSame('https://v/clip.mp4', $medium->url);
        self::assertSame('video', $medium->kind);
        self::assertSame(1280, $medium->width);
        self::assertNull($medium->height);
        self::assertSame('https://v/p.jpg', $medium->previewImageUrl);
    }

    public function testACompleteStoredAttachmentRoundTripsItsDeclaredFields(): void
    {
        $attachment = EntryAttachment::fromStored([
            'url' => 'https://cdn/ep.mp3',
            'mimeType' => 'audio/mpeg',
            'durationInSeconds' => 3723,
            'sizeInBytes' => '4200000',
            'title' => 'Chapter two',
        ]);

        self::assertSame('https://cdn/ep.mp3', $attachment->url);
        self::assertSame('audio/mpeg', $attachment->mimeType);
        self::assertSame(3723, $attachment->durationInSeconds);
        self::assertNull($attachment->sizeInBytes);
        self::assertSame('Chapter two', $attachment->title);
    }

    public function testAnIncompleteStoredMediumIsRefusedRatherThanGivenAnEmptyUrl(): void
    {
        $this->expectException(IncompleteStoredMediaException::class);

        EntryMedium::fromStored(['kind' => 'image']);
    }

    public function testAnIncompleteStoredAttachmentIsRefusedRatherThanGivenAnEmptyUrl(): void
    {
        $this->expectException(IncompleteStoredMediaException::class);

        EntryAttachment::fromStored(['mimeType' => 'audio/mpeg']);
    }
}
