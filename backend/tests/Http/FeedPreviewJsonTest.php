<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\FeedPreviewJson;
use App\Service\Preview\Model\FeedPreviewItemModel;
use App\Service\Preview\Model\FeedPreviewModel;
use PHPUnit\Framework\TestCase;

final class FeedPreviewJsonTest extends TestCase
{
    public function testAnUntitledItemHasAnEmptyTitle(): void
    {
        $json = FeedPreviewJson::one($this->previewOf('(untitled)'));

        self::assertSame('', $json['feed']['items'][0]['title']);
    }

    public function testATitleIsSentUnchanged(): void
    {
        $json = FeedPreviewJson::one($this->previewOf('A real headline'));

        self::assertSame('A real headline', $json['feed']['items'][0]['title']);
    }

    private function previewOf(string $itemTitle): FeedPreviewModel
    {
        return new FeedPreviewModel(
            'Feed',
            1,
            'full',
            false,
            [new FeedPreviewItemModel($itemTitle, 'https://example.com/a', null, null, null, null, null, null)],
        );
    }
}
