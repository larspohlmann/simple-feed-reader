<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\OpmlJson;
use App\Service\Library\Opml\Model\OpmlFeedModel;
use App\Service\Library\Opml\Model\OpmlFeedState;
use App\Service\Library\Opml\Model\OpmlImportResultModel;
use App\Service\Library\Opml\Model\OpmlPlacedFolderModel;
use App\Service\Library\Opml\Model\OpmlPreviewModel;
use PHPUnit\Framework\TestCase;

final class OpmlJsonTest extends TestCase
{
    public function testMapsEveryCount(): void
    {
        self::assertSame(
            ['imported' => 5, 'alreadySubscribed' => 2, 'invalid' => 1, 'skippedOverLimit' => 3],
            OpmlJson::imported(new OpmlImportResultModel(5, 2, 1, 3)),
        );
    }

    public function testADroppedFolderCarriesTheDestinationOfTheFolderAboveIt(): void
    {
        $dropped = new OpmlPlacedFolderModel('Misc', true, ['P', 'Tech'], [], []);
        $tech = new OpmlPlacedFolderModel('Tech', false, ['P', 'Tech'], [], [$dropped]);
        $root = new OpmlPlacedFolderModel(null, false, ['P'], [], [$tech]);

        $json = OpmlJson::preview(new OpmlPreviewModel(1, $root, []));

        self::assertSame([[
            'name' => 'Misc',
            'dropped' => true,
            'destination' => ['P', 'Tech'],
            'feeds' => [],
            'folders' => [],
        ]], $json['root']['folders'][0]['folders']);
    }

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
                'feeds' => [[
                    'url' => 'https://a.example/feed.xml',
                    'title' => 'A',
                    'siteUrl' => null,
                    'state' => 'new',
                ]],
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
            [[
                'url' => 'https://a.example/feed.xml',
                'title' => null,
                'siteUrl' => null,
                'state' => 'invalid_url',
            ]],
            $json['root']['feeds'],
        );
    }
}
