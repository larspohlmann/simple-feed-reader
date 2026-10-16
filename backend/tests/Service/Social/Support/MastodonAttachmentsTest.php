<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
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

    public function testAStatusesAttachmentsBecomeEntryMedia(): void
    {
        $media = MastodonAttachments::entryMediaOf(JsonNodeModel::of(['media_attachments' => [
            ['type' => 'audio', 'url' => 'https://files.example/a.mp3'],
            ['type' => 'gifv', 'url' => 'https://files.example/b.mp4', 'preview_url' => 'https://files.example/b.jpg'],
        ]]));

        self::assertCount(1, $media);
        self::assertSame('video', $media[0]->kind);
        self::assertSame('https://files.example/b.jpg', $media[0]->previewImageUrl);
    }
}
