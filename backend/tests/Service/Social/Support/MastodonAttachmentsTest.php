<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Parser\Model\ParsedMediumModel;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Social\Support\MastodonAttachments;
use PHPUnit\Framework\TestCase;

final class MastodonAttachmentsTest extends TestCase
{
    public function testUnusableAttachmentsLeaveAListOfTheUsableOnes(): void
    {
        $media = MastodonAttachments::of([
            JsonNodeModel::of(['type' => 'image', 'url' => 'http://files.example/plain.jpg']),
            JsonNodeModel::of(['type' => 'image', 'url' => 'https://files.example/a.jpg']),
        ]);

        self::assertCount(1, $media);
        self::assertSame('https://files.example/a.jpg', $media[0]->url);
    }

    public function testAnImageKeepsItsDescriptionAsAltText(): void
    {
        $media = MastodonAttachments::of([
            JsonNodeModel::of([
                'type' => 'image',
                'url' => 'https://files.example/a.jpg',
                'description' => 'A donkey in a field',
            ]),
        ]);

        self::assertSame('A donkey in a field', $media[0]->alt);
    }

    public function testAStatusesAttachmentsBecomeEntryMedia(): void
    {
        $media = MastodonAttachments::entryMediaOf(JsonNodeModel::of(['media_attachments' => [
            ['type' => 'audio', 'url' => 'https://files.example/a.mp3'],
            [
                'type' => 'gifv',
                'url' => 'https://files.example/b.mp4',
                'preview_url' => 'https://files.example/b.jpg',
                'description' => 'A cat waves',
            ],
        ]]));

        self::assertCount(1, $media);
        self::assertSame('video', $media[0]->kind);
        self::assertTrue($media[0]->isAnimated);
        self::assertSame('https://files.example/b.jpg', $media[0]->previewImageUrl);
        self::assertSame('A cat waves', $media[0]->alt);
    }

    public function testAGifIsAnAnimatedVideoAndAVideoStaysAPlainOne(): void
    {
        $media = MastodonAttachments::of([
            JsonNodeModel::of(['type' => 'gifv', 'url' => 'https://files.example/gif.mp4']),
            JsonNodeModel::of(['type' => 'video', 'url' => 'https://files.example/clip.mp4']),
        ]);

        self::assertSame(
            [[VisualMediaKind::Video, true], [VisualMediaKind::Video, false]],
            array_map(
                static fn (ParsedMediumModel $medium): array => [$medium->kind, $medium->isAnimated],
                $media,
            ),
        );
    }
}
