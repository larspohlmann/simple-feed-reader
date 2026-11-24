<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\OpmlJson;
use App\Service\Library\Opml\Model\OpmlFeedModel;
use App\Service\Library\Opml\Model\OpmlFeedState;
use App\Service\Library\Opml\Model\OpmlPlacedFolderModel;
use App\Service\Library\Opml\Model\OpmlPreviewModel;
use PHPUnit\Framework\TestCase;

final class OpmlJsonTest extends TestCase
{
    public function testAPreviewNestsFoldersAndCarriesEachFeedsState(): void
    {
        $feed = new OpmlFeedModel('https://a.example/feed.xml', 'A', 'rss');
        $tech = new OpmlPlacedFolderModel('Tech', false, ['P', 'Tech (1)'], [], []);
        $root = new OpmlPlacedFolderModel(null, false, ['P'], [$feed], [$tech]);

        $json = OpmlJson::preview(new OpmlPreviewModel(8, $root, [$feed->url => OpmlFeedState::New]));

        self::assertSame([
            'freeSlots' => 8,
            'root' => [
                'name' => null,
                'dropped' => false,
                'destination' => ['P'],
                'feeds' => [['url' => 'https://a.example/feed.xml', 'title' => 'A', 'state' => 'new']],
                'folders' => [[
                    'name' => 'Tech',
                    'dropped' => false,
                    'destination' => ['P', 'Tech (1)'],
                    'feeds' => [],
                    'folders' => [],
                ]],
            ],
        ], $json);
    }

    public function testAFeedWithoutATitleCarriesNull(): void
    {
        $feed = new OpmlFeedModel('https://a.example/feed.xml', null, 'rss');
        $root = new OpmlPlacedFolderModel(null, false, [], [$feed], []);

        $json = OpmlJson::preview(new OpmlPreviewModel(0, $root, [$feed->url => OpmlFeedState::InvalidUrl]));

        self::assertSame(
            [['url' => 'https://a.example/feed.xml', 'title' => null, 'state' => 'invalid_url']],
            $json['root']['feeds'],
        );
    }
}
