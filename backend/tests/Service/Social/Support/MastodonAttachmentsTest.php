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
}
