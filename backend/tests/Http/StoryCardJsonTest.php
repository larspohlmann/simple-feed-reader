<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\StoryCardJson;
use App\Service\Curation\Trending\Model\StoryFaceModel;
use App\Service\Curation\Trending\Model\StoryHeadingModel;
use App\Service\Curation\Trending\Model\StoryLifespanModel;
use App\Service\Curation\Trending\Model\StorySourcesModel;
use PHPUnit\Framework\TestCase;

final class StoryCardJsonTest extends TestCase
{
    public function testMapsEveryCardField(): void
    {
        $face = new StoryFaceModel(
            12,
            new StoryHeadingModel('Hafenstreik in Hamburg', 'Die Arbeit ruht.'),
            new StorySourcesModel(4, 3),
            true,
            new StoryLifespanModel(
                new \DateTimeImmutable('2031-05-14 08:00:00'),
                new \DateTimeImmutable('2031-05-14 11:00:00'),
            ),
        );

        self::assertSame(
            [
                'id' => 12,
                'title' => 'Hafenstreik in Hamburg',
                'synopsis' => 'Die Arbeit ruht.',
                'outletCount' => 4,
                'shareCount' => 3,
                'followed' => true,
                'createdAt' => '2031-05-14T08:00:00+00:00',
                'lastAddedAt' => '2031-05-14T11:00:00+00:00',
            ],
            (new StoryCardJson())->from($face),
        );
    }
}
