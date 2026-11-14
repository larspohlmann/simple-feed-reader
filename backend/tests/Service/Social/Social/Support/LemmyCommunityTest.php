<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Social\Support\LemmyCommunity;
use PHPUnit\Framework\TestCase;

final class LemmyCommunityTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../../../Fixtures/lemmy/community-list.json';

    private static function view(int $index): JsonNodeModel
    {
        $views = JsonNodeModel::of(json_decode((string) file_get_contents(self::FIXTURE), true))
            ->listAt('communities');
        self::assertNotNull($views);

        return $views[$index];
    }

    public function testACommunityReadsAsNameAtHostWithItsRssFeedTitleAndIcon(): void
    {
        $follow = LemmyCommunity::follow(self::view(0));

        self::assertNotNull($follow);
        self::assertSame('rss@feddit.example', $follow->identity);
        self::assertSame('!rss@feddit.example', $follow->handle);
        self::assertSame('RSS', $follow->displayName);
        self::assertSame('https://feddit.example/feeds/c/rss.xml', $follow->feedUrl);
        self::assertSame('https://feddit.example/pictrs/image/rss.png', $follow->avatarUrl);
    }

    public function testTheIdentityAndTheFeedAreLowerCased(): void
    {
        $follow = LemmyCommunity::follow(self::view(1));

        self::assertNotNull($follow);
        self::assertSame('linux@lemmy.example', $follow->identity);
        self::assertSame('https://lemmy.example/feeds/c/linux.xml', $follow->feedUrl);
        self::assertNull($follow->avatarUrl);
    }

    public function testACommunityOnAPlainHttpInstanceIsLeftOut(): void
    {
        self::assertNull(LemmyCommunity::follow(self::view(2)));
    }

    public function testAViewWithoutANameIsLeftOut(): void
    {
        self::assertNull(LemmyCommunity::follow(JsonNodeModel::of([
            'community' => ['actor_id' => 'https://feddit.example/c/rss'],
        ])));
    }
}
