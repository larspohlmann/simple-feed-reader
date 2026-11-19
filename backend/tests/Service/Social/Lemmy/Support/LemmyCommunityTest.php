<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy\Support;

use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Lemmy\Support\LemmyCommunity;
use App\Tests\Support\ReadsFixtures;
use PHPUnit\Framework\TestCase;

final class LemmyCommunityTest extends TestCase
{
    use ReadsFixtures;

    private const string FIXTURE = 'lemmy/community-list.json';

    private function view(int $index): JsonNodeModel
    {
        $views = JsonNodeModel::fromValue(json_decode($this->fixture(self::FIXTURE), true))
            ->listAt('communities');
        self::assertNotNull($views);

        return $views[$index];
    }

    public function testACommunityReadsAsNameAtHostWithItsRssFeedTitleAndIcon(): void
    {
        $follow = LemmyCommunity::follow($this->view(0));

        self::assertNotNull($follow);
        self::assertSame('rss@feddit.example', $follow->identity);
        self::assertSame('!rss@feddit.example', $follow->handle);
        self::assertSame('RSS', $follow->displayName);
        self::assertSame('https://feddit.example/feeds/c/rss.xml', $follow->feedUrl);
        self::assertSame('https://feddit.example/pictrs/image/rss.png', $follow->avatarUrl);
    }

    public function testTheIdentityAndTheFeedAreLowerCased(): void
    {
        $follow = LemmyCommunity::follow($this->view(1));

        self::assertNotNull($follow);
        self::assertSame('linux@lemmy.example', $follow->identity);
        self::assertSame('https://lemmy.example/feeds/c/linux.xml', $follow->feedUrl);
        self::assertNull($follow->avatarUrl);
    }

    public function testACommunityOnAPortKeepsThePortInItsIdentityAndFeed(): void
    {
        $follow = LemmyCommunity::follow(JsonNodeModel::fromValue([
            'community' => ['name' => 'Linux', 'actor_id' => 'https://Lemmy.Example:8443/c/Linux'],
        ]));

        self::assertNotNull($follow);
        self::assertSame('linux@lemmy.example:8443', $follow->identity);
        self::assertSame('!linux@lemmy.example:8443', $follow->handle);
        self::assertSame('https://lemmy.example:8443/feeds/c/linux.xml', $follow->feedUrl);
    }

    public function testACommunityOnAPlainHttpInstanceIsLeftOut(): void
    {
        self::assertNull(LemmyCommunity::follow($this->view(2)));
    }

    public function testAViewWithoutANameIsLeftOut(): void
    {
        self::assertNull(LemmyCommunity::follow(JsonNodeModel::fromValue([
            'community' => ['actor_id' => 'https://feddit.example/c/rss'],
        ])));
    }
}
