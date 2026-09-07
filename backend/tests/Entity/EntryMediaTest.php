<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\EntryAttachment;
use App\Entity\EntryMedia;
use App\Entity\EntryMedium;
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
}
